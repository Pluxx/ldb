<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Http\ApiException;
use PDO;
use PDOStatement;

/**
 * Shared PDO plumbing for all drivers.
 *
 * Subclasses must implement at least: id(), label(), isAvailable(), defaultPort(), buildDsn(),
 * capabilities(), serverInfo(), listDatabases(), listTables(), tableInfo(), createStatement(),
 * buildCreateTable(), buildAlterTable(), dataTypes().
 *
 * Extension hooks (override as needed):
 *  - buildDsn(array $params): string            DSN for new PDO()
 *  - pdoOptions(array $params): array           extra PDO driver options
 *  - afterConnect(array $params): void          session setup (charset, timeouts, …)
 *  - limitClause(int $limit, int $offset): string   default "LIMIT n OFFSET m"
 *  - buildPagedSelect(...)                      full paged SELECT (SQL Server needs ORDER BY)
 *  - likeOperator(): string                     'LIKE' (pgsql may use ILIKE)
 *  - likeSpecialChars(): array                  chars escaped with '!' in contains/starts/ends
 *  - castToText(string $quotedColumn, array $col): ?string   expression used by global search
 *                                               on non-text columns (null = skip column)
 *  - isTextType(string $type): bool
 *  - estimateBrowseTotal(string $table): ?int   cheap row estimate for huge tables
 *  - singleRowSuffix(string $verb): string      e.g. " LIMIT 1" for MySQL UPDATE/DELETE
 *  - emptyInsertSql(string $quotedTable): string
 *  - executeRaw(string $sql): PDOStatement      used by query() (MySQL: PS fallback)
 *  - beforeQuery()/afterQuery()                 e.g. MySQL unbuffered mode
 *  - lastInsertIdFor(string $table): ?string
 *  - renderDefaultClause(array $col): string    " DEFAULT …" for a ColumnDefinition
 *
 * Extra public API (not in DriverInterface, used by controllers):
 *  - fkValues(string $table, string $column, ?string $term): [{value,label}] (max 100)
 *  - completionWords(): {keywords:[string], functions:[string]}
 *  - applyRowChanges(string $table, array $changes, bool $preview): batched insert/update/delete
 *    in one transaction (table.applyChanges)
 *  - renderDisplaySql(string $sql, array $params): SQL with quoted literals, display only
 */
abstract class AbstractPdoDriver implements DriverInterface
{
    /** Escape character used in LIKE patterns built by browse()/fkValues(). */
    protected const LIKE_ESCAPE = '!';

    /** Largest integer that survives a JSON round-trip through JavaScript. */
    protected const JS_MAX_SAFE_INT = 9007199254740991;

    /** Bytes of binary values returned (base64) in result cells. */
    protected const BINARY_PREVIEW_BYTES = 256;

    protected ?PDO $pdo = null;
    protected array $params = [];
    protected ?string $database = null;
    protected ?string $schema = null;
    /** Session read-only mode (see setReadOnly()). */
    protected bool $readOnly = false;
    /** @var array<string,array> per-instance TableInfo cache for browse/fkValues/query */
    protected array $tableInfoCache = [];

    // ------------------------------------------------------------------ connection

    abstract protected function buildDsn(array $params): string;

    /** Driver specific PDO options merged over the defaults. */
    protected function pdoOptions(array $params): array
    {
        return [];
    }

    /** PDO class to instantiate (SQLite uses a subclass that blocks file-escaping statements). */
    protected function pdoClass(): string
    {
        return PDO::class;
    }

    /** Session initialisation after the PDO object exists. */
    protected function afterConnect(array $params): void
    {
    }

    public function connect(array $params): void
    {
        $this->params = $params;
        if (!empty($params['read_only'])) {
            $this->readOnly = true;
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => $this->connectTimeout(),
        ];
        foreach ($this->pdoOptions($params) as $k => $v) {
            $options[$k] = $v;
        }
        $class = $this->pdoClass();
        $this->pdo = new $class(
            $this->buildDsn($params),
            isset($params['user']) ? (string) $params['user'] : null,
            isset($params['password']) ? (string) $params['password'] : null,
            $options
        );
        // Not every driver accepts this attribute (e.g. some dblib builds) — ignore failures.
        try {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (\Throwable $e) {
        }
        $this->database = isset($params['database']) && $params['database'] !== '' ? (string) $params['database'] : null;
        $this->afterConnect($params);
        if ($this->readOnly) {
            $this->applyReadOnly(true);
        }
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
        if ($this->pdo !== null) {
            $this->applyReadOnly($readOnly);
        }
    }

    /**
     * Engine specific session statement(s) for setReadOnly(); runs on the open connection.
     * Default: nothing (engines without a session read-only mode, e.g. SQL Server).
     */
    protected function applyReadOnly(bool $readOnly): void
    {
    }

    /** Connect timeout in seconds (params.options.connect_timeout, default 10). */
    protected function connectTimeout(): int
    {
        $o = $this->params['options'] ?? [];
        return max(1, (int) ($o['connect_timeout'] ?? 10));
    }

    /** Statement timeout in seconds (params.options.query_timeout = config query_timeout), 0 = none. */
    protected function queryTimeout(): int
    {
        $o = $this->params['options'] ?? [];
        return max(0, (int) ($o['query_timeout'] ?? 0));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new ApiException('Not connected', 'internal', 500);
        }
        return $this->pdo;
    }

    // ------------------------------------------------------------------ server version

    /** Version string set by simulateServerVersion() (tests), null = ask the server. */
    protected ?string $simulatedVersion = null;

    /**
     * Raw version string as reported by the server (e.g. "10.3.39-MariaDB-1:10.3.39+maria~ubu2004").
     * Drivers override; serverVersion() normalizes it.
     */
    abstract protected function rawServerVersion(): string;

    /** Normalized numeric server version ("8.0.11", "10.3.39", "11.22", "15.0.4415.2", "3.22.0"). */
    public function serverVersion(): string
    {
        if ($this->simulatedVersion !== null) {
            return ServerVersion::normalize($this->simulatedVersion);
        }
        if ($this->pdo === null && $this->params === []) {
            return ''; // not connected: unknown, version checks treat it as new
        }
        return ServerVersion::normalize($this->rawServerVersion());
    }

    /** Support-policy flavor of this server: mysql | mariadb | pgsql | sqlsrv | sqlite. */
    public function versionFlavor(): string
    {
        return static::id();
    }

    /** True when the server version is >= $min (unknown versions count as new). */
    public function versionAtLeast(string $min): bool
    {
        return ServerVersion::atLeast($this->serverVersion(), $min);
    }

    /** {version_number, version_supported, min_version, version_flavor} merged into serverInfo(). */
    public function versionSupport(): array
    {
        return ServerVersion::support($this->versionFlavor(), $this->serverVersion());
    }

    /**
     * Testing aid: make the version-dependent logic (capabilities(), SQL generation) behave as on
     * the given server version without such a server (e.g. SQLite 3.22 while PHP ships 3.4x).
     * Null restores the real version. Never used by the application itself.
     */
    public function simulateServerVersion(?string $version): void
    {
        $this->simulatedVersion = $version;
    }

    public function select(?string $database, ?string $schema = null): void
    {
        $this->database = $database;
        $this->schema = $schema;
        $this->tableInfoCache = [];
    }

    public function currentDatabase(): ?string
    {
        return $this->database;
    }

    public function currentSchema(): ?string
    {
        return $this->schema;
    }

    // ------------------------------------------------------------------ quoting

