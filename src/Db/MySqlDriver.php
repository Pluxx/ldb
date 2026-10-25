<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Http\ApiException;
use PDO;
use PDOStatement;

/**
 * MySQL 5.7 / 8.x, MariaDB 10.x / 11.x, Percona.
 *
 * Extras beyond DriverInterface:
 *  - isMariaDb(): bool, serverVersion(): string
 *  - explainFormat(string $sql, string $format = 'traditional'|'json'|'tree'|'analyze'): QueryResult
 *  - BrowseResult may contain total_estimated:true (InnoDB estimate > 500k rows, no filters)
 *  - ColumnDefinition accepts optional keys: on_update (e.g. "CURRENT_TIMESTAMP"), generated
 *    (expression) + stored (bool) or extra (as returned by tableInfo, e.g. "on update
 *    current_timestamp()", "STORED GENERATED"), after/first in AlterSpec.modify definitions,
 *    and index column entries like "col(10)" (prefix length). add_index accepts type FULLTEXT|SPATIAL.
 */
class MySqlDriver extends AbstractPdoDriver
{
    /** Above this InnoDB estimate an unfiltered browse returns the estimate instead of COUNT(*). */
    public const ESTIMATE_THRESHOLD = 500000;

    private bool $mariaDb = false;
    private string $version = '';
    private string $versionComment = '';
    /** Last "query()" switched to unbuffered mode */
    private bool $unbuffered = false;

    public static function id(): string
    {
        return 'mysql';
    }

    public static function label(): string
    {
        return 'MySQL / MariaDB';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_mysql');
    }

    public static function defaultPort(): ?int
    {
        return 3306;
    }

    protected function buildDsn(array $params): string
    {
        $host = (string) ($params['host'] ?? 'localhost');
        if ($host === '') {
            $host = 'localhost';
        }
        if ($host[0] === '/' || !empty($params['socket'])) {
            $dsn = 'mysql:unix_socket=' . self::dsnValue(!empty($params['socket']) ? (string) $params['socket'] : $host);
        } else {
            $dsn = 'mysql:host=' . self::dsnValue($host);
            if (!empty($params['port'])) {
                $dsn .= ';port=' . (int) $params['port'];
            }
        }
        if (isset($params['database']) && $params['database'] !== '') {
            $dsn .= ';dbname=' . self::dsnValue((string) $params['database']);
        }
        return $dsn . ';charset=utf8mb4';
    }

    /**
     * PDO DSN values cannot be quoted: a ';' would start a new key (e.g. a database name
     * "x;host=10.0.0.1" would override the configured server).
     */
    public static function dsnValue(string $v): string
    {
        if (preg_match('/[;\x00-\x1F\x7F]/', $v)) {
            throw ApiException::badRequest('Invalid character in connection parameter');
        }
        return $v;
    }

