<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Http\ApiException;
use PDO;

/**
 * SQLite 3 (pdo_sqlite). One file = one database. Path validation (allowed directories)
 * is the controller's job; this driver only refuses non-existing files unless
 * params['create'] is truthy.
 *
 * Notes:
 *  - Foreign key names are synthetic ("fk_<cols>"), SQLite does not expose constraint names.
 *  - The primary key index is always reported as name "PRIMARY" (drop_index "PRIMARY" works).
 *  - buildAlterTable() uses native ALTER TABLE where possible, otherwise the 12-step rebuild
 *    (PRAGMA foreign_keys=OFF; BEGIN; … COMMIT; PRAGMA foreign_keys=ON). The statements
 *    must be executed in order on the same connection.
 *  - TableDefinition.options may contain without_rowid / strict (bool).
 */
class SqliteDriver extends AbstractPdoDriver
{
    /** Above this file size listTables() does not COUNT(*) every table. */
    private const COUNT_ROWS_MAX_FILESIZE = 268435456;

    /** Marks the BEGIN of a rebuild; a failing statement afterwards triggers ROLLBACK (see query()). */
    private const REBUILD_MARKER = '/* ldb:rebuild */';

    private string $file = '';
    private bool $inRebuild = false;

    public static function id(): string
    {
        return 'sqlite';
    }