    /** ANSI double-quote quoting; MySQL / SQL Server override. */
    public function quoteIdentifier(string $name): string
    {
        $this->assertIdentifier($name);
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function quoteTable(string $table): string
    {
        if ($this->schema !== null && $this->schema !== '') {
            return $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
        }
        return $this->quoteIdentifier($table);
    }

    /** Quote a string literal (for DDL contexts where binding is impossible). */
    public function quoteLiteral(string $value): string
    {
        if ($this->pdo !== null) {
            $q = $this->pdo->quote($value);
            if ($q !== false) {
                return $q;
            }
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function assertIdentifier(string $name): void
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            throw new ApiException('Invalid identifier', 'bad_request', 400);
        }
    }

    /** Validate a bare SQL word (collation, engine, charset names) that cannot be quoted. */
    protected function assertWord(string $word, string $what = 'name'): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $word)) {
            throw new ApiException('Invalid ' . $what . ': ' . $word, 'bad_request', 400);
        }
        return $word;
    }

    // ------------------------------------------------------------------ small helpers

    /** Prepare + execute with bound values. */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $value) {
            $this->bind($stmt, $i++, $value);
        }
        $stmt->execute();
        return $stmt;
    }

    protected function bind(PDOStatement $stmt, int $pos, $value): void
    {
        if ($value === null) {
            $stmt->bindValue($pos, null, PDO::PARAM_NULL);
        } elseif (is_bool($value)) {
            $stmt->bindValue($pos, $value ? 1 : 0, PDO::PARAM_INT);
        } elseif (is_int($value)) {
            $stmt->bindValue($pos, $value, PDO::PARAM_INT);
        } elseif (is_float($value)) {
            $stmt->bindValue($pos, (string) $value, PDO::PARAM_STR);
        } elseif (is_array($value) || is_object($value)) {
            throw new ApiException('Invalid value for a cell (arrays/objects are not allowed)', 'bad_request', 400);
        } else {
            $stmt->bindValue($pos, (string) $value, PDO::PARAM_STR);
        }
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row === false ? null : $row;
    }

    /** @return mixed|null first column of first row */
    protected function fetchValue(string $sql, array $params = [])
    {
        $stmt = $this->run($sql, $params);
        $v = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $v === false ? null : $v;
    }

    protected function exec(string $sql): int
    {
        return (int) $this->pdo()->exec($sql);
    }

    protected function unsupported(string $feature): ApiException
    {
        return new ApiException($feature . ' is not supported by the ' . static::label() . ' driver', 'unsupported', 400);
    }

    /** 'unsupported' error for a feature this server version does not have yet. */
    protected function needsVersion(string $feature, string $since): ApiException
    {
        return new ApiException($feature . ' needs ' . $since . ' or later (server version ' . $this->serverVersion() . ')', 'unsupported', 400);
    }

    protected function notFound(string $what): ApiException
    {
        return new ApiException($what . ' not found', 'not_found', 404);
    }

    /** TableInfo cached for the lifetime of this driver instance (one HTTP request). */
    protected function cachedTableInfo(string $table): array
    {
        $key = ($this->database ?? '') . "\0" . ($this->schema ?? '') . "\0" . $table;
        if (!isset($this->tableInfoCache[$key])) {
            $this->tableInfoCache[$key] = $this->tableInfo($table);
        }
        return $this->tableInfoCache[$key];
    }

    protected function forgetTableInfo(): void
    {
        $this->tableInfoCache = [];
    }

    /** Parse "varchar(255)" → ['varchar', '255'] ; "decimal(10,2) unsigned" → ['decimal','10,2']. */
    protected static function splitType(string $fullType): array
    {
        if (preg_match('/^\s*([^(]+?)\s*\((.*)\)/s', $fullType, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [trim(preg_replace('/\s+(unsigned|zerofill|signed)\b.*$/i', '', $fullType)), null];
    }

    // ------------------------------------------------------------------ type helpers

    public function isTextType(string $type): bool
    {
        return (bool) preg_match('/char|text|clob|string|enum|\bset\b|uuid|json|xml|citext|name|inet/i', $type)
            && !preg_match('/binary|blob|bytea/i', $type);
    }

    protected function isNumericType(string $type): bool
    {
        return (bool) preg_match('/int|dec|numeric|float|double|real|money|serial|bit/i', $type);
    }

    /**
     * Expression used by the global browse search for a non-text column, or null to skip it.
     * Default: skip. (Drivers may return e.g. "CAST(col AS CHAR)".)
     */
    protected function castToText(string $quotedColumn, array $col): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ browse

    protected function limitClause(int $limit, int $offset): string
    {
        return ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    /**
     * Build the paged SELECT. $where / $orderBy include their keywords or are ''.
     * SQL Server overrides this (OFFSET … FETCH requires ORDER BY).
     */
    protected function buildPagedSelect(string $quotedTable, string $where, string $orderBy, int $limit, int $offset, array $info): string
    {
        return 'SELECT * FROM ' . $quotedTable . $where . $orderBy . $this->limitClause($limit, $offset);
    }

    protected function likeOperator(): string
    {
        return 'LIKE';
    }

    /** Characters that must be escaped inside LIKE patterns (escape char is LIKE_ESCAPE). */
    protected function likeSpecialChars(): array
    {
        return [self::LIKE_ESCAPE, '%', '_'];
    }

    protected function escapeLike(string $value): string
    {
        $map = [];
        foreach ($this->likeSpecialChars() as $c) {
            $map[$c] = self::LIKE_ESCAPE . $c;
        }
        return strtr($value, $map);
    }

    protected function likeEscapeClause(): string
    {
        return " ESCAPE '" . self::LIKE_ESCAPE . "'";
    }

    /** Cheap row estimate used instead of COUNT(*) for huge unfiltered tables (null = count). */
    protected function estimateBrowseTotal(string $table): ?int
    {
        return null;
    }

    public function browse(string $table, array $opts): array
    {
        $start = microtime(true);
        $info = $this->cachedTableInfo($table);
        $colsByName = [];
        foreach ($info['columns'] as $c) {
            $colsByName[$c['name']] = $c;
        }
        $qt = $this->quoteTable($table);

        $perPage = (int) ($opts['per_page'] ?? 50);
        $perPage = max(1, min($perPage, (int) ($opts['max_per_page'] ?? 10000)));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        // WHERE
        $conds = [];
        $params = [];
        foreach ((array) ($opts['filters'] ?? []) as $f) {
            if (!is_array($f) || !isset($f['column'])) {
                continue;
            }
            $col = (string) $f['column'];
            if (!isset($colsByName[$col])) {
                throw new ApiException('Unknown column in filter: ' . $col, 'bad_request', 400);
            }
            $conds[] = $this->filterCondition($this->quoteIdentifier($col), strtoupper(trim((string) ($f['op'] ?? '='))), $f['value'] ?? null, $params, $colsByName[$col]);
        }
        $search = isset($opts['search']) ? trim((string) $opts['search']) : '';
        if ($search !== '') {
            $or = [];
            $like = $this->likeOperator();
            $pattern = '%' . $this->escapeLike($search) . '%';
            foreach ($info['columns'] as $c) {
                $qc = $this->quoteIdentifier($c['name']);
                if ($this->isTextType((string) ($c['full_type'] ?? $c['type']))) {
                    $or[] = $qc . ' ' . $like . ' ?' . $this->likeEscapeClause();
                    $params[] = $pattern;
                } elseif (is_numeric($search) && $this->isNumericType((string) $c['type'])) {
                    $or[] = $qc . ' = ?';
                    $params[] = $search;
                } else {
                    $expr = $this->castToText($qc, $c);
                    if ($expr !== null) {
                        $or[] = $expr . ' ' . $like . ' ?' . $this->likeEscapeClause();
                        $params[] = $pattern;
                    }
                }
            }
            $conds[] = $or ? '(' . implode(' OR ', $or) . ')' : '1 = 0';
        }
        $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

        // ORDER BY
        $orders = [];
        foreach ((array) ($opts['order'] ?? []) as $o) {
            if (!is_array($o) || !isset($o['column']) || !isset($colsByName[(string) $o['column']])) {
                continue;
            }
            $dir = strtoupper((string) ($o['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
            $orders[] = $this->quoteIdentifier((string) $o['column']) . ' ' . $dir;
        }
        $orderBy = $orders ? ' ORDER BY ' . implode(', ', $orders) : '';

        $sql = $this->buildPagedSelect($qt, $where, $orderBy, $perPage, $offset, $info);
        $stmt = $this->run($sql, $params);
        $types = [];
        foreach ($info['columns'] as $c) {
            $types[] = (string) $c['type'];
        }
        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            foreach ($r as $i => $v) {
                $r[$i] = $this->normalizeValue($v, $types[$i] ?? null);
            }
            $rows[] = $r;
        }
        $stmt->closeCursor();

        // total
        $total = null;
        $estimated = false;
        if ($where === '') {
            $est = $this->estimateBrowseTotal($table);
            if ($est !== null) {
                $total = $est;
                $estimated = true;
            }
        }
        if ($total === null) {
            $total = (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $qt . $where, $params);
        }

        // columns
        $fkMap = [];
        foreach ($info['foreign_keys'] as $fk) {
            if (count($fk['columns']) === 1) {
                $fkMap[$fk['columns'][0]] = ['table' => $fk['ref_table'], 'column' => $fk['ref_columns'][0] ?? null];
            }
        }
        $pk = [];
        foreach ($info['indexes'] as $ix) {
            if (!empty($ix['primary'])) {
                $pk = $ix['columns'];
                break;
            }
        }
        if (!$pk) {
            foreach ($info['columns'] as $c) {
                if (!empty($c['primary'])) {
                    $pk[] = $c['name'];
                }
            }
        }
        $columns = [];
        foreach ($info['columns'] as $c) {
            $columns[] = [
                'name' => $c['name'],
                'type' => $c['full_type'] ?? $c['type'],
                'nullable' => (bool) $c['nullable'],
                'primary' => in_array($c['name'], $pk, true),
                'fk' => $fkMap[$c['name']] ?? null,
            ];
        }

        $result = [
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'primary_key' => $pk,
            'editable' => $pk !== [] && ($info['type'] ?? 'table') === 'table',
            'sql' => $this->interpolateForDisplay($sql, $params),
            'time_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
        if ($estimated) {
            $result['total_estimated'] = true;
        }
        return $result;
    }

    /**
     * SQL fragment for one filter; appends bound values to $params.
     * Supported ops: = != < > <= >= LIKE NOT LIKE IN NOT IN IS NULL IS NOT NULL BETWEEN contains starts ends
     */
    protected function filterCondition(string $qc, string $op, $value, array &$params, array $col): string
    {
        $op = preg_replace('/\s+/', ' ', $op);
        switch ($op) {
            case '=':
            case '!=':
            case '<>':
            case '<':
            case '>':
            case '<=':
            case '>=':
                if ($value === null) {
                    return $qc . ($op === '=' ? ' IS NULL' : ($op === '!=' || $op === '<>' ? ' IS NOT NULL' : ' IS NULL'));
                }
                $params[] = $this->scalarValue($value);
                return $qc . ' ' . ($op === '<>' ? '!=' : $op) . ' ?';
            case 'LIKE':
            case 'NOT LIKE':
                $params[] = (string) $this->scalarValue($value);
                return $qc . ' ' . ($op === 'LIKE' ? $this->likeOperator() : 'NOT ' . $this->likeOperator()) . ' ?';
            case 'CONTAINS':
            case 'STARTS':
            case 'ENDS':
                $v = $this->escapeLike((string) $this->scalarValue($value));
                $params[] = ($op === 'STARTS' ? '' : '%') . $v . ($op === 'ENDS' ? '' : '%');
                $expr = $this->isTextType((string) ($col['full_type'] ?? $col['type'])) ? $qc : ($this->castToText($qc, $col) ?? $qc);
                return $expr . ' ' . $this->likeOperator() . ' ?' . $this->likeEscapeClause();
            case 'IN':
            case 'NOT IN':
                $list = $this->listValue($value);
                if (!$list) {
                    return $op === 'IN' ? '1 = 0' : '1 = 1';
                }
                foreach ($list as $v) {
                    $params[] = $v;
                }
                return $qc . ' ' . $op . ' (' . implode(', ', array_fill(0, count($list), '?')) . ')';
            case 'IS NULL':
                return $qc . ' IS NULL';
            case 'IS NOT NULL':
                return $qc . ' IS NOT NULL';
            case 'BETWEEN':
                $list = $this->listValue($value);
                if (count($list) !== 2) {
                    throw new ApiException('BETWEEN needs exactly two values', 'bad_request', 400);
                }
                $params[] = $list[0];
                $params[] = $list[1];
                return $qc . ' BETWEEN ? AND ?';
        }
        throw new ApiException('Unsupported filter operator: ' . $op, 'bad_request', 400);
    }

    /** @return string|int|float|null */
    private function scalarValue($value)
    {
        if (is_array($value) || is_object($value)) {
            throw new ApiException('Filter value must be a scalar', 'bad_request', 400);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return $value;
    }

    /** Array value, or a string split on commas ("a,b") / " AND " for BETWEEN. */
    private function listValue($value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $out[] = $this->scalarValue($v);
            }
            return array_values($out);
        }
        if ($value === null || $value === '') {
            return [];
        }
        $s = (string) $value;
        $parts = preg_split('/\s+AND\s+/i', $s);
        if (count($parts) !== 2) {
            $parts = explode(',', $s);
        }
        return array_map('trim', $parts);
    }

    /** Human-readable SQL with ?-placeholders replaced by quoted literals (display only, never executed). */
    protected function interpolateForDisplay(string $sql, array $params): string
    {
        if (!$params) {
            return $sql;
        }
        $i = 0;
        return (string) preg_replace_callback("/'(?:[^']|'')*'|\\?/", function (array $m) use (&$i, $params) {
            if ($m[0] !== '?') {
                return $m[0];
            }
            $v = $params[$i++] ?? null;
            if ($v === null) {
                return 'NULL';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            return "'" . str_replace("'", "''", (string) $v) . "'";
        }, $sql);
    }

    // ------------------------------------------------------------------ row editing

    protected function singleRowSuffix(string $verb): string
    {
        return '';
    }

    protected function emptyInsertSql(string $quotedTable): string
    {
        return 'INSERT INTO ' . $quotedTable . ' DEFAULT VALUES';
    }

    protected function lastInsertIdFor(string $table): ?string
    {
        try {
            $id = $this->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            return null;
        }
        return ($id === false || $id === '' || $id === '0') ? null : (string) $id;
    }

    public function insertRow(string $table, array $values): array
    {
        [$sql, $params] = $this->buildInsertSql($table, $values);
        $stmt = $this->run($sql, $params);
        $this->forgetTableInfo();
        return ['affected' => $stmt->rowCount(), 'insert_id' => $this->lastInsertIdFor($table)];
    }

    /**
     * Plain INSERT for one row (drivers whose insertRow() adds RETURNING / identity handling
     * still use this for display SQL). @return array{0:string,1:array} SQL with ? + params
     */
    protected function buildInsertSql(string $table, array $values): array
    {
        $qt = $this->quoteTable($table);
        if (!$values) {
            return [$this->emptyInsertSql($qt), []];
        }
        $cols = [];
        foreach (array_keys($values) as $c) {
            $cols[] = $this->quoteIdentifier((string) $c);
        }
        $sql = 'INSERT INTO ' . $qt . ' (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($values), '?')) . ')';
        return [$sql, array_values($values)];
    }

    /** Single-row UPDATE by key. @return array{0:string,1:array} */
    protected function buildUpdateSql(string $table, array $key, array $values): array
    {
        $params = [];
        $set = [];
        foreach ($values as $col => $val) {
            $set[] = $this->quoteIdentifier((string) $col) . ' = ?';
            $params[] = $val;
        }
        $sql = 'UPDATE ' . $this->quoteTable($table) . ' SET ' . implode(', ', $set)
            . $this->keyWhere($key, $params) . $this->singleRowSuffix('UPDATE');
        return [$sql, $params];
    }

    /** Single-row DELETE by key. @return array{0:string,1:array} */
    protected function buildDeleteSql(string $table, array $key): array
    {
        $params = [];
        $sql = 'DELETE FROM ' . $this->quoteTable($table) . $this->keyWhere($key, $params) . $this->singleRowSuffix('DELETE');
        return [$sql, $params];
    }

    /** WHERE clause for a key map (NULL → IS NULL). */
    protected function keyWhere(array $key, array &$params): string
    {
        if (!$key) {
            throw new ApiException('A row key is required', 'bad_request', 400);
        }
        $c = [];
        foreach ($key as $col => $val) {
            $qc = $this->quoteIdentifier((string) $col);
            if ($val === null) {
                $c[] = $qc . ' IS NULL';
            } else {
                $c[] = $qc . ' = ?';
                $params[] = $val;
            }
        }
        return ' WHERE ' . implode(' AND ', $c);
    }

    public function updateRow(string $table, array $key, array $values): int
    {
        if (!$values) {
            return 0;
        }
        [$sql, $params] = $this->buildUpdateSql($table, $key, $values);
        return $this->guardedWrite(function () use ($sql, $params) {
            $n = $this->run($sql, $params)->rowCount();
            if ($n > 1) {
                throw new ApiException('Row key matches more than one row; nothing was changed', 'bad_request', 400, $sql);
            }
            return $n;
        });
    }

    public function deleteRows(string $table, array $keys): int
    {
        if (!$keys) {
            return 0;
        }
        return $this->guardedWrite(function () use ($table, $keys) {
            $total = 0;
            foreach ($keys as $key) {
                if (!is_array($key)) {
                    throw new ApiException('Each key must be an object', 'bad_request', 400);
                }
                [$sql, $params] = $this->buildDeleteSql($table, $key);
                $n = $this->run($sql, $params)->rowCount();
                if ($n > 1) {
                    throw new ApiException('Row key matches more than one row; nothing was changed', 'bad_request', 400, $sql);
                }
                $total += $n;
            }
            return $total;
        });
    }

    /**
     * Run a write in a transaction (when none is active); any exception (e.g. a key that
     * matched more than one row) rolls everything back.
     */
    protected function guardedWrite(callable $fn): int
    {
        $pdo = $this->pdo();
        $own = false;
        try {
            if (!$pdo->inTransaction()) {
                $own = $pdo->beginTransaction();
            }
        } catch (\Throwable $e) {
            $own = false;
        }
        try {
            $n = (int) $fn();
            if ($own) {
                $pdo->commit();
            }
            return $n;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ batched row changes

    /** Max characters of a string value shown in display SQL (renderDisplaySql). */
    protected const DISPLAY_VALUE_MAX = 200;
    /** Max bytes of a binary value shown (hex) in display SQL. */
    protected const DISPLAY_BINARY_MAX = 32;

    /**
     * Validate and (unless $preview) execute a batch of row changes in ONE transaction.
     * Each change: {op:'update', key:{col:val}, values:{col:val}} | {op:'insert', values:{…}}
     * | {op:'delete', key:{…}}. Keys must cover the primary key or a unique index (non-NULL
     * values); updates/deletes must match exactly one row, otherwise everything is rolled back.
     * On failure throws ApiException (details: {index, op}) after rolling back.
     *
     * @return array{sql:string[],affected:int[],insert_ids:array<int,?string>,executed:bool}
     */
    public function applyRowChanges(string $table, array $changes, bool $preview = false): array
    {
        $info = $this->cachedTableInfo($table);
        if (($info['type'] ?? 'table') !== 'table') {
            throw new ApiException('Only base tables can be edited', 'bad_request', 400);
        }
        $known = [];
        foreach ($info['columns'] as $c) {
            $known[(string) $c['name']] = true;
        }
        $keySets = $this->rowKeyCandidates($info);

        $plan = [];
        foreach (array_values($changes) as $i => $ch) {
            try {
                $plan[] = $this->planRowChange($table, $ch, $known, $keySets);
            } catch (ApiException $e) {
                throw (new ApiException('Change ' . ($i + 1) . ': ' . $e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), null, $e))
                    ->withDetails(['index' => $i, 'op' => is_array($ch) ? (string) ($ch['op'] ?? '') : '']);
            }
        }
        $display = [];
        foreach ($plan as $p) {
            $display[] = $this->renderDisplaySql($p['sql'], $p['params']);
        }
        if ($preview) {
            return ['sql' => $display, 'affected' => [], 'insert_ids' => [], 'executed' => false];
        }

        $pdo = $this->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $affected = [];
        $ids = [];
        $i = 0;
        try {
            foreach ($plan as $i => $p) {
                if ($p['op'] === 'insert') {
                    $r = $this->insertRow($table, $p['values']);
                    $affected[] = (int) $r['affected'];
                    $ids[] = $r['insert_id'];
                    continue;
                }
                $n = $this->run($p['sql'], $p['params'])->rowCount();
                if ($n === 0 && $p['op'] === 'update') {
                    // Engines report changed (not matched) rows: an UPDATE writing the current
                    // values reports 0. Tell that apart from a row that is gone.
                    $wp = [];
                    $where = $this->keyWhere($p['key'], $wp);
                    $n = (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->quoteTable($table) . $where, $wp);
                }
                if ($n === 0) {
                    throw new ApiException('Row not found (it was changed or deleted in the meantime); nothing was changed', 'stale_row', 409);
                }
                if ($n > 1) {
                    throw new ApiException('Row key matches more than one row; nothing was changed', 'ambiguous_key', 409);
                }
                $affected[] = $n;
                $ids[] = null;
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $ignored) {
                    // connection lost: the server rolls back by itself
                }
            }
            $this->forgetTableInfo();
            $op = $plan[$i]['op'] ?? '';
            $prefix = 'Change ' . ($i + 1) . ' of ' . count($plan) . ' (' . strtoupper($op) . ') failed, all changes were rolled back: ';
            if ($e instanceof ApiException) {
                $wrapped = new ApiException($prefix . $e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $display[$i] ?? null, $e);
            } elseif ($e instanceof \PDOException) {
                $wrapped = ApiException::dbError($prefix . $e->getMessage(), $display[$i] ?? null, $e);
            } else {
                throw $e;
            }
            throw $wrapped->withDetails(['index' => $i, 'op' => $op]);
        }
        $this->forgetTableInfo();
        return ['sql' => $display, 'affected' => $affected, 'insert_ids' => $ids, 'executed' => true];
    }

    /**
     * Column sets that identify a row: the primary key, then every unique index.
     * @return array<int,array{columns:string[],primary:bool}>
     */
    protected function rowKeyCandidates(array $info): array
    {
        $out = [];
        $pk = [];
        foreach ($info['indexes'] ?? [] as $ix) {
            if (!empty($ix['primary'])) {
                $pk = array_map('strval', (array) $ix['columns']);
                break;
            }
        }
        if (!$pk) {
            foreach ($info['columns'] as $c) {
                if (!empty($c['primary'])) {
                    $pk[] = (string) $c['name'];
                }
            }
        }
        if ($pk) {
            $out[] = ['columns' => $pk, 'primary' => true];
        }
        foreach ($info['indexes'] ?? [] as $ix) {
            if (empty($ix['primary']) && !empty($ix['unique']) && !empty($ix['columns'])) {
                $out[] = ['columns' => array_map('strval', (array) $ix['columns']), 'primary' => false];
            }
        }
        return $out;
    }

    /**
     * Validate one change and build its prepared statement.
     * @return array{op:string,key:array,values:array,sql:string,params:array}
     */
    private function planRowChange(string $table, $ch, array $known, array $keySets): array
    {
        if (!is_array($ch)) {
            throw new ApiException('Each change must be an object', 'bad_request', 400);
        }
        $op = strtolower((string) ($ch['op'] ?? ''));
        if (!in_array($op, ['insert', 'update', 'delete'], true)) {
            throw new ApiException('Unknown operation: ' . $op, 'bad_request', 400);
        }
        $values = [];
        if ($op !== 'delete') {
            $raw = $ch['values'] ?? [];
            if (!is_array($raw) || ($raw !== [] && array_keys($raw) === range(0, count($raw) - 1))) {
                throw new ApiException('values must be an object', 'bad_request', 400);
            }
            $values = $this->checkedCells($raw, $known);
            if ($op === 'update' && !$values) {
                throw new ApiException('Nothing to update', 'bad_request', 400);
            }
        }
        $key = [];
        if ($op !== 'insert') {
            $raw = $ch['key'] ?? null;
            if (!is_array($raw) || $raw === []) {
                throw new ApiException('A row key is required', 'bad_request', 400);
            }
            $key = $this->checkedCells($raw, $known);
            $ok = false;
            foreach ($keySets as $set) {
                $covered = true;
                foreach ($set['columns'] as $kc) {
                    // primary-key columns are never NULL; a unique index only identifies non-NULL tuples
                    if (!array_key_exists($kc, $key) || ($key[$kc] === null && !$set['primary'])) {
                        $covered = false;
                        break;
                    }
                }
                if ($covered) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new ApiException($keySets ? 'The row key must contain the full primary key (or a unique key)' : 'The table has no primary or unique key; rows cannot be identified', 'bad_request', 400);
            }
        }
        if ($op === 'insert') {
            [$sql, $params] = $this->buildInsertSql($table, $values);
        } elseif ($op === 'update') {
            [$sql, $params] = $this->buildUpdateSql($table, $key, $values);
        } else {
            [$sql, $params] = $this->buildDeleteSql($table, $key);
        }
        return ['op' => $op, 'key' => $key, 'values' => $values, 'sql' => $sql, 'params' => $params];
    }

    /** Column => scalar|null map with known column names only. */
    private function checkedCells(array $cells, array $known): array
    {
        $out = [];
        foreach ($cells as $col => $val) {
            $col = (string) $col;
            if (!isset($known[$col])) {
                throw new ApiException('Unknown column: ' . $col, 'bad_request', 400);
            }
            if ($val !== null && !is_scalar($val)) {
                throw new ApiException('Invalid value for column ' . $col . ' (binary, array and object values cannot be written here)', 'bad_request', 400);
            }
            $out[$col] = $val;
        }
        return $out;
    }

    /**
     * Human-readable SQL with ?-placeholders replaced by quoted literals — DISPLAY ONLY, never
     * executed (execution binds the values). Placeholders inside quoted strings/identifiers are
     * left alone; long strings are cut (multibyte safe), non-UTF-8 values are shown as hex.
     */
    public function renderDisplaySql(string $sql, array $params, int $maxLength = self::DISPLAY_VALUE_MAX): string
    {
        if (!$params) {
            return $sql;
        }
        $params = array_values($params);
        $i = 0;
        return (string) preg_replace_callback('/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|\?/', function (array $m) use (&$i, $params, $maxLength): string {
            if ($m[0] !== '?') {
                return $m[0];
            }
            if (!array_key_exists($i, $params)) {
                return '?';
            }
            return $this->displayLiteral($params[$i++], $maxLength);
        }, $sql);
    }

    /** Literal for display SQL. */
    protected function displayLiteral($v, int $maxLength): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? (string) $v : "'" . (string) $v . "'";
        }
        if (!is_scalar($v)) {
            return "'…'";
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $len = strlen($s);
            $hex = strtoupper(bin2hex(substr($s, 0, self::DISPLAY_BINARY_MAX)));
            return $this->displayBinaryLiteral($hex) . ($len > self::DISPLAY_BINARY_MAX ? ' /* … ' . $len . ' bytes */' : '');
        }
        if ($maxLength > 0 && mb_strlen($s, 'UTF-8') > $maxLength) {
            $s = mb_substr($s, 0, $maxLength, 'UTF-8') . '…';
        }
        return $this->displayStringLiteral($s);
    }

    /** Quoted string literal for display (standard SQL: '' doubling). MySQL also escapes backslashes. */
    protected function displayStringLiteral(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /** Hex binary literal for display; $hex is upper-case hex digits. */
    protected function displayBinaryLiteral(string $hex): string
    {
        return "X'" . $hex . "'";
    }

    // ------------------------------------------------------------------ query

    /** @var array|null values bound by the next executeRaw() (set only by queryWithParams()) */
    private ?array $pendingParams = null;

    /**
     * query() with bound values (prepared statement): same QueryResult / multiple result sets.
     * Used to call routines with user-supplied arguments (routine.execute).
     */
    public function queryWithParams(string $sql, array $params, int $limit): array
    {
        $this->pendingParams = array_values($params);
        try {
            return $this->query($sql, $limit);
        } finally {
            $this->pendingParams = null;
        }
    }

    protected function executeRaw(string $sql): PDOStatement
    {
        if ($this->pendingParams !== null) {
            $params = $this->pendingParams;
            $this->pendingParams = null; // only the statement itself, not follow-up lookups
            return $this->run($sql, $params);
        }
        $stmt = $this->pdo()->query($sql);
        if ($stmt === false) {
            throw new \PDOException('Query failed');
        }
        return $stmt;
    }

    protected function beforeQuery(): void
    {
    }

    protected function afterQuery(): void
    {
    }

    /** Max result sets collected from one statement/batch (guards against endless nextRowset loops). */
    protected const MAX_ROWSETS = 100;

    /** Total rows kept across all result sets of one statement = limit * this factor (memory guard). */
    protected const TOTAL_ROWS_FACTOR = 10;

    /** Whether PDOStatement::nextRowset() is worth calling (pdo_sqlite never has further sets). */
    protected function supportsMultipleRowsets(): bool
    {
        return true;
    }

    /** Driver quirk hook: the statement reports columns for a rowset that has none (see MySqlDriver). */
    protected function isGhostRowset(PDOStatement $stmt): bool
    {
        return false;
    }

    /**
     * Run one statement or batch. The first result set fills the classic QueryResult fields; further result
     * sets (T-SQL batches, MySQL CALL, ...) are returned in 'more_results' as
     * [{is_result_set,columns,rows,affected,truncated}]. Row-count-only sets are dropped when the batch also
     * produced a result set (SET NOCOUNT OFF noise); otherwise their counts are summed into 'affected'.
     * Every set is limited to $limit rows and all sets together to $limit * TOTAL_ROWS_FACTOR rows
     * (the set that hits the cap gets truncated=true, later sets are skipped). At most MAX_ROWSETS sets are read.
     * A PDOException raised while advancing (error in a later statement of the batch) propagates as the error.
     */
    public function query(string $sql, int $limit): array
    {
        $limit = max(0, $limit);
        $this->beforeQuery();
        try {
            $stmt = $this->executeRaw($sql);
            $budget = $limit * self::TOTAL_ROWS_FACTOR;
            $sets = [];
            try {
                $guard = 0;
                do {
                    $sets[] = $this->readRowset($stmt, $limit, $budget);
                    $guard++;
                } while ($guard < self::MAX_ROWSETS && $this->advanceRowset($stmt));
            } finally {
                try {
                    $stmt->closeCursor();
                } catch (\Throwable $e) {
                }
            }
            $primaryIdx = null;
            foreach ($sets as $i => $set) {
                if ($set['is_result_set']) {
                    $primaryIdx = $i;
                    break;
                }
            }
            $result = [
                'is_result_set' => false,
                'columns' => [],
                'rows' => [],
                'affected' => null,
                'insert_id' => null,
                'truncated' => false,
                'error' => null,
                'editable' => null,
            ];
            if ($primaryIdx === null) {
                $affected = null;
                foreach ($sets as $set) {
                    if ($set['affected'] !== null && $set['affected'] >= 0) {
                        $affected = ($affected ?? 0) + $set['affected'];
                    }
                }
                $result['affected'] = $affected ?? $sets[0]['affected'];
                if (preg_match('/^\s*(INSERT|REPLACE)\b/i', $sql)) {
                    $result['insert_id'] = $this->lastInsertIdFor('');
                }
            } else {
                $first = $sets[$primaryIdx];
                $result['is_result_set'] = true;
                $result['columns'] = $first['columns'];
                $result['rows'] = $first['rows'];
                $result['truncated'] = $first['truncated'];
                $more = [];
                foreach ($sets as $i => $set) {
                    if ($i !== $primaryIdx && $set['is_result_set']) {
                        $more[] = $set;
                    }
                }
                if ($more) {
                    $result['more_results'] = $more;
                }
            }
        } finally {
            $this->afterQuery();
        }
        if ($result['is_result_set']) {
            $result['editable'] = $this->detectEditable($result['columns']);
        } else {
            // the statement may have changed the schema
            $this->forgetTableInfo();
        }
        return $result;
    }

    /** nextRowset() that treats "driver does not support multiple rowsets" (SQLSTATE IM001) as the end. */
    private function advanceRowset(PDOStatement $stmt): bool
    {
        if (!$this->supportsMultipleRowsets()) {
            return false;
        }
        try {
            return (bool) $stmt->nextRowset();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === 'IM001') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Read the statement's current rowset. $budget (rows left across all sets) is decremented.
     * @return array{is_result_set:bool,columns:array,rows:array,affected:?int,truncated:bool}
     */
    private function readRowset(PDOStatement $stmt, int $limit, int &$budget): array
    {
        $set = ['is_result_set' => false, 'columns' => [], 'rows' => [], 'affected' => null, 'truncated' => false];
        $n = $stmt->columnCount();
        if ($n <= 0 || $this->isGhostRowset($stmt)) {
            $set['affected'] = $stmt->rowCount();
            return $set;
        }
        $set['is_result_set'] = true;
        $types = [];
        for ($i = 0; $i < $n; $i++) {
            $meta = $stmt->getColumnMeta($i) ?: [];
            $type = $this->metaType($meta);
            $types[$i] = $type;
            $col = ['name' => (string) ($meta['name'] ?? ('col' . ($i + 1)))];
            if ($type !== null) {
                $col['type'] = $type;
            }
            if (!empty($meta['table'])) {
                $col['table'] = (string) $meta['table'];
            }
            $set['columns'][] = $col;
        }
        $max = min($limit, max(0, $budget));
        $count = 0;
        while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if ($count >= $max) {
                $set['truncated'] = true;
                break;
            }
            foreach ($r as $i => $v) {
                $r[$i] = $this->normalizeValue($v, $types[$i] ?? null);
            }
            $set['rows'][] = $r;
            $count++;
        }
        $budget -= $count;
        return $set;
    }

    /** Column type from PDOStatement::getColumnMeta(). */
    protected function metaType(array $meta): ?string
    {
        foreach (['sqlite:decl_type', 'pgsql:oid_name', 'sqlsrv:decl_type', 'native_type'] as $k) {
            if (!empty($meta[$k]) && is_string($meta[$k])) {
                return strtolower($meta[$k]);
            }
        }
        return null;
    }

    /**
     * Result set is editable when every column comes from the same table of the current
     * database, the names are unique and existing, and the table's primary key is included.
     */
    protected function detectEditable(array $columns): ?array
    {
        $table = null;
        $names = [];
        foreach ($columns as $c) {
            if (empty($c['table'])) {
                return null;
            }
            if ($table === null) {
                $table = $c['table'];
            } elseif ($table !== $c['table']) {
                return null;
            }
            if (isset($names[$c['name']])) {
                return null;
            }
            $names[$c['name']] = true;
        }
        if ($table === null) {
            return null;
        }
        try {
            $info = $this->cachedTableInfo($table);
        } catch (\Throwable $e) {
            return null;
        }
        if (($info['type'] ?? 'table') !== 'table') {
            return null;
        }
        $existing = [];
        foreach ($info['columns'] as $c) {
            $existing[$c['name']] = true;
        }
        foreach (array_keys($names) as $n) {
            if (!isset($existing[$n])) {
                return null;
            }
        }
        $pk = [];
        foreach ($info['indexes'] as $ix) {
            if (!empty($ix['primary'])) {
                $pk = $ix['columns'];
            }
        }
        if (!$pk) {
            return null;
        }
        foreach ($pk as $p) {
            if (!isset($names[$p])) {
                return null;
            }
        }
        return ['table' => $table, 'primary_key' => $pk];
    }

    public function explain(string $sql): array
    {
        return $this->query('EXPLAIN ' . $sql, 10000);
    }

    // ------------------------------------------------------------------ values

    public function normalizeValue($value, ?string $type = null)
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return ($value > self::JS_MAX_SAFE_INT || $value < -self::JS_MAX_SAFE_INT) ? (string) $value : $value;
        }
        if (is_float($value)) {
            return (is_nan($value) || is_infinite($value)) ? (string) $value : $value;
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }
        if (!is_string($value)) {
            return (string) $value;
        }
        if ($type !== null && preg_match('/binary|blob|bytea|image|geometry|point|polygon|linestring/i', $type) && $value !== '') {
            return $this->binaryCell($value);
        }
        $valid = function_exists('mb_check_encoding') ? mb_check_encoding($value, 'UTF-8') : (bool) preg_match('//u', $value);
        if (!$valid) {
            return $this->binaryCell($value);
        }
        return $value;
    }

    protected function binaryCell(string $value): array
    {
        return ['$binary' => base64_encode(substr($value, 0, self::BINARY_PREVIEW_BYTES)), 'length' => strlen($value)];
    }

    // ------------------------------------------------------------------ FK helper

    /**
     * Values for a foreign key dropdown: [{value, label}] (max 100).
     * $column is a column of $table that has a single-column FK. Label is the first
     * text-like column of the referenced table (other than the referenced column).
     */
    public function fkValues(string $table, string $column, ?string $term): array
    {
        $info = $this->cachedTableInfo($table);
        $fk = null;
        foreach ($info['foreign_keys'] as $f) {
            if (count($f['columns']) === 1 && $f['columns'][0] === $column) {
                $fk = $f;
                break;
            }
        }
        if ($fk === null) {
            throw new ApiException('Column has no foreign key: ' . $column, 'bad_request', 400);
        }
        $refCol = $fk['ref_columns'][0];
        $savedSchema = $this->schema;
        $params = [];
        if (!empty($fk['ref_schema']) && $this->usesSchemaForFk()) {
            $this->schema = $fk['ref_schema'];
        }
        try {
            $refInfo = $this->cachedTableInfo($fk['ref_table']);
            $qt = $this->quoteFkTable($fk);
            $label = null;
            foreach ($refInfo['columns'] as $c) {
                if ($c['name'] !== $refCol && $this->isTextType((string) ($c['full_type'] ?? $c['type']))) {
                    $label = $c['name'];
                    break;
                }
            }
            $qv = $this->quoteIdentifier($refCol);
            $select = $qv . ($label !== null ? ', ' . $this->quoteIdentifier($label) : '');
            $where = '';
            if ($term !== null && $term !== '') {
                $like = $this->likeOperator();
                $pattern = '%' . $this->escapeLike($term) . '%';
                $refC = null;
                foreach ($refInfo['columns'] as $c) {
                    if ($c['name'] === $refCol) {
                        $refC = $c;
                    }
                }
                $valExpr = ($refC !== null && $this->isTextType((string) ($refC['full_type'] ?? $refC['type']))) ? $qv : $this->castToText($qv, $refC ?? []);
                $or = [];
                if ($valExpr !== null) {
                    $or[] = $valExpr . ' ' . $like . ' ?' . $this->likeEscapeClause();
                    $params[] = $pattern;
                } else {
                    $or[] = $qv . ' = ?';
                    $params[] = $term;
                }
                if ($label !== null) {
                    $or[] = $this->quoteIdentifier($label) . ' ' . $like . ' ?' . $this->likeEscapeClause();
                    $params[] = $pattern;
                }
                $where = ' WHERE ' . implode(' OR ', $or);
            }
            $sql = $this->buildPagedSelect($qt, $where, ' ORDER BY ' . $qv, 100, 0, $refInfo);
            $sql = 'SELECT ' . $select . substr($sql, strlen('SELECT *'));
            $out = [];
            foreach ($this->run($sql, $params)->fetchAll(PDO::FETCH_NUM) as $r) {
                $v = $this->normalizeValue($r[0]);
                $l = $label !== null ? $this->normalizeValue($r[1]) : null;
                $out[] = ['value' => $v, 'label' => is_string($l) ? $l : null];
            }
            return $out;
        } catch (\PDOException $e) {
            // ref table could be valid but non-numeric term compared against numeric column
            if ($term !== null && $term !== '' && $params) {
                return [];
            }
            throw $e;
        } finally {
            $this->schema = $savedSchema;
        }
    }

    /** Whether ForeignKey.ref_schema is a schema of the current database (pgsql/sqlsrv) — default true. */
    protected function usesSchemaForFk(): bool
    {
        return true;
    }

    protected function quoteFkTable(array $fk): string
    {
        return $this->quoteTable($fk['ref_table']);
    }

    // ------------------------------------------------------------------ DDL helpers

    /**
     * " DEFAULT …" clause for a ColumnDefinition (default_kind none|null|value|expression).
     * Expressions are inserted verbatim (they come from the authenticated user, same trust
     * level as the SQL editor).
     */
    protected function renderDefaultClause(array $col): string
    {
        $kind = (string) ($col['default_kind'] ?? 'none');
        switch ($kind) {
            case 'null':
                return ' DEFAULT NULL';
            case 'value':
                $v = $col['default'] ?? '';
                if (is_bool($v)) {
                    return ' DEFAULT ' . ($v ? '1' : '0');
                }
                if ((is_int($v) || is_float($v)) && $this->isNumericType((string) ($col['type'] ?? ''))) {
                    return ' DEFAULT ' . $v;
                }
                return ' DEFAULT ' . $this->quoteLiteral((string) $v);
            case 'expression':
                $e = trim((string) ($col['default'] ?? ''));
                if ($e === '') {
                    return '';
                }
                return ' DEFAULT ' . $e;
        }
        return '';
    }

    /** Validated type name + optional length, e.g. "VARCHAR(255)", "DECIMAL(10,2)", "ENUM('a','b')". */
    protected function renderType(array $col): string
    {
        $type = trim((string) ($col['type'] ?? ''));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_ ]*(\[\])?$/', $type)) {
            throw new ApiException('Invalid column type: ' . $type, 'bad_request', 400);
        }
        $len = isset($col['length']) ? trim((string) $col['length']) : '';
        if ($len === '') {
            return strtoupper($type);
        }
        if (preg_match('/^\s*\d+\s*(,\s*\d+\s*)?$/', $len) || preg_match('/^max$/i', $len)) {
            return strtoupper($type) . '(' . preg_replace('/\s+/', '', $len) . ')';
        }
        // enum/set value list: 'a','b' (no backslashes; quotes doubled)
        if (preg_match("/^\\s*'(?:[^'\\\\]|'')*'(?:\\s*,\\s*'(?:[^'\\\\]|'')*')*\\s*$/", $len)) {
            return strtoupper($type) . '(' . trim($len) . ')';
        }
        throw new ApiException('Invalid length/values for column ' . ($col['name'] ?? '') . ': ' . $len, 'bad_request', 400);
    }

    /** Quoted, comma separated column list. */
    protected function columnList(array $cols): string
    {
        $out = [];
        foreach ($cols as $c) {
            $out[] = $this->quoteIdentifier((string) $c);
        }
        return implode(', ', $out);
    }

    protected function fkAction(?string $action): ?string
    {
        if ($action === null || trim($action) === '') {
            return null;
        }
        $a = strtoupper(preg_replace('/\s+/', ' ', trim($action)));
        if (!in_array($a, ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'], true)) {
            throw new ApiException('Invalid foreign key action: ' . $action, 'bad_request', 400);
        }
        return $a;
    }

    /** "FOREIGN KEY (…) REFERENCES t (…) ON DELETE … ON UPDATE …" (without CONSTRAINT name). */
    protected function renderForeignKeyBody(array $fk, ?string $refTableSql = null): string
    {
        if (empty($fk['columns']) || empty($fk['ref_table']) || empty($fk['ref_columns'])) {
            throw new ApiException('Foreign key needs columns, ref_table and ref_columns', 'bad_request', 400);
        }
        $ref = $refTableSql ?? ((!empty($fk['ref_schema']) ? $this->quoteIdentifier((string) $fk['ref_schema']) . '.' : '') . $this->quoteIdentifier((string) $fk['ref_table']));
        $sql = 'FOREIGN KEY (' . $this->columnList((array) $fk['columns']) . ') REFERENCES ' . $ref
            . ' (' . $this->columnList((array) $fk['ref_columns']) . ')';
        $d = $this->fkAction($fk['on_delete'] ?? null);
        $u = $this->fkAction($fk['on_update'] ?? null);
        if ($d !== null) {
            $sql .= ' ON DELETE ' . $d;
        }
        if ($u !== null) {
            $sql .= ' ON UPDATE ' . $u;
        }
        return $sql;
    }

    // ------------------------------------------------------------------ generic table ops

    public function dropTable(string $table, bool $isView = false): void
    {
        $this->exec('DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' ' . $this->quoteTable($table));
        $this->forgetTableInfo();
    }

    public function truncateTable(string $table): void
    {
        $this->exec('TRUNCATE TABLE ' . $this->quoteTable($table));
    }

    public function renameTable(string $table, string $newName): void
    {
        $this->exec('ALTER TABLE ' . $this->quoteTable($table) . ' RENAME TO ' . $this->quoteIdentifier($newName));
        $this->forgetTableInfo();
    }

    // ------------------------------------------------------------------ database settings

    public function alterDatabase(string $name, array $changes): array
    {
        $plan = $this->buildAlterDatabase($name, $changes);
        $this->runDatabaseStatements($plan['sql']);
        return $plan;
    }

    public function copyDatabase(string $source, string $target, array $opts = []): array
    {
        throw $this->unsupported('Copying databases');
    }

    /** Run statements one by one; a failure becomes db_error carrying the failed statement. */
    protected function runDatabaseStatements(array $sqls): void
    {
        foreach ($sqls as $sql) {
            try {
                $this->exec($sql);
            } catch (\PDOException $e) {
                throw ApiException::dbError($e->getMessage(), $sql, $e);
            }
        }
    }

    /**
     * Reject keys outside $allowed so a typo never silently does nothing.
     * @param string[] $allowed
     */
    protected static function assertChangeKeys(array $changes, array $allowed): void
    {
        foreach (array_keys($changes) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                throw ApiException::badRequest('Unknown database change: ' . $k);
            }
        }
    }

    /** Non-empty string or null for an optional change value. */
    protected static function changeString(array $changes, string $key): ?string
    {
        if (!array_key_exists($key, $changes) || $changes[$key] === null) {
            return null;
        }
        if (!is_string($changes[$key]) && !is_int($changes[$key])) {
            throw ApiException::badRequest('Invalid value for ' . $key);
        }
        $v = trim((string) $changes[$key]);
        return $v === '' ? null : $v;
    }

    /** Integer change value within [$min, $max] or null when absent. */
    protected static function changeInt(array $changes, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $changes) || $changes[$key] === null || $changes[$key] === '') {
            return null;
        }
        $v = $changes[$key];
        if (is_string($v) && preg_match('/^-?\d{1,10}$/', trim($v))) {
            $v = (int) trim($v);
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw ApiException::badRequest('Invalid value for ' . $key . " (expected an integer between $min and $max)");
        }
        return $v;
    }

    /** Boolean change value (true/false, 1/0, "on"/"off") or null when absent. */
    protected static function changeBool(array $changes, string $key): ?bool
    {
        if (!array_key_exists($key, $changes) || $changes[$key] === null) {
            return null;
        }
        $v = $changes[$key];
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) (is_scalar($v) ? $v : '')));
        if (in_array($s, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        throw ApiException::badRequest('Invalid value for ' . $key);
    }

    // ------------------------------------------------------------------ defaults for unsupported features

    public function createDatabase(string $name, ?string $collation = null): void
    {
        throw $this->unsupported('Creating databases');
    }

    public function dropDatabase(string $name): void
    {
        throw $this->unsupported('Dropping databases');
    }

    public function listSchemas(): array
    {
        return [];
    }

    public function listRoutines(): array
    {
        return [];
    }

    public function listTriggers(): array
    {
        return [];
    }

    public function listEvents(): array
    {
        return [];
    }

    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        throw $this->unsupported('Copying tables');
    }

    public function maintenance(array $tables, string $op): array
    {
        throw $this->unsupported('Table maintenance');
    }

    public function variables(?string $filter = null): array
    {
        throw $this->unsupported('Server variables');
    }

    public function status(): array
    {
        throw $this->unsupported('Server status');
    }

    public function processes(): array
    {
        throw $this->unsupported('Process list');
    }

    public function kill(string $id): void
    {
        throw $this->unsupported('Killing processes');
    }

    /** Default: engine cannot cancel a statement from another connection. */
    public function backendId(): ?string
    {
        return null;
    }

    public function cancelBackend(string $id): bool
    {
        return false;
    }

    public function users(): array
    {
        throw $this->unsupported('User management');
    }

    public function userGrants(string $user, ?string $host): array
    {
        throw $this->unsupported('User management');
    }

    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void
    {
        throw $this->unsupported('User management');
    }

    public function dropUser(string $user, ?string $host): void
    {
        throw $this->unsupported('User management');
    }

    public function collations(): array
    {
        return [];
    }

    public function charsets(): array
    {
        return [];
    }

    public function engines(): array
    {
        return [];
    }

    /** Filter a [{name,value}] list by case-insensitive substring (or LIKE pattern with %/_). */
    protected function filterNameValue(array $list, ?string $filter): array
    {
        if ($filter === null || trim($filter) === '') {
            return $list;
        }
        $f = trim($filter);
        if (strpbrk($f, '%_') !== false) {
            $re = '/^' . strtr(preg_quote($f, '/'), ['%' => '.*', '_' => '.']) . '$/i';
            return array_values(array_filter($list, function ($r) use ($re) {
                return (bool) preg_match($re, (string) $r['name']);
            }));
        }
        return array_values(array_filter($list, function ($r) use ($f) {
            return stripos((string) $r['name'], $f) !== false;
        }));
    }

    /**
     * {keywords:[…], functions:[…]} — subclasses merge their dialect specific words into this.
     */
    public function completionWords(): array
    {
        return [
            'keywords' => [
                'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'BETWEEN', 'LIKE', 'EXISTS',
                'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'VIEW', 'INDEX',
                'UNIQUE', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'CONSTRAINT', 'DEFAULT', 'CHECK',
                'ALTER', 'ADD', 'DROP', 'COLUMN', 'RENAME', 'TO', 'AS', 'ON', 'JOIN', 'INNER', 'LEFT',
                'RIGHT', 'FULL', 'OUTER', 'CROSS', 'NATURAL', 'USING', 'GROUP', 'BY', 'HAVING', 'ORDER',
                'ASC', 'DESC', 'LIMIT', 'OFFSET', 'UNION', 'ALL', 'DISTINCT', 'CASE', 'WHEN', 'THEN', 'ELSE',
                'END', 'WITH', 'RECURSIVE', 'BEGIN', 'COMMIT', 'ROLLBACK', 'TRANSACTION', 'TRIGGER',
                'CASCADE', 'RESTRICT', 'IF', 'EXCEPT', 'INTERSECT', 'OVER', 'PARTITION', 'WINDOW',
                'TRUE', 'FALSE', 'EXPLAIN', 'GRANT', 'REVOKE', 'TRUNCATE',
            ],
            'functions' => [
                'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE', 'NULLIF', 'CAST', 'LOWER', 'UPPER',
                'TRIM', 'LTRIM', 'RTRIM', 'LENGTH', 'SUBSTRING', 'REPLACE', 'ABS', 'ROUND', 'FLOOR',
                'CEIL', 'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE',
                'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP',
            ],
        ];
    }

    protected function mergeWords(array $base, array $keywords, array $functions): array
    {
        $kw = array_values(array_unique(array_merge($base['keywords'], $keywords)));
        $fn = array_values(array_unique(array_merge($base['functions'], $functions)));
        sort($kw);
        sort($fn);
        return ['keywords' => $kw, 'functions' => $fn];
    }
}