    protected function pdoOptions(array $params): array
    {
        $o = [];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $o[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }
        // Never let a (possibly hostile) server read client files via LOAD DATA LOCAL INFILE.
        $o[PDO::MYSQL_ATTR_LOCAL_INFILE] = false;
        $opts = $params['options'] ?? [];
        if (!empty($opts['ssl_ca'])) {
            $o[PDO::MYSQL_ATTR_SSL_CA] = (string) $opts['ssl_ca'];
        }
        if (isset($opts['ssl_verify']) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) $opts['ssl_verify'];
        }
        return $o;
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        // Every following transaction (autocommit statements included) rejects writes with
        // ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION; DDL is refused as well.
        $this->exec('SET SESSION TRANSACTION ' . ($readOnly ? 'READ ONLY' : 'READ WRITE'));
    }

    protected function afterConnect(array $params): void
    {
        $row = $this->executeRaw('SELECT VERSION() AS v, @@version_comment AS c')->fetch(PDO::FETCH_ASSOC);
        $this->version = (string) ($row['v'] ?? '');
        $this->versionComment = (string) ($row['c'] ?? '');
        $this->mariaDb = stripos($this->version, 'mariadb') !== false || stripos($this->versionComment, 'mariadb') !== false;
        $this->exec("SET NAMES utf8mb4");
        if (!$this->mariaDb && version_compare($this->numericVersion(), '8.0.3', '>=')) {
            // always fresh TABLE_ROWS / AUTO_INCREMENT in information_schema
            try {
                $this->exec('SET SESSION information_schema_stats_expiry = 0');
            } catch (\PDOException $e) {
            }
        }
        $t = $this->queryTimeout();
        if ($t > 0) {
            try {
                if ($this->mariaDb) {
                    $this->exec('SET SESSION max_statement_time = ' . $t);
                } else {
                    $this->exec('SET SESSION max_execution_time = ' . ($t * 1000));
                }
            } catch (\PDOException $e) {
            }
        }
    }

    public function isMariaDb(): bool
    {
        return $this->mariaDb;
    }

    public function serverVersion(): string
    {
        return $this->version;
    }

    /** "8.0.36" / "11.4.2" without suffixes. */
    private function numericVersion(): string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $this->version, $m) ? $m[1] : '0.0.0';
    }

    public function select(?string $database, ?string $schema = null): void
    {
        if ($database !== null && $database !== '' && $database !== $this->database) {
            $this->exec('USE ' . $this->quoteIdentifier($database));
        }
        parent::select($database === '' ? null : $database, null);
    }

    public function quoteIdentifier(string $name): string
    {
        $this->assertIdentifier($name);
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function quoteTable(string $table): string
    {
        if ($this->database !== null && $this->database !== '') {
            return $this->quoteIdentifier($this->database) . '.' . $this->quoteIdentifier($table);
        }
        return $this->quoteIdentifier($table);
    }

    private function requireDb(): string
    {
        if ($this->database === null || $this->database === '') {
            throw new ApiException('No database selected', 'bad_request', 400);
        }
        return $this->database;
    }

    public function capabilities(): array
    {
        return [
            'schemas' => false,
            'users' => true,
            'processes' => true,
            'variables' => true,
            'status' => true,
            'engines' => true,
            'collations' => true,
            'charsets' => true,
            'routines' => true,
            'triggers' => true,
            'events' => true,
            'foreign_keys' => true,
            'alter_column' => true,
            'drop_column' => true,
            'rename_table' => true,
            'copy_table' => true,
            'unsigned' => true,
            'auto_increment' => true,
            'comments' => true,
            'maintenance' => true,
            'explain' => true,
            'create_database' => true,
            'kill' => true,
            'cancel' => true,
            'db_alter' => true,
            'db_copy' => true,
            'db_rename' => true,
            'monitor' => true,
        ];
    }

    public function serverInfo(): array
    {
        $row = $this->fetchOne('SELECT @@hostname AS host, CURRENT_USER() AS user, @@character_set_server AS charset');
        $uptime = null;
        try {
            $u = $this->executeRaw("SHOW GLOBAL STATUS LIKE 'Uptime'")->fetch(PDO::FETCH_NUM);
            $uptime = $u ? (int) $u[1] : null;
        } catch (\PDOException $e) {
        }
        $name = $this->mariaDb ? 'MariaDB' : (stripos($this->versionComment, 'percona') !== false ? 'Percona Server' : 'MySQL');
        return [
            'name' => $name,
            'version' => $this->version,
            'version_comment' => $this->versionComment,
            'host' => $row['host'] ?? null,
            'user' => $row['user'] ?? null,
            'charset' => $row['charset'] ?? null,
            'uptime' => $uptime,
            'driver' => 'mysql',
        ];
    }

    // ------------------------------------------------------------------ databases

    public function listDatabases(): array
    {
        $dbs = $this->fetchAll('SELECT SCHEMA_NAME AS name, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME');
        $stats = [];
        try {
            foreach ($this->fetchAll('SELECT TABLE_SCHEMA AS db, COUNT(*) AS n, SUM(COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0)) AS size FROM information_schema.TABLES GROUP BY TABLE_SCHEMA') as $r) {
                $stats[$r['db']] = $r;
            }
        } catch (\PDOException $e) {
        }
        $out = [];
        foreach ($dbs as $d) {
            $s = $stats[$d['name']] ?? null;
            $out[] = [
                'name' => $d['name'],
                'collation' => $d['collation'],
                'tables' => $s ? (int) $s['n'] : 0,
                'size' => $s ? (int) $s['size'] : 0,
            ];
        }
        return $out;
    }

    public function createDatabase(string $name, ?string $collation = null): void
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($name);
        if ($collation !== null && $collation !== '') {
            $sql .= ' COLLATE ' . $this->assertWord($collation, 'collation');
        } else {
            $sql .= ' CHARACTER SET utf8mb4';
        }
        $this->exec($sql);
    }

    public function dropDatabase(string $name): void
    {
        $this->exec('DROP DATABASE ' . $this->quoteIdentifier($name));
        if ($this->database === $name) {
            $this->database = null;
        }
    }

    // ------------------------------------------------------------------ database settings

    /** information_schema.TABLES types handled as base tables (copied with data / converted). */
    private const BASE_TABLE_TYPES = ['BASE TABLE', 'SYSTEM VERSIONED'];

    /** {charset, collation} defaults of a database (information_schema.SCHEMATA). */
    private function schemaDefaults(string $name): array
    {
        $row = $this->fetchOne(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS coll FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );
        if ($row === null) {
            throw $this->notFound('Database ' . $name);
        }
        return ['charset' => (string) $row['cs'], 'collation' => (string) $row['coll']];
    }

    /** Base-table names of a database (views and sequences excluded). @return string[] */
    protected function baseTableNames(string $db): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$db]) as $r) {
            if (in_array(strtoupper((string) $r['type']), self::BASE_TABLE_TYPES, true)) {
                $out[] = (string) $r['name'];
            }
        }
        return $out;
    }

    public function databaseInfo(string $name): array
    {
        $defaults = $this->schemaDefaults($name);
        $differing = [];
        foreach ($this->fetchAll(
            'SELECT TABLE_NAME AS t, COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ? GROUP BY TABLE_NAME',
            [$name, $defaults['collation']]
        ) as $r) {
            $differing[(string) $r['t']] = (int) $r['n'];
        }
        $tables = [];
        $size = 0;
        $counts = ['tables' => 0, 'views' => 0, 'other' => 0];
        $diffTables = 0;
        $diffColumns = 0;
        foreach ($this->fetchAll(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_COLLATION AS collation,
                    COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) AS size, TABLE_ROWS AS row_count
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$name]
        ) as $r) {
            $type = strtoupper((string) $r['type']);
            $kind = in_array($type, self::BASE_TABLE_TYPES, true) ? 'table' : (strpos($type, 'VIEW') !== false ? 'view' : 'other');
            $counts[$kind === 'table' ? 'tables' : ($kind === 'view' ? 'views' : 'other')]++;
            $cols = $kind === 'table' ? ($differing[(string) $r['name']] ?? 0) : 0;
            $differs = $kind === 'table' && (($r['collation'] !== null && $r['collation'] !== $defaults['collation']) || $cols > 0);
            if ($differs) {
                $diffTables++;
                $diffColumns += $cols;
            }
            $size += $kind === 'table' ? (int) $r['size'] : 0;
            $tables[] = [
                'name' => (string) $r['name'],
                'type' => $kind,
                'engine' => $r['engine'],
                'collation' => $r['collation'],
                'rows' => $kind === 'table' && $r['row_count'] !== null ? (int) $r['row_count'] : null,
                'size' => $kind === 'table' ? (int) $r['size'] : null,
                'columns_differing' => $cols,
                'differs' => $differs,
            ];
        }
        $objects = ['routines' => 0, 'triggers' => 0, 'events' => 0];
        foreach ([
            'routines' => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
            'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
            'events' => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
        ] as $k => $sql) {
            try {
                $objects[$k] = (int) $this->fetchValue($sql, [$name]);
            } catch (\PDOException $e) {
            }
        }
        return [
            'driver' => 'mysql',
            'name' => $name,
            'settings' => $defaults,
            'size' => $size,
            'table_count' => $counts['tables'],
            'view_count' => $counts['views'],
            'other_count' => $counts['other'],
            'objects' => $objects,
            'tables' => $tables,
            'differing_tables' => $diffTables,
            'differing_columns' => $diffColumns,
            'system' => in_array(strtolower($name), ['information_schema', 'performance_schema', 'mysql', 'sys'], true),
        ];
    }

    public function buildAlterDatabase(string $name, array $changes): array
    {
        $current = $this->schemaDefaults($name);
        $tables = !empty($changes['convert_tables']) ? $this->baseTableNames($name) : [];
        return $this->planAlterDatabase($name, $changes, $current, $this->collations(), $this->charsets(), $tables);
    }

    /**
     * Pure SQL generation for db.alter (unit-tested): validates charset/collation against the
     * server lists and the table selection against the base tables.
     * @param array{charset:string,collation:string} $current
     * @param array<int,array{name:string,charset:string,default?:bool}> $collations  collations()
     * @param array<int,array{name:string,default_collation?:string}> $charsets       charsets()
     * @param string[] $baseTables
     * @return array{sql:string[],warnings:string[]}
     */
    public function planAlterDatabase(string $name, array $changes, array $current, array $collations, array $charsets, array $baseTables): array
    {
        self::assertChangeKeys($changes, ['charset', 'collation', 'convert_tables', 'tables']);
        [$cs, $coll] = self::resolveCharsetCollation(
            self::changeString($changes, 'charset'),
            self::changeString($changes, 'collation'),
            $current,
            $collations,
            $charsets
        );
        $sql = [];
        $warnings = [];
        $qdb = $this->quoteIdentifier($name);
        if ($cs !== $current['charset'] || $coll !== $current['collation']) {
            $sql[] = 'ALTER DATABASE ' . $qdb . ' CHARACTER SET ' . $cs . ' COLLATE ' . $coll;
        }
        if (self::changeBool($changes, 'convert_tables')) {
            $selected = $baseTables;
            if (isset($changes['tables']) && $changes['tables'] !== null) {
                if (!is_array($changes['tables'])) {
                    throw ApiException::badRequest('Invalid value for tables');
                }
                $selected = [];
                foreach ($changes['tables'] as $t) {
                    $t = is_string($t) ? $t : '';
                    if (!in_array($t, $baseTables, true)) {
                        throw ApiException::badRequest('Not a base table of ' . $name . ': ' . $t);
                    }
                    $selected[] = $t;
                }
                $selected = array_values(array_unique($selected));
            }
            foreach ($selected as $t) {
                $sql[] = 'ALTER TABLE ' . $qdb . '.' . $this->quoteIdentifier($t) . ' CONVERT TO CHARACTER SET ' . $cs . ' COLLATE ' . $coll;
            }
            if ($selected !== []) {
                $warnings[] = 'convert_rewrites_tables';
            }
        }
        return ['sql' => $sql, 'warnings' => $warnings];
    }

    /**
     * Validate a charset / collation pair (either may be omitted) against SHOW COLLATION /
     * SHOW CHARACTER SET data. Omitted charset → the collation's charset; omitted collation →
     * the charset's default collation (or the current one when the charset is unchanged).
     * @return array{0:string,1:string}
     */
    public static function resolveCharsetCollation(?string $charset, ?string $collation, array $current, array $collations, array $charsets): array
    {
        $byCharset = [];
        foreach ($charsets as $c) {
            $byCharset[strtolower((string) $c['name'])] = $c;
        }
        $pairs = [];
        foreach ($collations as $c) {
            $pairs[strtolower((string) $c['name'])][] = strtolower((string) $c['charset']);
        }
        if ($charset !== null && !isset($byCharset[strtolower($charset)])) {
            throw ApiException::badRequest('Unknown character set: ' . $charset);
        }
        if ($collation !== null && !isset($pairs[strtolower($collation)])) {
            throw ApiException::badRequest('Unknown collation: ' . $collation);
        }
        if ($charset === null && $collation === null) {
            return [$current['charset'], $current['collation']];
        }
        if ($collation === null) {
            $cs = strtolower((string) $charset);
            if ($cs === strtolower($current['charset'])) {
                return [$cs, $current['collation']];
            }
            $def = (string) ($byCharset[$cs]['default_collation'] ?? '');
            if ($def === '') {
                foreach ($collations as $c) {
                    if (strtolower((string) $c['charset']) === $cs && !empty($c['default'])) {
                        $def = (string) $c['name'];
                    }
                }
            }
            if ($def === '') {
                throw ApiException::badRequest('No default collation for character set ' . $charset);
            }
            return [$cs, strtolower($def)];
        }
        $coll = strtolower($collation);
        $owners = $pairs[$coll];
        if ($charset === null) {
            // MariaDB UCA-14 collations may belong to several charsets: prefer the current one.
            $cs = in_array(strtolower($current['charset']), $owners, true) ? strtolower($current['charset']) : $owners[0];
            return [$cs, $coll];
        }
        $cs = strtolower($charset);
        if (!in_array($cs, $owners, true)) {
            throw ApiException::badRequest('Collation ' . $collation . ' does not belong to character set ' . $charset);
        }
        return [$cs, $coll];
    }

    public function alterDatabase(string $name, array $changes): array
    {
        $plan = $this->buildAlterDatabase($name, $changes);
        $this->runDatabaseStatements($plan['sql']);
        $this->forgetTableInfo();
        return $plan;
    }

    /**
     * Copy a database: CREATE DATABASE (same defaults) → every base table via SHOW CREATE TABLE
     * (keeps indexes, foreign keys, AUTO_INCREMENT) + INSERT … SELECT (generated columns left out)
     * with FOREIGN_KEY_CHECKS=0 → views (database qualifier rewritten, retried for dependencies)
     * → routines, triggers, events (events disabled in a copy so they do not run twice).
     * Objects that fail (e.g. DEFINER of another account without SET_USER_ID / SUPER) are
     * retried without DEFINER, then reported in `skipped`. A failure while copying tables or
     * data drops the half-created target again. drop_source drops the source afterwards
     * ("rename" = move), but only when nothing was skipped. Grants are not moved.
     */
    public function copyDatabase(string $source, string $target, array $opts = []): array
    {
        $preview = !empty($opts['preview']);
        $dropSource = !empty($opts['drop_source']);
        if ($target === '' || $target === $source) {
            throw ApiException::badRequest('Choose a different name for the new database');
        }
        if (mb_strlen($target, 'UTF-8') > 64) {
            throw ApiException::badRequest('Database names are limited to 64 characters');
        }
        $defaults = $this->schemaDefaults($source);
        if ($this->fetchValue('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]) !== null) {
            throw new ApiException('Database ' . $target . ' already exists', 'bad_request', 409);
        }
        $qs = $this->quoteIdentifier($source);
        $qt = $this->quoteIdentifier($target);
        $previousDb = $this->database;
        $this->select($source);

        /** @var array<int,array{kind:string,name:string,sql:string,type?:string}> $plan */
        $plan = [['kind' => 'core', 'name' => $target, 'sql' => 'CREATE DATABASE ' . $qt . ' CHARACTER SET ' . $this->assertWord($defaults['charset'], 'charset') . ' COLLATE ' . $this->assertWord($defaults['collation'], 'collation')]];
        $plan[] = ['kind' => 'session', 'name' => '', 'sql' => 'SET FOREIGN_KEY_CHECKS = 0'];
        $plan[] = ['kind' => 'session', 'name' => '', 'sql' => 'USE ' . $qt];
        $skipped = [];
        $copied = ['tables' => 0, 'views' => 0, 'routines' => 0, 'triggers' => 0, 'events' => 0];

        $columns = [];
        foreach ($this->fetchAll(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, GENERATION_EXPRESSION AS g FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$source]
        ) as $r) {
            if ($r['g'] === null || $r['g'] === '') {
                $columns[(string) $r['t']][] = $this->quoteIdentifier((string) $r['c']);
            }
        }
        $views = [];
        foreach ($this->fetchAll('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$source]) as $r) {
            $n = (string) $r['name'];
            $type = strtoupper((string) $r['type']);
            if (strpos($type, 'VIEW') !== false) {
                $views[] = $n;
                continue;
            }
            if (!in_array($type, self::BASE_TABLE_TYPES, true)) {
                $skipped[] = ['type' => strtolower($type), 'name' => $n, 'error' => 'not supported'];
                continue;
            }
            $plan[] = ['kind' => 'core', 'name' => $n, 'type' => 'tables', 'sql' => rtrim($this->createStatement($n, 'table'), "; \t\r\n")];
            if (!empty($columns[$n])) {
                $list = implode(', ', $columns[$n]);
                $plan[] = ['kind' => 'core', 'name' => $n, 'sql' => 'INSERT INTO ' . $qt . '.' . $this->quoteIdentifier($n) . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $qs . '.' . $this->quoteIdentifier($n)];
            }
        }
        $objects = [];
        foreach ($views as $v) {
            $objects[] = ['views', 'view', $v];
        }
        foreach ($this->listRoutines() as $r) {
            $objects[] = ['routines', strtolower((string) $r['type']) === 'procedure' ? 'procedure' : 'function', (string) $r['name']];
        }
        foreach ($this->listTriggers() as $tr) {
            $objects[] = ['triggers', 'trigger', (string) $tr['name']];
        }
        try {
            foreach ($this->listEvents() as $e) {
                $objects[] = ['events', 'event', (string) $e['name']];
            }
        } catch (\PDOException $e) {
        }
        foreach ($objects as [$group, $type, $n]) {
            try {
                $ddl = rtrim($this->createStatement($n, $type), "; \t\r\n");
            } catch (\Throwable $e) {
                $skipped[] = ['type' => $type, 'name' => $n, 'error' => $e->getMessage()];
                continue;
            }
            if ($type === 'view') {
                // SHOW CREATE VIEW qualifies every column/table with the source database.
                $ddl = str_replace($qs . '.', $qt . '.', $ddl);
            } else {
                // MariaDB keeps the original text ("CREATE TRIGGER `src`.t … ON `src`.books"):
                // retarget the object head only, never the body.
                $ddl = self::retargetHead($ddl, $type, $qs, $qt);
            }
            $plan[] = ['kind' => 'object', 'name' => $n, 'type' => $group, 'sql' => $ddl];
            if ($type === 'event' && !$dropSource) {
                $plan[] = ['kind' => 'object', 'name' => $n, 'sql' => 'ALTER EVENT ' . $qt . '.' . $this->quoteIdentifier($n) . ' DISABLE'];
            }
        }
        $plan[] = ['kind' => 'session', 'name' => '', 'sql' => 'SET FOREIGN_KEY_CHECKS = 1'];
        if ($dropSource) {
            $plan[] = ['kind' => 'drop', 'name' => $source, 'sql' => 'DROP DATABASE ' . $qs];
        }
        $warnings = ['copy_grants_not_copied'];
        if ($dropSource) {
            $warnings[] = 'move_drops_source';
        }
        if ($views !== [] || $objects !== []) {
            $warnings[] = 'copy_objects_best_effort';
        }
        $result = [
            'sql' => array_map(static function (array $p): string {
                return $p['sql'];
            }, $plan),
            'warnings' => $warnings,
            'executed' => false,
            'copied' => $copied,
            'skipped' => $skipped,
            'dropped' => false,
        ];
        if ($preview) {
            return $result;
        }

        $created = false;
        $restoreFk = false;
        $pending = [];
        try {
            foreach ($plan as $p) {
                if ($p['kind'] === 'drop') {
                    continue;
                }
                if ($p['kind'] === 'object') {
                    $pending[] = $p;
                    continue;
                }
                try {
                    $this->exec($p['sql']);
                } catch (\PDOException $e) {
                    throw ApiException::dbError($e->getMessage(), $p['sql'], $e);
                }
                if ($p['sql'] === 'SET FOREIGN_KEY_CHECKS = 0') {
                    $restoreFk = true;
                }
                $created = true;
                if (($p['type'] ?? '') === 'tables') {
                    $copied['tables']++;
                }
                if ($p['sql'] === 'SET FOREIGN_KEY_CHECKS = 1') {
                    $restoreFk = false;
                }
            }
        } catch (ApiException $e) {
            if ($restoreFk) {
                $this->quietly('SET FOREIGN_KEY_CHECKS = 1');
            }
            if ($created) {
                $this->quietly('DROP DATABASE ' . $qt);
            }
            $this->quietly('USE ' . $qs);
            throw $e;
        }
        // Views / routines / triggers / events: after the data; retried for view dependencies.
        $this->quietly('USE ' . $qt);
        for ($round = 0; $pending !== [] && $round < 10; $round++) {
            $failed = [];
            $progress = false;
            foreach ($pending as $p) {
                $err = $this->tryObjectDdl($p['sql']);
                if ($err === null) {
                    if (isset($p['type'])) {
                        $copied[$p['type']]++;
                    }
                    $progress = true;
                } else {
                    $failed[] = $p + ['error' => $err];
                }
            }
            $pending = $failed;
            if (!$progress) {
                break;
            }
        }
        foreach ($pending as $p) {
            $skipped[] = ['type' => rtrim((string) ($p['type'] ?? 'object'), 's'), 'name' => $p['name'], 'error' => (string) $p['error']];
        }
        $dropped = false;
        if ($dropSource && $skipped === []) {
            $this->exec('DROP DATABASE ' . $qs);
            $dropped = true;
            $this->database = $target;
        } else {
            $this->quietly('USE ' . ($previousDb !== null && $previousDb !== '' && $previousDb !== $source ? $this->quoteIdentifier($previousDb) : $qs));
            $this->database = $previousDb !== null && $previousDb !== '' ? $previousDb : $source;
        }
        $this->forgetTableInfo();
        $result['executed'] = true;
        $result['copied'] = $copied;
        $result['skipped'] = $skipped;
        $result['dropped'] = $dropped;
        return $result;
    }

    /**
     * Replace the `src`. qualifier in the head of a routine/trigger/event DDL (up to the
     * parameter list, FOR EACH ROW or ON SCHEDULE) with the target database.
     */
    public static function retargetHead(string $ddl, string $type, string $quotedSource, string $quotedTarget): string
    {
        $stops = ['trigger' => '/\bFOR\s+EACH\s+ROW\b/i', 'event' => '/\bON\s+SCHEDULE\b/i'];
        $pos = false;
        if (isset($stops[$type]) && preg_match($stops[$type], $ddl, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $m[0][1];
        } elseif (!isset($stops[$type])) {
            $pos = strpos($ddl, '(');
        }
        if ($pos === false) {
            return $ddl;
        }
        $head = str_replace($quotedSource . '.', $quotedTarget . '.', substr($ddl, 0, $pos));
        $bare = str_replace('``', '`', substr($quotedSource, 1, -1));
        if (preg_match('/^[A-Za-z0-9_$]+$/', $bare)) {
            // unquoted qualifier as typed by the user (CREATE TRIGGER src.t … ON src.books)
            $head = (string) preg_replace('/(?<![\w`$.])' . preg_quote($bare, '/') . '\./', $quotedTarget . '.', $head);
        }
        return $head . substr($ddl, $pos);
    }

    /** Run an object DDL; on failure retry once without DEFINER. Returns the error or null. */
    private function tryObjectDdl(string $sql): ?string
    {
        try {
            $this->exec($sql);
            return null;
        } catch (\PDOException $e) {
            $plain = preg_replace('/\sDEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s@]+)(?:@(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s]+))?/i', '', $sql, 1);
            if ($plain === null || $plain === $sql) {
                return $e->getMessage();
            }
            try {
                $this->exec($plain);
                return null;
            } catch (\PDOException $e2) {
                return $e2->getMessage();
            }
        }
    }

    private function quietly(string $sql): void
    {
        try {
            $this->exec($sql);
        } catch (\PDOException $e) {
        }
    }

    // ------------------------------------------------------------------ objects

    public function listTables(): array
    {
        $rows = $this->fetchAll(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS row_count,
                    DATA_LENGTH AS data_size, INDEX_LENGTH AS index_size, TABLE_COLLATION AS collation,
                    TABLE_COMMENT AS comment, AUTO_INCREMENT AS ai, COALESCE(UPDATE_TIME, CREATE_TIME) AS updated
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->requireDb()]
        );
        $out = [];
        foreach ($rows as $r) {
            $isView = stripos((string) $r['type'], 'VIEW') !== false;
            $out[] = [
                'name' => $r['name'],
                'type' => $isView ? 'view' : 'table',
                'rows' => $isView || $r['row_count'] === null ? null : (int) $r['row_count'],
                'data_size' => $r['data_size'] === null ? null : (int) $r['data_size'],
                'index_size' => $r['index_size'] === null ? null : (int) $r['index_size'],
                'engine' => $r['engine'],
                'collation' => $r['collation'],
                'comment' => $isView || $r['comment'] === '' ? null : $r['comment'],
                'auto_increment' => $r['ai'] === null ? null : (int) $r['ai'],
                'updated' => $r['updated'] === null ? null : (string) $r['updated'],
            ];
        }
        return $out;
    }

    public function listRoutines(): array
    {
        $rows = $this->fetchAll(
            'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type, DTD_IDENTIFIER AS returns, DEFINER AS definer
             FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME',
            [$this->requireDb()]
        );
        foreach ($rows as &$r) {
            $r['type'] = strtoupper((string) $r['type']);
        }
        return $rows;
    }

    public function listTriggers(): array
    {
        return $this->fetchAll(
            'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS `table`, ACTION_TIMING AS timing,
                    EVENT_MANIPULATION AS event, ACTION_STATEMENT AS statement
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER, TRIGGER_NAME',
            [$this->requireDb()]
        );
    }

    public function listEvents(): array
    {
        $rows = $this->fetchAll(
            'SELECT EVENT_NAME AS name, EVENT_TYPE AS etype, EXECUTE_AT AS at, INTERVAL_VALUE AS iv,
                    INTERVAL_FIELD AS field, STARTS AS starts, ENDS AS ends, STATUS AS status
             FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME',
            [$this->requireDb()]
        );
        $out = [];
        foreach ($rows as $r) {
            if (strtoupper((string) $r['etype']) === 'RECURRING') {
                $schedule = 'EVERY ' . $r['iv'] . ' ' . $r['field'];
                if ($r['starts'] !== null) {
                    $schedule .= ' STARTS ' . $r['starts'];
                }
                if ($r['ends'] !== null) {
                    $schedule .= ' ENDS ' . $r['ends'];
                }
            } else {
                $schedule = 'AT ' . $r['at'];
            }
            $out[] = ['name' => $r['name'], 'schedule' => $schedule, 'status' => $r['status']];
        }
        return $out;
    }

    public function tableInfo(string $table): array
    {
        $db = $this->requireDb();
        $t = $this->fetchOne(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_COLLATION AS collation,
                    TABLE_COMMENT AS comment, TABLE_ROWS AS row_count, AUTO_INCREMENT AS ai
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db, $table]
        );
        if ($t === null) {
            throw $this->notFound('Table ' . $table);
        }
        $isView = stripos((string) $t['type'], 'VIEW') !== false;

        // MariaDB: JSON is LONGTEXT + column CHECK (json_valid(`col`)); report it as json
        $jsonCols = [];
        if ($this->mariaDb) {
            try {
                foreach ($this->fetchAll(
                    "SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND LEVEL = 'Column'",
                    [$db, $table]
                ) as $ck) {
                    if (preg_match('/^json_valid\(`((?:[^`]|``)+)`\)$/i', trim((string) $ck['clause']), $m)) {
                        $jsonCols[str_replace('``', '`', $m[1])] = true;
                    }
                }
            } catch (\PDOException $e) {
            }
        }
        $columns = [];
        foreach ($this->fetchAll('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $table]) as $c) {
            $c = array_change_key_case($c, CASE_UPPER);
            $col = $this->columnFromInfoSchema($c);
            if (isset($jsonCols[$col['name']]) && $col['type'] === 'longtext') {
                $col['type'] = 'json';
                $col['full_type'] = 'json';
                $col['collation'] = null;
            }
            $columns[] = $col;
        }

        // indexes
        $indexes = [];
        foreach ($this->fetchAll(
            'SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME = \'PRIMARY\' DESC, INDEX_NAME, SEQ_IN_INDEX',
            [$db, $table]
        ) as $s) {
            $s = array_change_key_case($s, CASE_UPPER);
            $name = $s['INDEX_NAME'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (int) $s['NON_UNIQUE'] === 0,
                    'primary' => $name === 'PRIMARY',
                    'type' => $s['INDEX_TYPE'] ?? null,
                ];
            }
            if ($s['COLUMN_NAME'] === null && !empty($s['EXPRESSION'])) {
                $col = '(' . $s['EXPRESSION'] . ')';
            } else {
                $col = (string) $s['COLUMN_NAME'];
                if ($s['SUB_PART'] !== null && $s['SUB_PART'] !== '') {
                    $col .= '(' . (int) $s['SUB_PART'] . ')';
                }
            }
            $indexes[$name]['columns'][] = $col;
        }

        $fks = $this->foreignKeys(
            'k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$db, $table]
        );
        $refBy = $this->foreignKeys(
            'k.REFERENCED_TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME = ?',
            [$db, $table],
            true
        );

        $triggers = [];
        foreach ($this->fetchAll(
            'SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, ACTION_STATEMENT AS statement
             FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ? ORDER BY ACTION_ORDER, TRIGGER_NAME',
            [$db, $table]
        ) as $tr) {
            $triggers[] = $tr;
        }

        return [
            'name' => $t['name'],
            'type' => $isView ? 'view' : 'table',
            'columns' => $columns,
            'indexes' => array_values($indexes),
            'foreign_keys' => $fks,
            'referenced_by' => $refBy,
            'triggers' => $triggers,
            'options' => [
                'engine' => $t['engine'],
                'collation' => $t['collation'],
                'comment' => $isView || $t['comment'] === '' ? null : $t['comment'],
                'rows' => $t['row_count'] === null ? null : (int) $t['row_count'],
                'auto_increment' => $t['ai'] === null ? null : (int) $t['ai'],
            ],
        ];
    }

    /** Column shape from an information_schema.COLUMNS row (upper-cased keys). */
    private function columnFromInfoSchema(array $c): array
    {
        $fullType = (string) $c['COLUMN_TYPE'];
        [, $length] = self::splitType($fullType);
        $extra = (string) ($c['EXTRA'] ?? '');
        $nullable = strtoupper((string) $c['IS_NULLABLE']) === 'YES';
        $def = $c['COLUMN_DEFAULT'];
        $kind = 'none';
        $default = null;
        if ($this->mariaDb) {
            // MariaDB 10.2.7+: literals quoted, NULL default as 'NULL', expressions verbatim
            if ($def === null) {
                $kind = $nullable ? 'null' : 'none';
            } elseif (strtoupper($def) === 'NULL') {
                $kind = 'null';
            } elseif (strlen($def) >= 2 && $def[0] === "'" && substr($def, -1) === "'") {
                $kind = 'value';
                $default = strtr(substr($def, 1, -1), ["''" => "'", '\\\\' => '\\', "\\'" => "'", '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\0' => "\0"]);
            } elseif (is_numeric($def) || preg_match("/^b'[01]*'$/i", $def)) {
                $kind = 'value';
                $default = $def;
            } else {
                $kind = 'expression';
                $default = $def;
            }
        } else {
            if ($def === null) {
                $kind = $nullable ? 'null' : 'none';
            } elseif (stripos($extra, 'DEFAULT_GENERATED') !== false
                || (preg_match('/^(timestamp|datetime)/i', $fullType) && preg_match('/^current_timestamp(\(\d*\))?$/i', $def))) {
                $kind = 'expression';
                $default = $def;
            } else {
                $kind = 'value';
                $default = $def;
            }
        }
        $generated = isset($c['GENERATION_EXPRESSION']) && $c['GENERATION_EXPRESSION'] !== '' ? (string) $c['GENERATION_EXPRESSION'] : null;
        $cleanExtra = trim(preg_replace('/\bDEFAULT_GENERATED\b/i', '', $extra));
        return [
            'name' => $c['COLUMN_NAME'],
            'type' => strtolower((string) $c['DATA_TYPE']),
            'full_type' => $fullType,
            'length' => $length,
            'unsigned' => stripos($fullType, 'unsigned') !== false,
            'nullable' => $nullable,
            'default' => $default === null ? null : (string) $default,
            'default_kind' => $generated !== null ? 'none' : $kind,
            'auto_increment' => stripos($extra, 'auto_increment') !== false,
            'primary' => ($c['COLUMN_KEY'] ?? '') === 'PRI',
            'comment' => ($c['COLUMN_COMMENT'] ?? '') === '' ? null : $c['COLUMN_COMMENT'],
            'collation' => $c['COLLATION_NAME'] ?? null,
            'extra' => $cleanExtra === '' ? null : $cleanExtra,
            'generated' => $generated,
        ];
    }

    /** ForeignKey list; with $withTable each entry also has table (+schema) of the referencing side. */
    private function foreignKeys(string $where, array $params, bool $withTable = false): array
    {
        $rows = $this->fetchAll(
            'SELECT k.CONSTRAINT_NAME AS name, k.TABLE_SCHEMA AS tschema, k.TABLE_NAME AS tname, k.COLUMN_NAME AS col,
                    k.REFERENCED_TABLE_SCHEMA AS rschema, k.REFERENCED_TABLE_NAME AS rtable, k.REFERENCED_COLUMN_NAME AS rcol,
                    r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE ' . $where . '
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            $params
        );
        $fks = [];
        foreach ($rows as $r) {
            $key = $r['tschema'] . '.' . $r['tname'] . '.' . $r['name'];
            if (!isset($fks[$key])) {
                $fks[$key] = [
                    'name' => $r['name'],
                    'columns' => [],
                    'ref_schema' => $r['rschema'],
                    'ref_table' => $r['rtable'],
                    'ref_columns' => [],
                    'on_delete' => $r['on_delete'],
                    'on_update' => $r['on_update'],
                ];
                if ($withTable) {
                    $fks[$key]['table'] = $r['tname'];
                    $fks[$key]['schema'] = $r['tschema'];
                }
            }
            $fks[$key]['columns'][] = $r['col'];
            $fks[$key]['ref_columns'][] = $r['rcol'];
        }
        return array_values($fks);
    }

    protected function usesSchemaForFk(): bool
    {
        return false;
    }

    protected function quoteFkTable(array $fk): string
    {
        if (!empty($fk['ref_schema'])) {
            return $this->quoteIdentifier($fk['ref_schema']) . '.' . $this->quoteIdentifier($fk['ref_table']);
        }
        return $this->quoteTable($fk['ref_table']);
    }

    public function fkValues(string $table, string $column, ?string $term): array
    {
        $info = $this->cachedTableInfo($table);
        foreach ($info['foreign_keys'] as $f) {
            if (count($f['columns']) === 1 && $f['columns'][0] === $column
                && !empty($f['ref_schema']) && $f['ref_schema'] !== $this->database) {
                // referenced table in another database: plain value list
                $q = $this->quoteIdentifier($f['ref_schema']) . '.' . $this->quoteIdentifier($f['ref_table']);
                $qv = $this->quoteIdentifier($f['ref_columns'][0]);
                $params = [];
                $where = '';
                if ($term !== null && $term !== '') {
                    $where = ' WHERE CAST(' . $qv . ' AS CHAR) LIKE ?' . $this->likeEscapeClause();
                    $params[] = '%' . $this->escapeLike($term) . '%';
                }
                $out = [];
                foreach ($this->run('SELECT ' . $qv . ' FROM ' . $q . $where . ' ORDER BY ' . $qv . ' LIMIT 100', $params)->fetchAll(PDO::FETCH_NUM) as $r) {
                    $out[] = ['value' => $this->normalizeValue($r[0]), 'label' => null];
                }
                return $out;
            }
        }
        return parent::fkValues($table, $column, $term);
    }

    public function createStatement(string $name, string $type = 'table'): string
    {
        $db = $this->requireDb();
        $q = $this->quoteIdentifier($db) . '.' . $this->quoteIdentifier($name);
        $type = strtolower($type);
        if ($type === 'routine') {
            $t = $this->fetchValue('SELECT ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? LIMIT 1', [$db, $name]);
            if ($t === null) {
                throw $this->notFound('Routine ' . $name);
            }
            $type = strtolower((string) $t);
        }
        switch ($type) {
            case 'table':
            case 'view':
                $sql = 'SHOW CREATE TABLE ' . $q; // works for views too
                $keys = ['Create Table', 'Create View'];
                break;
            case 'procedure':
                $sql = 'SHOW CREATE PROCEDURE ' . $q;
                $keys = ['Create Procedure'];
                break;
            case 'function':
                $sql = 'SHOW CREATE FUNCTION ' . $q;
                $keys = ['Create Function'];
                break;
            case 'trigger':
                $sql = 'SHOW CREATE TRIGGER ' . $q;
                $keys = ['SQL Original Statement'];
                break;
            case 'event':
                $sql = 'SHOW CREATE EVENT ' . $q;
                $keys = ['Create Event'];
                break;
            default:
                throw new ApiException('Unknown object type: ' . $type, 'bad_request', 400);
        }
        $row = $this->executeRaw($sql)->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw $this->notFound(ucfirst($type) . ' ' . $name);
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                if ($row[$k] === null) {
                    throw new ApiException('Insufficient privileges to view the definition of ' . $name, 'db_error', 403);
                }
                return (string) $row[$k];
            }
        }
        throw $this->notFound(ucfirst($type) . ' ' . $name);
    }

    // ------------------------------------------------------------------ browse / edit

    protected function estimateBrowseTotal(string $table): ?int
    {
        $r = $this->fetchOne(
            'SELECT ENGINE AS engine, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->requireDb(), $table]
        );
        if ($r && strcasecmp((string) $r['engine'], 'InnoDB') === 0 && (int) $r['n'] > self::ESTIMATE_THRESHOLD) {
            return (int) $r['n'];
        }
        return null;
    }

    protected function castToText(string $quotedColumn, array $col): ?string
    {
        $t = strtolower((string) ($col['type'] ?? ''));
        if (preg_match('/blob|binary|geometry|point|polygon|linestring|bit/', $t)) {
            return null;
        }
        return 'CAST(' . $quotedColumn . ' AS CHAR)';
    }

    /** Display literal: MySQL treats backslash as an escape character (unless NO_BACKSLASH_ESCAPES). */
    protected function displayStringLiteral(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }

    protected function singleRowSuffix(string $verb): string
    {
        return ' LIMIT 1';
    }

    protected function emptyInsertSql(string $quotedTable): string
    {
        return 'INSERT INTO ' . $quotedTable . ' () VALUES ()';
    }

    public function normalizeValue($value, ?string $type = null)
    {
        // BIT columns arrive as raw bytes; show as a number
        if (is_string($value) && $type !== null && ($type === 'bit' || $type === 'BIT') && strlen($value) <= 8) {
            $n = 0;
            for ($i = 0, $l = strlen($value); $i < $l; $i++) {
                $n = ($n << 8) | ord($value[$i]);
            }
            return $n;
        }
        return parent::normalizeValue($value, $type);
    }

    // ------------------------------------------------------------------ query

    protected function beforeQuery(): void
    {
        // stream big result sets instead of buffering them in PHP memory
        $this->pdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $this->unbuffered = true;
    }

    protected function afterQuery(): void
    {
        if ($this->unbuffered) {
            $this->pdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $this->unbuffered = false;
        }
    }

    /**
     * pdo_mysql (PHP 8, native prepared statements) keeps the previous columnCount() for the trailing OK
     * packet of a CALL while getColumnMeta() returns false: such a rowset is not a result set.
     */
    protected function isGhostRowset(PDOStatement $stmt): bool
    {
        return $stmt->getColumnMeta(0) === false;
    }

    protected function executeRaw(string $sql): PDOStatement
    {
        try {
            return parent::executeRaw($sql);
        } catch (\PDOException $e) {
            // 1295: "This command is not supported in the prepared statement protocol yet"
            if ((int) ($e->errorInfo[1] ?? 0) !== 1295) {
                throw $e;
            }
        }
        $pdo = $this->pdo();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            return parent::executeRaw($sql);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    protected function metaType(array $meta): ?string
    {
        // pdo_mysql native_type: LONG, VAR_STRING, BLOB (also for TEXT!), NEWDECIMAL …
        $t = isset($meta['native_type']) ? strtolower((string) $meta['native_type']) : null;
        // TEXT and BLOB share native type BLOB: report "lob" so normalizeValue decides by UTF-8 validity
        return $t === 'blob' ? 'lob' : $t;
    }

    public function explain(string $sql): array
    {
        return $this->query('EXPLAIN ' . $sql, 10000);
    }

    /** EXPLAIN with FORMAT=JSON / TREE (MySQL 8) / ANALYZE. JSON on MariaDB: EXPLAIN FORMAT=JSON works too. */
    public function explainFormat(string $sql, string $format = 'traditional'): array
    {
        switch (strtolower($format)) {
            case 'json':
                return $this->query('EXPLAIN FORMAT=JSON ' . $sql, 10000);
            case 'tree':
                if ($this->mariaDb) {
                    throw $this->unsupported('EXPLAIN FORMAT=TREE');
                }
                return $this->query('EXPLAIN FORMAT=TREE ' . $sql, 10000);
            case 'analyze':
                return $this->query(($this->mariaDb ? 'ANALYZE ' : 'EXPLAIN ANALYZE ') . $sql, 10000);
        }
        return $this->explain($sql);
    }

    // ------------------------------------------------------------------ DDL

    private function columnSql(array $col): string
    {
        $name = (string) ($col['name'] ?? '');
        $sql = $this->quoteIdentifier($name) . ' ' . $this->renderType($col);
        $type = strtolower((string) ($col['type'] ?? ''));
        if (!empty($col['unsigned']) && preg_match('/int|dec|numeric|float|double|real/', $type)) {
            $sql .= ' UNSIGNED';
        }
        if (!empty($col['collation'])) {
            if (preg_match('/char|text|enum|set/', $type)) {
                $sql .= ' COLLATE ' . $this->assertWord((string) $col['collation'], 'collation');
            }
        }
        $generated = isset($col['generated']) ? trim((string) $col['generated']) : '';
        if ($generated !== '') {
            $stored = !empty($col['stored']) || stripos((string) ($col['extra'] ?? ''), 'STORED') !== false
                || stripos((string) ($col['extra'] ?? ''), 'PERSISTENT') !== false;
            $sql .= ' GENERATED ALWAYS AS (' . $generated . ') ' . ($stored ? 'STORED' : 'VIRTUAL');
            if (empty($col['nullable']) && !$this->mariaDb) {
                $sql .= ' NOT NULL';
            }
        } else {
            $sql .= !empty($col['nullable']) ? ' NULL' : ' NOT NULL';
            $sql .= $this->renderDefaultClause($col);
            $onUpdate = isset($col['on_update']) ? trim((string) $col['on_update']) : '';
            if ($onUpdate === '' && preg_match('/on update (current_timestamp(?:\(\d*\))?)/i', (string) ($col['extra'] ?? ''), $m)) {
                $onUpdate = $m[1];
            }
            if ($onUpdate !== '') {
                if (!preg_match('/^(current_timestamp|now|localtimestamp|localtime)(\(\d*\))?$/i', $onUpdate)) {
                    throw new ApiException('Invalid ON UPDATE value: ' . $onUpdate, 'bad_request', 400);
                }
                $sql .= ' ON UPDATE ' . strtoupper($onUpdate);
            }
            if (!empty($col['auto_increment'])) {
                $sql .= ' AUTO_INCREMENT';
            }
        }
        if (isset($col['comment']) && $col['comment'] !== null && $col['comment'] !== '') {
            $sql .= ' COMMENT ' . $this->quoteLiteral((string) $col['comment']);
        }
        return $sql;
    }

    protected function renderDefaultClause(array $col): string
    {
        $kind = (string) ($col['default_kind'] ?? 'none');
        $type = strtolower((string) ($col['type'] ?? ''));
        if ($kind === 'value' && $type === 'bit') {
            $v = trim((string) ($col['default'] ?? ''));
            if (preg_match("/^b'[01]+'$/i", $v) || ctype_digit($v)) {
                return ' DEFAULT ' . $v;
            }
        }
        if ($kind === 'expression') {
            $e = trim((string) ($col['default'] ?? ''));
            if ($e === '') {
                return '';
            }
            if (preg_match('/^(current_timestamp|now|localtime|localtimestamp)(\(\d*\))?$/i', $e)
                || $e[0] === '(' || $this->mariaDb) {
                return ' DEFAULT ' . $e;
            }
            return ' DEFAULT (' . $e . ')';
        }
        return parent::renderDefaultClause($col);
    }

    /** Index column list supporting prefix lengths ("col(10)") and expressions "(expr)". */
    private function indexColumns(array $cols): string
    {
        $out = [];
        foreach ($cols as $c) {
            $c = (string) $c;
            if ($c !== '' && $c[0] === '(') {
                $out[] = $c; // functional key part (MySQL 8.0.13+)
            } elseif (preg_match('/^(.+)\((\d+)\)$/', $c, $m)) {
                $out[] = $this->quoteIdentifier($m[1]) . '(' . $m[2] . ')';
            } else {
                $out[] = $this->quoteIdentifier($c);
            }
        }
        return implode(', ', $out);
    }

    private function indexTypePrefix(array $ix): string
    {
        $t = strtoupper((string) ($ix['type'] ?? ''));
        if ($t === 'FULLTEXT' || $t === 'SPATIAL') {
            return $t . ' INDEX';
        }
        return !empty($ix['unique']) ? 'UNIQUE INDEX' : 'INDEX';
    }

    private function fkSql(array $fk): string
    {
        $ref = (!empty($fk['ref_schema']) && $fk['ref_schema'] !== $this->database)
            ? $this->quoteIdentifier((string) $fk['ref_schema']) . '.' . $this->quoteIdentifier((string) $fk['ref_table'])
            : $this->quoteIdentifier((string) $fk['ref_table']);
        $name = !empty($fk['name']) ? 'CONSTRAINT ' . $this->quoteIdentifier((string) $fk['name']) . ' ' : '';
        return $name . $this->renderForeignKeyBody($fk, $ref);
    }

    private function tableOptionsSql(array $opts): array
    {
        $out = [];
        if (!empty($opts['engine'])) {
            $out[] = 'ENGINE=' . $this->assertWord((string) $opts['engine'], 'engine');
        }
        if (!empty($opts['collation'])) {
            $coll = $this->assertWord((string) $opts['collation'], 'collation');
            $cs = strstr($coll, '_', true);
            $out[] = ($cs !== false ? 'DEFAULT CHARSET=' . $cs . ' ' : '') . 'COLLATE=' . $coll;
        }
        if (array_key_exists('comment', $opts) && $opts['comment'] !== null) {
            $out[] = 'COMMENT=' . $this->quoteLiteral((string) $opts['comment']);
        }
        if (isset($opts['auto_increment']) && $opts['auto_increment'] !== null && $opts['auto_increment'] !== '') {
            $out[] = 'AUTO_INCREMENT=' . (int) $opts['auto_increment'];
        }
        return $out;
    }

    public function buildCreateTable(array $definition): array
    {
        $name = (string) ($definition['name'] ?? '');
        if (empty($definition['columns'])) {
            throw new ApiException('A table needs at least one column', 'bad_request', 400);
        }
        $lines = [];
        foreach ($definition['columns'] as $c) {
            $lines[] = '  ' . $this->columnSql($c);
        }
        if (!empty($definition['primary_key'])) {
            $lines[] = '  PRIMARY KEY (' . $this->indexColumns((array) $definition['primary_key']) . ')';
        }
        foreach ((array) ($definition['indexes'] ?? []) as $ix) {
            if (!empty($ix['primary'])) {
                $lines[] = '  PRIMARY KEY (' . $this->indexColumns((array) $ix['columns']) . ')';
                continue;
            }
            $lines[] = '  ' . $this->indexTypePrefix($ix) . (!empty($ix['name']) ? ' ' . $this->quoteIdentifier((string) $ix['name']) : '')
                . ' (' . $this->indexColumns((array) $ix['columns']) . ')';
        }
        foreach ((array) ($definition['foreign_keys'] ?? []) as $fk) {
            $lines[] = '  ' . $this->fkSql($fk);
        }
        $sql = 'CREATE TABLE ' . $this->quoteTable($name) . " (\n" . implode(",\n", $lines) . "\n)";
        $opts = $this->tableOptionsSql((array) ($definition['options'] ?? []));
        if ($opts) {
            $sql .= ' ' . implode(' ', $opts);
        }
        return [$sql];
    }

    public function buildAlterTable(string $table, array $spec): array
    {
        $qt = $this->quoteTable($table);
        $out = [];

        $dropFk = [];
        foreach ((array) ($spec['drop_fk'] ?? []) as $n) {
            $dropFk[] = 'DROP FOREIGN KEY ' . $this->quoteIdentifier((string) $n);
        }
        if ($dropFk) {
            $out[] = 'ALTER TABLE ' . $qt . "\n  " . implode(",\n  ", $dropFk);
        }

        $c = [];
        foreach ((array) ($spec['drop_index'] ?? []) as $n) {
            $c[] = strtoupper((string) $n) === 'PRIMARY' ? 'DROP PRIMARY KEY' : 'DROP INDEX ' . $this->quoteIdentifier((string) $n);
        }
        foreach ((array) ($spec['drop'] ?? []) as $col) {
            $c[] = 'DROP COLUMN ' . $this->quoteIdentifier((string) $col);
        }
        foreach ((array) ($spec['modify'] ?? []) as $m) {
            $def = (array) ($m['definition'] ?? []);
            $old = (string) ($m['column'] ?? ($def['name'] ?? ''));
            if (!isset($def['name']) || $def['name'] === '') {
                $def['name'] = $old;
            }
            $c[] = 'CHANGE COLUMN ' . $this->quoteIdentifier($old) . ' ' . $this->columnSql($def) . $this->positionSql($def + $m);
        }
        foreach ((array) ($spec['add'] ?? []) as $def) {
            $c[] = 'ADD COLUMN ' . $this->columnSql((array) $def) . $this->positionSql((array) $def);
        }
        foreach ((array) ($spec['add_index'] ?? []) as $ix) {
            if (!empty($ix['primary'])) {
                $c[] = 'ADD PRIMARY KEY (' . $this->indexColumns((array) $ix['columns']) . ')';
            } else {
                $c[] = 'ADD ' . $this->indexTypePrefix($ix) . (!empty($ix['name']) ? ' ' . $this->quoteIdentifier((string) $ix['name']) : '')
                    . ' (' . $this->indexColumns((array) $ix['columns']) . ')';
            }
        }
        $c = array_merge($c, $this->tableOptionsSql((array) ($spec['options'] ?? [])));
        if ($c) {
            $out[] = 'ALTER TABLE ' . $qt . "\n  " . implode(",\n  ", $c);
        }

        $addFk = [];
        foreach ((array) ($spec['add_fk'] ?? []) as $fk) {
            $addFk[] = 'ADD ' . $this->fkSql((array) $fk);
        }
        if ($addFk) {
            $out[] = 'ALTER TABLE ' . $qt . "\n  " . implode(",\n  ", $addFk);
        }
        return $out;
    }

    private function positionSql(array $def): string
    {
        if (!empty($def['first'])) {
            return ' FIRST';
        }
        if (isset($def['after']) && $def['after'] !== null && $def['after'] !== '') {
            return ' AFTER ' . $this->quoteIdentifier((string) $def['after']);
        }
        return '';
    }

    public function renameTable(string $table, string $newName): void
    {
        $this->exec('RENAME TABLE ' . $this->quoteTable($table) . ' TO ' . $this->quoteTable($newName));
        $this->forgetTableInfo();
    }

    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        $src = $this->quoteTable($table);
        $dstDb = ($targetDb !== null && $targetDb !== '') ? $targetDb : $this->requireDb();
        $dst = $this->quoteIdentifier($dstDb) . '.' . $this->quoteIdentifier($newName);
        $info = $this->cachedTableInfo($table);
        if ($info['type'] === 'view') {
            throw $this->unsupported('Copying views');
        }
        $this->exec('CREATE TABLE ' . $dst . ' LIKE ' . $src);
        if ($withData) {
            $cols = [];
            foreach ($info['columns'] as $c) {
                if ($c['generated'] === null) {
                    $cols[] = $this->quoteIdentifier($c['name']);
                }
            }
            $list = implode(', ', $cols);
            $this->exec('INSERT INTO ' . $dst . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $src);
        }
    }

    public function maintenance(array $tables, string $op): array
    {
        $op = strtoupper($op);
        if (!in_array($op, ['OPTIMIZE', 'ANALYZE', 'CHECK', 'REPAIR'], true)) {
            throw $this->unsupported('Maintenance operation ' . strtolower($op));
        }
        if (!$tables) {
            throw new ApiException('No tables given', 'bad_request', 400);
        }
        $q = [];
        foreach ($tables as $t) {
            $q[] = $this->quoteTable((string) $t);
        }
        $stmt = $this->executeRaw($op . ' TABLE ' . implode(', ', $q));
        $columns = [];
        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $m = $stmt->getColumnMeta($i);
            $columns[] = $m['name'] ?? ('col' . $i);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return ['columns' => $columns, 'rows' => $rows];
    }

    // ------------------------------------------------------------------ server

    private function nameValueList(string $sql): array
    {
        $out = [];
        foreach ($this->executeRaw($sql)->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = ['name' => $r[0], 'value' => $r[1]];
        }
        return $out;
    }

    public function variables(?string $filter = null): array
    {
        return $this->filterNameValue($this->nameValueList('SHOW GLOBAL VARIABLES'), $filter);
    }

    public function status(): array
    {
        return $this->nameValueList('SHOW GLOBAL STATUS');
    }

    public function processes(): array
    {
        $stmt = $this->executeRaw('SHOW FULL PROCESSLIST');
        $columns = [];
        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $m = $stmt->getColumnMeta($i);
            $name = (string) ($m['name'] ?? ('col' . $i));
            $columns[] = strtolower($name) === 'id' ? 'id' : $name;
        }
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
            foreach ($r as $i => $v) {
                $r[$i] = $this->normalizeValue($v);
            }
            $rows[] = $r;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    public function kill(string $id): void
    {
        if (!ctype_digit($id)) {
            throw new ApiException('Invalid process id', 'bad_request', 400);
        }
        $this->exec('KILL ' . $id);
    }

    public function backendId(): ?string
    {
        return (string) $this->fetchValue('SELECT CONNECTION_ID()');
    }

    /** KILL QUERY: aborts only the running statement; the target connection stays open. */
    public function cancelBackend(string $id): bool
    {
        if (!ctype_digit($id)) {
            return false;
        }
        try {
            $this->exec('KILL QUERY ' . $id);
            return true;
        } catch (\PDOException $e) {
            return false; // 1094 unknown thread id, 1095 not your thread …
        }
    }

    private function account(string $user, ?string $host): string
    {
        return $this->quoteLiteral($user) . '@' . $this->quoteLiteral($host === null || $host === '' ? '%' : $host);
    }

    public function users(): array
    {
        $rows = $this->executeRaw('SELECT * FROM mysql.user')->fetchAll(PDO::FETCH_ASSOC);
        $locked = [];
        if ($this->mariaDb) {
            // MariaDB 10.4+: lock state only in mysql.global_priv JSON
            try {
                foreach ($this->executeRaw("SELECT User AS u, Host AS h, JSON_VALUE(Priv, '$.account_locked') AS l FROM mysql.global_priv")->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $locked[$l['u'] . '@' . $l['h']] = $l['l'] === 'true' || $l['l'] === '1';
                }
            } catch (\PDOException $e) {
            }
        }
        $roleEdges = [];
        if (!$this->mariaDb) {
            // MySQL 8 roles are ordinary (locked) accounts; those granted to someone are roles
            try {
                foreach ($this->executeRaw('SELECT DISTINCT FROM_USER AS u, FROM_HOST AS h FROM mysql.role_edges')->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $roleEdges[$e['u'] . '@' . $e['h']] = true;
                }
            } catch (\PDOException $e) {
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $isRole = isset($r['is_role'])
                ? strtoupper((string) $r['is_role']) === 'Y'
                : isset($roleEdges[$r['user'] . '@' . $r['host']])
                    || (strtoupper((string) ($r['account_locked'] ?? 'N')) === 'Y' && (string) ($r['authentication_string'] ?? 'x') === '');
            $privs = 0;
            $total = 0;
            foreach ($r as $k => $v) {
                if (substr($k, -5) === '_priv') {
                    $total++;
                    if (strtoupper((string) $v) === 'Y') {
                        $privs++;
                    }
                }
            }
            $out[] = [
                'user' => $r['user'],
                'host' => $isRole && $this->mariaDb ? null : $r['host'],
                'is_role' => $isRole,
                'plugin' => $r['plugin'] ?? null,
                'account_locked' => isset($r['account_locked']) ? strtoupper((string) $r['account_locked']) === 'Y' : ($locked[$r['user'] . '@' . $r['host']] ?? null),
                'password_expired' => isset($r['password_expired']) ? strtoupper((string) $r['password_expired']) === 'Y' : null,
                'global_privileges' => $total > 0 ? ($privs === $total ? 'ALL' : ($privs === 0 ? 'USAGE' : $privs . '/' . $total)) : null,
            ];
        }
        usort($out, function ($a, $b) {
            return strcmp($a['user'] . '@' . $a['host'], $b['user'] . '@' . $b['host']);
        });
        return $out;
    }

    public function userGrants(string $user, ?string $host): array
    {
        $out = [];
        foreach ($this->executeRaw('SHOW GRANTS FOR ' . $this->account($user, $host))->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = (string) $r[0];
        }
        return $out;
    }

    /**
     * Create or update a user. For existing users a non-empty $grants list REPLACES the current
     * privileges (REVOKE ALL first), except for the account we are logged in as (grants are then
     * only added, to avoid locking ourselves out). Empty $grants leaves privileges untouched.
     */
    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void
    {
        $acct = $this->account($user, $host);
        $clean = [];
        foreach ($grants as $g) {
            $g = rtrim(trim((string) $g), "; \t\n\r");
            if ($g === '') {
                continue;
            }
            if (!preg_match('/^GRANT\s/i', $g)) {
                throw new ApiException('Only GRANT statements are allowed: ' . $g, 'bad_request', 400);
            }
            $clean[] = $g;
        }
        if ($isNew) {
            $sql = 'CREATE USER ' . $acct;
            if ($password !== null && $password !== '') {
                $sql .= ' IDENTIFIED BY ' . $this->quoteLiteral($password);
            }
            $this->exec($sql);
        } else {
            if ($password !== null && $password !== '') {
                try {
                    $this->exec('ALTER USER ' . $acct . ' IDENTIFIED BY ' . $this->quoteLiteral($password));
                } catch (\PDOException $e) {
                    $this->exec('SET PASSWORD FOR ' . $acct . ' = PASSWORD(' . $this->quoteLiteral($password) . ')');
                }
            }
            if ($clean && !$this->isCurrentAccount($user, $host)) {
                $this->exec('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $acct);
            }
        }
        foreach ($clean as $g) {
            $this->exec($g);
        }
    }

    private function isCurrentAccount(string $user, ?string $host): bool
    {
        $cur = (string) $this->fetchValue('SELECT CURRENT_USER()');
        $pos = strrpos($cur, '@');
        $cu = $pos === false ? $cur : substr($cur, 0, $pos);
        $ch = $pos === false ? '%' : substr($cur, $pos + 1);
        return $cu === $user && $ch === ($host === null || $host === '' ? '%' : $host);
    }

    public function dropUser(string $user, ?string $host): void
    {
        $this->exec('DROP USER ' . $this->account($user, $host));
    }

    public function collations(): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT COLLATION_NAME AS name, CHARACTER_SET_NAME AS charset, IS_DEFAULT AS dflt FROM information_schema.COLLATIONS ORDER BY COLLATION_NAME') as $r) {
            if ($r['charset'] === null) {
                continue;
            }
            $out[$r['name']] = ['name' => $r['name'], 'charset' => $r['charset'], 'default' => strtoupper((string) $r['dflt']) === 'YES'];
        }
        if ($this->mariaDb) {
            // MariaDB 10.10+: UCA-14 collations shared by several charsets
            try {
                foreach ($this->fetchAll('SELECT FULL_COLLATION_NAME AS name, CHARACTER_SET_NAME AS charset, IS_DEFAULT AS dflt FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY') as $r) {
                    if (!isset($out[$r['name']])) {
                        $out[$r['name']] = ['name' => $r['name'], 'charset' => $r['charset'], 'default' => strtoupper((string) $r['dflt']) === 'YES'];
                    } elseif (strtoupper((string) $r['dflt']) === 'YES') {
                        $out[$r['name']]['default'] = true;
                    }
                }
            } catch (\PDOException $e) {
            }
        }
        ksort($out);
        return array_values($out);
    }

    public function charsets(): array
    {
        return $this->fetchAll('SELECT CHARACTER_SET_NAME AS name, DESCRIPTION AS description, DEFAULT_COLLATE_NAME AS default_collation FROM information_schema.CHARACTER_SETS ORDER BY CHARACTER_SET_NAME');
    }

    public function engines(): array
    {
        $out = [];
        foreach ($this->executeRaw('SHOW ENGINES')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['name' => $r['Engine'], 'support' => $r['Support'], 'comment' => $r['Comment']];
        }
        return $out;
    }

    public function dataTypes(): array
    {
        $string = ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BINARY', 'VARBINARY',
            'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB', 'ENUM', 'SET'];
        $other = ['JSON'];
        if ($this->mariaDb) {
            $v = $this->numericVersion();
            if (version_compare($v, '10.5.0', '>=')) {
                $other[] = 'INET6';
            }
            if (version_compare($v, '10.7.0', '>=')) {
                $other[] = 'UUID';
            }
            if (version_compare($v, '10.10.0', '>=')) {
                $other[] = 'INET4';
            }
        }
        return [
            'Numeric' => ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BIT', 'BOOLEAN'],
            'Date and time' => ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR'],
            'String' => $string,
            'Spatial' => ['GEOMETRY', 'POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT', 'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION'],
            'Other' => $other,
        ];
    }

    public function completionWords(): array
    {
        return $this->mergeWords(parent::completionWords(), [
            'SHOW', 'DATABASES', 'TABLES', 'COLUMNS', 'PROCESSLIST', 'VARIABLES', 'STATUS', 'GRANTS', 'USE',
            'DESCRIBE', 'DESC', 'ENGINE', 'CHARSET', 'CHARACTER', 'COLLATE', 'AUTO_INCREMENT', 'UNSIGNED',
            'ZEROFILL', 'COMMENT', 'AFTER', 'FIRST', 'MODIFY', 'CHANGE', 'REPLACE', 'IGNORE', 'DUPLICATE',
            'LOW_PRIORITY', 'HIGH_PRIORITY', 'DELAYED', 'STRAIGHT_JOIN', 'SQL_CALC_FOUND_ROWS', 'FORCE',
            'LOCK', 'UNLOCK', 'TABLES', 'PROCEDURE', 'FUNCTION', 'EVENT', 'SCHEDULE', 'EVERY', 'DEFINER',
            'DELIMITER', 'CALL', 'DECLARE', 'HANDLER', 'CURSOR', 'LOOP', 'WHILE', 'REPEAT', 'LEAVE',
            'ITERATE', 'RETURNS', 'RETURN', 'DETERMINISTIC', 'OPTIMIZE', 'ANALYZE', 'CHECK', 'REPAIR',
            'FULLTEXT', 'SPATIAL', 'MATCH', 'AGAINST', 'REGEXP', 'RLIKE', 'XOR', 'DIV', 'MOD', 'INTERVAL',
            'KILL', 'FLUSH', 'PRIVILEGES', 'IDENTIFIED', 'USER', 'ROLE', 'GENERATED', 'ALWAYS', 'VIRTUAL',
            'STORED', 'TEMPORARY', 'DATABASE', 'SCHEMA', 'PARTITION', 'ROLLUP',
        ], [
            'CONCAT', 'CONCAT_WS', 'GROUP_CONCAT', 'IFNULL', 'IF', 'NOW', 'CURDATE', 'CURTIME', 'SYSDATE',
            'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATE_FORMAT', 'STR_TO_DATE', 'DATE_ADD', 'DATE_SUB',
            'DATEDIFF', 'TIMESTAMPDIFF', 'TIMESTAMPADD', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND',
            'WEEK', 'WEEKDAY', 'DAYOFWEEK', 'DAYNAME', 'MONTHNAME', 'LAST_DAY', 'EXTRACT', 'CONVERT',
            'CONVERT_TZ', 'CHAR_LENGTH', 'LEFT', 'RIGHT', 'LPAD', 'RPAD', 'LOCATE', 'INSTR', 'SUBSTRING_INDEX',
            'REVERSE', 'REPEAT', 'FORMAT', 'HEX', 'UNHEX', 'MD5', 'SHA1', 'SHA2', 'UUID', 'RAND', 'MOD',
            'POW', 'POWER', 'SQRT', 'GREATEST', 'LEAST', 'FIND_IN_SET', 'FIELD', 'ELT', 'JSON_EXTRACT',
            'JSON_UNQUOTE', 'JSON_OBJECT', 'JSON_ARRAY', 'JSON_CONTAINS', 'JSON_SET', 'JSON_REMOVE',
            'JSON_KEYS', 'JSON_LENGTH', 'JSON_VALID', 'JSON_TABLE', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG',
            'LAST_INSERT_ID', 'ROW_COUNT', 'FOUND_ROWS', 'DATABASE', 'USER', 'CURRENT_USER', 'VERSION',
            'CONNECTION_ID', 'INET_ATON', 'INET_NTOA', 'REGEXP_REPLACE', 'REGEXP_SUBSTR', 'REGEXP_LIKE',
            'BIT_LENGTH', 'OCT', 'BIN', 'TO_BASE64', 'FROM_BASE64', 'COMPRESS', 'UNCOMPRESS', 'SLEEP',
            'BENCHMARK', 'ANY_VALUE', 'NTILE', 'PERCENT_RANK', 'CUME_DIST', 'NTH_VALUE', 'ST_ASTEXT',
            'ST_GEOMFROMTEXT', 'ST_DISTANCE',
        ]);
    }
}