    public static function label(): string
    {
        return 'SQLite 3';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    public static function defaultPort(): ?int
    {
        return null;
    }

    protected function buildDsn(array $params): string
    {
        return 'sqlite:' . $this->file;
    }

    protected function pdoClass(): string
    {
        return SqlitePdo::class;
    }

    public function connect(array $params): void
    {
        $file = (string) ($params['file'] ?? ($params['database'] ?? ''));
        if ($file === '' || strpos($file, "\0") !== false) {
            throw new \PDOException('No SQLite database file given');
        }
        if (!is_file($file) && empty($params['create'])) {
            throw new \PDOException('SQLite database file not found: ' . basename($file));
        }
        $this->file = $file;
        unset($params['user'], $params['password']);
        parent::connect($params);
        $this->database = basename($file);
    }

    protected function pdoOptions(array $params): array
    {
        // PHP >= 8.1: open the file read-only at the OS level (the flag must be given up front).
        if ($this->readOnly && defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
            return [constant('PDO::SQLITE_ATTR_OPEN_FLAGS') => constant('PDO::SQLITE_OPEN_READONLY')];
        }
        return [];
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        // Works on every PHP version; PRAGMA is rejected by ReadOnlyGuard so it cannot be undone.
        $this->exec('PRAGMA query_only = ' . ($readOnly ? 'ON' : 'OFF'));
    }

    protected function afterConnect(array $params): void
    {
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('PRAGMA busy_timeout = ' . ($this->connectTimeout() * 1000));
    }

    public function file(): string
    {
        return $this->file;
    }

    public function select(?string $database, ?string $schema = null): void
    {
        // single-file database: nothing to switch
        $this->schema = null;
        $this->tableInfoCache = [];
    }

    public function quoteTable(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    /** Library version (or the simulated one, see simulateServerVersion()). */
    public function sqliteVersion(): string
    {
        return $this->serverVersion();
    }

    protected function rawServerVersion(): string
    {
        return (string) $this->fetchValue('SELECT sqlite_version()');
    }

    public function capabilities(): array
    {
        return [
            'schemas' => false,
            'users' => false,
            'processes' => false,
            'variables' => true,
            'status' => false,
            'engines' => false,
            'collations' => true,
            'charsets' => false,
            'routines' => false,
            'triggers' => true,
            'events' => false,
            'foreign_keys' => true,
            'alter_column' => true,
            'drop_column' => true,
            'rename_table' => true,
            'copy_table' => true,
            'unsigned' => false,
            'auto_increment' => true,
            'comments' => false,
            'maintenance' => true,
            'explain' => true,
            'create_database' => false,
            'kill' => false,
            'cancel' => false,
            'db_alter' => true,
            'db_copy' => false,
            'db_rename' => false,
            'monitor' => false,
            // version dependent (3.22 is the supported minimum; older features use the rebuild path)
            'check_constraints' => true,
            'rename_column' => $this->versionAtLeast('3.25.0'),      // else: table rebuild
            'drop_column_native' => $this->versionAtLeast('3.35.0'), // else: table rebuild
            'upsert' => $this->versionAtLeast('3.24.0'),
            'window_functions' => $this->versionAtLeast('3.25.0'),
            'generated_columns' => $this->versionAtLeast('3.31.0'),
            'returning' => $this->versionAtLeast('3.35.0'),
            'strict_tables' => $this->versionAtLeast('3.37.0'),
        ];
    }

    public function serverInfo(): array
    {
        return [
            'name' => 'SQLite',
            'version' => $this->sqliteVersion(),
            'version_comment' => 'file ' . basename($this->file),
            'host' => basename($this->file),
            'user' => null,
            'charset' => (string) $this->fetchValue('PRAGMA encoding'),
            'uptime' => null,
            'driver' => 'sqlite',
        ] + $this->versionSupport();
    }

    public function listDatabases(): array
    {
        $n = (int) $this->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite!_%' ESCAPE '!'");
        clearstatcache(true, $this->file);
        return [[
            'name' => basename($this->file),
            'collation' => null,
            'tables' => $n,
            'size' => is_file($this->file) ? (int) filesize($this->file) : null,
        ]];
    }

    // ------------------------------------------------------------------ database settings

    /** PRAGMA values db.alter may set (anything else is refused). */
    public const PRAGMA_WHITELIST = [
        'journal_mode' => ['delete', 'truncate', 'persist', 'wal'],
        'foreign_keys' => 'bool',
        'auto_vacuum' => ['none', 'full', 'incremental'],
        'user_version' => 'int32',
    ];

    private const AUTO_VACUUM_NAMES = [0 => 'none', 1 => 'full', 2 => 'incremental'];

    /** @return array<string,mixed> current PRAGMA values used by the settings page */
    private function pragmaValues(): array
    {
        $get = function (string $p) {
            $v = $this->fetchValue('PRAGMA ' . $p);
            return $v === null ? null : $v;
        };
        return [
            'page_size' => (int) $get('page_size'),
            'page_count' => (int) $get('page_count'),
            'freelist_count' => (int) $get('freelist_count'),
            'journal_mode' => strtolower((string) $get('journal_mode')),
            'foreign_keys' => (bool) (int) $get('foreign_keys'),
            'auto_vacuum' => self::AUTO_VACUUM_NAMES[(int) $get('auto_vacuum')] ?? 'none',
            'user_version' => (int) $get('user_version'),
            'application_id' => (int) $get('application_id'),
            'encoding' => (string) $get('encoding'),
        ];
    }

    public function databaseInfo(string $name): array
    {
        clearstatcache(true, $this->file);
        $tables = (int) $this->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'");
        $views = (int) $this->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type = 'view'");
        return [
            'driver' => 'sqlite',
            'name' => basename($this->file),
            'file' => $this->file,
            'size' => is_file($this->file) ? (int) filesize($this->file) : null,
            'writable' => is_writable($this->file) && is_writable(dirname($this->file)),
            'sqlite_version' => $this->sqliteVersion(),
            'settings' => $this->pragmaValues(),
            'table_count' => $tables,
            'view_count' => $views,
        ];
    }

    public function buildAlterDatabase(string $name, array $changes): array
    {
        return $this->planAlterDatabase($changes, $this->pragmaValues());
    }

    /**
     * Pure SQL generation: whitelisted PRAGMAs (values never interpolated unchecked) and an
     * optional VACUUM (needed for an auto_vacuum switch between NONE and FULL/INCREMENTAL).
     * @param array<string,mixed> $current pragma values (pragmaValues() shape)
     * @return array{sql:string[],warnings:string[]}
     */
    public function planAlterDatabase(array $changes, array $current = []): array
    {
        self::assertChangeKeys($changes, ['pragmas', 'vacuum']);
        $pragmas = $changes['pragmas'] ?? [];
        if (!is_array($pragmas)) {
            throw ApiException::badRequest('Invalid value for pragmas');
        }
        $sql = [];
        $warnings = [];
        $autoVacuumChanged = false;
        foreach ($pragmas as $name => $value) {
            $name = (string) $name;
            if (!isset(self::PRAGMA_WHITELIST[$name])) {
                throw ApiException::badRequest('PRAGMA not allowed here: ' . $name);
            }
            $rule = self::PRAGMA_WHITELIST[$name];
            if ($rule === 'bool') {
                $b = self::changeBool([$name => $value], $name);
                if ($b === null) {
                    throw ApiException::badRequest('Invalid value for ' . $name);
                }
                $lit = $b ? 'ON' : 'OFF';
            } elseif ($rule === 'int32') {
                $lit = (string) self::changeInt([$name => $value], $name, -2147483648, 2147483647);
                if ($lit === '') {
                    throw ApiException::badRequest('Invalid value for ' . $name);
                }
            } else {
                $v = is_string($value) ? strtolower(trim($value)) : '';
                if (!in_array($v, $rule, true)) {
                    throw ApiException::badRequest('Invalid value for ' . $name . ' (allowed: ' . implode(', ', $rule) . ')');
                }
                $lit = strtoupper($v);
                if ($name === 'auto_vacuum') {
                    $was = (string) ($current['auto_vacuum'] ?? 'none');
                    $autoVacuumChanged = $v !== $was && ($v === 'none' || $was === 'none');
                }
            }
            $sql[] = 'PRAGMA ' . $name . ' = ' . $lit;
            if ($name === 'foreign_keys') {
                $warnings[] = 'sqlite_foreign_keys_per_connection';
            }
            if ($name === 'journal_mode' && $lit === 'WAL') {
                $warnings[] = 'sqlite_wal_files';
            }
        }
        if (self::changeBool($changes, 'vacuum')) {
            $sql[] = 'VACUUM';
            $warnings[] = 'sqlite_vacuum_rewrites';
        } elseif ($autoVacuumChanged) {
            $warnings[] = 'sqlite_auto_vacuum_needs_vacuum';
        }
        return ['sql' => $sql, 'warnings' => array_values(array_unique($warnings))];
    }

    // ------------------------------------------------------------------ objects

    private function hasTable(string $name): bool
    {
        return $this->fetchValue("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]) !== null;
    }

    private function autoIncrementValue(string $table): ?int
    {
        if (!$this->hasTable('sqlite_sequence')) {
            return null;
        }
        $v = $this->fetchValue('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);
        return $v === null ? null : (int) $v + 1;
    }

    public function listTables(): array
    {
        $rows = $this->fetchAll("SELECT name, type, tbl_name FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite!_%' ESCAPE '!' ORDER BY name");
        clearstatcache(true, $this->file);
        $count = is_file($this->file) && filesize($this->file) <= self::COUNT_ROWS_MAX_FILESIZE;
        $sizes = [];
        $idxSizes = [];
        try {
            $owner = [];
            foreach ($this->fetchAll("SELECT name, tbl_name FROM sqlite_master WHERE type = 'index'") as $ix) {
                $owner[$ix['name']] = $ix['tbl_name'];
            }
            foreach ($this->fetchAll('SELECT name, SUM(pgsize) AS s FROM dbstat GROUP BY name') as $r) {
                if (isset($owner[$r['name']])) {
                    $t = $owner[$r['name']];
                    $idxSizes[$t] = ($idxSizes[$t] ?? 0) + (int) $r['s'];
                } else {
                    $sizes[$r['name']] = (int) $r['s'];
                }
            }
        } catch (\PDOException $e) {
            // dbstat virtual table not compiled in
        }
        $seq = [];
        if ($this->hasTable('sqlite_sequence')) {
            foreach ($this->fetchAll('SELECT name, seq FROM sqlite_sequence') as $r) {
                $seq[$r['name']] = (int) $r['seq'] + 1;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $isView = $r['type'] === 'view';
            $n = null;
            if (!$isView && $count) {
                try {
                    $n = (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($r['name']));
                } catch (\PDOException $e) {
                    $n = null;
                }
            }
            $out[] = [
                'name' => $r['name'],
                'type' => $isView ? 'view' : 'table',
                'rows' => $n,
                'data_size' => $sizes[$r['name']] ?? null,
                'index_size' => $isView ? null : ($idxSizes[$r['name']] ?? ($sizes ? 0 : null)),
                'engine' => null,
                'collation' => null,
                'comment' => null,
                'auto_increment' => $seq[$r['name']] ?? null,
                'updated' => null,
            ];
        }
        return $out;
    }

    public function listTriggers(): array
    {
        $out = [];
        foreach ($this->fetchAll("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY tbl_name, name") as $r) {
            $t = $this->parseTrigger((string) $r['sql']);
            $out[] = ['name' => $r['name'], 'table' => $r['tbl_name'], 'timing' => $t['timing'], 'event' => $t['event'], 'statement' => $t['statement']];
        }
        return $out;
    }

    /** {timing, event, statement} from CREATE TRIGGER sql. */
    private function parseTrigger(string $sql): array
    {
        $timing = 'BEFORE';
        $event = '';
        if (preg_match('/\bTRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")*"|`[^`]*`|\[[^\]]*\]|[^\s(]+)\s+(BEFORE|AFTER|INSTEAD\s+OF)?\s*(INSERT|UPDATE|DELETE)\b/is', $sql, $m)) {
            $timing = $m[1] !== '' ? strtoupper(preg_replace('/\s+/', ' ', $m[1])) : 'BEFORE';
            $event = strtoupper($m[2]);
        }
        $stmt = $sql;
        if (preg_match('/\bBEGIN\b(.*)\bEND\s*;?\s*$/is', $sql, $m)) {
            $stmt = trim($m[1]);
        }
        return ['timing' => $timing, 'event' => $event, 'statement' => $stmt];
    }

    private function masterRow(string $name, array $types): ?array
    {
        $in = implode(',', array_fill(0, count($types), '?'));
        return $this->fetchOne('SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name = ? AND type IN (' . $in . ')', array_merge([$name], $types));
    }

    public function tableInfo(string $table): array
    {
        $master = $this->masterRow($table, ['table', 'view']);
        if ($master === null) {
            throw $this->notFound('Table ' . $table);
        }
        $isView = $master['type'] === 'view';
        $parsed = $isView ? ['columns' => [], 'constraints' => [], 'without_rowid' => false, 'strict' => false]
            : self::parseCreateTable((string) $master['sql']);
        $qt = $this->quoteLiteral($table);

        $pragmaCols = [];
        if ($this->versionAtLeast('3.26.0')) {
            try {
                $pragmaCols = $this->fetchAll('PRAGMA table_xinfo(' . $qt . ')');
            } catch (\PDOException $e) {
                $pragmaCols = [];
            }
        }
        if ($pragmaCols === []) {
            // table_xinfo (hidden/generated columns) needs 3.26; older libraries ignore the
            // unknown PRAGMA silently, so an empty result falls back to table_info.
            $pragmaCols = $this->fetchAll('PRAGMA table_info(' . $qt . ')');
        }
        $pkCols = [];
        foreach ($pragmaCols as $c) {
            if ((int) $c['pk'] > 0) {
                $pkCols[(int) $c['pk']] = $c['name'];
            }
        }
        ksort($pkCols);
        $pkCols = array_values($pkCols);

        $columns = [];
        foreach ($pragmaCols as $c) {
            $hidden = (int) ($c['hidden'] ?? 0);
            if ($hidden === 1) {
                continue; // hidden column of a virtual table
            }
            $declared = (string) $c['type'];
            [$base, $length] = self::splitType($declared);
            $p = $parsed['columns'][strtolower((string) $c['name'])] ?? [];
            [$kind, $default] = self::classifyDefault($c['dflt_value'], !((int) $c['notnull']));
            $isRowid = count($pkCols) === 1 && $pkCols[0] === $c['name'] && strtoupper($declared) === 'INTEGER' && !$parsed['without_rowid'];
            $generated = $hidden >= 2 ? ($p['generated'] ?? '') : null;
            $extra = [];
            if (!empty($p['autoincrement'])) {
                $extra[] = 'AUTOINCREMENT';
            }
            if ($hidden === 2) {
                $extra[] = 'VIRTUAL GENERATED';
            } elseif ($hidden === 3) {
                $extra[] = 'STORED GENERATED';
            }
            $columns[] = [
                'name' => $c['name'],
                'type' => strtolower($base),
                'full_type' => $declared,
                'length' => $length,
                'unsigned' => stripos($declared, 'unsigned') !== false,
                'nullable' => !((int) $c['notnull']) && !$isRowid,
                'default' => $default,
                'default_kind' => $generated !== null ? 'none' : $kind,
                'auto_increment' => $isRowid,
                'primary' => in_array($c['name'], $pkCols, true),
                'comment' => null,
                'collation' => $p['collation'] ?? null,
                'extra' => $extra ? implode(' ', $extra) : null,
                'generated' => $generated,
            ];
        }

        $indexes = $isView ? [] : $this->indexes($table, $pkCols);
        $fks = $isView ? [] : $this->foreignKeysOf($table);

        $refBy = [];
        foreach ($this->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'") as $t) {
            foreach ($this->foreignKeysOf($t['name']) as $fk) {
                if (strcasecmp($fk['ref_table'], $table) === 0) {
                    $fk['table'] = $t['name'];
                    $refBy[] = $fk;
                }
            }
        }

        $triggers = [];
        foreach ($this->fetchAll("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY name", [$table]) as $tr) {
            $p = $this->parseTrigger((string) $tr['sql']);
            $triggers[] = ['name' => $tr['name'], 'timing' => $p['timing'], 'event' => $p['event'], 'statement' => $p['statement']];
        }

        $rows = null;
        if (!$isView) {
            try {
                $rows = (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table));
            } catch (\PDOException $e) {
            }
        }

        return [
            'name' => $master['name'],
            'type' => $isView ? 'view' : 'table',
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $fks,
            'referenced_by' => $refBy,
            'triggers' => $triggers,
            'options' => [
                'engine' => null,
                'collation' => null,
                'comment' => null,
                'rows' => $rows,
                'auto_increment' => $isView ? null : $this->autoIncrementValue($table),
                'without_rowid' => $parsed['without_rowid'],
                'strict' => $parsed['strict'],
            ],
        ];
    }

    /** [kind, default] from PRAGMA table_info.dflt_value (SQL text). */
    private static function classifyDefault($dflt, bool $nullable): array
    {
        if ($dflt === null) {
            return [$nullable ? 'null' : 'none', null];
        }
        $d = trim((string) $dflt);
        if (strtoupper($d) === 'NULL') {
            return ['null', null];
        }
        if (strlen($d) >= 2 && $d[0] === "'" && substr($d, -1) === "'") {
            return ['value', str_replace("''", "'", substr($d, 1, -1))];
        }
        if (is_numeric($d)) {
            return ['value', $d];
        }
        if (preg_match('/^[+-]?\s*\d/', $d) && is_numeric(preg_replace('/\s+/', '', $d))) {
            return ['value', preg_replace('/\s+/', '', $d)];
        }
        if (strtoupper($d) === 'TRUE' || strtoupper($d) === 'FALSE') {
            return ['value', strtoupper($d) === 'TRUE' ? '1' : '0'];
        }
        return ['expression', $d];
    }

    private function indexes(string $table, array $pkCols): array
    {
        $out = [];
        $hasPkIndex = false;
        $sqlByName = [];
        foreach ($this->fetchAll("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$table]) as $r) {
            $sqlByName[$r['name']] = $r['sql'];
        }
        foreach ($this->fetchAll('PRAGMA index_list(' . $this->quoteLiteral($table) . ')') as $ix) {
            $cols = [];
            foreach ($this->fetchAll('PRAGMA index_info(' . $this->quoteLiteral((string) $ix['name']) . ')') as $ii) {
                $cols[] = $ii['name'] === null ? '(expression)' : $ii['name'];
            }
            $origin = (string) ($ix['origin'] ?? 'c');
            $primary = $origin === 'pk';
            if ($primary) {
                $hasPkIndex = true;
            }
            $out[] = [
                'name' => $primary ? 'PRIMARY' : $ix['name'],
                'columns' => $cols,
                'unique' => (int) $ix['unique'] === 1,
                'primary' => $primary,
                'type' => null,
                'origin' => $origin,
                'partial' => (int) ($ix['partial'] ?? 0) === 1,
                'sql' => $sqlByName[$ix['name']] ?? null,
            ];
        }
        if (!$hasPkIndex && $pkCols) {
            // INTEGER PRIMARY KEY (rowid alias) has no separate index
            array_unshift($out, ['name' => 'PRIMARY', 'columns' => $pkCols, 'unique' => true, 'primary' => true, 'type' => null, 'origin' => 'pk', 'partial' => false, 'sql' => null]);
        } else {
            usort($out, function ($a, $b) {
                return ($b['primary'] <=> $a['primary']) ?: strcmp((string) $a['name'], (string) $b['name']);
            });
        }
        return $out;
    }

    private function foreignKeysOf(string $table): array
    {
        $groups = [];
        foreach ($this->fetchAll('PRAGMA foreign_key_list(' . $this->quoteLiteral($table) . ')') as $r) {
            $id = (int) $r['id'];
            if (!isset($groups[$id])) {
                $groups[$id] = ['columns' => [], 'ref_table' => $r['table'], 'ref_columns' => [], 'on_delete' => $r['on_delete'], 'on_update' => $r['on_update']];
            }
            $groups[$id]['columns'][] = $r['from'];
            $groups[$id]['ref_columns'][] = $r['to'];
        }
        ksort($groups);
        $out = [];
        $used = [];
        foreach (array_reverse($groups) as $g) {
            if (in_array(null, $g['ref_columns'], true)) {
                // REFERENCES t without column list → primary key of t
                $pk = [];
                try {
                    foreach ($this->fetchAll('PRAGMA table_info(' . $this->quoteLiteral($g['ref_table']) . ')') as $c) {
                        if ((int) $c['pk'] > 0) {
                            $pk[(int) $c['pk']] = $c['name'];
                        }
                    }
                } catch (\PDOException $e) {
                }
                ksort($pk);
                $g['ref_columns'] = array_values($pk);
            }
            $name = 'fk_' . implode('_', $g['columns']);
            $base = $name;
            $i = 2;
            while (isset($used[$name])) {
                $name = $base . '_' . $i++;
            }
            $used[$name] = true;
            $out[] = [
                'name' => $name,
                'columns' => $g['columns'],
                'ref_schema' => null,
                'ref_table' => $g['ref_table'],
                'ref_columns' => $g['ref_columns'],
                'on_delete' => $g['on_delete'],
                'on_update' => $g['on_update'],
            ];
        }
        return $out;
    }

    public function createStatement(string $name, string $type = 'table'): string
    {
        $type = strtolower($type);
        if (!in_array($type, ['table', 'view', 'trigger', 'index'], true)) {
            throw $this->unsupported(ucfirst($type) . ' definitions');
        }
        $row = $this->masterRow($name, $type === 'table' ? ['table', 'view'] : [$type]);
        if ($row === null || $row['sql'] === null) {
            throw $this->notFound(ucfirst($type) . ' ' . $name);
        }
        $sql = (string) $row['sql'];
        if ($row['type'] === 'table') {
            // append the table's explicit indexes and triggers, like SHOW CREATE in spirit
            $extra = [];
            foreach ($this->fetchAll("SELECT sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index','trigger') AND sql IS NOT NULL ORDER BY type, name", [$name]) as $r) {
                $extra[] = $r['sql'] . ';';
            }
            if ($extra && $type === 'table') {
                return $sql . ";\n" . implode("\n", $extra);
            }
        }
        return $sql;
    }

    // ------------------------------------------------------------------ browse helpers

    protected function castToText(string $quotedColumn, array $col): ?string
    {
        if (preg_match('/blob/i', (string) ($col['type'] ?? ''))) {
            return null;
        }
        return $quotedColumn; // LIKE converts any value to text
    }

    public function isTextType(string $type): bool
    {
        return $type === '' || parent::isTextType($type);
    }

    protected function supportsMultipleRowsets(): bool
    {
        return false;
    }

    public function explain(string $sql): array
    {
        return $this->query('EXPLAIN QUERY PLAN ' . $sql, 10000);
    }

    /**
     * Same as the base implementation, plus: if a statement of a table rebuild (as produced by
     * buildAlterTable) fails, the rebuild transaction is rolled back and foreign keys re-enabled,
     * so the connection is left in a clean state.
     */
    public function query(string $sql, int $limit): array
    {
        $trim = trim($sql);
        if (strpos($trim, self::REBUILD_MARKER) !== false && preg_match('/^BEGIN\b/i', $trim)) {
            $this->inRebuild = true;
        } elseif ($this->inRebuild && preg_match('/^(COMMIT|END|ROLLBACK)\b/i', $trim)) {
            $this->inRebuild = false;
        }
        try {
            return parent::query($sql, $limit);
        } catch (\Throwable $e) {
            if ($this->inRebuild) {
                $this->inRebuild = false;
                try {
                    $this->exec('ROLLBACK');
                } catch (\Throwable $e2) {
                }
                try {
                    $this->exec('PRAGMA legacy_alter_table = OFF');
                    $this->exec('PRAGMA foreign_keys = ON');
                } catch (\Throwable $e2) {
                }
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ DDL

    /** Column SQL. $inlinePkAutoinc adds "PRIMARY KEY AUTOINCREMENT". $checks: extra CHECK(...) clauses. */
    private function columnSql(array $col, bool $inlinePkAutoinc = false, array $checks = []): string
    {
        $sql = $this->quoteIdentifier((string) ($col['name'] ?? ''));
        $type = trim((string) ($col['type'] ?? ''));
        if ($inlinePkAutoinc) {
            return $sql . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if ($type !== '') {
            $sql .= ' ' . $this->renderType($col);
        }
        $generated = isset($col['generated']) ? trim((string) $col['generated']) : '';
        if (empty($col['nullable']) && !$inlinePkAutoinc) {
            $sql .= ' NOT NULL';
        }
        if ($generated === '') {
            $sql .= $this->renderDefaultClause($col);
        }
        if (!empty($col['collation'])) {
            $sql .= ' COLLATE ' . $this->assertWord((string) $col['collation'], 'collation');
        }
        foreach ($checks as $ch) {
            $sql .= ' ' . $ch;
        }
        if ($generated !== '') {
            if (!$this->versionAtLeast('3.31.0')) {
                throw $this->needsVersion('Generated columns', 'SQLite 3.31');
            }
            $stored = !empty($col['stored']) || stripos((string) ($col['extra'] ?? ''), 'STORED') !== false;
            $sql .= ' GENERATED ALWAYS AS (' . $generated . ') ' . ($stored ? 'STORED' : 'VIRTUAL');
        }
        return $sql;
    }

    protected function renderDefaultClause(array $col): string
    {
        $kind = (string) ($col['default_kind'] ?? 'none');
        if ($kind === 'value') {
            $v = $col['default'] ?? '';
            if ((is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)))
                && preg_match('/int|real|num|dec|float|double|bool/i', (string) ($col['type'] ?? ''))) {
                return ' DEFAULT ' . $v;
            }
            return ' DEFAULT ' . $this->quoteLiteral(is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }
        if ($kind === 'expression') {
            $e = trim((string) ($col['default'] ?? ''));
            if ($e === '') {
                return '';
            }
            if (preg_match('/^(current_timestamp|current_date|current_time)$/i', $e) || $e[0] === '(' || is_numeric($e)) {
                return ' DEFAULT ' . $e;
            }
            return ' DEFAULT (' . $e . ')';
        }
        return parent::renderDefaultClause($col);
    }

    private function isAutoincPk(array $col, array $pk): bool
    {
        return !empty($col['auto_increment']) && count($pk) === 1 && $pk[0] === ($col['name'] ?? null);
    }

    private function indexSql(string $table, array $ix): string
    {
        $cols = (array) ($ix['columns'] ?? []);
        if (!$cols) {
            throw new ApiException('Index needs at least one column', 'bad_request', 400);
        }
        $name = !empty($ix['name']) ? (string) $ix['name'] : 'idx_' . $table . '_' . implode('_', $cols);
        return 'CREATE ' . (!empty($ix['unique']) ? 'UNIQUE ' : '') . 'INDEX ' . $this->quoteIdentifier($name)
            . ' ON ' . $this->quoteIdentifier($table) . ' (' . $this->columnList($cols) . ')';
    }

    private function fkSql(array $fk): string
    {
        $ref = $this->quoteIdentifier((string) ($fk['ref_table'] ?? ''));
        return $this->renderForeignKeyBody($fk, $ref);
    }

    /**
     * CREATE TABLE body.
     * @param array $cols list of [def(ColumnDefinition), checks(string[])]
     */
    private function createTableSql(string $name, array $cols, array $pk, array $uniques, array $fks, array $tableChecks, bool $withoutRowid, bool $strict): string
    {
        $lines = [];
        $inline = false;
        foreach ($cols as $c) {
            $def = $c[0];
            $isAi = $this->isAutoincPk($def, $pk) && !$withoutRowid;
            if ($isAi) {
                $inline = true;
            }
            $lines[] = '  ' . $this->columnSql($def, $isAi, $c[1] ?? []);
        }
        if ($pk && !$inline) {
            $lines[] = '  PRIMARY KEY (' . $this->columnList($pk) . ')';
        }
        foreach ($uniques as $u) {
            $lines[] = '  UNIQUE (' . $this->columnList($u) . ')';
        }
        foreach ($fks as $fk) {
            $lines[] = '  ' . $this->fkSql($fk);
        }
        foreach ($tableChecks as $ch) {
            $lines[] = '  ' . $ch;
        }
        $sql = 'CREATE TABLE ' . $this->quoteIdentifier($name) . " (\n" . implode(",\n", $lines) . "\n)";
        $opts = [];
        if ($withoutRowid) {
            $opts[] = 'WITHOUT ROWID';
        }
        if ($strict) {
            $opts[] = 'STRICT';
        }
        return $sql . ($opts ? ' ' . implode(', ', $opts) : '');
    }

    public function buildCreateTable(array $definition): array
    {
        $name = (string) ($definition['name'] ?? '');
        if ($name === '' || empty($definition['columns'])) {
            throw new ApiException('A table needs a name and at least one column', 'bad_request', 400);
        }
        $pk = array_values((array) ($definition['primary_key'] ?? []));
        $uniqueIdx = [];
        $other = [];
        foreach ((array) ($definition['indexes'] ?? []) as $ix) {
            if (!empty($ix['primary'])) {
                $pk = array_values((array) $ix['columns']);
            } else {
                $other[] = $ix;
            }
        }
        $cols = [];
        foreach ($definition['columns'] as $c) {
            $cols[] = [(array) $c, []];
        }
        $opts = (array) ($definition['options'] ?? []);
        if (!empty($opts['strict']) && !$this->versionAtLeast('3.37.0')) {
            throw $this->needsVersion('STRICT tables', 'SQLite 3.37');
        }
        $out = [$this->createTableSql($name, $cols, $pk, $uniqueIdx, (array) ($definition['foreign_keys'] ?? []), [], !empty($opts['without_rowid']), !empty($opts['strict']))];
        foreach ($other as $ix) {
            $out[] = $this->indexSql($name, $ix);
        }
        return $out;
    }

    /** Definition of an existing column usable with columnSql(). */
    private static function columnToDef(array $c): array
    {
        return [
            'name' => $c['name'],
            'type' => $c['full_type'] === '' ? '' : self::splitType($c['full_type'])[0],
            'length' => $c['length'],
            'unsigned' => false,
            'nullable' => $c['nullable'] || ($c['auto_increment'] && stripos((string) $c['extra'], 'AUTOINCREMENT') === false),
            'default_kind' => $c['default_kind'],
            'default' => $c['default'],
            'auto_increment' => stripos((string) $c['extra'], 'AUTOINCREMENT') !== false,
            'comment' => null,
            'collation' => $c['collation'],
            'generated' => $c['generated'],
            'extra' => $c['extra'],
        ];
    }

    public function buildAlterTable(string $table, array $spec): array
    {
        $this->forgetTableInfo();
        $info = $this->tableInfo($table);
        if ($info['type'] !== 'table') {
            throw $this->unsupported('Altering views');
        }
        $master = $this->masterRow($table, ['table']);
        $parsed = self::parseCreateTable((string) $master['sql']);
        $colsByName = [];
        foreach ($info['columns'] as $c) {
            $colsByName[$c['name']] = $c;
        }
        $pk = [];
        foreach ($info['indexes'] as $ix) {
            if ($ix['primary']) {
                $pk = $ix['columns'];
            }
        }
        $indexByName = [];
        foreach ($info['indexes'] as $ix) {
            $indexByName[$ix['name']] = $ix;
        }

        $native = [];
        $after = []; // CREATE INDEX statements run after the main work
        $rebuild = false;
        $v = $this->serverVersion();

        // modify
        foreach ((array) ($spec['modify'] ?? []) as $m) {
            $old = (string) ($m['column'] ?? '');
            if (!isset($colsByName[$old])) {
                throw new ApiException('Unknown column: ' . $old, 'bad_request', 400);
            }
            $def = (array) ($m['definition'] ?? []);
            $newName = isset($def['name']) && $def['name'] !== '' ? (string) $def['name'] : $old;
            $cur = self::columnToDef($colsByName[$old]);
            $cmpNew = $def;
            $cmpNew['name'] = $old;
            $sameDef = $this->columnSql($cur) === $this->columnSql($cmpNew + ['generated' => $cur['generated'], 'extra' => $cur['extra']]);
            $moves = !empty($def['first']) || !empty($m['first']) || (isset($def['after']) && $def['after'] !== null && $def['after'] !== '') || (isset($m['after']) && $m['after'] !== null && $m['after'] !== '');
            if ($sameDef && !$moves && version_compare($v, '3.25.0', '>=')) {
                if ($newName !== $old) {
                    $native[] = 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' RENAME COLUMN ' . $this->quoteIdentifier($old) . ' TO ' . $this->quoteIdentifier($newName);
                }
            } else {
                $rebuild = true;
            }
        }
        // drop column
        foreach ((array) ($spec['drop'] ?? []) as $d) {
            $d = (string) $d;
            if (!isset($colsByName[$d])) {
                throw new ApiException('Unknown column: ' . $d, 'bad_request', 400);
            }
            $blocked = in_array($d, $pk, true) || version_compare($v, '3.35.0', '<');
            foreach ($info['indexes'] as $ix) {
                if (in_array($d, $ix['columns'], true) || ($ix['sql'] !== null && self::mentions((string) $ix['sql'], $d))) {
                    $blocked = true;
                }
            }
            foreach ($info['foreign_keys'] as $fk) {
                if (in_array($d, $fk['columns'], true)) {
                    $blocked = true;
                }
            }
            foreach ($info['referenced_by'] as $fk) {
                if (strcasecmp($fk['table'], $table) === 0 && in_array($d, $fk['ref_columns'], true)) {
                    $blocked = true;
                }
            }
            foreach ($parsed['constraints'] as $con) {
                if (self::mentions($con, $d)) {
                    $blocked = true;
                }
            }
            foreach ($info['columns'] as $c) {
                if ($c['generated'] !== null && self::mentions($c['generated'], $d)) {
                    $blocked = true;
                }
            }
            if ($blocked) {
                $rebuild = true;
            } else {
                $native[] = 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP COLUMN ' . $this->quoteIdentifier($d);
            }
        }
        // add column
        foreach ((array) ($spec['add'] ?? []) as $a) {
            $a = (array) $a;
            $kind = (string) ($a['default_kind'] ?? 'none');
            $ok = empty($a['first']) && (!isset($a['after']) || $a['after'] === null || $a['after'] === '')
                && empty($a['auto_increment'])
                && $kind !== 'expression'
                && (!empty($a['nullable']) || $kind === 'value')
                && !(isset($a['generated']) && trim((string) $a['generated']) !== '' && (!empty($a['stored']) || stripos((string) ($a['extra'] ?? ''), 'STORED') !== false));
            if ($ok) {
                $native[] = 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD COLUMN ' . $this->columnSql($a);
            } else {
                $rebuild = true;
            }
        }
        // indexes
        foreach ((array) ($spec['drop_index'] ?? []) as $n) {
            $n = (string) $n;
            $ix = $indexByName[$n] ?? null;
            if ($ix === null) {
                throw new ApiException('Unknown index: ' . $n, 'bad_request', 400);
            }
            if ($ix['origin'] === 'c') {
                $native[] = 'DROP INDEX ' . $this->quoteIdentifier($n);
            } else {
                $rebuild = true;
            }
        }
        foreach ((array) ($spec['add_index'] ?? []) as $ix) {
            if (!empty($ix['primary'])) {
                $rebuild = true;
            } else {
                $after[] = $this->indexSql($table, (array) $ix);
            }
        }
        if (!empty($spec['add_fk']) || !empty($spec['drop_fk'])) {
            $rebuild = true;
        }
        $opts = (array) ($spec['options'] ?? []);
        if (isset($opts['auto_increment']) && $opts['auto_increment'] !== null && $opts['auto_increment'] !== '') {
            if (!$this->hasTable('sqlite_sequence') || stripos((string) $master['sql'], 'AUTOINCREMENT') === false) {
                throw $this->unsupported('Setting AUTO_INCREMENT on a table without AUTOINCREMENT column');
            }
            $seq = max(0, (int) $opts['auto_increment'] - 1);
            $after[] = 'DELETE FROM sqlite_sequence WHERE name = ' . $this->quoteLiteral($table);
            $after[] = 'INSERT INTO sqlite_sequence (name, seq) VALUES (' . $this->quoteLiteral($table) . ', ' . $seq . ')';
        }

        if (!$rebuild) {
            return array_merge($native, $after);
        }
        return array_merge($this->rebuildStatements($table, $spec, $info, $parsed, $pk), $after);
    }

    /** Whether $sql mentions identifier $name as a word (case-insensitive). */
    private static function mentions(string $sql, string $name): bool
    {
        return (bool) preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($name, '/') . '(?![A-Za-z0-9_$])/i', $sql);
    }

    /** The SQLite "12-step" generalized ALTER TABLE procedure. */
    private function rebuildStatements(string $table, array $spec, array $info, array $parsed, array $pk): array
    {
        // working column list: [def, source(old name|null), checks]
        $cols = [];
        foreach ($info['columns'] as $c) {
            $p = $parsed['columns'][strtolower($c['name'])] ?? [];
            $cols[] = ['def' => self::columnToDef($c), 'source' => $c['name'], 'checks' => $p['checks'] ?? []];
        }
        $dropped = array_map('strval', (array) ($spec['drop'] ?? []));
        $cols = array_values(array_filter($cols, function ($e) use ($dropped) {
            return !in_array($e['source'], $dropped, true);
        }));
        $rename = [];
        $place = function (array &$cols, array $entry, array $pos) {
            if (!empty($pos['first'])) {
                array_unshift($cols, $entry);
                return;
            }
            if (isset($pos['after']) && $pos['after'] !== null && $pos['after'] !== '') {
                foreach ($cols as $i => $e) {
                    if ($e['def']['name'] === $pos['after']) {
                        array_splice($cols, $i + 1, 0, [$entry]);
                        return;
                    }
                }
                throw new ApiException('Unknown column for AFTER: ' . $pos['after'], 'bad_request', 400);
            }
            $cols[] = $entry;
        };
        foreach ((array) ($spec['modify'] ?? []) as $m) {
            $old = (string) $m['column'];
            $def = (array) ($m['definition'] ?? []);
            if (!isset($def['name']) || $def['name'] === '') {
                $def['name'] = $old;
            }
            foreach ($cols as $i => $e) {
                if ($e['source'] === $old) {
                    $entry = $e;
                    $entry['def'] = $def + ['generated' => $e['def']['generated'], 'extra' => $e['def']['extra']];
                    $rename[$old] = (string) $def['name'];
                    $pos = $m + $def;
                    if (!empty($pos['first']) || (isset($pos['after']) && $pos['after'] !== null && $pos['after'] !== '')) {
                        array_splice($cols, $i, 1);
                        $place($cols, $entry, $pos);
                    } else {
                        $cols[$i] = $entry;
                    }
                    break;
                }
            }
        }
        foreach ((array) ($spec['add'] ?? []) as $a) {
            $place($cols, ['def' => (array) $a, 'source' => null, 'checks' => []], (array) $a);
        }
        $map = function (array $names) use ($rename, $dropped): ?array {
            $out = [];
            foreach ($names as $n) {
                if (in_array($n, $dropped, true)) {
                    return null;
                }
                $out[] = $rename[$n] ?? $n;
            }
            return $out;
        };

        $dropIdx = array_map('strval', (array) ($spec['drop_index'] ?? []));
        // primary key
        $newPk = in_array('PRIMARY', $dropIdx, true) ? [] : (array) ($map($pk) ?? array_values(array_diff($pk, $dropped)));
        foreach ((array) ($spec['add_index'] ?? []) as $ix) {
            if (!empty($ix['primary'])) {
                $newPk = array_values((array) $ix['columns']);
            }
        }
        // unique constraints (autoindexes) and explicit indexes
        $uniques = [];
        $recreate = [];
        foreach ($info['indexes'] as $ix) {
            if ($ix['primary'] || in_array($ix['name'], $dropIdx, true)) {
                continue;
            }
            $mapped = $map($ix['columns']);
            if ($mapped === null || in_array('(expression)', $ix['columns'], true) || $ix['partial']) {
                // expression/partial index: recreate verbatim if it does not mention dropped columns
                if ($ix['sql'] !== null && $ix['origin'] === 'c') {
                    $ok = true;
                    foreach ($dropped as $d) {
                        if (self::mentions($ix['sql'], $d)) {
                            $ok = false;
                        }
                    }
                    if ($ok) {
                        $recreate[] = $ix['sql'];
                    }
                }
                continue;
            }
            if ($ix['origin'] === 'u') {
                $uniques[] = $mapped;
            } else {
                $recreate[] = $this->indexSql($table, ['name' => $ix['name'], 'columns' => $mapped, 'unique' => $ix['unique']]);
            }
        }
        // foreign keys
        $dropFk = array_map('strval', (array) ($spec['drop_fk'] ?? []));
        $fks = [];
        foreach ($info['foreign_keys'] as $fk) {
            if (in_array($fk['name'], $dropFk, true)) {
                continue;
            }
            $mapped = $map($fk['columns']);
            if ($mapped === null) {
                continue;
            }
            $fk['columns'] = $mapped;
            if (strcasecmp($fk['ref_table'], $table) === 0) {
                $refMapped = $map($fk['ref_columns']);
                if ($refMapped === null) {
                    continue;
                }
                $fk['ref_columns'] = $refMapped;
            }
            $fks[] = $fk;
        }
        foreach ((array) ($spec['add_fk'] ?? []) as $fk) {
            $fks[] = (array) $fk;
        }
        // CHECK table constraints
        $checks = [];
        foreach ($parsed['constraints'] as $con) {
            if (!preg_match('/^\s*(CONSTRAINT\s+\S+\s+)?CHECK\b/i', $con)) {
                continue;
            }
            $ok = true;
            foreach ($dropped as $d) {
                if (self::mentions($con, $d)) {
                    $ok = false;
                }
            }
            if ($ok) {
                $checks[] = trim($con);
            }
        }

        $tmp = '_ldb_new_' . $table;
        $colDefs = [];
        $insertCols = [];
        $selectCols = [];
        foreach ($cols as $e) {
            $colDefs[] = [$e['def'], $e['checks']];
            $isGenerated = isset($e['def']['generated']) && $e['def']['generated'] !== null && trim((string) $e['def']['generated']) !== '';
            if ($e['source'] !== null && !$isGenerated) {
                $insertCols[] = $this->quoteIdentifier((string) $e['def']['name']);
                $selectCols[] = $this->quoteIdentifier($e['source']);
            }
        }
        $triggers = [];
        foreach ($this->fetchAll("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? AND sql IS NOT NULL", [$table]) as $r) {
            $triggers[] = $r['sql'];
        }

        $qt = $this->quoteIdentifier($table);
        $out = [
            'PRAGMA foreign_keys = OFF',
            'BEGIN ' . self::REBUILD_MARKER,
            $this->createTableSql($tmp, $colDefs, $newPk, $uniques, $fks, $checks, $parsed['without_rowid'], $parsed['strict']),
        ];
        if ($insertCols) {
            $out[] = 'INSERT INTO ' . $this->quoteIdentifier($tmp) . ' (' . implode(', ', $insertCols) . ') SELECT ' . implode(', ', $selectCols) . ' FROM ' . $qt;
        }
        $out[] = 'DROP TABLE ' . $qt;
        $out[] = 'PRAGMA legacy_alter_table = ON';
        $out[] = 'ALTER TABLE ' . $this->quoteIdentifier($tmp) . ' RENAME TO ' . $qt;
        $out[] = 'PRAGMA legacy_alter_table = OFF';
        foreach ($recreate as $s) {
            $out[] = $s;
        }
        foreach ($triggers as $s) {
            $out[] = $s;
        }
        $out[] = 'COMMIT';
        $out[] = 'PRAGMA foreign_keys = ON';
        return $out;
    }

    // ------------------------------------------------------------------ DDL parser

    /**
     * Minimal CREATE TABLE parser.
     * @return array{columns: array<string,array{name:string,collation:?string,generated:?string,stored:bool,checks:string[],autoincrement:bool}>, constraints: string[], without_rowid: bool, strict: bool}
     */
    public static function parseCreateTable(string $sql): array
    {
        $res = ['columns' => [], 'constraints' => [], 'without_rowid' => false, 'strict' => false];
        $open = self::findTopLevel($sql, '(');
        if ($open === null) {
            return $res;
        }
        $close = self::scanGroup($sql, $open);
        $body = substr($sql, $open + 1, $close - $open - 2);
        $tail = strtoupper(substr($sql, $close));
        $res['without_rowid'] = (bool) preg_match('/WITHOUT\s+ROWID/', $tail);
        $res['strict'] = (bool) preg_match('/\bSTRICT\b/', $tail);
        foreach (self::splitTopLevel($body) as $item) {
            $tokens = self::tokenize($item);
            if (!$tokens) {
                continue;
            }
            $first = strtoupper($tokens[0][1]);
            if ($tokens[0][0] === 'word' && in_array($first, ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN'], true)) {
                $res['constraints'][] = trim($item);
                continue;
            }
            $name = self::unquote($tokens[0]);
            $col = ['name' => $name, 'collation' => null, 'generated' => null, 'stored' => false, 'checks' => [], 'autoincrement' => false];
            $n = count($tokens);
            for ($i = 1; $i < $n; $i++) {
                [$type, $text] = $tokens[$i];
                $u = strtoupper($text);
                if ($type !== 'word') {
                    continue;
                }
                if ($u === 'COLLATE' && isset($tokens[$i + 1])) {
                    $col['collation'] = self::unquote($tokens[$i + 1]);
                    $i++;
                } elseif ($u === 'AS' && isset($tokens[$i + 1]) && $tokens[$i + 1][0] === 'group') {
                    $col['generated'] = trim(substr($tokens[$i + 1][1], 1, -1));
                    $i++;
                } elseif ($u === 'STORED') {
                    $col['stored'] = true;
                } elseif ($u === 'CHECK' && isset($tokens[$i + 1]) && $tokens[$i + 1][0] === 'group') {
                    $col['checks'][] = 'CHECK ' . $tokens[$i + 1][1];
                    $i++;
                } elseif ($u === 'AUTOINCREMENT') {
                    $col['autoincrement'] = true;
                }
            }
            $res['columns'][strtolower($name)] = $col;
        }
        return $res;
    }

    private static function unquote(array $token): string
    {
        $t = $token[1];
        if ($token[0] === 'ident' || $token[0] === 'string') {
            $q = $t[0];
            if ($q === '[') {
                return substr($t, 1, -1);
            }
            return str_replace($q . $q, $q, substr($t, 1, -1));
        }
        return $t;
    }

    /** Index just after a quoted section / comment starting at $i, or null if none starts there. */
    private static function skipQuoted(string $s, int $i): ?int
    {
        $c = $s[$i];
        $n = strlen($s);
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n) {
                if ($s[$j] === $c) {
                    if ($j + 1 < $n && $s[$j + 1] === $c) {
                        $j += 2;
                        continue;
                    }
                    return $j + 1;
                }
                $j++;
            }
            return $n;
        }
        if ($c === '[') {
            $j = strpos($s, ']', $i);
            return $j === false ? $n : $j + 1;
        }
        if ($c === '-' && substr($s, $i, 2) === '--') {
            $j = strpos($s, "\n", $i);
            return $j === false ? $n : $j + 1;
        }
        if ($c === '/' && substr($s, $i, 2) === '/*') {
            $j = strpos($s, '*/', $i + 2);
            return $j === false ? $n : $j + 2;
        }
        return null;
    }

    /** Position after the ")" matching the "(" at $open. */
    private static function scanGroup(string $s, int $open): int
    {
        $depth = 0;
        $n = strlen($s);
        for ($i = $open; $i < $n;) {
            $skip = self::skipQuoted($s, $i);
            if ($skip !== null) {
                $i = $skip;
                continue;
            }
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }
        return $n;
    }

    private static function findTopLevel(string $s, string $char): ?int
    {
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $skip = self::skipQuoted($s, $i);
            if ($skip !== null) {
                $i = $skip;
                continue;
            }
            if ($s[$i] === $char) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    /** Split on commas outside parentheses / quotes. */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $skip = self::skipQuoted($s, $i);
            if ($skip !== null) {
                $i = $skip;
                continue;
            }
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 1;
            }
            $i++;
        }
        $parts[] = substr($s, $start);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /** Tokens: [type, text] with type word|ident|string|group|punct (comments dropped). */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            $skip = self::skipQuoted($s, $i);
            if ($skip !== null) {
                if ($c === "'") {
                    $tokens[] = ['string', substr($s, $i, $skip - $i)];
                } elseif ($c === '"' || $c === '`' || $c === '[') {
                    $tokens[] = ['ident', substr($s, $i, $skip - $i)];
                }
                $i = $skip;
                continue;
            }
            if ($c === '(') {
                $end = self::scanGroup($s, $i);
                $tokens[] = ['group', substr($s, $i, $end - $i)];
                $i = $end;
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$\x80-\xff]/', $c)) {
                $j = $i;
                while ($j < $n && preg_match('/[A-Za-z0-9_$\x80-\xff.]/', $s[$j])) {
                    $j++;
                }
                $tokens[] = ['word', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $tokens[] = ['punct', $c];
            $i++;
        }
        return $tokens;
    }

    // ------------------------------------------------------------------ table ops

    public function dropTable(string $table, bool $isView = false): void
    {
        parent::dropTable($table, $isView);
    }

    public function truncateTable(string $table): void
    {
        $this->exec('DELETE FROM ' . $this->quoteIdentifier($table));
        if ($this->hasTable('sqlite_sequence')) {
            $this->run('DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
        }
    }

    public function renameTable(string $table, string $newName): void
    {
        $row = $this->masterRow($table, ['table', 'view']);
        if ($row === null) {
            throw $this->notFound('Table ' . $table);
        }
        if ($row['type'] === 'view') {
            throw $this->unsupported('Renaming views');
        }
        parent::renameTable($table, $newName);
    }

    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        if ($targetDb !== null && $targetDb !== '' && $targetDb !== $this->database) {
            throw $this->unsupported('Copying to another database');
        }
        $row = $this->masterRow($table, ['table', 'view']);
        if ($row === null) {
            throw $this->notFound('Table ' . $table);
        }
        if ($row['type'] === 'view') {
            throw $this->unsupported('Copying views');
        }
        $sql = (string) $row['sql'];
        $open = self::findTopLevel($sql, '(');
        if ($open === null) {
            throw $this->unsupported('Copying this table');
        }
        $create = 'CREATE TABLE ' . $this->quoteIdentifier($newName) . ' ' . substr($sql, $open);
        $info = $this->tableInfo($table);
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $this->exec($create);
            if ($withData) {
                $cols = [];
                foreach ($info['columns'] as $c) {
                    if ($c['generated'] === null) {
                        $cols[] = $this->quoteIdentifier($c['name']);
                    }
                }
                $list = implode(', ', $cols);
                $this->exec('INSERT INTO ' . $this->quoteIdentifier($newName) . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $this->quoteIdentifier($table));
            }
            foreach ($info['indexes'] as $ix) {
                if ($ix['origin'] !== 'c' || $ix['partial'] || in_array('(expression)', $ix['columns'], true)) {
                    continue;
                }
                $this->exec($this->indexSql($newName, ['name' => $newName . '_' . $ix['name'], 'columns' => $ix['columns'], 'unique' => $ix['unique']]));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->forgetTableInfo();
    }

    public function maintenance(array $tables, string $op): array
    {
        $op = strtolower($op);
        $rows = [];
        $columns = ['Table', 'Op', 'Msg_type', 'Msg_text'];
        switch ($op) {
            case 'vacuum':
                $this->exec('VACUUM');
                $rows[] = [basename($this->file), 'vacuum', 'status', 'OK'];
                break;
            case 'optimize':
                $this->exec('PRAGMA optimize');
                $rows[] = [basename($this->file), 'optimize', 'status', 'OK'];
                break;
            case 'analyze':
                foreach ($tables ?: [null] as $t) {
                    $this->exec('ANALYZE' . ($t !== null ? ' ' . $this->quoteIdentifier((string) $t) : ''));
                    $rows[] = [$t ?? basename($this->file), 'analyze', 'status', 'OK'];
                }
                break;
            case 'check':
            case 'integrity_check':
            case 'quick_check':
                $pragma = $op === 'quick_check' ? 'quick_check' : 'integrity_check';
                foreach ($tables ?: [null] as $t) {
                    $sql = 'PRAGMA ' . $pragma . ($t !== null ? '(' . $this->quoteLiteral((string) $t) . ')' : '');
                    try {
                        $res = $this->pdo()->query($sql)->fetchAll(PDO::FETCH_NUM);
                    } catch (\PDOException $e) {
                        // integrity_check(table) needs SQLite 3.33+
                        $res = $this->pdo()->query('PRAGMA ' . $pragma)->fetchAll(PDO::FETCH_NUM);
                    }
                    foreach ($res as $r) {
                        $msg = (string) $r[0];
                        $rows[] = [$t ?? basename($this->file), 'check', $msg === 'ok' ? 'status' : 'error', $msg === 'ok' ? 'OK' : $msg];
                    }
                }
                break;
            default:
                throw $this->unsupported('Maintenance operation ' . $op);
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    // ------------------------------------------------------------------ "server"

    public function variables(?string $filter = null): array
    {
        $pragmas = ['application_id', 'auto_vacuum', 'automatic_index', 'busy_timeout', 'cache_size',
            'cache_spill', 'cell_size_check', 'checkpoint_fullfsync', 'defer_foreign_keys', 'encoding',
            'foreign_keys', 'freelist_count', 'fullfsync', 'hard_heap_limit', 'ignore_check_constraints',
            'journal_mode', 'journal_size_limit', 'legacy_alter_table', 'locking_mode', 'max_page_count',
            'mmap_size', 'page_count', 'page_size', 'query_only', 'read_uncommitted', 'recursive_triggers',
            'reverse_unordered_selects', 'schema_version', 'secure_delete', 'soft_heap_limit', 'synchronous',
            'temp_store', 'threads', 'trusted_schema', 'user_version', 'wal_autocheckpoint'];
        $out = [['name' => 'sqlite_version', 'value' => $this->sqliteVersion()]];
        foreach ($pragmas as $p) {
            try {
                $v = $this->pdo()->query('PRAGMA ' . $p)->fetchColumn();
            } catch (\PDOException $e) {
                continue;
            }
            if ($v === false) {
                continue;
            }
            $out[] = ['name' => $p, 'value' => (string) $v];
        }
        try {
            $opts = $this->pdo()->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
            $out[] = ['name' => 'compile_options', 'value' => implode(' ', $opts)];
        } catch (\PDOException $e) {
        }
        return $this->filterNameValue($out, $filter);
    }

    public function collations(): array
    {
        $out = [];
        foreach ($this->pdo()->query('PRAGMA collation_list')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['name' => $r['name'], 'charset' => null, 'default' => $r['name'] === 'BINARY'];
        }
        usort($out, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    public function dataTypes(): array
    {
        return [
            'Affinity' => ['INTEGER', 'REAL', 'TEXT', 'BLOB', 'NUMERIC', 'ANY'],
            'Numeric' => ['INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'DECIMAL', 'DOUBLE', 'FLOAT', 'BOOLEAN'],
            'String' => ['VARCHAR', 'CHAR', 'CLOB', 'JSON'],
            'Date and time' => ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME'],
        ];
    }

    public function completionWords(): array
    {
        return $this->mergeWords(parent::completionWords(), [
            'PRAGMA', 'VACUUM', 'ATTACH', 'DETACH', 'DATABASE', 'AUTOINCREMENT', 'WITHOUT', 'ROWID',
            'STRICT', 'GLOB', 'REGEXP', 'MATCH', 'ESCAPE', 'COLLATE', 'NOCASE', 'RTRIM', 'BINARY',
            'CONFLICT', 'REPLACE', 'IGNORE', 'ABORT', 'FAIL', 'INSTEAD', 'OF', 'EACH', 'ROW', 'FOR',
            'TEMP', 'TEMPORARY', 'VIRTUAL', 'GENERATED', 'ALWAYS', 'STORED', 'RETURNING', 'UPSERT',
            'NOTHING', 'DO', 'INDEXED', 'REINDEX', 'ANALYZE', 'QUERY', 'PLAN', 'DEFERRABLE', 'INITIALLY',
            'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE', 'SAVEPOINT', 'RELEASE',
        ], [
            'IFNULL', 'IIF', 'INSTR', 'SUBSTR', 'PRINTF', 'FORMAT', 'QUOTE', 'HEX', 'UNHEX', 'RANDOM',
            'RANDOMBLOB', 'ZEROBLOB', 'TYPEOF', 'UNICODE', 'CHAR', 'GLOB', 'LIKELIHOOD', 'LIKELY',
            'UNLIKELY', 'LAST_INSERT_ROWID', 'CHANGES', 'TOTAL_CHANGES', 'SQLITE_VERSION', 'DATE', 'TIME',
            'DATETIME', 'JULIANDAY', 'UNIXEPOCH', 'STRFTIME', 'GROUP_CONCAT', 'STRING_AGG', 'TOTAL',
            'JSON', 'JSON_ARRAY', 'JSON_OBJECT', 'JSON_EXTRACT', 'JSON_INSERT', 'JSON_REPLACE', 'JSON_SET',
            'JSON_REMOVE', 'JSON_TYPE', 'JSON_VALID', 'JSON_EACH', 'JSON_TREE', 'JSON_GROUP_ARRAY',
            'JSON_GROUP_OBJECT', 'NTILE', 'PERCENT_RANK', 'CUME_DIST', 'NTH_VALUE',
        ]);
    }
}
