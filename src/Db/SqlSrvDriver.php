<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Http\ApiException;
use PDO;

/**
 * Microsoft SQL Server 2016+ driver via pdo_sqlsrv (preferred) or pdo_dblib (FreeTDS fallback).
 *
 * Schemas are supported (default: the user's default schema, usually dbo); metadata comes from
 * the sys.* catalog views. Paging uses OFFSET … FETCH which requires an ORDER BY.
 */
class SqlSrvDriver extends AbstractPdoDriver
{
    /** 'sqlsrv' or 'dblib' — the PDO driver actually used. */
    private string $pdoDriver = 'sqlsrv';

    public static function id(): string
    {
        return 'sqlsrv';
    }

    public static function label(): string
    {
        return 'SQL Server';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlsrv') || extension_loaded('pdo_dblib');
    }

    public static function defaultPort(): ?int
    {
        return 1433;
    }

    // ------------------------------------------------------------------ connection

    private static function usesSqlsrvExtension(array $params): bool
    {
        $o = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];
        if (isset($o['pdo_driver']) && $o['pdo_driver'] === 'dblib' && extension_loaded('pdo_dblib')) {
            return false;
        }
        return extension_loaded('pdo_sqlsrv') || !extension_loaded('pdo_dblib');
    }

    private function dsnValue(string $v): string
    {
        if (strpbrk($v, ";\0{}") !== false) {
            throw ApiException::badRequest('Invalid character in connection parameter');
        }
        return $v;
    }

    protected function buildDsn(array $params): string
    {
        $host = isset($params['host']) && $params['host'] !== '' ? $this->dsnValue((string) $params['host']) : 'localhost';
        $port = isset($params['port']) && $params['port'] !== '' && $params['port'] !== null ? (int) $params['port'] : 1433;
        $db = isset($params['database']) && $params['database'] !== '' ? $this->dsnValue((string) $params['database']) : null;
        $o = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];
        if (self::usesSqlsrvExtension($params)) {
            $dsn = 'sqlsrv:Server=' . $host . ',' . $port;
            if ($db !== null) {
                $dsn .= ';Database=' . $db;
            }
            $trust = !array_key_exists('trust_server_certificate', $o) || !empty($o['trust_server_certificate']);
            $dsn .= ';TrustServerCertificate=' . ($trust ? '1' : '0');
            if (array_key_exists('encrypt', $o)) {
                $dsn .= ';Encrypt=' . (!empty($o['encrypt']) ? '1' : '0');
            }
            $dsn .= ';LoginTimeout=' . $this->connectTimeout();
            return $dsn;
        }
        $dsn = 'dblib:host=' . $host . ':' . $port . ';charset=UTF-8';
        if ($db !== null) {
            $dsn .= ';dbname=' . $db;
        }
        return $dsn;
    }

    /**
     * Overridden because the base class passes PDO::ATTR_TIMEOUT to the constructor, which
     * pdo_sqlsrv rejects ("unsupported attribute"); the login timeout is part of the DSN instead.
     */
    public function connect(array $params): void
    {
        $this->params = $params;
        $this->pdoDriver = self::usesSqlsrvExtension($params) ? 'sqlsrv' : 'dblib';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($this->pdoDriver === 'sqlsrv') {
            if (defined('PDO::SQLSRV_ATTR_ENCODING')) {
                $options[constant('PDO::SQLSRV_ATTR_ENCODING')] = constant('PDO::SQLSRV_ENCODING_UTF8');
            }
            if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
                $options[constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')] = true;
            }
        } else {
            $options[PDO::ATTR_TIMEOUT] = $this->connectTimeout();
            if (defined('PDO::DBLIB_ATTR_STRINGIFY_UNIQUEIDENTIFIER')) {
                $options[constant('PDO::DBLIB_ATTR_STRINGIFY_UNIQUEIDENTIFIER')] = true;
            }
        }
        $this->pdo = new PDO(
            $this->buildDsn($params),
            isset($params['user']) ? (string) $params['user'] : null,
            isset($params['password']) ? (string) $params['password'] : null,
            $options
        );
        $this->afterConnect($params);
    }

    protected function afterConnect(array $params): void
    {
        $pdo = $this->pdo();
        if ($this->pdoDriver === 'dblib') {
            // FreeTDS defaults differ from SSMS/ODBC; indexed views, computed columns etc. need these.
            $pdo->exec('SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; '
                . 'SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET TEXTSIZE 2147483647');
        }
        if ($this->pdoDriver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_DIRECT_QUERY')) {
            // PDO::query()/exec() via SQLExecDirect: keeps #temp tables and SET options of SQL editor
            // statements in the session (prepared execution would scope them to sp_prepexec) and is
            // required for SET SHOWPLAN_TEXT.
            $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_DIRECT_QUERY'), true);
        }
        $t = $this->queryTimeout();
        if ($t > 0) {
            if ($this->pdoDriver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                try {
                    $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), $t);
                } catch (\Throwable $e) {
                }
            } else {
                $pdo->exec('SET LOCK_TIMEOUT ' . ($t * 1000));
            }
        }
        $row = $this->fetchOne('SELECT DB_NAME() AS db, SCHEMA_NAME() AS sch');
        $this->database = $row['db'] ?? null;
        $this->schema = !empty($row['sch']) ? (string) $row['sch'] : 'dbo';
        $this->forgetTableInfo();
    }

    public function select(?string $database, ?string $schema = null): void
    {
        if ($database !== null && $database !== '' && $database !== $this->database) {
            $this->pdo()->exec('USE ' . $this->quoteIdentifier($database));
            $this->database = $database;
        }
        if ($schema !== null && $schema !== '') {
            $this->schema = $schema;
        } else {
            $s = $this->fetchValue('SELECT SCHEMA_NAME()');
            $this->schema = $s ? (string) $s : 'dbo';
        }
        $this->forgetTableInfo();
    }

    private function schemaName(): string
    {
        return $this->schema !== null && $this->schema !== '' ? $this->schema : 'dbo';
    }

    public function quoteIdentifier(string $name): string
    {
        $this->assertIdentifier($name);
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    public function quoteTable(string $table): string
    {
        return $this->quoteIdentifier($this->schemaName()) . '.' . $this->quoteIdentifier($table);
    }

    /** Unicode string literal N'…' (T-SQL has no backslash escapes). */
    private function nLiteral(string $value): string
    {
        return "N'" . str_replace("'", "''", $value) . "'";
    }

    private function objectId(string $table): int
    {
        $id = $this->fetchValue('SELECT OBJECT_ID(?)', [$this->quoteTable($table)]);
        if ($id === null) {
            throw $this->notFound('Object "' . $this->schemaName() . '.' . $table . '"');
        }
        return (int) $id;
    }

    // ------------------------------------------------------------------ server

    public function capabilities(): array
    {
        return [
            'schemas' => true,
            'users' => true,
            'processes' => true,
            'variables' => true,
            'status' => true,
            'engines' => false,
            'collations' => true,
            'charsets' => false,
            'routines' => true,
            'triggers' => true,
            'events' => false,
            'foreign_keys' => true,
            'alter_column' => true,
            'drop_column' => true,
            'rename_table' => true,
            'copy_table' => true,
            'unsigned' => false,
            'auto_increment' => true,
            'comments' => true,
            'maintenance' => true,
            'explain' => true,
            'create_database' => true,
            'kill' => true,
            'cancel' => true,
            'db_alter' => true,
            'db_copy' => false,
            'db_rename' => true,
            'monitor' => true,
        ];
    }

    public function serverInfo(): array
    {
        $r = $this->fetchOne(
            "SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS version,
                    CAST(SERVERPROPERTY('Edition') AS nvarchar(128)) AS edition,
                    @@VERSION AS full_version, SUSER_SNAME() AS usr,
                    CAST(SERVERPROPERTY('Collation') AS nvarchar(128)) AS collation"
        ) ?? [];
        $uptime = null;
        try {
            $uptime = $this->fetchValue('SELECT DATEDIFF(SECOND, sqlserver_start_time, SYSDATETIME()) FROM sys.dm_os_sys_info');
        } catch (\PDOException $e) {
            $uptime = null; // needs VIEW SERVER STATE
        }
        return [
            'name' => 'SQL Server',
            'version' => (string) ($r['version'] ?? ''),
            'version_comment' => trim((string) ($r['edition'] ?? '') . ' — ' . strtok((string) ($r['full_version'] ?? ''), "\n")),
            'host' => isset($this->params['host']) && $this->params['host'] !== '' ? (string) $this->params['host'] : 'localhost',
            'user' => (string) ($r['usr'] ?? ''),
            'charset' => $r['collation'] ?? null,
            'uptime' => $uptime === null ? null : (int) $uptime,
            // must be exactly the driver id: the frontend picks its driver profile (types, quoting) from it
            'driver' => self::id(),
            'pdo_extension' => 'pdo_' . $this->pdoDriver,
        ];
    }

    public function listDatabases(): array
    {
        $rows = $this->fetchAll(
            'SELECT d.name, d.collation_name AS collation, d.database_id
             FROM sys.databases d WHERE HAS_DBACCESS(d.name) = 1 ORDER BY d.name'
        );
        $sizes = [];
        try {
            foreach ($this->fetchAll('SELECT database_id, SUM(CAST(size AS bigint)) * 8192 AS size FROM sys.master_files GROUP BY database_id') as $s) {
                $sizes[(int) $s['database_id']] = (int) $s['size'];
            }
        } catch (\PDOException $e) {
            // no permission
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $r['name'],
                'collation' => $r['collation'],
                'tables' => null,
                'size' => $sizes[(int) $r['database_id']] ?? null,
            ];
        }
        return $out;
    }

    public function createDatabase(string $name, ?string $collation = null): void
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($name);
        if ($collation !== null && $collation !== '') {
            $sql .= ' COLLATE ' . $this->assertWord($collation, 'collation');
        }
        $this->exec($sql);
    }

    public function dropDatabase(string $name): void
    {
        if ($name === $this->database) {
            $this->exec('USE [master]');
            $this->database = 'master';
        }
        $this->exec('DROP DATABASE ' . $this->quoteIdentifier($name));
    }

    // ------------------------------------------------------------------ database settings

    public function databaseInfo(string $name): array
    {
        $row = $this->fetchOne(
            'SELECT d.name, d.collation_name AS collation, d.recovery_model_desc AS recovery_model,
                    d.compatibility_level, d.state_desc AS state, d.user_access_desc AS user_access,
                    d.is_read_only, CONVERT(varchar(19), d.create_date, 126) AS created, d.database_id
             FROM sys.databases d WHERE d.name = ?',
            [$name]
        );
        if ($row === null) {
            throw $this->notFound('Database ' . $name);
        }
        $size = null;
        try {
            $v = $this->fetchValue('SELECT SUM(CAST(size AS bigint)) * 8192 FROM sys.master_files WHERE database_id = ?', [(int) $row['database_id']]);
            $size = $v === null ? null : (int) $v;
        } catch (\PDOException $e) {
        }
        return [
            'driver' => 'sqlsrv',
            'name' => $name,
            'settings' => [
                'collation' => $row['collation'],
                'recovery_model' => $row['recovery_model'],
                'compatibility_level' => $row['compatibility_level'] === null ? null : (int) $row['compatibility_level'],
                'state' => $row['state'],
                'user_access' => $row['user_access'],
                'read_only' => (bool) $row['is_read_only'],
                'created' => $row['created'],
            ],
            'size' => $size,
            'other_connections' => $this->otherSessions($name),
        ];
    }

    /** Sessions (other than ours) using $name; null without VIEW SERVER STATE. */
    private function otherSessions(string $name): ?int
    {
        try {
            $v = $this->fetchValue('SELECT COUNT(*) FROM sys.dm_exec_sessions WHERE database_id = DB_ID(?) AND session_id <> @@SPID', [$name]);
            return $v === null ? null : (int) $v;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function buildAlterDatabase(string $name, array $changes): array
    {
        $names = [];
        if (self::changeString($changes, 'collation') !== null) {
            foreach ($this->collations() as $c) {
                $names[] = (string) $c['name'];
            }
        }
        return $this->planAlterDatabase($name, $changes, $names);
    }

    /**
     * Pure SQL generation: COLLATE (validated against sys.fn_helpcollations()) then MODIFY NAME.
     * @param string[] $collations
     * @return array{sql:string[],warnings:string[]}
     */
    public function planAlterDatabase(string $name, array $changes, array $collations): array
    {
        self::assertChangeKeys($changes, ['collation', 'rename']);
        $q = $this->quoteIdentifier($name);
        $sql = [];
        $coll = self::changeString($changes, 'collation');
        if ($coll !== null) {
            $this->assertWord($coll, 'collation');
            if (!in_array(strtolower($coll), array_map('strtolower', $collations), true)) {
                throw ApiException::badRequest('Unknown collation: ' . $coll);
            }
            $sql[] = 'ALTER DATABASE ' . $q . ' COLLATE ' . $coll;
        }
        $rename = self::changeString($changes, 'rename');
        if ($rename !== null && $rename !== $name) {
            if (mb_strlen($rename, 'UTF-8') > 128) {
                throw ApiException::badRequest('SQL Server database names are limited to 128 characters');
            }
            $sql[] = 'ALTER DATABASE ' . $q . ' MODIFY NAME = ' . $this->quoteIdentifier($rename);
        }
        return ['sql' => $sql, 'warnings' => $sql === [] ? [] : ['sqlsrv_exclusive']];
    }

    /**
     * Both COLLATE and MODIFY NAME need the database to be free of other sessions (otherwise
     * SQL Server fails with "could not be exclusively locked"). We refuse up front instead of
     * forcing SINGLE_USER WITH ROLLBACK IMMEDIATE, which would kill other users' work.
     */
    public function alterDatabase(string $name, array $changes): array
    {
        $plan = $this->buildAlterDatabase($name, $changes);
        if ($plan['sql'] === []) {
            return $plan;
        }
        if ($this->database !== 'master') {
            $this->exec('USE [master]');
            $this->database = 'master';
        }
        $others = $this->otherSessions($name);
        if ($others !== null && $others > 0) {
            throw new ApiException(
                'Database "' . $name . '" is used by ' . $others . ' other session(s). Changing its collation or name needs exclusive access: '
                . 'close those connections (Server → Processes) and try again.',
                'in_use',
                409
            );
        }
        $this->runDatabaseStatements($plan['sql']);
        $this->forgetTableInfo();
        return $plan;
    }

    public function listSchemas(): array
    {
        $rows = $this->fetchAll(
            "SELECT name FROM sys.schemas
             WHERE schema_id < 16384 AND name NOT IN ('sys', 'INFORMATION_SCHEMA', 'guest')
             ORDER BY CASE WHEN name = 'dbo' THEN 0 ELSE 1 END, name"
        );
        return array_map(static function (array $r): string {
            return (string) $r['name'];
        }, $rows);
    }

    // ------------------------------------------------------------------ objects

    public function listTables(): array
    {
        $rows = $this->fetchAll(
            "SELECT o.name, o.type, CONVERT(varchar(19), o.modify_date, 120) AS updated,
                    CAST(ep.value AS nvarchar(max)) AS comment,
                    (SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = o.object_id AND p.index_id IN (0, 1)) AS row_count,
                    (SELECT SUM(a.used_pages) FROM sys.partitions p JOIN sys.allocation_units a ON a.container_id = p.partition_id
                      WHERE p.object_id = o.object_id AND p.index_id IN (0, 1)) * 8192 AS data_size,
                    (SELECT SUM(a.used_pages) FROM sys.partitions p JOIN sys.allocation_units a ON a.container_id = p.partition_id
                      WHERE p.object_id = o.object_id AND p.index_id > 1) * 8192 AS index_size,
                    CASE WHEN OBJECTPROPERTY(o.object_id, 'TableHasIdentity') = 1
                         THEN CAST(IDENT_CURRENT(QUOTENAME(s.name) + '.' + QUOTENAME(o.name)) AS bigint)
                              + CAST(IDENT_INCR(QUOTENAME(s.name) + '.' + QUOTENAME(o.name)) AS bigint) END AS auto_increment
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             LEFT JOIN sys.extended_properties ep ON ep.class = 1 AND ep.major_id = o.object_id AND ep.minor_id = 0
                  AND ep.name = 'MS_Description'
             WHERE s.name = ? AND o.type IN ('U', 'V') AND o.is_ms_shipped = 0
             ORDER BY o.name",
            [$this->schemaName()]
        );
        $out = [];
        foreach ($rows as $r) {
            $isView = trim((string) $r['type']) === 'V';
            $out[] = [
                'name' => $r['name'],
                'type' => $isView ? 'view' : 'table',
                'rows' => $isView || $r['row_count'] === null ? null : (int) $r['row_count'],
                'data_size' => $isView || $r['data_size'] === null ? null : (int) $r['data_size'],
                'index_size' => $isView ? null : (int) ($r['index_size'] ?? 0),
                'engine' => null,
                'collation' => null,
                'comment' => $r['comment'],
                'auto_increment' => $r['auto_increment'] === null ? null : (int) $r['auto_increment'],
                'updated' => $r['updated'],
            ];
        }
        return $out;
    }

    public function listRoutines(): array
    {
        $rows = $this->fetchAll(
            "SELECT o.name, o.type,
                    (SELECT TYPE_NAME(p.user_type_id) FROM sys.parameters p WHERE p.object_id = o.object_id AND p.parameter_id = 0) AS returns,
                    USER_NAME(OBJECTPROPERTY(o.object_id, 'OwnerId')) AS definer
             FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id
             WHERE s.name = ? AND o.type IN ('P', 'FN', 'IF', 'TF') AND o.is_ms_shipped = 0
             ORDER BY o.name",
            [$this->schemaName()]
        );
        $out = [];
        foreach ($rows as $r) {
            $type = trim((string) $r['type']);
            $out[] = [
                'name' => $r['name'],
                'type' => $type === 'P' ? 'PROCEDURE' : 'FUNCTION',
                'returns' => $type === 'P' ? null : ($type === 'FN' ? $r['returns'] : 'TABLE'),
                'definer' => $r['definer'],
            ];
        }
        return $out;
    }

    private function triggerRows(?int $parentId): array
    {
        $sql = "SELECT t.object_id, t.name, OBJECT_NAME(t.parent_id) AS tbl, t.is_instead_of_trigger,
                       OBJECT_DEFINITION(t.object_id) AS def
                FROM sys.triggers t JOIN sys.objects p ON p.object_id = t.parent_id
                JOIN sys.schemas s ON s.schema_id = p.schema_id
                WHERE t.parent_class = 1 AND ";
        $params = [];
        if ($parentId !== null) {
            $sql .= 't.parent_id = ?';
            $params[] = $parentId;
        } else {
            $sql .= 's.name = ?';
            $params[] = $this->schemaName();
        }
        $rows = $this->fetchAll($sql . ' ORDER BY tbl, t.name', $params);
        $events = [];
        foreach ($this->fetchAll('SELECT object_id, type_desc FROM sys.trigger_events') as $e) {
            $events[(int) $e['object_id']][] = $e['type_desc'];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $r['name'],
                'table' => $r['tbl'],
                'timing' => $r['is_instead_of_trigger'] ? 'INSTEAD OF' : 'AFTER',
                'event' => implode(' OR ', $events[(int) $r['object_id']] ?? []),
                'statement' => $r['def'],
            ];
        }
        return $out;
    }

    public function listTriggers(): array
    {
        return $this->triggerRows(null);
    }

    // ------------------------------------------------------------------ table info

    /** Strip the redundant parentheses SQL Server stores around default/computed definitions. */
    private static function stripParens(string $expr): string
    {
        $expr = trim($expr);
        while (strlen($expr) >= 2 && $expr[0] === '(' && substr($expr, -1) === ')') {
            $depth = 0;
            $balanced = true;
            $len = strlen($expr);
            for ($i = 0; $i < $len; $i++) {
                if ($expr[$i] === '(') {
                    $depth++;
                } elseif ($expr[$i] === ')') {
                    $depth--;
                    if ($depth === 0 && $i < $len - 1) {
                        $balanced = false;
                        break;
                    }
                }
            }
            if (!$balanced) {
                break;
            }
            $expr = trim(substr($expr, 1, -1));
        }
        return $expr;
    }

    private function classifyDefault(?string $def): array
    {
        if ($def === null) {
            return ['none', null];
        }
        $e = self::stripParens($def);
        if (strcasecmp($e, 'NULL') === 0) {
            return ['null', null];
        }
        if (preg_match("/^N?'((?:[^']|'')*)'$/s", $e, $m)) {
            return ['value', str_replace("''", "'", $m[1])];
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $e)) {
            return ['value', $e];
        }
        return ['expression', $e];
    }

    /** Length string for a sys.columns row. */
    private static function lengthOf(string $type, int $maxLength, int $precision, int $scale): ?string
    {
        $t = strtolower($type);
        if (in_array($t, ['varchar', 'char', 'varbinary', 'binary'], true)) {
            return $maxLength === -1 ? 'max' : (string) $maxLength;
        }
        if (in_array($t, ['nvarchar', 'nchar'], true)) {
            return $maxLength === -1 ? 'max' : (string) intdiv($maxLength, 2);
        }
        if (in_array($t, ['decimal', 'numeric'], true)) {
            return $precision . ',' . $scale;
        }
        if (in_array($t, ['datetime2', 'time', 'datetimeoffset'], true)) {
            return (string) $scale;
        }
        return null;
    }

    private function rawColumns(int $oid): array
    {
        return $this->fetchAll(
            "SELECT c.column_id, c.name, TYPE_NAME(c.user_type_id) AS type, c.max_length, c.precision, c.scale,
                    c.is_nullable, c.is_identity, c.is_computed, cc.definition AS computed_def, cc.is_persisted,
                    dc.definition AS default_def, dc.name AS default_name, c.collation_name,
                    CAST(DATABASEPROPERTYEX(DB_NAME(), 'Collation') AS nvarchar(128)) AS db_collation,
                    CAST(ep.value AS nvarchar(max)) AS comment,
                    CAST(ic.seed_value AS bigint) AS seed, CAST(ic.increment_value AS bigint) AS incr
             FROM sys.columns c
             LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
             LEFT JOIN sys.computed_columns cc ON cc.object_id = c.object_id AND cc.column_id = c.column_id
             LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
             LEFT JOIN sys.extended_properties ep ON ep.class = 1 AND ep.major_id = c.object_id
                  AND ep.minor_id = c.column_id AND ep.name = 'MS_Description'
             WHERE c.object_id = ?
             ORDER BY c.column_id",
            [$oid]
        );
    }

    private function rawIndexes(int $oid): array
    {
        $idx = $this->fetchAll(
            'SELECT index_id, name, is_unique, is_primary_key, is_unique_constraint, type_desc, filter_definition
             FROM sys.indexes WHERE object_id = ? AND index_id > 0 AND is_hypothetical = 0
             ORDER BY is_primary_key DESC, name',
            [$oid]
        );
        $cols = [];
        foreach ($this->fetchAll(
            'SELECT ic.index_id, c.name, ic.is_descending_key, ic.is_included_column
             FROM sys.index_columns ic JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
             WHERE ic.object_id = ? ORDER BY ic.index_id, ic.is_included_column, ic.key_ordinal, ic.index_column_id',
            [$oid]
        ) as $c) {
            $key = $c['is_included_column'] ? 'include' : 'keys';
            $cols[(int) $c['index_id']][$key][] = ['name' => $c['name'], 'desc' => (bool) $c['is_descending_key']];
        }
        foreach ($idx as &$i) {
            $i['keys'] = $cols[(int) $i['index_id']]['keys'] ?? [];
            $i['include'] = $cols[(int) $i['index_id']]['include'] ?? [];
        }
        unset($i);
        return $idx;
    }

    private function rawForeignKeys(int $oid, bool $incoming): array
    {
        $rows = $this->fetchAll(
            'SELECT fk.object_id AS id, fk.name, OBJECT_SCHEMA_NAME(fk.parent_object_id) AS src_schema,
                    OBJECT_NAME(fk.parent_object_id) AS src_table,
                    OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS ref_schema, OBJECT_NAME(fk.referenced_object_id) AS ref_table,
                    fk.delete_referential_action_desc AS on_delete, fk.update_referential_action_desc AS on_update,
                    pc.name AS col, rc.name AS ref_col
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
             JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
             JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
             WHERE ' . ($incoming ? 'fk.referenced_object_id' : 'fk.parent_object_id') . ' = ?
             ORDER BY fk.name, fkc.constraint_column_id',
            [$oid]
        );
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'name' => $r['name'],
                    'columns' => [],
                    'ref_schema' => $r['ref_schema'],
                    'ref_table' => $r['ref_table'],
                    'ref_columns' => [],
                    'on_delete' => str_replace('_', ' ', (string) $r['on_delete']),
                    'on_update' => str_replace('_', ' ', (string) $r['on_update']),
                ];
                if ($incoming) {
                    $out[$id]['table'] = $r['src_table'];
                    $out[$id]['schema'] = $r['src_schema'];
                }
            }
            $out[$id]['columns'][] = $r['col'];
            $out[$id]['ref_columns'][] = $r['ref_col'];
        }
        return array_values($out);
    }

    public function tableInfo(string $table): array
    {
        $oid = $this->objectId($table);
        $type = trim((string) $this->fetchValue('SELECT type FROM sys.objects WHERE object_id = ?', [$oid]));
        $rawIdx = $this->rawIndexes($oid);
        $indexes = [];
        $pk = [];
        foreach ($rawIdx as $i) {
            $cols = array_column($i['keys'], 'name');
            if ($i['is_primary_key']) {
                $pk = $cols;
            }
            $indexes[] = [
                'name' => $i['name'],
                'columns' => $cols,
                'unique' => (bool) $i['is_unique'],
                'primary' => (bool) $i['is_primary_key'],
                'type' => $i['type_desc'],
            ];
        }
        $columns = [];
        foreach ($this->rawColumns($oid) as $c) {
            $len = self::lengthOf((string) $c['type'], (int) $c['max_length'], (int) $c['precision'], (int) $c['scale']);
            [$kind, $default] = $this->classifyDefault($c['default_def']);
            $extra = null;
            if ($c['is_identity']) {
                $extra = 'IDENTITY(' . $c['seed'] . ',' . $c['incr'] . ')';
            }
            $generated = $c['is_computed'] ? self::stripParens((string) $c['computed_def']) : null;
            if ($generated !== null) {
                $extra = 'AS (' . $generated . ')' . ($c['is_persisted'] ? ' PERSISTED' : '');
            }
            $columns[] = [
                'name' => $c['name'],
                'type' => $c['type'],
                'full_type' => $c['type'] . ($len !== null ? '(' . $len . ')' : ''),
                'length' => $len,
                'unsigned' => false,
                'nullable' => (bool) $c['is_nullable'],
                'default' => $default,
                'default_kind' => $kind,
                'auto_increment' => (bool) $c['is_identity'],
                'primary' => in_array($c['name'], $pk, true),
                'comment' => $c['comment'],
                'collation' => ($c['collation_name'] !== null && $c['collation_name'] !== $c['db_collation']) ? $c['collation_name'] : null,
                'extra' => $extra,
                'generated' => $generated,
            ];
        }
        $meta = $this->fetchOne(
            "SELECT CAST(ep.value AS nvarchar(max)) AS comment,
                    (SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = ? AND p.index_id IN (0, 1)) AS row_count,
                    CASE WHEN OBJECTPROPERTY(?, 'TableHasIdentity') = 1
                         THEN CAST(IDENT_CURRENT(?) AS bigint) + CAST(IDENT_INCR(?) AS bigint) END AS ai
             FROM (SELECT 1 AS x) d
             LEFT JOIN sys.extended_properties ep ON ep.class = 1 AND ep.major_id = ? AND ep.minor_id = 0 AND ep.name = 'MS_Description'",
            [$oid, $oid, $this->quoteTable($table), $this->quoteTable($table), $oid]
        ) ?? [];
        $triggers = [];
        foreach ($this->triggerRows($oid) as $t) {
            unset($t['table']);
            $triggers[] = $t;
        }
        return [
            'name' => $table,
            'type' => $type === 'V' ? 'view' : 'table',
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $this->rawForeignKeys($oid, false),
            'referenced_by' => $this->rawForeignKeys($oid, true),
            'triggers' => $triggers,
            'options' => [
                'engine' => null,
                'collation' => null,
                'comment' => $meta['comment'] ?? null,
                'rows' => isset($meta['row_count']) ? (int) $meta['row_count'] : null,
                'auto_increment' => isset($meta['ai']) ? (int) $meta['ai'] : null,
            ],
        ];
    }

    // ------------------------------------------------------------------ DDL output

    public function createStatement(string $name, string $type = 'table'): string
    {
        $type = strtolower($type);
        if ($type === 'event') {
            throw $this->unsupported('Events');
        }
        $oid = $this->objectId($name);
        $objType = trim((string) $this->fetchValue('SELECT type FROM sys.objects WHERE object_id = ?', [$oid]));
        if ($objType !== 'U') {
            $def = $this->fetchValue('SELECT OBJECT_DEFINITION(?)', [$oid]);
            if ($def === null) {
                throw new ApiException('Definition is not available (encrypted object or missing VIEW DEFINITION permission)', 'bad_request', 400);
            }
            return rtrim((string) $def) . "\n";
        }
        return $this->tableDdl($name, $oid);
    }

    private function tableDdl(string $table, int $oid): string
    {
        $qt = $this->quoteTable($table);
        $lines = [];
        $comments = [];
        foreach ($this->rawColumns($oid) as $c) {
            $qc = $this->quoteIdentifier((string) $c['name']);
            if ($c['is_computed']) {
                $lines[] = '    ' . $qc . ' AS ' . $c['computed_def'] . ($c['is_persisted'] ? ' PERSISTED' : '');
                continue;
            }
            $len = self::lengthOf((string) $c['type'], (int) $c['max_length'], (int) $c['precision'], (int) $c['scale']);
            $line = '    ' . $qc . ' ' . $this->quoteIdentifier((string) $c['type']) . ($len !== null ? '(' . $len . ')' : '');
            if ($c['collation_name'] !== null && $c['collation_name'] !== $c['db_collation']) {
                $line .= ' COLLATE ' . $c['collation_name'];
            }
            if ($c['is_identity']) {
                $line .= ' IDENTITY(' . $c['seed'] . ',' . $c['incr'] . ')';
            }
            $line .= $c['is_nullable'] ? ' NULL' : ' NOT NULL';
            if ($c['default_def'] !== null) {
                $line .= ' CONSTRAINT ' . $this->quoteIdentifier((string) $c['default_name']) . ' DEFAULT ' . $c['default_def'];
            }
            $lines[] = $line;
            if ($c['comment'] !== null) {
                $comments[] = $this->extendedPropertySql('add', (string) $c['comment'], $table, (string) $c['name']);
            }
        }
        $indexSql = [];
        foreach ($this->rawIndexes($oid) as $i) {
            $keys = implode(', ', array_map(function (array $k): string {
                return $this->quoteIdentifier($k['name']) . ($k['desc'] ? ' DESC' : '');
            }, $i['keys']));
            $kind = (string) $i['type_desc'];
            if ($i['is_primary_key'] || $i['is_unique_constraint']) {
                $lines[] = '    CONSTRAINT ' . $this->quoteIdentifier((string) $i['name'])
                    . ($i['is_primary_key'] ? ' PRIMARY KEY ' : ' UNIQUE ') . $kind . ' (' . $keys . ')';
                continue;
            }
            if ($kind === 'HEAP' || $keys === '') {
                continue;
            }
            $s = 'CREATE ' . ($i['is_unique'] ? 'UNIQUE ' : '') . $kind . ' INDEX ' . $this->quoteIdentifier((string) $i['name'])
                . ' ON ' . $qt . ' (' . $keys . ')';
            if ($i['include']) {
                $s .= ' INCLUDE (' . $this->columnList(array_column($i['include'], 'name')) . ')';
            }
            if ($i['filter_definition'] !== null) {
                $s .= ' WHERE ' . $i['filter_definition'];
            }
            $indexSql[] = $s . ';';
        }
        foreach ($this->fetchAll('SELECT name, definition FROM sys.check_constraints WHERE parent_object_id = ? ORDER BY name', [$oid]) as $ck) {
            $lines[] = '    CONSTRAINT ' . $this->quoteIdentifier((string) $ck['name']) . ' CHECK ' . $ck['definition'];
        }
        foreach ($this->rawForeignKeys($oid, false) as $fk) {
            $ref = $this->quoteIdentifier((string) $fk['ref_schema']) . '.' . $this->quoteIdentifier((string) $fk['ref_table']);
            $lines[] = '    CONSTRAINT ' . $this->quoteIdentifier((string) $fk['name']) . ' ' . $this->renderForeignKeyBody($fk, $ref);
        }
        $out = ['CREATE TABLE ' . $qt . " (\n" . implode(",\n", $lines) . "\n);"];
        $out = array_merge($out, $indexSql);
        $tc = $this->fetchValue(
            "SELECT CAST(value AS nvarchar(max)) FROM sys.extended_properties WHERE class = 1 AND major_id = ? AND minor_id = 0 AND name = 'MS_Description'",
            [$oid]
        );
        if ($tc !== null) {
            $out[] = $this->extendedPropertySql('add', (string) $tc, $table, null) . ';';
        }
        foreach ($comments as $c) {
            $out[] = $c . ';';
        }
        foreach ($this->triggerRows($oid) as $t) {
            $out[] = "GO\n" . rtrim((string) $t['statement']) . "\nGO";
        }
        return implode("\n", $out);
    }

    /** sp_add/update/dropextendedproperty call for MS_Description on a table or column. */
    private function extendedPropertySql(string $verb, ?string $value, string $table, ?string $column): string
    {
        $sql = 'EXEC sys.sp_' . $verb . "extendedproperty @name = N'MS_Description', ";
        if ($verb !== 'drop') {
            $sql .= '@value = ' . $this->nLiteral((string) $value) . ', ';
        }
        $sql .= "@level0type = N'SCHEMA', @level0name = " . $this->nLiteral($this->schemaName())
            . ", @level1type = N'TABLE', @level1name = " . $this->nLiteral($table);
        if ($column !== null) {
            $sql .= ", @level2type = N'COLUMN', @level2name = " . $this->nLiteral($column);
        }
        return $sql;
    }

    // ------------------------------------------------------------------ DDL generation

    private function typeSql(array $col): string
    {
        $type = trim((string) ($col['type'] ?? ''));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*( [A-Za-z]+)?$/', $type)) {
            throw new ApiException('Invalid column type: ' . $type, 'bad_request', 400);
        }
        $len = isset($col['length']) && $col['length'] !== null ? trim((string) $col['length']) : '';
        if ($len === '') {
            return $type;
        }
        if (!preg_match('/^(\d+(\s*,\s*\d+)?|max)$/i', $len)) {
            throw new ApiException('Invalid length for column ' . ($col['name'] ?? '') . ': ' . $len, 'bad_request', 400);
        }
        return $type . '(' . preg_replace('/\s+/', '', strtolower($len) === 'max' ? 'max' : $len) . ')';
    }

    private function defaultSql(array $col): ?string
    {
        switch ((string) ($col['default_kind'] ?? 'none')) {
            case 'null':
                return 'NULL';
            case 'value':
                $v = (string) ($col['default'] ?? '');
                return preg_match('/^-?\d+(\.\d+)?$/', $v) && $this->isNumericType((string) ($col['type'] ?? '')) ? $v : $this->nLiteral($v);
            case 'expression':
                $e = trim((string) ($col['default'] ?? ''));
                return $e === '' ? null : $e;
        }
        return null;
    }

    private function columnSql(array $col, bool $withDefault = true): string
    {
        $name = (string) ($col['name'] ?? '');
        $sql = $this->quoteIdentifier($name) . ' ' . $this->typeSql($col);
        if (!empty($col['collation'])) {
            $sql .= ' COLLATE ' . $this->assertWord((string) $col['collation'], 'collation');
        }
        if (!empty($col['auto_increment'])) {
            $sql .= ' IDENTITY(1,1)';
        }
        $nullable = (!array_key_exists('nullable', $col) || !empty($col['nullable'])) && empty($col['auto_increment']);
        $sql .= $nullable ? ' NULL' : ' NOT NULL';
        if ($withDefault && empty($col['auto_increment'])) {
            $d = $this->defaultSql($col);
            if ($d !== null) {
                $sql .= ' DEFAULT ' . $d;
            }
        }
        return $sql;
    }

    private function indexName(string $table, array $idx, string $prefix): string
    {
        if (!empty($idx['name'])) {
            return (string) $idx['name'];
        }
        return substr($prefix . '_' . $table . '_' . implode('_', (array) ($idx['columns'] ?? [])), 0, 128);
    }

    public function buildCreateTable(array $definition): array
    {
        $name = (string) ($definition['name'] ?? '');
        if ($name === '' || empty($definition['columns'])) {
            throw new ApiException('Table name and at least one column are required', 'bad_request', 400);
        }
        $qt = $this->quoteTable($name);
        $parts = [];
        $after = [];
        foreach ((array) $definition['columns'] as $col) {
            $parts[] = '    ' . $this->columnSql($col);
            if (isset($col['comment']) && $col['comment'] !== null && $col['comment'] !== '') {
                $after[] = $this->extendedPropertySql('add', (string) $col['comment'], $name, (string) $col['name']);
            }
        }
        $pk = (array) ($definition['primary_key'] ?? []);
        if ($pk) {
            $parts[] = '    CONSTRAINT ' . $this->quoteIdentifier('PK_' . $name) . ' PRIMARY KEY (' . $this->columnList($pk) . ')';
        }
        foreach ((array) ($definition['foreign_keys'] ?? []) as $fk) {
            $fk['ref_schema'] = !empty($fk['ref_schema']) ? $fk['ref_schema'] : $this->schemaName();
            $parts[] = '    ' . (!empty($fk['name']) ? 'CONSTRAINT ' . $this->quoteIdentifier((string) $fk['name']) . ' ' : '')
                . $this->renderForeignKeyBody($fk);
        }
        $out = ['CREATE TABLE ' . $qt . " (\n" . implode(",\n", $parts) . "\n)"];
        foreach ((array) ($definition['indexes'] ?? []) as $idx) {
            $out[] = 'CREATE ' . (!empty($idx['unique']) ? 'UNIQUE ' : '') . 'INDEX '
                . $this->quoteIdentifier($this->indexName($name, $idx, !empty($idx['unique']) ? 'UQ' : 'IX'))
                . ' ON ' . $qt . ' (' . $this->columnList((array) ($idx['columns'] ?? [])) . ')';
        }
        $opts = (array) ($definition['options'] ?? []);
        if (!empty($opts['comment'])) {
            $out[] = $this->extendedPropertySql('add', (string) $opts['comment'], $name, null);
        }
        return array_merge($out, $after);
    }

    public function buildAlterTable(string $table, array $spec): array
    {
        $qt = $this->quoteTable($table);
        $alter = 'ALTER TABLE ' . $qt . ' ';
        $oid = $this->objectId($table);
        $cols = [];
        foreach ($this->rawColumns($oid) as $c) {
            $cols[$c['name']] = $c;
        }
        $constraintIdx = [];
        foreach ($this->rawIndexes($oid) as $i) {
            if ($i['is_primary_key'] || $i['is_unique_constraint']) {
                $constraintIdx[$i['name']] = true;
            }
        }
        $out = [];
        foreach ((array) ($spec['drop_fk'] ?? []) as $n) {
            $out[] = $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier((string) $n);
        }
        foreach ((array) ($spec['drop_index'] ?? []) as $n) {
            $n = (string) $n;
            if ($n === 'PRIMARY') {
                foreach ($this->rawIndexes($oid) as $i) {
                    if ($i['is_primary_key']) {
                        $n = (string) $i['name'];
                    }
                }
            }
            $out[] = isset($constraintIdx[$n])
                ? $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier($n)
                : 'DROP INDEX ' . $this->quoteIdentifier($n) . ' ON ' . $qt;
        }
        foreach ((array) ($spec['drop'] ?? []) as $c) {
            $c = (string) $c;
            if (isset($cols[$c]) && $cols[$c]['default_name'] !== null) {
                $out[] = $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier((string) $cols[$c]['default_name']);
            }
            $out[] = $alter . 'DROP COLUMN ' . $this->quoteIdentifier($c);
        }
        foreach ((array) ($spec['modify'] ?? []) as $m) {
            $old = (string) ($m['column'] ?? '');
            $def = (array) ($m['definition'] ?? []);
            if (!isset($cols[$old])) {
                throw $this->notFound('Column "' . $old . '"');
            }
            $cur = $cols[$old];
            if ((bool) $cur['is_identity'] !== !empty($def['auto_increment'])) {
                throw new ApiException('SQL Server cannot add or remove IDENTITY on an existing column; recreate the column instead', 'bad_request', 400);
            }
            $def['name'] = $old;
            $out[] = $alter . 'ALTER COLUMN ' . $this->columnSql($def, false);
            $newDefault = empty($def['auto_increment']) ? $this->defaultSql($def) : null;
            $curDefault = $cur['default_def'] !== null ? self::stripParens((string) $cur['default_def']) : null;
            if ($newDefault !== $curDefault) {
                if ($cur['default_name'] !== null) {
                    $out[] = $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier((string) $cur['default_name']);
                }
                if ($newDefault !== null) {
                    $out[] = $alter . 'ADD CONSTRAINT ' . $this->quoteIdentifier(substr('DF_' . $table . '_' . $old, 0, 128))
                        . ' DEFAULT ' . $newDefault . ' FOR ' . $this->quoteIdentifier($old);
                }
            }
            if (array_key_exists('comment', (array) ($m['definition'] ?? []))) {
                $newComment = $m['definition']['comment'];
                $newComment = $newComment === null || $newComment === '' ? null : (string) $newComment;
                if ($newComment !== $cur['comment']) {
                    if ($newComment === null) {
                        $out[] = $this->extendedPropertySql('drop', null, $table, $old);
                    } else {
                        $out[] = $this->extendedPropertySql($cur['comment'] === null ? 'add' : 'update', $newComment, $table, $old);
                    }
                }
            }
            $newName = isset($m['definition']['name']) && $m['definition']['name'] !== '' ? (string) $m['definition']['name'] : $old;
            if ($newName !== $old) {
                $out[] = 'EXEC sp_rename ' . $this->nLiteral($this->schemaName() . '.' . $table . '.' . $old) . ', '
                    . $this->nLiteral($newName) . ", N'COLUMN'";
            }
        }
        foreach ((array) ($spec['add'] ?? []) as $col) {
            $out[] = $alter . 'ADD ' . $this->columnSql($col);
            if (isset($col['comment']) && $col['comment'] !== null && $col['comment'] !== '') {
                $out[] = $this->extendedPropertySql('add', (string) $col['comment'], $table, (string) $col['name']);
            }
        }
        foreach ((array) ($spec['add_index'] ?? []) as $idx) {
            if (!empty($idx['primary'])) {
                $name = !empty($idx['name']) && $idx['name'] !== 'PRIMARY' ? (string) $idx['name'] : 'PK_' . $table;
                $out[] = $alter . 'ADD CONSTRAINT ' . $this->quoteIdentifier($name) . ' PRIMARY KEY (' . $this->columnList((array) $idx['columns']) . ')';
            } else {
                $out[] = 'CREATE ' . (!empty($idx['unique']) ? 'UNIQUE ' : '') . 'INDEX '
                    . $this->quoteIdentifier($this->indexName($table, $idx, !empty($idx['unique']) ? 'UQ' : 'IX'))
                    . ' ON ' . $qt . ' (' . $this->columnList((array) ($idx['columns'] ?? [])) . ')';
            }
        }
        foreach ((array) ($spec['add_fk'] ?? []) as $fk) {
            $fk['ref_schema'] = !empty($fk['ref_schema']) ? $fk['ref_schema'] : $this->schemaName();
            $out[] = $alter . 'ADD ' . (!empty($fk['name']) ? 'CONSTRAINT ' . $this->quoteIdentifier((string) $fk['name']) . ' ' : '')
                . $this->renderForeignKeyBody($fk);
        }
        $opts = (array) ($spec['options'] ?? []);
        if (array_key_exists('comment', $opts)) {
            $cur = $this->fetchValue(
                "SELECT CAST(value AS nvarchar(max)) FROM sys.extended_properties WHERE class = 1 AND major_id = ? AND minor_id = 0 AND name = 'MS_Description'",
                [$oid]
            );
            $new = $opts['comment'] === null || $opts['comment'] === '' ? null : (string) $opts['comment'];
            if ($new === null && $cur !== null) {
                $out[] = $this->extendedPropertySql('drop', null, $table, null);
            } elseif ($new !== null && $new !== $cur) {
                $out[] = $this->extendedPropertySql($cur === null ? 'add' : 'update', $new, $table, null);
            }
        }
        if (isset($opts['auto_increment']) && $opts['auto_increment'] !== null && $opts['auto_increment'] !== '') {
            $out[] = 'DBCC CHECKIDENT (' . $this->nLiteral($this->schemaName() . '.' . $table) . ', RESEED, '
                . ((int) $opts['auto_increment'] - 1) . ')';
        }
        $this->forgetTableInfo();
        return $out;
    }

    public function truncateTable(string $table): void
    {
        $this->exec('TRUNCATE TABLE ' . $this->quoteTable($table));
    }

    public function renameTable(string $table, string $newName): void
    {
        // consume all results: sp_rename emits a caution message; dblib would otherwise mix it
        // into the next statement's result sets
        $stmt = $this->run('EXEC sp_rename ?, ?', [$this->schemaName() . '.' . $table, $newName]);
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        $this->forgetTableInfo();
    }

    /**
     * SELECT … INTO copies columns, NULL-ability and IDENTITY; defaults and the primary key are
     * re-added afterwards (other indexes, FKs and triggers are not copied).
     */
    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        $info = $this->tableInfo($table);
        $prefix = ($targetDb !== null && $targetDb !== '') ? $this->quoteIdentifier($targetDb) . '.' : '';
        $dst = $prefix . $this->quoteIdentifier($this->schemaName()) . '.' . $this->quoteIdentifier($newName);
        $cols = [];
        foreach ($info['columns'] as $c) {
            $cols[] = $this->quoteIdentifier($c['name']);
        }
        $this->exec('SELECT ' . implode(', ', $cols) . ' INTO ' . $dst . ' FROM ' . $this->quoteTable($table) . ($withData ? '' : ' WHERE 1 = 0'));
        foreach ($this->rawColumns($this->objectId($table)) as $c) {
            if ($c['default_def'] !== null && !$c['is_computed']) {
                $this->exec('ALTER TABLE ' . $dst . ' ADD DEFAULT ' . $c['default_def'] . ' FOR ' . $this->quoteIdentifier((string) $c['name']));
            }
        }
        foreach ($info['indexes'] as $ix) {
            if ($ix['primary']) {
                $this->exec('ALTER TABLE ' . $dst . ' ADD CONSTRAINT ' . $this->quoteIdentifier(substr('PK_' . $newName, 0, 128))
                    . ' PRIMARY KEY (' . $this->columnList($ix['columns']) . ')');
            }
        }
    }

    public function maintenance(array $tables, string $op): array
    {
        $op = strtolower($op);
        $map = [
            'analyze' => 'UPDATE STATISTICS %s',
            'optimize' => 'ALTER INDEX ALL ON %s REBUILD',
            'rebuild' => 'ALTER INDEX ALL ON %s REBUILD',
            'reorganize' => 'ALTER INDEX ALL ON %s REORGANIZE',
            'check' => 'DBCC CHECKTABLE (%s) WITH NO_INFOMSGS',
        ];
        if (!isset($map[$op])) {
            throw $this->unsupported('Maintenance operation ' . $op);
        }
        $rows = [];
        foreach ($tables as $t) {
            $target = $op === 'check' ? $this->nLiteral($this->schemaName() . '.' . $t) : $this->quoteTable((string) $t);
            try {
                $this->exec(sprintf($map[$op], $target));
                $rows[] = [(string) $t, $op, 'status', 'OK'];
            } catch (\PDOException $e) {
                $rows[] = [(string) $t, $op, 'error', $e->getMessage()];
            }
        }
        return ['columns' => ['Table', 'Op', 'Msg_type', 'Msg_text'], 'rows' => $rows];
    }

    // ------------------------------------------------------------------ browse / editing hooks

    protected function limitClause(int $limit, int $offset): string
    {
        return ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    protected function buildPagedSelect(string $quotedTable, string $where, string $orderBy, int $limit, int $offset, array $info): string
    {
        if ($orderBy === '') {
            $pk = [];
            foreach ($info['indexes'] ?? [] as $ix) {
                if (!empty($ix['primary'])) {
                    $pk = $ix['columns'];
                }
            }
            $orderBy = ' ORDER BY ' . ($pk ? $this->columnList($pk) : '(SELECT NULL)');
        }
        return 'SELECT * FROM ' . $quotedTable . $where . $orderBy . $this->limitClause($limit, $offset);
    }

    /** '[' starts a character class in T-SQL LIKE patterns. */
    protected function likeSpecialChars(): array
    {
        return [self::LIKE_ESCAPE, '%', '_', '['];
    }

    public function isTextType(string $type): bool
    {
        return (bool) preg_match('/^(n?varchar|n?char|n?text|sysname|uniqueidentifier)\b/i', trim($type));
    }

    protected function isNumericType(string $type): bool
    {
        return (bool) preg_match('/^(tinyint|smallint|int|bigint|decimal|numeric|float|real|money|smallmoney|bit)\b/i', trim($type));
    }

    protected function castToText(string $quotedColumn, array $col): ?string
    {
        if (preg_match('/^(binary|varbinary|image|timestamp|rowversion|geography|geometry|hierarchyid|sql_variant)\b/i', (string) ($col['type'] ?? ''))) {
            return null;
        }
        return 'CAST(' . $quotedColumn . ' AS nvarchar(max))';
    }

    protected function estimateBrowseTotal(string $table): ?int
    {
        $n = $this->fetchValue(
            'SELECT SUM(rows) FROM sys.partitions WHERE object_id = OBJECT_ID(?) AND index_id IN (0, 1)',
            [$this->quoteTable($table)]
        );
        return ($n !== null && (int) $n > 500000) ? (int) $n : null;
    }

    /** Display literal: N'' keeps non-ASCII text intact in NVARCHAR columns. */
    protected function displayStringLiteral(string $s): string
    {
        return preg_match('/[^\x00-\x7F]/', $s) ? 'N' . parent::displayStringLiteral($s) : parent::displayStringLiteral($s);
    }

    protected function displayBinaryLiteral(string $hex): string
    {
        return '0x' . $hex;
    }

    /** INSERT + SCOPE_IDENTITY() in one batch (a separate batch would see another scope). */
    public function insertRow(string $table, array $values): array
    {
        $qt = $this->quoteTable($table);
        $info = $this->cachedTableInfo($table);
        $identity = null;
        foreach ($info['columns'] as $c) {
            if ($c['auto_increment']) {
                $identity = $c['name'];
            }
        }
        $explicitIdentity = $identity !== null && array_key_exists($identity, $values)
            && $values[$identity] !== null && $values[$identity] !== '';
        if ($identity !== null && !$explicitIdentity) {
            unset($values[$identity]);
        }
        if (!$values) {
            $sql = 'INSERT INTO ' . $qt . ' DEFAULT VALUES';
        } else {
            $cols = [];
            foreach (array_keys($values) as $c) {
                $cols[] = $this->quoteIdentifier((string) $c);
            }
            $sql = 'INSERT INTO ' . $qt . ' (' . implode(', ', $cols) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')';
        }
        if ($explicitIdentity) {
            $sql = 'SET IDENTITY_INSERT ' . $qt . ' ON; ' . $sql . '; SET IDENTITY_INSERT ' . $qt . ' OFF';
        }
        $sql = 'SET NOCOUNT OFF; ' . $sql . '; SELECT CAST(SCOPE_IDENTITY() AS varchar(40)) AS insert_id';
        $stmt = $this->run($sql, array_values($values));
        $affected = 0;
        $id = null;
        do {
            if ($stmt->columnCount() > 0) {
                $v = $stmt->fetchColumn();
                if ($v !== false && $v !== null) {
                    $id = (string) $v;
                }
            } elseif ($stmt->rowCount() > 0) {
                $affected += $stmt->rowCount();
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
        $this->forgetTableInfo();
        if ($explicitIdentity) {
            $id = (string) $values[$identity];
        }
        return ['affected' => $affected, 'insert_id' => $id];
    }

    public function explain(string $sql): array
    {
        // SHOWPLAN_TEXT returns one result set with the statement text and one with the plan;
        // both are merged into a single QueryResult.
        $this->exec('SET SHOWPLAN_TEXT ON');
        try {
            $stmt = $this->executeRaw($sql);
            $rows = [];
            do {
                if ($stmt->columnCount() > 0) {
                    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
                        $rows[] = [$this->normalizeValue($r[0])];
                    }
                }
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        } finally {
            $this->exec('SET SHOWPLAN_TEXT OFF');
        }
        return [
            'is_result_set' => true,
            'columns' => [['name' => 'StmtText']],
            'rows' => $rows,
            'affected' => null,
            'insert_id' => null,
            'truncated' => false,
            'error' => null,
            'editable' => null,
        ];
    }

    public function normalizeValue($value, ?string $type = null)
    {
        if (is_string($value) && $type !== null && preg_match('/^(timestamp|rowversion)$/i', $type)) {
            return '0x' . strtoupper(bin2hex($value));
        }
        return parent::normalizeValue($value, $type);
    }

    // ------------------------------------------------------------------ server admin

    public function variables(?string $filter = null): array
    {
        $list = [];
        foreach ($this->fetchAll('SELECT name, CAST(value_in_use AS nvarchar(256)) AS value FROM sys.configurations ORDER BY name') as $r) {
            $list[] = ['name' => $r['name'], 'value' => $r['value']];
        }
        $props = ['BuildClrVersion', 'Collation', 'Edition', 'EngineEdition', 'HadrManagerStatus', 'InstanceName',
            'IsClustered', 'IsFullTextInstalled', 'IsHadrEnabled', 'IsIntegratedSecurityOnly', 'MachineName',
            'ProductLevel', 'ProductUpdateLevel', 'ProductVersion', 'ServerName'];
        foreach ($props as $p) {
            $v = $this->fetchValue('SELECT CAST(SERVERPROPERTY(?) AS nvarchar(256))', [$p]);
            $list[] = ['name' => 'SERVERPROPERTY.' . $p, 'value' => $v];
        }
        return $this->filterNameValue($list, $filter);
    }

    public function status(): array
    {
        $r = $this->fetchOne(
            'SELECT @@CONNECTIONS AS connections, @@CPU_BUSY AS cpu_busy, @@IO_BUSY AS io_busy, @@IDLE AS idle,
                    @@PACK_RECEIVED AS packets_received, @@PACK_SENT AS packets_sent, @@PACKET_ERRORS AS packet_errors,
                    @@TOTAL_READ AS total_read, @@TOTAL_WRITE AS total_write, @@TOTAL_ERRORS AS total_errors,
                    @@MAX_CONNECTIONS AS max_connections, @@SPID AS current_spid'
        ) ?? [];
        $out = [];
        foreach ($r as $k => $v) {
            $out[] = ['name' => $k, 'value' => (string) $v];
        }
        try {
            foreach ($this->fetchAll(
                "SELECT RTRIM(counter_name) AS name, cntr_value AS value FROM sys.dm_os_performance_counters
                 WHERE counter_name IN ('User Connections', 'Batch Requests/sec', 'SQL Compilations/sec',
                       'Page life expectancy', 'Buffer cache hit ratio', 'Lock Waits/sec', 'Processes blocked',
                       'Transactions/sec', 'Number of Deadlocks/sec') AND (instance_name = '' OR instance_name = '_Total')"
            ) as $c) {
                $out[] = ['name' => $c['name'], 'value' => (string) $c['value']];
            }
        } catch (\PDOException $e) {
            // needs VIEW SERVER STATE
        }
        return $out;
    }

    public function processes(): array
    {
        $stmt = $this->run(
            'SELECT s.session_id AS id, s.login_name AS [user], s.host_name AS host, DB_NAME(s.database_id) AS db,
                    s.program_name, s.status, r.command, r.wait_type, r.total_elapsed_time AS elapsed_ms,
                    CONVERT(varchar(19), s.login_time, 120) AS login_time, t.text AS query
             FROM sys.dm_exec_sessions s
             LEFT JOIN sys.dm_exec_requests r ON r.session_id = s.session_id
             OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) t
             WHERE s.is_user_process = 1
             ORDER BY s.session_id'
        );
        $columns = [];
        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $m = $stmt->getColumnMeta($i);
            $columns[] = (string) ($m['name'] ?? ('col' . $i));
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
        return (string) $this->fetchValue('SELECT @@SPID');
    }

    /**
     * T-SQL cannot cancel just the running statement of another session: KILL ends the whole
     * session (its open transaction is rolled back). Requires ALTER ANY CONNECTION.
     */
    public function cancelBackend(string $id): bool
    {
        if (!ctype_digit($id)) {
            return false;
        }
        try {
            $this->exec('KILL ' . $id);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function users(): array
    {
        $rows = $this->fetchAll(
            "SELECT p.name, p.type_desc, p.is_disabled, p.default_database_name, CONVERT(varchar(19), p.create_date, 120) AS created,
                    dp.name AS db_user
             FROM sys.server_principals p
             LEFT JOIN sys.database_principals dp ON dp.sid = p.sid
             WHERE p.type IN ('S', 'U', 'G') AND p.name NOT LIKE '##%'
             ORDER BY p.name"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user' => $r['name'],
                'host' => null,
                'type' => $r['type_desc'],
                'disabled' => (bool) $r['is_disabled'],
                'default_database' => $r['default_database_name'],
                'created' => $r['created'],
                'database_user' => $r['db_user'],
            ];
        }
        return $out;
    }

    public function userGrants(string $user, ?string $host): array
    {
        $login = $this->fetchOne('SELECT principal_id, sid, is_disabled FROM sys.server_principals WHERE name = ?', [$user]);
        if ($login === null) {
            throw $this->notFound('Login "' . $user . '"');
        }
        $ql = $this->quoteIdentifier($user);
        $out = [];
        if ($login['is_disabled']) {
            $out[] = 'ALTER LOGIN ' . $ql . ' DISABLE;';
        }
        foreach ($this->fetchAll(
            'SELECT r.name FROM sys.server_role_members m JOIN sys.server_principals r ON r.principal_id = m.role_principal_id
             WHERE m.member_principal_id = ? ORDER BY r.name',
            [(int) $login['principal_id']]
        ) as $r) {
            $out[] = 'ALTER SERVER ROLE ' . $this->quoteIdentifier((string) $r['name']) . ' ADD MEMBER ' . $ql . ';';
        }
        foreach ($this->fetchAll(
            "SELECT state_desc, permission_name FROM sys.server_permissions WHERE grantee_principal_id = ?
             AND permission_name <> 'CONNECT SQL' ORDER BY permission_name",
            [(int) $login['principal_id']]
        ) as $p) {
            $out[] = $this->permissionSql((string) $p['state_desc'], (string) $p['permission_name'], '', $ql);
        }
        // database user mapped to this login in the current database
        $dbUser = $this->fetchOne(
            'SELECT dp.principal_id, dp.name FROM sys.database_principals dp JOIN sys.server_principals sp ON sp.sid = dp.sid WHERE sp.name = ?',
            [$user]
        );
        if ($dbUser !== null) {
            $qu = $this->quoteIdentifier((string) $dbUser['name']);
            foreach ($this->fetchAll(
                'SELECT r.name FROM sys.database_role_members m JOIN sys.database_principals r ON r.principal_id = m.role_principal_id
                 WHERE m.member_principal_id = ? ORDER BY r.name',
                [(int) $dbUser['principal_id']]
            ) as $r) {
                $out[] = 'ALTER ROLE ' . $this->quoteIdentifier((string) $r['name']) . ' ADD MEMBER ' . $qu . ';';
            }
            foreach ($this->fetchAll(
                "SELECT p.state_desc, p.permission_name, p.class, OBJECT_SCHEMA_NAME(p.major_id) AS sch,
                        OBJECT_NAME(p.major_id) AS obj, SCHEMA_NAME(p.major_id) AS schema_name
                 FROM sys.database_permissions p
                 WHERE p.grantee_principal_id = ? AND NOT (p.class = 0 AND p.permission_name = 'CONNECT')
                 ORDER BY p.class, sch, obj, p.permission_name",
                [(int) $dbUser['principal_id']]
            ) as $p) {
                $on = '';
                if ((int) $p['class'] === 1 && $p['obj'] !== null) {
                    $on = ' ON ' . $this->quoteIdentifier((string) $p['sch']) . '.' . $this->quoteIdentifier((string) $p['obj']);
                } elseif ((int) $p['class'] === 3 && $p['schema_name'] !== null) {
                    $on = ' ON SCHEMA::' . $this->quoteIdentifier((string) $p['schema_name']);
                }
                $out[] = $this->permissionSql((string) $p['state_desc'], (string) $p['permission_name'], $on, $qu);
            }
        }
        return $out;
    }

    private function permissionSql(string $state, string $perm, string $on, string $grantee): string
    {
        if ($state === 'GRANT_WITH_GRANT_OPTION') {
            return 'GRANT ' . $perm . $on . ' TO ' . $grantee . ' WITH GRANT OPTION;';
        }
        return ($state === 'DENY' ? 'DENY ' : 'GRANT ') . $perm . $on . ' TO ' . $grantee . ';';
    }

    /** Creates/updates a SQL login; a new login also gets a user in the current (non-master) database. */
    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void
    {
        $ql = $this->quoteIdentifier($user);
        if ($isNew) {
            if ($password === null || $password === '') {
                throw new ApiException('A password is required for new SQL Server logins', 'bad_request', 400);
            }
            $this->exec('CREATE LOGIN ' . $ql . ' WITH PASSWORD = ' . $this->nLiteral($password));
            if ($this->database !== null && strtolower($this->database) !== 'master') {
                $this->exec('CREATE USER ' . $ql . ' FOR LOGIN ' . $ql);
            }
        } elseif ($password !== null && $password !== '') {
            $this->exec('ALTER LOGIN ' . $ql . ' WITH PASSWORD = ' . $this->nLiteral($password));
        }
        foreach ($grants as $g) {
            $g = trim((string) $g);
            if ($g !== '') {
                $this->exec($g);
            }
        }
    }

    public function dropUser(string $user, ?string $host): void
    {
        $dbUser = $this->fetchValue(
            'SELECT dp.name FROM sys.database_principals dp JOIN sys.server_principals sp ON sp.sid = dp.sid WHERE sp.name = ?',
            [$user]
        );
        if ($dbUser !== null && $dbUser !== 'dbo') {
            $this->exec('DROP USER ' . $this->quoteIdentifier((string) $dbUser));
        }
        $this->exec('DROP LOGIN ' . $this->quoteIdentifier($user));
    }

    public function collations(): array
    {
        $default = $this->fetchValue("SELECT CAST(SERVERPROPERTY('Collation') AS nvarchar(128))");
        $out = [];
        foreach ($this->fetchAll('SELECT name FROM sys.fn_helpcollations() ORDER BY name') as $r) {
            $out[] = ['name' => $r['name'], 'charset' => null, 'default' => $r['name'] === $default];
        }
        return $out;
    }

    public function engines(): array
    {
        throw $this->unsupported('Storage engines');
    }

    public function dataTypes(): array
    {
        return [
            'Numeric' => ['tinyint', 'smallint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney', 'bit'],
            'String' => ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'sysname'],
            'Date/Time' => ['date', 'time', 'datetime2', 'datetimeoffset', 'datetime', 'smalldatetime'],
            'Binary' => ['varbinary', 'binary', 'image', 'rowversion'],
            'Other' => ['uniqueidentifier', 'xml', 'sql_variant', 'hierarchyid', 'geography', 'geometry'],
        ];
    }

    public function completionWords(): array
    {
        $keywords = ['TOP', 'OFFSET', 'FETCH NEXT', 'ROWS ONLY', 'OUTPUT', 'INSERTED', 'DELETED', 'MERGE', 'MATCHED',
            'IDENTITY', 'NVARCHAR', 'GO', 'EXEC', 'EXECUTE', 'DECLARE', 'PRINT', 'RAISERROR', 'THROW', 'TRY', 'CATCH',
            'NOLOCK', 'WITH (NOLOCK)', 'CROSS APPLY', 'OUTER APPLY', 'PIVOT', 'UNPIVOT', 'PROCEDURE', 'FUNCTION',
            'SCHEMA', 'USE', 'SET NOCOUNT ON', 'IDENTITY_INSERT', 'CLUSTERED', 'NONCLUSTERED', 'INCLUDE', 'PERSISTED'];
        $functions = ['GETDATE', 'GETUTCDATE', 'SYSDATETIME', 'SYSUTCDATETIME', 'DATEADD', 'DATEDIFF', 'DATEPART',
            'DATENAME', 'EOMONTH', 'FORMAT', 'CONVERT', 'TRY_CONVERT', 'TRY_CAST', 'ISNULL', 'IIF', 'CHOOSE', 'LEN',
            'DATALENGTH', 'CHARINDEX', 'PATINDEX', 'STUFF', 'LEFT', 'RIGHT', 'CONCAT', 'CONCAT_WS', 'STRING_AGG',
            'STRING_SPLIT', 'REPLICATE', 'REVERSE', 'NEWID', 'NEWSEQUENTIALID', 'SCOPE_IDENTITY', 'IDENT_CURRENT',
            'OBJECT_ID', 'OBJECT_NAME', 'DB_NAME', 'SCHEMA_NAME', 'SUSER_SNAME', 'JSON_VALUE', 'JSON_QUERY',
            'OPENJSON', 'ISJSON', 'ROW_NUMBER', 'NTILE', 'COUNT_BIG', 'STDEV', 'VAR'];
        return $this->mergeWords(parent::completionWords(), $keywords, $functions);
    }
}
