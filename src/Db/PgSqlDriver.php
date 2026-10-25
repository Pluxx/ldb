<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Http\ApiException;
use PDO;

/**
 * PostgreSQL 10–17 driver (pdo_pgsql).
 *
 * A PostgreSQL connection is bound to one database, so select() reconnects when the database
 * changes; the schema is applied through search_path and used to qualify every identifier.
 */
class PgSqlDriver extends AbstractPdoDriver
{
    private ?int $pgVersionNum = null;

    public static function id(): string
    {
        return 'pgsql';
    }

    public static function label(): string
    {
        return 'PostgreSQL';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_pgsql');
    }

    public static function defaultPort(): ?int
    {
        return 5432;
    }

    // ------------------------------------------------------------------ connection

    protected function buildDsn(array $params): string
    {
        $host = isset($params['host']) && $params['host'] !== '' ? (string) $params['host'] : 'localhost';
        $port = isset($params['port']) && $params['port'] !== '' && $params['port'] !== null ? (int) $params['port'] : 5432;
        $db = isset($params['database']) && $params['database'] !== '' ? (string) $params['database'] : 'postgres';
        $dsn = 'pgsql:host=' . self::pgDsnValue($host) . ';port=' . $port . ';dbname=' . self::pgDsnValue($db);
        $opts = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];
        if (!empty($opts['sslmode'])) {
            $dsn .= ';sslmode=' . self::pgDsnValue((string) $opts['sslmode']);
        }
        return $dsn;
    }

    /**
     * Quote a libpq conninfo value. pdo_pgsql turns every ';' of the DSN into a space and hands
     * the string to libpq, so an unquoted value containing a space ("db host=10.0.0.1") would
     * inject further keywords and bypass the configured server. ';' cannot be quoted at all.
     */
    public static function pgDsnValue(string $v): string
    {
        if (preg_match('/[;\x00-\x1F\x7F]/', $v)) {
            throw ApiException::badRequest('Invalid character in connection parameter');
        }
        return "'" . strtr($v, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    /**
     * Lazy: the PDO connection is opened on first use (see pdo()), so that select() can still
     * switch the target database without a throw-away connection to the default one.
     */
    public function connect(array $params): void
    {
        $this->params = $params;
        $this->pdo = null;
        $this->pgVersionNum = null;
        $this->database = isset($params['database']) && $params['database'] !== '' ? (string) $params['database'] : 'postgres';
        $this->schema = 'public';
        $this->forgetTableInfo();
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            if ($this->params === []) {
                throw new ApiException('Not connected', 'internal', 500);
            }
            parent::connect($this->params);
        }
        return $this->pdo;
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        // Session default for all following transactions; also blocks nextval()/setval() and
        // writes inside functions. (SET is rejected by ReadOnlyGuard, so users cannot undo it.)
        $mode = $readOnly ? 'READ ONLY' : 'READ WRITE';
        $this->pdo()->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ' . $mode);
        $this->pdo()->exec('SET default_transaction_read_only = ' . ($readOnly ? 'on' : 'off'));
    }

    protected function afterConnect(array $params): void
    {
        $this->pdo()->exec("SET client_encoding TO 'UTF8'");
        $t = $this->queryTimeout();
        if ($t > 0) {
            $this->pdo()->exec('SET statement_timeout TO ' . ($t * 1000));
        }
        $this->pgVersionNum = null;
        // pg always has a current database (default "postgres") and schema (default "public").
        $this->database = (string) $this->fetchValue('SELECT current_database()');
        $this->schema = 'public';
        $this->forgetTableInfo();
    }

    public function select(?string $database, ?string $schema = null): void
    {
        if ($database !== null && $database !== '' && $database !== $this->database) {
            $p = $this->params;
            $p['database'] = $database;
            $this->connect($p); // lazy: connects to the new database on the next pdo() call
        }
        $schema = $schema !== null && $schema !== '' ? $schema : 'public';
        $this->pdo()->exec('SET search_path TO ' . $this->quoteIdentifier($schema));
        $this->schema = $schema;
        $this->forgetTableInfo();
    }

    private function schemaName(): string
    {
        return $this->schema !== null && $this->schema !== '' ? $this->schema : 'public';
    }

    public function quoteTable(string $table): string
    {
        return $this->quoteIdentifier($this->schemaName()) . '.' . $this->quoteIdentifier($table);
    }

    private function pgVersion(): int
    {
        if ($this->pgVersionNum === null) {
            $this->pgVersionNum = (int) $this->fetchValue('SHOW server_version_num');
        }
        return $this->pgVersionNum;
    }

    // ------------------------------------------------------------------ small helpers

    private function pgJsonList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $d = json_decode((string) $value, true);
        return is_array($d) ? $d : [];
    }

    /** oid of a relation in the current schema, or not_found. */
    private function pgRelation(string $table): array
    {
        $row = $this->fetchOne(
            'SELECT c.oid, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relname = ?',
            [$this->schemaName(), $table]
        );
        if ($row === null) {
            throw ApiException::notFound('Table "' . $table . '" not found in schema "' . $this->schemaName() . '"');
        }
        return ['oid' => (int) $row['oid'], 'relkind' => (string) $row['relkind']];
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
            'charsets' => true,
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
            'db_copy' => true,
            'db_rename' => true,
            'monitor' => true,
        ];
    }

    public function serverInfo(): array
    {
        $row = $this->fetchOne(
            "SELECT current_setting('server_version') AS version, version() AS full_version, current_user AS usr,
                    current_setting('server_encoding') AS enc,
                    EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint AS uptime"
        ) ?? [];
        $host = isset($this->params['host']) && $this->params['host'] !== '' ? (string) $this->params['host'] : 'localhost';
        return [
            'name' => 'PostgreSQL',
            'version' => (string) ($row['version'] ?? ''),
            'version_comment' => (string) ($row['full_version'] ?? ''),
            'host' => $host,
            'user' => (string) ($row['usr'] ?? ''),
            'charset' => $row['enc'] ?? null,
            'uptime' => isset($row['uptime']) ? (int) $row['uptime'] : null,
            'driver' => self::id(),
        ];
    }

    public function listDatabases(): array
    {
        $rows = $this->fetchAll(
            "SELECT d.datname AS name, d.datcollate AS collation,
                    CASE WHEN has_database_privilege(d.datname, 'CONNECT') THEN pg_database_size(d.oid) END AS size
             FROM pg_database d
             WHERE NOT d.datistemplate
             ORDER BY d.datname"
        );
        // Table counts are only visible for the database we are connected to.
        $current = (int) $this->fetchValue(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r','p','v','m','f') AND NOT c.relispartition
               AND n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $r['name'],
                'collation' => $r['collation'],
                'tables' => $r['name'] === $this->database ? $current : null,
                'size' => $r['size'] === null ? null : (int) $r['size'],
            ];
        }
        return $out;
    }

    public function createDatabase(string $name, ?string $collation = null): void
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($name) . " TEMPLATE template0 ENCODING 'UTF8'";
        if ($collation !== null && $collation !== '') {
            // Accept either an OS locale ("en_US.utf8") or a libc collation name from pg_collation.
            $locale = $this->fetchValue(
                "SELECT collcollate FROM pg_collation WHERE collname = ? AND collprovider = 'c' LIMIT 1",
                [$collation]
            );
            $locale = $locale !== null && $locale !== '' ? (string) $locale : $collation;
            $sql .= ' LC_COLLATE ' . $this->quoteLiteral($locale) . ' LC_CTYPE ' . $this->quoteLiteral($locale);
        }
        $this->pdo()->exec($sql);
    }

    public function dropDatabase(string $name): void
    {
        if ($name === $this->database) {
            // Cannot drop the database we are connected to: move to the maintenance db first.
            $p = $this->params;
            $p['database'] = $name === 'postgres' ? 'template1' : 'postgres';
            $this->connect($p);
        }
        $this->pdo()->exec('DROP DATABASE ' . $this->quoteIdentifier($name));
    }

    // ------------------------------------------------------------------ database settings

    public function databaseInfo(string $name): array
    {
        $row = $this->fetchOne(
            "SELECT d.datname AS name, pg_get_userbyid(d.datdba) AS owner, pg_encoding_to_char(d.encoding) AS encoding,
                    d.datcollate AS lc_collate, d.datctype AS lc_ctype, d.datconnlimit AS connection_limit,
                    t.spcname AS tablespace, shobj_description(d.oid, 'pg_database') AS comment,
                    d.datallowconn AS allow_connections, d.datistemplate AS is_template,
                    CASE WHEN has_database_privilege(d.datname, 'CONNECT') THEN pg_database_size(d.oid) END AS size
             FROM pg_database d LEFT JOIN pg_tablespace t ON t.oid = d.dattablespace
             WHERE d.datname = ?",
            [$name]
        );
        if ($row === null) {
            throw $this->notFound('Database ' . $name);
        }
        $others = null;
        try {
            $others = (int) $this->fetchValue('SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$name]);
        } catch (\PDOException $e) {
        }
        $roles = [];
        try {
            foreach ($this->fetchAll("SELECT rolname FROM pg_roles WHERE rolname !~ '^pg_' ORDER BY rolname") as $r) {
                $roles[] = (string) $r['rolname'];
            }
        } catch (\PDOException $e) {
        }
        $tables = null;
        if ($name === $this->database) {
            $tables = (int) $this->fetchValue(
                "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind IN ('r','p') AND NOT c.relispartition
                   AND n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'"
            );
        }
        return [
            'driver' => 'pgsql',
            'name' => $name,
            'settings' => [
                'owner' => $row['owner'],
                'encoding' => $row['encoding'],
                'lc_collate' => $row['lc_collate'],
                'lc_ctype' => $row['lc_ctype'],
                'connection_limit' => (int) $row['connection_limit'],
                'tablespace' => $row['tablespace'],
                'comment' => $row['comment'],
                'allow_connections' => (bool) $row['allow_connections'],
                'is_template' => (bool) $row['is_template'],
            ],
            'size' => $row['size'] === null ? null : (int) $row['size'],
            'table_count' => $tables,
            'other_connections' => $others,
            'roles' => $roles,
            'current_user' => (string) $this->fetchValue('SELECT current_user'),
        ];
    }

    public function buildAlterDatabase(string $name, array $changes): array
    {
        return $this->planAlterDatabase($name, $changes);
    }

    /**
     * Pure SQL generation for db.alter: owner, comment, connection limit, then rename (last, so
     * the other statements still use the old name).
     * @return array{sql:string[],warnings:string[]}
     */
    public function planAlterDatabase(string $name, array $changes): array
    {
        self::assertChangeKeys($changes, ['owner', 'comment', 'connection_limit', 'rename']);
        $q = $this->quoteIdentifier($name);
        $sql = [];
        $warnings = [];
        $owner = self::changeString($changes, 'owner');
        if ($owner !== null) {
            $sql[] = 'ALTER DATABASE ' . $q . ' OWNER TO ' . $this->quoteIdentifier($owner);
        }
        if (array_key_exists('comment', $changes)) {
            $c = $changes['comment'];
            if ($c !== null && !is_string($c)) {
                throw ApiException::badRequest('Invalid value for comment');
            }
            $sql[] = 'COMMENT ON DATABASE ' . $q . ' IS ' . ($c === null || $c === '' ? 'NULL' : $this->quoteLiteral($c));
        }
        $limit = self::changeInt($changes, 'connection_limit', -1, 2147483647);
        if ($limit !== null) {
            $sql[] = 'ALTER DATABASE ' . $q . ' WITH CONNECTION LIMIT ' . $limit;
        }
        $rename = self::changeString($changes, 'rename');
        if ($rename !== null && $rename !== $name) {
            if (strlen($rename) > 63) {
                throw ApiException::badRequest('PostgreSQL database names are limited to 63 bytes');
            }
            $sql[] = 'ALTER DATABASE ' . $q . ' RENAME TO ' . $this->quoteIdentifier($rename);
            $warnings[] = 'pg_rename_exclusive';
        }
        return ['sql' => $sql, 'warnings' => $warnings];
    }

    public function alterDatabase(string $name, array $changes): array
    {
        $plan = $this->planAlterDatabase($name, $changes);
        if (in_array('pg_rename_exclusive', $plan['warnings'], true)) {
            // A database cannot be renamed while anyone (this session included) is connected to it.
            $this->connectMaintenance($name);
        }
        try {
            $this->runDatabaseStatements($plan['sql']);
        } catch (ApiException $e) {
            throw $this->pgInUse($e, $name);
        }
        return $plan;
    }

    /**
     * CREATE DATABASE new TEMPLATE old (run from a maintenance database). PostgreSQL refuses
     * while other sessions are connected to the template database; that error is explained.
     */
    public function copyDatabase(string $source, string $target, array $opts = []): array
    {
        if (!empty($opts['drop_source'])) {
            throw ApiException::badRequest('PostgreSQL renames databases natively: use rename instead of copy + drop');
        }
        if ($target === '' || $target === $source) {
            throw ApiException::badRequest('Choose a different name for the new database');
        }
        if (strlen($target) > 63) {
            throw ApiException::badRequest('PostgreSQL database names are limited to 63 bytes');
        }
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($target) . ' TEMPLATE ' . $this->quoteIdentifier($source);
        $result = ['sql' => [$sql], 'warnings' => ['pg_template_exclusive', 'copy_grants_not_copied'], 'executed' => false, 'copied' => [], 'skipped' => [], 'dropped' => false];
        if (!empty($opts['preview'])) {
            return $result;
        }
        if ($this->fetchValue('SELECT 1 FROM pg_database WHERE datname = ?', [$target]) !== null) {
            throw new ApiException('Database ' . $target . ' already exists', 'bad_request', 409);
        }
        $this->connectMaintenance($source);
        try {
            $this->runDatabaseStatements([$sql]);
        } catch (ApiException $e) {
            throw $this->pgInUse($e, $source);
        }
        $result['executed'] = true;
        return $result;
    }

    /** Reconnect to "postgres" (or template1) so that $avoid has no session of ours. */
    private function connectMaintenance(string $avoid): void
    {
        $candidates = $avoid === 'postgres' ? ['template1'] : ['postgres', 'template1'];
        $last = null;
        foreach ($candidates as $db) {
            $p = $this->params;
            $p['database'] = $db;
            $this->connect($p);
            try {
                $this->pdo();
                return;
            } catch (\PDOException $e) {
                $last = $e;
            }
        }
        throw ApiException::dbError('Cannot connect to a maintenance database (postgres/template1): ' . ($last ? $last->getMessage() : ''), null, $last);
    }

    /** SQLSTATE 55006 (object in use) → an explanation instead of the raw message. */
    private function pgInUse(ApiException $e, string $name): ApiException
    {
        $prev = $e->getPrevious();
        if ($prev instanceof \PDOException && (string) $prev->getCode() === '55006') {
            return new ApiException(
                'Database "' . $name . '" is being used by other sessions. PostgreSQL needs it to be idle for this: '
                . 'close other connections (Server → Processes, other tools or browser tabs) and try again.',
                'in_use',
                409,
                $e->getSql(),
                $prev
            );
        }
        return $e;
    }

    public function listSchemas(): array
    {
        $rows = $this->fetchAll(
            "SELECT nspname FROM pg_namespace
             WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema'
             ORDER BY nspname = 'public' DESC, nspname"
        );
        return array_map(static function (array $r): string {
            return (string) $r['nspname'];
        }, $rows);
    }

    // ------------------------------------------------------------------ objects

    public function listTables(): array
    {
        $rows = $this->fetchAll(
            "SELECT c.relname AS name, c.relkind,
                    CASE WHEN c.relkind IN ('r','p','m') AND c.reltuples >= 0 THEN c.reltuples::bigint END AS rows,
                    CASE WHEN c.relkind IN ('r','p','m') THEN pg_table_size(c.oid) END AS data_size,
                    CASE WHEN c.relkind IN ('r','p','m') THEN pg_indexes_size(c.oid) END AS index_size,
                    obj_description(c.oid, 'pg_class') AS comment,
                    GREATEST(s.last_vacuum, s.last_autovacuum, s.last_analyze, s.last_autoanalyze) AS updated
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
             WHERE n.nspname = ? AND c.relkind IN ('r','p','v','m','f') AND NOT c.relispartition
             ORDER BY c.relname",
            [$this->schemaName()]
        );
        $kinds = ['p' => 'partitioned', 'm' => 'materialized view', 'f' => 'foreign table'];
        $out = [];
        foreach ($rows as $r) {
            $kind = (string) $r['relkind'];
            $out[] = [
                'name' => $r['name'],
                'type' => in_array($kind, ['v', 'm'], true) ? 'view' : 'table',
                'rows' => $r['rows'] === null ? null : (int) $r['rows'],
                'data_size' => $r['data_size'] === null ? null : (int) $r['data_size'],
                'index_size' => $r['index_size'] === null ? null : (int) $r['index_size'],
                'engine' => $kinds[$kind] ?? null,
                'collation' => null,
                'comment' => $r['comment'],
                'auto_increment' => null,
                'updated' => $r['updated'],
            ];
        }
        return $out;
    }

    public function listRoutines(): array
    {
        $kindExpr = $this->pgVersion() >= 110000
            ? "p.prokind"
            : "CASE WHEN p.proisagg THEN 'a' WHEN p.proiswindow THEN 'w' ELSE 'f' END";
        $rows = $this->fetchAll(
            "SELECT p.proname AS name, $kindExpr AS kind,
                    pg_get_function_identity_arguments(p.oid) AS args,
                    pg_get_function_result(p.oid) AS returns,
                    pg_get_userbyid(p.proowner) AS definer,
                    l.lanname AS language
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             JOIN pg_language l ON l.oid = p.prolang
             WHERE n.nspname = ?
               AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass
                               AND d.objid = p.oid AND d.deptype = 'e')
             ORDER BY p.proname, 3",
            [$this->schemaName()]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!in_array($r['kind'], ['f', 'p'], true)) {
                continue; // aggregates / window functions
            }
            $out[] = [
                'name' => $r['name'],
                'type' => $r['kind'] === 'p' ? 'PROCEDURE' : 'FUNCTION',
                'returns' => $r['kind'] === 'p' ? null : $r['returns'],
                'definer' => $r['definer'],
                'arguments' => $r['args'],
                'signature' => $r['name'] . '(' . $r['args'] . ')',
                'language' => $r['language'],
            ];
        }
        return $out;
    }

    public function listTriggers(): array
    {
        $rows = $this->fetchAll(
            'SELECT t.tgname AS name, c.relname AS tbl, t.tgtype, pg_get_triggerdef(t.oid, true) AS def
             FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND NOT t.tgisinternal
             ORDER BY c.relname, t.tgname',
            [$this->schemaName()]
        );
        $out = [];
        foreach ($rows as $r) {
            $t = $this->pgDecodeTrigger((int) $r['tgtype']);
            $out[] = [
                'name' => $r['name'],
                'table' => $r['tbl'],
                'timing' => $t['timing'],
                'event' => $t['event'],
                'statement' => $r['def'],
            ];
        }
        return $out;
    }

    /** Decode pg_trigger.tgtype bit mask. */
    private function pgDecodeTrigger(int $type): array
    {
        $timing = ($type & 2) ? 'BEFORE' : (($type & 64) ? 'INSTEAD OF' : 'AFTER');
        $events = [];
        if ($type & 4) {
            $events[] = 'INSERT';
        }
        if ($type & 16) {
            $events[] = 'UPDATE';
        }
        if ($type & 8) {
            $events[] = 'DELETE';
        }
        if ($type & 32) {
            $events[] = 'TRUNCATE';
        }
        return ['timing' => $timing, 'event' => implode(' OR ', $events), 'level' => ($type & 1) ? 'ROW' : 'STATEMENT'];
    }

    public function listEvents(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ table info

    private function pgActionName(?string $code): ?string
    {
        $map = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];
        return $code !== null && isset($map[$code]) ? $map[$code] : null;
    }

    /** Raw column rows from pg_attribute. */
    private function pgColumns(int $oid): array
    {
        $generated = $this->pgVersion() >= 120000 ? 'a.attgenerated' : "''::text";
        return $this->fetchAll(
            "SELECT a.attnum, a.attname AS name, format_type(a.atttypid, a.atttypmod) AS full_type,
                    t.typname, t.typtype, a.attnotnull AS notnull, pg_get_expr(d.adbin, d.adrelid) AS default_expr,
                    a.attidentity AS identity, $generated AS generated,
                    col_description(a.attrelid, a.attnum) AS comment,
                    CASE WHEN a.attcollation <> t.typcollation THEN co.collname END AS collation
             FROM pg_attribute a
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             LEFT JOIN pg_collation co ON co.oid = a.attcollation
             WHERE a.attrelid = ? AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            [$oid]
        );
    }

    /** Split "character varying(20)[]" into ["character varying[]", "20"]. */
    private function pgSplitType(string $fullType): array
    {
        if (preg_match('/^(.*?)\(([^)]*)\)(.*)$/', $fullType, $m)) {
            $type = trim(preg_replace('/\s+/', ' ', $m[1] . $m[3]));
            $type = str_replace(' [', '[', $type);
            return [$type, $m[2]];
        }
        return [$fullType, null];
    }

    /** Classify a pg default expression into [kind, value]. */
    private function pgClassifyDefault(?string $expr): array
    {
        if ($expr === null) {
            return ['none', null];
        }
        if (preg_match('/^NULL(::[\w\s".\[\]()]+)?$/i', $expr)) {
            return ['null', null];
        }
        if (preg_match("/^'((?:[^']|'')*)'(::[\\w\\s\".\\[\\]()]+)?$/s", $expr, $m)) {
            return ['value', str_replace("''", "'", $m[1])];
        }
        if (preg_match('/^\(?(-?\d+(\.\d+)?)\)?(::[\w\s".]+)?$/', $expr, $m)) {
            return ['value', $m[1]];
        }
        if (preg_match('/^(true|false)$/i', $expr)) {
            return ['value', strtolower($expr)];
        }
        return ['expression', $expr];
    }

    private function pgIsSerialDefault(?string $expr): bool
    {
        return $expr !== null && stripos($expr, 'nextval(') === 0;
    }

    public function tableInfo(string $table): array
    {
        $rel = $this->pgRelation($table);
        $oid = $rel['oid'];

        // indexes
        $nkey = $this->pgVersion() >= 110000 ? 'ix.indnkeyatts' : 'ix.indnatts';
        $idxRows = $this->fetchAll(
            "SELECT i.relname AS name, ix.indisunique AS uniq, ix.indisprimary AS prim, am.amname AS method,
                    (SELECT json_agg(CASE WHEN ix.indkey[s.i - 1] <> 0
                                          THEN (SELECT attname FROM pg_attribute WHERE attrelid = ix.indrelid AND attnum = ix.indkey[s.i - 1])
                                          ELSE pg_get_indexdef(ix.indexrelid, s.i, true) END ORDER BY s.i)
                     FROM generate_series(1, $nkey) AS s(i)) AS cols
             FROM pg_index ix
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_am am ON am.oid = i.relam
             WHERE ix.indrelid = ?
             ORDER BY ix.indisprimary DESC, i.relname",
            [$oid]
        );
        $indexes = [];
        $pkCols = [];
        foreach ($idxRows as $r) {
            $cols = $this->pgJsonList($r['cols']);
            $primary = (bool) $r['prim'];
            if ($primary) {
                $pkCols = $cols;
            }
            $indexes[] = [
                'name' => $r['name'],
                'columns' => $cols,
                'unique' => (bool) $r['uniq'],
                'primary' => $primary,
                'type' => strtoupper((string) $r['method']),
            ];
        }

        // columns
        $columns = [];
        $autoInc = null;
        foreach ($this->pgColumns($oid) as $r) {
            [$type, $length] = $this->pgSplitType((string) $r['full_type']);
            $identity = (string) $r['identity'];
            $gen = (string) $r['generated'];
            $expr = $r['default_expr'];
            $isSerial = $this->pgIsSerialDefault($expr);
            $extra = null;
            $generated = null;
            if ($identity === 'a') {
                $extra = 'GENERATED ALWAYS AS IDENTITY';
            } elseif ($identity === 'd') {
                $extra = 'GENERATED BY DEFAULT AS IDENTITY';
            } elseif ($isSerial) {
                $extra = 'serial: ' . $expr;
            }
            if ($gen === 's') {
                $generated = $expr;
                $expr = null;
                $extra = 'GENERATED ALWAYS AS (' . $generated . ') STORED';
            }
            [$kind, $default] = ($isSerial || $identity !== '') ? ['none', null] : $this->pgClassifyDefault($expr);
            if ($kind === 'expression') {
                $default = $expr;
            }
            $ai = $identity !== '' || $isSerial;
            if ($ai && $autoInc === null) {
                $autoInc = $r['name'];
            }
            $columns[] = [
                'name' => $r['name'],
                'type' => $type,
                'full_type' => $r['full_type'],
                'length' => $length,
                'unsigned' => false,
                'nullable' => !$r['notnull'],
                'default' => $default,
                'default_kind' => $kind,
                'auto_increment' => $ai,
                'primary' => in_array($r['name'], $pkCols, true),
                'comment' => $r['comment'],
                'collation' => $r['collation'],
                'extra' => $extra,
                'generated' => $generated,
            ];
        }

        // foreign keys (outgoing + incoming)
        $fkSql = "SELECT con.conname AS name, con.confupdtype, con.confdeltype,
                         rn.nspname AS ref_schema, rc.relname AS ref_table,
                         sn.nspname AS src_schema, sc.relname AS src_table,
                         (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
                          JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) AS cols,
                         (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(con.confkey) WITH ORDINALITY AS k(attnum, ord)
                          JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.attnum) AS ref_cols
                  FROM pg_constraint con
                  JOIN pg_class rc ON rc.oid = con.confrelid JOIN pg_namespace rn ON rn.oid = rc.relnamespace
                  JOIN pg_class sc ON sc.oid = con.conrelid JOIN pg_namespace sn ON sn.oid = sc.relnamespace
                  WHERE con.contype = 'f' AND %s = ?
                  ORDER BY con.conname";
        $mapFk = function (array $r, bool $incoming): array {
            $fk = [
                'name' => $r['name'],
                'columns' => $this->pgJsonList($r['cols']),
                'ref_schema' => $r['ref_schema'],
                'ref_table' => $r['ref_table'],
                'ref_columns' => $this->pgJsonList($r['ref_cols']),
                'on_delete' => $this->pgActionName($r['confdeltype']),
                'on_update' => $this->pgActionName($r['confupdtype']),
            ];
            if ($incoming) {
                $fk['table'] = $r['src_table'];
                $fk['schema'] = $r['src_schema'];
            }
            return $fk;
        };
        $fks = [];
        foreach ($this->fetchAll(sprintf($fkSql, 'con.conrelid'), [$oid]) as $r) {
            $fks[] = $mapFk($r, false);
        }
        $refBy = [];
        foreach ($this->fetchAll(sprintf($fkSql, 'con.confrelid'), [$oid]) as $r) {
            $refBy[] = $mapFk($r, true);
        }

        // triggers
        $triggers = [];
        foreach ($this->fetchAll(
            'SELECT tgname, tgtype, pg_get_triggerdef(oid, true) AS def FROM pg_trigger
             WHERE tgrelid = ? AND NOT tgisinternal ORDER BY tgname',
            [$oid]
        ) as $r) {
            $t = $this->pgDecodeTrigger((int) $r['tgtype']);
            $triggers[] = ['name' => $r['tgname'], 'timing' => $t['timing'], 'event' => $t['event'], 'statement' => $r['def']];
        }

        $meta = $this->fetchOne(
            "SELECT obj_description(c.oid, 'pg_class') AS comment,
                    CASE WHEN c.reltuples >= 0 THEN c.reltuples::bigint END AS rows
             FROM pg_class c WHERE c.oid = ?",
            [$oid]
        ) ?? [];
        $autoValue = null;
        if ($autoInc !== null) {
            try {
                $seq = $this->fetchValue('SELECT pg_get_serial_sequence(?, ?)', [$this->quoteTable($table), $autoInc]);
                if ($seq !== null) {
                    $s = $this->fetchOne('SELECT last_value, is_called FROM ' . $this->pgSeqIdent((string) $seq));
                    if ($s !== null) {
                        $autoValue = (int) $s['last_value'] + ($s['is_called'] ? 1 : 0);
                    }
                }
            } catch (\PDOException $e) {
                $autoValue = null; // no privilege on the sequence
            }
        }
        $kinds = ['r' => 'table', 'p' => 'table', 'f' => 'table', 'v' => 'view', 'm' => 'view'];

        return [
            'name' => $table,
            'type' => $kinds[$rel['relkind']] ?? 'table',
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $fks,
            'referenced_by' => $refBy,
            'triggers' => $triggers,
            'options' => [
                'engine' => null,
                'collation' => null,
                'comment' => $meta['comment'] ?? null,
                'rows' => isset($meta['rows']) ? (int) $meta['rows'] : null,
                'auto_increment' => $autoValue,
            ],
        ];
    }

    /**
     * pg_get_serial_sequence() returns an already quoted, qualified name; it is still re-validated
     * before use in a FROM clause.
     */
    private function pgSeqIdent(string $seq): string
    {
        if (!preg_match('/^("([^"]|"")+"|[a-z_][a-z0-9_$]*)(\.("([^"]|"")+"|[a-z_][a-z0-9_$]*))?$/', $seq)) {
            throw new ApiException('Unexpected sequence name', 'internal', 500);
        }
        return $seq;
    }

    // ------------------------------------------------------------------ DDL output

    public function createStatement(string $name, string $type = 'table'): string
    {
        $type = strtolower($type);
        switch ($type) {
            case 'view':
                return $this->pgViewDdl($name);
            case 'routine':
            case 'function':
            case 'procedure':
                return $this->pgRoutineDdl($name);
            case 'trigger':
                return $this->pgTriggerDdl($name);
            case 'event':
                throw ApiException::unsupported('PostgreSQL has no events');
            case 'table':
            default:
                $rel = $this->pgRelation($name);
                if (in_array($rel['relkind'], ['v', 'm'], true)) {
                    return $this->pgViewDdl($name);
                }
                return $this->pgTableDdl($name, $rel['oid'], $rel['relkind']);
        }
    }

    private function pgTableDdl(string $table, int $oid, string $relkind): string
    {
        $qt = $this->quoteTable($table);
        $lines = [];
        $comments = [];
        foreach ($this->pgColumns($oid) as $c) {
            $line = '    ' . $this->quoteIdentifier($c['name']) . ' ';
            $expr = $c['default_expr'];
            $serialType = null;
            if ($this->pgIsSerialDefault($expr) && in_array($c['typname'], ['int2', 'int4', 'int8'], true)) {
                $serialType = ['int2' => 'smallserial', 'int4' => 'serial', 'int8' => 'bigserial'][$c['typname']];
            }
            $line .= $serialType ?? $c['full_type'];
            if ($c['collation'] !== null) {
                $line .= ' COLLATE ' . $this->quoteIdentifier($c['collation']);
            }
            if ($c['generated'] === 's') {
                $line .= ' GENERATED ALWAYS AS (' . $expr . ') STORED';
            } elseif ($c['identity'] === 'a') {
                $line .= ' GENERATED ALWAYS AS IDENTITY';
            } elseif ($c['identity'] === 'd') {
                $line .= ' GENERATED BY DEFAULT AS IDENTITY';
            } elseif ($expr !== null && $serialType === null) {
                $line .= ' DEFAULT ' . $expr;
            }
            if ($c['notnull']) {
                $line .= ' NOT NULL';
            }
            $lines[] = $line;
            if ($c['comment'] !== null) {
                $comments[] = 'COMMENT ON COLUMN ' . $qt . '.' . $this->quoteIdentifier($c['name'])
                    . ' IS ' . $this->quoteLiteral($c['comment']) . ';';
            }
        }
        foreach ($this->fetchAll(
            "SELECT conname, pg_get_constraintdef(oid, true) AS def FROM pg_constraint
             WHERE conrelid = ? AND contype IN ('p','u','c','x','f')
             ORDER BY CASE contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 WHEN 'x' THEN 3 ELSE 4 END, conname",
            [$oid]
        ) as $con) {
            $lines[] = '    CONSTRAINT ' . $this->quoteIdentifier($con['conname']) . ' ' . $con['def'];
        }
        $sql = 'CREATE ' . ($relkind === 'f' ? 'FOREIGN ' : '') . 'TABLE ' . $qt . " (\n" . implode(",\n", $lines) . "\n)";
        if ($relkind === 'p') {
            $part = $this->fetchValue('SELECT pg_get_partkeydef(?)', [$oid]);
            if ($part !== null) {
                $sql .= ' PARTITION BY ' . $part;
            }
        }
        $out = [$sql . ';'];
        foreach ($this->fetchAll(
            'SELECT pg_get_indexdef(ix.indexrelid) AS def FROM pg_index ix
             WHERE ix.indrelid = ? AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = ix.indexrelid AND c.conrelid = ix.indrelid)
             ORDER BY ix.indexrelid',
            [$oid]
        ) as $ix) {
            $out[] = $ix['def'] . ';';
        }
        $tc = $this->fetchValue("SELECT obj_description(?::oid, 'pg_class')", [$oid]);
        if ($tc !== null) {
            $out[] = 'COMMENT ON TABLE ' . $qt . ' IS ' . $this->quoteLiteral((string) $tc) . ';';
        }
        foreach ($comments as $c) {
            $out[] = $c;
        }
        foreach ($this->fetchAll('SELECT pg_get_triggerdef(oid, true) AS def FROM pg_trigger WHERE tgrelid = ? AND NOT tgisinternal ORDER BY tgname', [$oid]) as $t) {
            $out[] = $t['def'] . ';';
        }
        return implode("\n", $out);
    }

    private function pgViewDdl(string $name): string
    {
        $rel = $this->pgRelation($name);
        if (!in_array($rel['relkind'], ['v', 'm'], true)) {
            throw ApiException::badRequest('"' . $name . '" is not a view');
        }
        $def = rtrim((string) $this->fetchValue('SELECT pg_get_viewdef(?::oid, true)', [$rel['oid']]));
        $def = rtrim($def, ';');
        $qt = $this->quoteTable($name);
        $sql = $rel['relkind'] === 'm'
            ? 'CREATE MATERIALIZED VIEW ' . $qt . " AS\n" . $def . ';'
            : 'CREATE OR REPLACE VIEW ' . $qt . " AS\n" . $def . ';';
        $c = $this->fetchValue("SELECT obj_description(?::oid, 'pg_class')", [$rel['oid']]);
        if ($c !== null) {
            $sql .= "\nCOMMENT ON " . ($rel['relkind'] === 'm' ? 'MATERIALIZED VIEW ' : 'VIEW ') . $qt . ' IS ' . $this->quoteLiteral((string) $c) . ';';
        }
        return $sql;
    }

    /** $name may be a bare name (all overloads are returned) or a signature "fn(integer, text)". */
    private function pgRoutineDdl(string $name): string
    {
        if (strpos($name, '(') !== false) {
            $oid = $this->fetchValue('SELECT ?::regprocedure::oid', [$this->quoteIdentifier($this->schemaName()) . '.' . $this->pgQualifySignature($name)]);
            $oids = $oid === null ? [] : [(int) $oid];
        } else {
            $oids = array_map(static function (array $r): int {
                return (int) $r['oid'];
            }, $this->fetchAll(
                'SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = ? AND p.proname = ? ORDER BY p.oid',
                [$this->schemaName(), $name]
            ));
        }
        if (!$oids) {
            throw ApiException::notFound('Routine "' . $name . '" not found');
        }
        $parts = [];
        foreach ($oids as $oid) {
            $parts[] = rtrim((string) $this->fetchValue('SELECT pg_get_functiondef(?::oid)', [$oid])) . ';';
        }
        return implode("\n\n", $parts);
    }

    /** Quote the bare function name of a signature, keep the argument list (parsed by regprocedure). */
    private function pgQualifySignature(string $sig): string
    {
        $pos = strpos($sig, '(');
        $fn = trim(substr($sig, 0, $pos));
        if ($fn !== '' && $fn[0] === '"') {
            return $sig; // already quoted
        }
        return $this->quoteIdentifier($fn) . substr($sig, $pos);
    }

    private function pgTriggerDdl(string $name): string
    {
        $rows = $this->fetchAll(
            'SELECT pg_get_triggerdef(t.oid, true) AS def FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND t.tgname = ? AND NOT t.tgisinternal ORDER BY c.relname',
            [$this->schemaName(), $name]
        );
        if (!$rows) {
            throw ApiException::notFound('Trigger "' . $name . '" not found');
        }
        return implode("\n", array_map(static function (array $r): string {
            return $r['def'] . ';';
        }, $rows));
    }

    // ------------------------------------------------------------------ DDL generation

    /** Validated type expression for a ColumnDefinition (type names cannot be bound). */
    private function pgTypeSql(array $col, bool $forAlter = false): string
    {
        $type = trim((string) ($col['type'] ?? ''));
        $length = isset($col['length']) && $col['length'] !== null ? trim((string) $col['length']) : '';
        if ($type === '' || !preg_match('/^[A-Za-z_"][A-Za-z0-9_ ."$]*(\(\s*\d+(\s*,\s*-?\d+)?\s*\))?([A-Za-z ]*)(\[\d*\])*$/', $type)
            || substr_count($type, '"') % 2 !== 0) {
            throw ApiException::badRequest('Invalid column type: ' . $type);
        }
        if ($length !== '' && !preg_match('/^\d+(\s*,\s*-?\d+)?$/', $length)) {
            throw ApiException::badRequest('Invalid length for column ' . ($col['name'] ?? '') . ': ' . $length);
        }
        $lower = strtolower(preg_replace('/\s+/', ' ', $type));
        if ($forAlter) {
            $map = ['serial' => 'integer', 'serial4' => 'integer', 'bigserial' => 'bigint', 'serial8' => 'bigint',
                    'smallserial' => 'smallint', 'serial2' => 'smallint'];
            if (isset($map[$lower])) {
                $type = $map[$lower];
                $lower = $type;
            }
        }
        if ($length !== '' && strpos($type, '(') === false) {
            $arr = '';
            if (preg_match('/^(.*?)((\[\d*\])+)$/', $type, $m)) {
                $type = $m[1];
                $arr = $m[2];
            }
            if (preg_match('/^(timestamp|time)(\s+with(out)?\s+time\s+zone)$/i', trim($type), $m)) {
                $type = $m[1] . '(' . $length . ')' . $m[2];
            } else {
                $type = rtrim($type) . '(' . $length . ')';
            }
            $type .= $arr;
        }
        return $type;
    }

    private function pgIsSerialType(string $type): bool
    {
        return in_array(strtolower(trim($type)), ['serial', 'serial4', 'bigserial', 'serial8', 'smallserial', 'serial2'], true);
    }

    private function pgDefaultSql(array $col): ?string
    {
        $kind = $col['default_kind'] ?? 'none';
        $def = $col['default'] ?? null;
        switch ($kind) {
            case 'null':
                return 'NULL';
            case 'value':
                return $this->quoteLiteral((string) $def);
            case 'expression':
                $def = trim((string) $def);
                return $def === '' ? null : $def;
            default:
                return null;
        }
    }

    private function pgColumnSql(array $col): string
    {
        $name = (string) ($col['name'] ?? '');
        if ($name === '') {
            throw ApiException::badRequest('Column name is required');
        }
        $type = $this->pgTypeSql($col);
        $sql = $this->quoteIdentifier($name) . ' ' . $type;
        if (!empty($col['collation'])) {
            $sql .= ' COLLATE ' . $this->quoteIdentifier((string) $col['collation']);
        }
        if (!empty($col['auto_increment']) && !$this->pgIsSerialType($type)) {
            $sql .= ' GENERATED BY DEFAULT AS IDENTITY';
        } elseif (empty($col['auto_increment'])) {
            $d = $this->pgDefaultSql($col);
            if ($d !== null) {
                $sql .= ' DEFAULT ' . $d;
            }
        }
        $nullable = !array_key_exists('nullable', $col) || !empty($col['nullable']);
        if (!empty($col['auto_increment'])) {
            $nullable = false;
        }
        $sql .= $nullable ? ' NULL' : ' NOT NULL';
        return $sql;
    }

    private function pgCols(array $cols): string
    {
        if (!$cols) {
            throw ApiException::badRequest('Column list must not be empty');
        }
        return implode(', ', array_map(function ($c): string {
            return $this->quoteIdentifier((string) $c);
        }, $cols));
    }

    private function pgFkAction(?string $action): ?string
    {
        if ($action === null || $action === '') {
            return null;
        }
        $a = strtoupper(trim(preg_replace('/\s+/', ' ', $action)));
        if (!in_array($a, ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'], true)) {
            throw ApiException::badRequest('Invalid foreign key action: ' . $action);
        }
        return $a;
    }

    private function pgFkSql(array $fk): string
    {
        $sql = '';
        if (!empty($fk['name'])) {
            $sql .= 'CONSTRAINT ' . $this->quoteIdentifier((string) $fk['name']) . ' ';
        }
        $refSchema = !empty($fk['ref_schema']) ? (string) $fk['ref_schema'] : $this->schemaName();
        $sql .= 'FOREIGN KEY (' . $this->pgCols((array) ($fk['columns'] ?? [])) . ') REFERENCES '
            . $this->quoteIdentifier($refSchema) . '.' . $this->quoteIdentifier((string) ($fk['ref_table'] ?? ''))
            . ' (' . $this->pgCols((array) ($fk['ref_columns'] ?? [])) . ')';
        $d = $this->pgFkAction($fk['on_delete'] ?? null);
        if ($d !== null) {
            $sql .= ' ON DELETE ' . $d;
        }
        $u = $this->pgFkAction($fk['on_update'] ?? null);
        if ($u !== null) {
            $sql .= ' ON UPDATE ' . $u;
        }
        return $sql;
    }

    private function pgIndexSql(string $qt, array $idx): string
    {
        $sql = 'CREATE ' . (!empty($idx['unique']) ? 'UNIQUE ' : '') . 'INDEX ';
        if (!empty($idx['name'])) {
            $sql .= $this->quoteIdentifier((string) $idx['name']) . ' ';
        }
        return $sql . 'ON ' . $qt . ' (' . $this->pgCols((array) ($idx['columns'] ?? [])) . ')';
    }

    public function buildCreateTable(array $definition): array
    {
        $name = (string) ($definition['name'] ?? '');
        if ($name === '') {
            throw ApiException::badRequest('Table name is required');
        }
        $columns = (array) ($definition['columns'] ?? []);
        if (!$columns) {
            throw ApiException::badRequest('A table needs at least one column');
        }
        $qt = $this->quoteTable($name);
        $parts = [];
        $comments = [];
        foreach ($columns as $col) {
            $parts[] = '    ' . $this->pgColumnSql($col);
            if (isset($col['comment']) && $col['comment'] !== null && $col['comment'] !== '') {
                $comments[] = 'COMMENT ON COLUMN ' . $qt . '.' . $this->quoteIdentifier((string) $col['name'])
                    . ' IS ' . $this->quoteLiteral((string) $col['comment']);
            }
        }
        $pk = (array) ($definition['primary_key'] ?? []);
        if ($pk) {
            $parts[] = '    PRIMARY KEY (' . $this->pgCols($pk) . ')';
        }
        foreach ((array) ($definition['foreign_keys'] ?? []) as $fk) {
            $parts[] = '    ' . $this->pgFkSql($fk);
        }
        $out = ['CREATE TABLE ' . $qt . " (\n" . implode(",\n", $parts) . "\n)"];
        foreach ((array) ($definition['indexes'] ?? []) as $idx) {
            $out[] = $this->pgIndexSql($qt, $idx);
        }
        $opts = (array) ($definition['options'] ?? []);
        if (isset($opts['comment']) && $opts['comment'] !== null && $opts['comment'] !== '') {
            $out[] = 'COMMENT ON TABLE ' . $qt . ' IS ' . $this->quoteLiteral((string) $opts['comment']);
        }
        return array_merge($out, $comments);
    }

    public function buildAlterTable(string $table, array $spec): array
    {
        $qt = $this->quoteTable($table);
        $alter = 'ALTER TABLE ' . $qt . ' ';
        $out = [];
        $info = null;
        $current = [];
        $needInfo = !empty($spec['modify']) || !empty($spec['drop_index'])
            || (isset($spec['options']['auto_increment']) && $spec['options']['auto_increment'] !== null && $spec['options']['auto_increment'] !== '');
        if ($needInfo) {
            $info = $this->tableInfo($table);
            foreach ($info['columns'] as $c) {
                $current[$c['name']] = $c;
            }
        }

        foreach ((array) ($spec['drop_fk'] ?? []) as $name) {
            $out[] = $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier((string) $name);
        }
        foreach ((array) ($spec['drop_index'] ?? []) as $name) {
            $name = (string) $name;
            $isConstraint = $this->fetchValue(
                'SELECT 1 FROM pg_constraint WHERE conrelid = ? AND conname = ?',
                [$this->pgRelation($table)['oid'], $name]
            );
            if ($name === 'PRIMARY' && !$isConstraint) {
                foreach ($info['indexes'] as $ix) {
                    if ($ix['primary']) {
                        $name = $ix['name'];
                        $isConstraint = true;
                    }
                }
            }
            $out[] = $isConstraint
                ? $alter . 'DROP CONSTRAINT ' . $this->quoteIdentifier($name)
                : 'DROP INDEX ' . $this->quoteIdentifier($this->schemaName()) . '.' . $this->quoteIdentifier($name);
        }
        foreach ((array) ($spec['drop'] ?? []) as $col) {
            $out[] = $alter . 'DROP COLUMN ' . $this->quoteIdentifier((string) $col);
        }

        foreach ((array) ($spec['modify'] ?? []) as $m) {
            $old = (string) ($m['column'] ?? '');
            $def = (array) ($m['definition'] ?? []);
            if (!isset($current[$old])) {
                throw ApiException::notFound('Column "' . $old . '" not found');
            }
            $cur = $current[$old];
            $qc = $this->quoteIdentifier($old);
            $ac = $alter . 'ALTER COLUMN ' . $qc . ' ';
            $newType = $this->pgTypeSql($def, true);
            $curType = (string) $cur['full_type'];
            $collation = isset($def['collation']) && $def['collation'] !== '' ? (string) $def['collation'] : null;
            if (strtolower(preg_replace('/\s+/', ' ', $newType)) !== strtolower($curType) || $collation !== $cur['collation']) {
                $s = $ac . 'TYPE ' . $newType;
                if ($collation !== null) {
                    $s .= ' COLLATE ' . $this->quoteIdentifier($collation);
                }
                $out[] = $s . ' USING ' . $qc . '::' . $newType;
            }
            $wantAi = !empty($def['auto_increment']);
            $isIdentity = $cur['auto_increment'] && strpos((string) $cur['extra'], 'IDENTITY') !== false;
            $isSerial = $cur['auto_increment'] && !$isIdentity;
            $nullable = !array_key_exists('nullable', $def) || !empty($def['nullable']);
            if ($wantAi) {
                $nullable = false;
            }
            if ($wantAi && !$cur['auto_increment']) {
                if ($cur['default_kind'] !== 'none') {
                    $out[] = $ac . 'DROP DEFAULT';
                }
                if ($cur['nullable']) {
                    $out[] = $ac . 'SET NOT NULL';
                }
                $out[] = $ac . 'ADD GENERATED BY DEFAULT AS IDENTITY';
                $out[] = 'SELECT setval(pg_get_serial_sequence(' . $this->quoteLiteral($qt) . ', ' . $this->quoteLiteral($old)
                    . '), COALESCE(MAX(' . $qc . '), 0) + 1, false) FROM ' . $qt;
            } elseif (!$wantAi && $isIdentity) {
                $out[] = $ac . 'DROP IDENTITY IF EXISTS';
            } elseif (!$wantAi && $isSerial) {
                $out[] = $ac . 'DROP DEFAULT';
            }
            if (!$wantAi || $cur['auto_increment']) {
                if ($nullable !== $cur['nullable']) {
                    $out[] = $ac . ($nullable ? 'DROP NOT NULL' : 'SET NOT NULL');
                }
            }
            if (!$wantAi && $cur['generated'] === null) {
                $newDefault = $this->pgDefaultSql($def);
                $kind = $def['default_kind'] ?? 'none';
                $curDefault = $cur['default_kind'] === 'value' ? $this->quoteLiteral((string) $cur['default'])
                    : ($cur['default_kind'] === 'null' ? 'NULL' : $cur['default']);
                if ($newDefault === null && $cur['default_kind'] !== 'none' && !$isSerial) {
                    $out[] = $ac . 'DROP DEFAULT';
                } elseif ($newDefault !== null && ($kind !== $cur['default_kind'] || $newDefault !== $curDefault)) {
                    $out[] = $ac . 'SET DEFAULT ' . $newDefault;
                }
            }
            $newName = isset($def['name']) && $def['name'] !== '' ? (string) $def['name'] : $old;
            if ($newName !== $old) {
                $out[] = $alter . 'RENAME COLUMN ' . $qc . ' TO ' . $this->quoteIdentifier($newName);
            }
            if (array_key_exists('comment', $def)) {
                $newComment = $def['comment'] === null || $def['comment'] === '' ? null : (string) $def['comment'];
                if ($newComment !== $cur['comment']) {
                    $out[] = 'COMMENT ON COLUMN ' . $qt . '.' . $this->quoteIdentifier($newName) . ' IS '
                        . ($newComment === null ? 'NULL' : $this->quoteLiteral($newComment));
                }
            }
        }

        foreach ((array) ($spec['add'] ?? []) as $col) {
            $out[] = $alter . 'ADD COLUMN ' . $this->pgColumnSql($col);
            if (isset($col['comment']) && $col['comment'] !== null && $col['comment'] !== '') {
                $out[] = 'COMMENT ON COLUMN ' . $qt . '.' . $this->quoteIdentifier((string) $col['name'])
                    . ' IS ' . $this->quoteLiteral((string) $col['comment']);
            }
        }
        foreach ((array) ($spec['add_index'] ?? []) as $idx) {
            if (!empty($idx['primary'])) {
                $s = $alter . 'ADD ';
                if (!empty($idx['name']) && $idx['name'] !== 'PRIMARY') {
                    $s .= 'CONSTRAINT ' . $this->quoteIdentifier((string) $idx['name']) . ' ';
                }
                $out[] = $s . 'PRIMARY KEY (' . $this->pgCols((array) ($idx['columns'] ?? [])) . ')';
            } else {
                $out[] = $this->pgIndexSql($qt, $idx);
            }
        }
        foreach ((array) ($spec['add_fk'] ?? []) as $fk) {
            $out[] = $alter . 'ADD ' . $this->pgFkSql($fk);
        }

        $opts = (array) ($spec['options'] ?? []);
        if (array_key_exists('comment', $opts)) {
            $c = $opts['comment'];
            $out[] = 'COMMENT ON TABLE ' . $qt . ' IS ' . ($c === null || $c === '' ? 'NULL' : $this->quoteLiteral((string) $c));
        }
        if (isset($opts['auto_increment']) && $opts['auto_increment'] !== null && $opts['auto_increment'] !== '') {
            $n = (int) $opts['auto_increment'];
            $aiCol = null;
            foreach ($info['columns'] as $c) {
                if ($c['auto_increment']) {
                    $aiCol = $c;
                    break;
                }
            }
            if ($aiCol === null) {
                throw ApiException::badRequest('Table has no identity/serial column');
            }
            if (strpos((string) $aiCol['extra'], 'IDENTITY') !== false) {
                $out[] = $alter . 'ALTER COLUMN ' . $this->quoteIdentifier($aiCol['name']) . ' RESTART WITH ' . $n;
            } else {
                $out[] = 'SELECT setval(pg_get_serial_sequence(' . $this->quoteLiteral($qt) . ', '
                    . $this->quoteLiteral($aiCol['name']) . '), ' . $n . ', false)';
            }
        }
        return $out;
    }

    public function dropTable(string $table, bool $isView = false): void
    {
        $kind = 'TABLE';
        if ($isView) {
            $rel = $this->pgRelation($table);
            $kind = $rel['relkind'] === 'm' ? 'MATERIALIZED VIEW' : 'VIEW';
        }
        $this->pdo()->exec('DROP ' . $kind . ' ' . $this->quoteTable($table));
        $this->forgetTableInfo();
    }

    public function truncateTable(string $table, bool $restartIdentity = true): void
    {
        $this->pdo()->exec('TRUNCATE TABLE ' . $this->quoteTable($table) . ($restartIdentity ? ' RESTART IDENTITY' : ''));
    }

    public function renameTable(string $table, string $newName): void
    {
        $rel = $this->pgRelation($table);
        $kind = ['v' => 'VIEW', 'm' => 'MATERIALIZED VIEW', 'f' => 'FOREIGN TABLE'][$rel['relkind']] ?? 'TABLE';
        $this->pdo()->exec('ALTER ' . $kind . ' ' . $this->quoteTable($table) . ' RENAME TO ' . $this->quoteIdentifier($newName));
        $this->forgetTableInfo();
    }

    /**
     * PostgreSQL cannot copy across databases; $targetDb is therefore interpreted as a target
     * schema of the current database (if such a schema exists).
     */
    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        $rel = $this->pgRelation($table);
        $targetSchema = $this->schemaName();
        if ($targetDb !== null && $targetDb !== '' && $targetDb !== $this->database) {
            $exists = $this->fetchValue('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$targetDb]);
            if (!$exists) {
                throw ApiException::unsupported('PostgreSQL cannot copy tables between databases; pass a schema of the current database instead');
            }
            $targetSchema = $targetDb;
        }
        $src = $this->quoteTable($table);
        $dst = $this->quoteIdentifier($targetSchema) . '.' . $this->quoteIdentifier($newName);
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('CREATE TABLE ' . $dst . ' (LIKE ' . $src . ' INCLUDING ALL)');
            // serial columns would still share the source sequence: give them their own.
            $cols = $this->pgColumns($rel['oid']);
            $newOid = (int) $this->fetchValue('SELECT ?::regclass::oid', [$dst]);
            foreach ($cols as $c) {
                if ($this->pgIsSerialDefault($c['default_expr']) && $c['identity'] === '') {
                    $seqName = substr($newName . '_' . $c['name'], 0, 59) . '_seq';
                    $seq = $this->quoteIdentifier($targetSchema) . '.' . $this->quoteIdentifier($seqName);
                    $seqType = in_array($c['typname'], ['int2', 'int4', 'int8'], true)
                        ? ['int2' => 'smallint', 'int4' => 'integer', 'int8' => 'bigint'][$c['typname']] : 'bigint';
                    $pdo->exec('CREATE SEQUENCE ' . $seq . ' AS ' . $seqType . ' OWNED BY ' . $dst . '.' . $this->quoteIdentifier($c['name']));
                    $pdo->exec('ALTER TABLE ' . $dst . ' ALTER COLUMN ' . $this->quoteIdentifier($c['name'])
                        . ' SET DEFAULT nextval(' . $this->quoteLiteral($seq) . '::regclass)');
                }
            }
            if ($withData) {
                $list = [];
                foreach ($cols as $c) {
                    if ($c['generated'] !== 's') {
                        $list[] = $this->quoteIdentifier($c['name']);
                    }
                }
                $l = implode(', ', $list);
                $pdo->exec('INSERT INTO ' . $dst . ' (' . $l . ') OVERRIDING SYSTEM VALUE SELECT ' . $l . ' FROM ' . $src);
                foreach ($this->pgColumns($newOid) as $c) {
                    if ($c['identity'] !== '' || $this->pgIsSerialDefault($c['default_expr'])) {
                        $qc = $this->quoteIdentifier($c['name']);
                        $this->fetchValue(
                            'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE(MAX(' . $qc . '), 0) + 1, false) FROM ' . $dst,
                            [$dst, $c['name']]
                        );
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function maintenance(array $tables, string $op): array
    {
        $op = strtolower($op);
        $ops = [
            'vacuum' => 'VACUUM',
            'analyze' => 'ANALYZE',
            'optimize' => 'VACUUM (FULL, ANALYZE)',
            'vacuum_full' => 'VACUUM (FULL, ANALYZE)',
            'vacuum_analyze' => 'VACUUM (ANALYZE)',
            'reindex' => 'REINDEX TABLE',
        ];
        if (!isset($ops[$op])) {
            throw ApiException::unsupported('Maintenance operation "' . $op . '" is not supported by PostgreSQL');
        }
        $rows = [];
        foreach ($tables as $t) {
            $sql = $ops[$op] . ' ' . $this->quoteTable((string) $t);
            try {
                $this->pdo()->exec($sql);
                $rows[] = [(string) $t, $op, 'status', 'OK'];
            } catch (\PDOException $e) {
                $rows[] = [(string) $t, $op, 'error', $e->getMessage()];
            }
        }
        return ['columns' => ['Table', 'Op', 'Msg_type', 'Msg_text'], 'rows' => $rows];
    }

    // ------------------------------------------------------------------ queries

    public function explain(string $sql): array
    {
        $trim = ltrim($sql);
        $analyze = false;
        if (preg_match('/^EXPLAIN\b/i', $trim)) {
            $analyze = (bool) preg_match('/^EXPLAIN\s+(\(\s*[^)]*\bANALYZE\b[^)]*\)|ANALYZE\b|ANALYSE\b)/i', $trim);
            $explainSql = $trim;
        } else {
            $explainSql = 'EXPLAIN ' . $trim;
        }
        if (!$analyze) {
            return $this->query($explainSql, 10000);
        }
        // EXPLAIN ANALYZE really executes the statement: run it in a transaction and roll back.
        $pdo = $this->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            return $this->query($explainSql, 10000);
        } finally {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    // ------------------------------------------------------------------ browse / editing hooks

    /** Case-insensitive search like MySQL's default collations. */
    protected function likeOperator(): string
    {
        return 'ILIKE';
    }

    /**
     * Only real character types may be compared with LIKE in PostgreSQL (uuid/json/inet ~~ text
     * is an error), everything else goes through castToText().
     */
    public function isTextType(string $type): bool
    {
        return (bool) preg_match('/^(character varying|varchar|character|char|bpchar|text|citext|name)\b/i', trim($type));
    }

    protected function isNumericType(string $type): bool
    {
        return (bool) preg_match('/^(smallint|integer|bigint|int[248]?|numeric|decimal|real|double precision|float[48]?|money|smallserial|serial|bigserial)\b/i', trim($type));
    }

    protected function castToText(string $quotedColumn, array $col): ?string
    {
        $type = strtolower((string) ($col['type'] ?? ''));
        if ($type === 'bytea') {
            return null;
        }
        return 'CAST(' . $quotedColumn . ' AS text)';
    }

    /** Use the planner estimate instead of COUNT(*) for big unfiltered tables. */
    protected function estimateBrowseTotal(string $table): ?int
    {
        $est = $this->fetchValue(
            "SELECT c.reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relname = ? AND c.relkind IN ('r','p','m')",
            [$this->schemaName(), $table]
        );
        return ($est !== null && (int) $est > 500000) ? (int) $est : null;
    }

    protected function displayBinaryLiteral(string $hex): string
    {
        return "'\\x" . $hex . "'::bytea";
    }

    /**
     * lastval() is session-global (may return a value from another table's sequence) and raises
     * an error – aborting the current transaction – when no sequence was used yet. insertRow()
     * uses RETURNING instead; plain SQL editor statements get no insert_id.
     */
    protected function lastInsertIdFor(string $table): ?string
    {
        return null;
    }

    public function insertRow(string $table, array $values): array
    {
        $qt = $this->quoteTable($table);
        $info = $this->cachedTableInfo($table);
        $idCol = null;
        foreach ($info['columns'] as $c) {
            if ($c['auto_increment']) {
                $idCol = $c['name'];
                break;
            }
        }
        if ($idCol === null) {
            foreach ($info['indexes'] as $ix) {
                if ($ix['primary'] && count($ix['columns']) === 1) {
                    $idCol = $ix['columns'][0];
                }
            }
        }
        if (!$values) {
            $sql = 'INSERT INTO ' . $qt . ' DEFAULT VALUES';
            $params = [];
        } else {
            $cols = [];
            foreach (array_keys($values) as $c) {
                $cols[] = $this->quoteIdentifier((string) $c);
            }
            $sql = 'INSERT INTO ' . $qt . ' (' . implode(', ', $cols) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')';
            $params = array_values($values);
        }
        if ($idCol !== null) {
            $sql .= ' RETURNING ' . $this->quoteIdentifier($idCol);
        }
        $stmt = $this->run($sql, $params);
        $id = null;
        if ($idCol !== null) {
            $v = $stmt->fetchColumn();
            $id = ($v === false || $v === null) ? null : (string) $v;
        }
        $affected = $stmt->rowCount();
        $stmt->closeCursor();
        $this->forgetTableInfo();
        return ['affected' => $affected, 'insert_id' => $id];
    }

    // ------------------------------------------------------------------ server admin

    public function variables(?string $filter = null): array
    {
        $sql = 'SELECT name, current_setting(name) AS value FROM pg_settings';
        $params = [];
        if ($filter !== null && $filter !== '') {
            $sql .= " WHERE name ILIKE ? ESCAPE '\\'";
            $params[] = '%' . addcslashes($filter, '%_\\') . '%';
        }
        return array_map(static function (array $r): array {
            return ['name' => $r['name'], 'value' => $r['value']];
        }, $this->fetchAll($sql . ' ORDER BY name', $params));
    }

    public function status(): array
    {
        $out = [];
        $srv = $this->fetchOne(
            "SELECT pg_postmaster_start_time() AS started,
                    EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint AS uptime,
                    (SELECT count(*) FROM pg_stat_activity) AS connections,
                    current_setting('max_connections') AS max_connections,
                    pg_database_size(current_database()) AS database_size,
                    (SELECT sum(xact_commit) FROM pg_stat_database) AS total_commits,
                    (SELECT sum(xact_rollback) FROM pg_stat_database) AS total_rollbacks"
        ) ?? [];
        foreach ($srv as $k => $v) {
            $out[] = ['name' => $k, 'value' => $v === null ? null : (string) $v];
        }
        $db = $this->fetchOne('SELECT * FROM pg_stat_database WHERE datname = current_database()');
        if ($db !== null) {
            foreach ($db as $k => $v) {
                if (in_array($k, ['datid', 'datname'], true)) {
                    continue;
                }
                $out[] = ['name' => 'db.' . $k, 'value' => $v === null ? null : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v)];
            }
            if (isset($db['blks_hit'], $db['blks_read']) && ((int) $db['blks_hit'] + (int) $db['blks_read']) > 0) {
                $ratio = (int) $db['blks_hit'] / ((int) $db['blks_hit'] + (int) $db['blks_read']) * 100;
                $out[] = ['name' => 'db.cache_hit_ratio', 'value' => number_format($ratio, 2) . '%'];
            }
        }
        return $out;
    }

    public function processes(): array
    {
        $backend = $this->pgVersion() >= 100000 ? 'backend_type' : "NULL::text AS backend_type";
        $st = $this->pdo()->query(
            "SELECT pid AS id, usename AS user, datname AS db, client_addr::text AS host, application_name,
                    $backend, state, wait_event_type, wait_event,
                    date_trunc('second', now() - query_start)::text AS duration,
                    backend_start::text AS started, query
             FROM pg_stat_activity ORDER BY pid"
        );
        $columns = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $meta = $st->getColumnMeta($i);
            $columns[] = (string) $meta['name'];
        }
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $rows[] = array_map(function ($v) {
                return $this->normalizeValue($v);
            }, $r);
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    public function kill(string $id): void
    {
        if (!preg_match('/^\d+$/', $id)) {
            throw ApiException::badRequest('Invalid process id');
        }
        $ok = $this->fetchValue('SELECT pg_terminate_backend(?)', [(int) $id]);
        if (!$ok) {
            throw ApiException::badRequest('Could not terminate backend ' . $id);
        }
    }

    public function backendId(): ?string
    {
        return (string) $this->fetchValue('SELECT pg_backend_pid()');
    }

    /** pg_cancel_backend(): cancels the running statement only (SQLSTATE 57014 on the victim). */
    public function cancelBackend(string $id): bool
    {
        if (!ctype_digit($id)) {
            return false;
        }
        try {
            return (bool) $this->fetchValue('SELECT pg_cancel_backend(?)', [(int) $id]);
        } catch (\PDOException $e) {
            return false; // e.g. insufficient privilege for another role's backend
        }
    }

    public function users(): array
    {
        $rows = $this->fetchAll(
            "SELECT r.rolname, r.rolsuper, r.rolcanlogin, r.rolcreatedb, r.rolcreaterole, r.rolreplication,
                    r.rolconnlimit, r.rolvaliduntil::text AS valid_until,
                    (SELECT json_agg(g.rolname ORDER BY g.rolname) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid
                     WHERE m.member = r.oid) AS member_of
             FROM pg_roles r
             WHERE r.rolname NOT LIKE 'pg\\_%'
             ORDER BY r.rolname"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user' => $r['rolname'],
                'host' => null,
                'login' => (bool) $r['rolcanlogin'],
                'is_role' => !$r['rolcanlogin'],
                'superuser' => (bool) $r['rolsuper'],
                'create_db' => (bool) $r['rolcreatedb'],
                'create_role' => (bool) $r['rolcreaterole'],
                'replication' => (bool) $r['rolreplication'],
                'connection_limit' => (int) $r['rolconnlimit'],
                'valid_until' => $r['valid_until'],
                'member_of' => $this->pgJsonList($r['member_of']),
            ];
        }
        return $out;
    }

    public function userGrants(string $user, ?string $host): array
    {
        $r = $this->fetchOne('SELECT * FROM pg_roles WHERE rolname = ?', [$user]);
        if ($r === null) {
            throw ApiException::notFound('Role "' . $user . '" not found');
        }
        $qu = $this->quoteIdentifier($user);
        $attrs = [
            $r['rolsuper'] ? 'SUPERUSER' : 'NOSUPERUSER',
            $r['rolcreatedb'] ? 'CREATEDB' : 'NOCREATEDB',
            $r['rolcreaterole'] ? 'CREATEROLE' : 'NOCREATEROLE',
            $r['rolinherit'] ? 'INHERIT' : 'NOINHERIT',
            $r['rolcanlogin'] ? 'LOGIN' : 'NOLOGIN',
            $r['rolreplication'] ? 'REPLICATION' : 'NOREPLICATION',
            $r['rolbypassrls'] ? 'BYPASSRLS' : 'NOBYPASSRLS',
        ];
        if ((int) $r['rolconnlimit'] !== -1) {
            $attrs[] = 'CONNECTION LIMIT ' . (int) $r['rolconnlimit'];
        }
        if ($r['rolvaliduntil'] !== null) {
            $attrs[] = 'VALID UNTIL ' . $this->quoteLiteral((string) $r['rolvaliduntil']);
        }
        $out = ['ALTER ROLE ' . $qu . ' WITH ' . implode(' ', $attrs) . ';'];

        foreach ($this->fetchAll(
            'SELECT g.rolname, m.admin_option FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid
             JOIN pg_roles u ON u.oid = m.member WHERE u.rolname = ? ORDER BY g.rolname',
            [$user]
        ) as $m) {
            $out[] = 'GRANT ' . $this->quoteIdentifier($m['rolname']) . ' TO ' . $qu . ($m['admin_option'] ? ' WITH ADMIN OPTION' : '') . ';';
        }

        $grouped = function (array $rows, string $prefix) use ($qu): array {
            $acc = [];
            foreach ($rows as $row) {
                $key = $row['obj'] . '|' . ($row['is_grantable'] ? '1' : '0');
                $acc[$key]['obj'] = $row['obj'];
                $acc[$key]['grantable'] = (bool) $row['is_grantable'];
                $acc[$key]['privs'][] = $row['privilege_type'];
            }
            $lines = [];
            foreach ($acc as $a) {
                $lines[] = 'GRANT ' . implode(', ', array_unique($a['privs'])) . ' ON ' . $prefix . $a['obj'] . ' TO ' . $qu
                    . ($a['grantable'] ? ' WITH GRANT OPTION' : '') . ';';
            }
            return $lines;
        };

        $out = array_merge($out, $grouped($this->fetchAll(
            'SELECT quote_ident(d.datname) AS obj, a.privilege_type, a.is_grantable
             FROM pg_database d, aclexplode(d.datacl) a, pg_roles r
             WHERE r.rolname = ? AND a.grantee = r.oid AND d.datacl IS NOT NULL ORDER BY d.datname',
            [$user]
        ), 'DATABASE '));
        $out = array_merge($out, $grouped($this->fetchAll(
            "SELECT quote_ident(n.nspname) AS obj, a.privilege_type, a.is_grantable
             FROM pg_namespace n, aclexplode(n.nspacl) a, pg_roles r
             WHERE r.rolname = ? AND a.grantee = r.oid AND n.nspacl IS NOT NULL
               AND n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema' ORDER BY n.nspname",
            [$user]
        ), 'SCHEMA '));
        $out = array_merge($out, $grouped($this->fetchAll(
            "SELECT quote_ident(n.nspname) || '.' || quote_ident(c.relname) AS obj, a.privilege_type, a.is_grantable
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(c.relacl) a, pg_roles r
             WHERE r.rolname = ? AND a.grantee = r.oid AND c.relacl IS NOT NULL AND c.relkind IN ('r','p','v','m','f')
               AND n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema' ORDER BY 1",
            [$user]
        ), 'TABLE '));
        $out = array_merge($out, $grouped($this->fetchAll(
            "SELECT quote_ident(n.nspname) || '.' || quote_ident(c.relname) AS obj, a.privilege_type, a.is_grantable
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(c.relacl) a, pg_roles r
             WHERE r.rolname = ? AND a.grantee = r.oid AND c.relacl IS NOT NULL AND c.relkind = 'S'
               AND n.nspname NOT LIKE 'pg\\_%' ORDER BY 1",
            [$user]
        ), 'SEQUENCE '));
        return $out;
    }

    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void
    {
        if ($user === '') {
            throw ApiException::badRequest('Role name is required');
        }
        $pdo = $this->pdo();
        $qu = $this->quoteIdentifier($user);
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $sql = 'CREATE ROLE ' . $qu . ' WITH LOGIN';
                if ($password !== null && $password !== '') {
                    $sql .= ' PASSWORD ' . $this->quoteLiteral($password);
                }
                $pdo->exec($sql);
            } elseif ($password !== null && $password !== '') {
                $pdo->exec('ALTER ROLE ' . $qu . ' WITH PASSWORD ' . $this->quoteLiteral($password));
            }
            foreach ($grants as $g) {
                $g = trim((string) $g);
                if ($g !== '') {
                    $pdo->exec($g);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function dropUser(string $user, ?string $host): void
    {
        $this->pdo()->exec('DROP ROLE ' . $this->quoteIdentifier($user));
    }

    public function collations(): array
    {
        $default = $this->fetchValue('SELECT datcollate FROM pg_database WHERE datname = current_database()');
        $rows = $this->fetchAll(
            "SELECT collname AS name, pg_encoding_to_char(collencoding) AS charset, collcollate
             FROM pg_collation
             WHERE collencoding = -1 OR collencoding = pg_char_to_encoding(current_setting('server_encoding'))
             ORDER BY collname"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $r['name'],
                'charset' => $r['charset'] === '' ? null : $r['charset'],
                'default' => $default !== null && ($r['collcollate'] === $default || $r['name'] === $default),
            ];
        }
        return $out;
    }

    public function charsets(): array
    {
        $server = (string) $this->fetchValue("SELECT current_setting('server_encoding')");
        $rows = $this->fetchAll(
            "SELECT DISTINCT pg_encoding_to_char(i) AS name FROM generate_series(0, 64) AS s(i)
             WHERE pg_encoding_to_char(i) <> '' ORDER BY 1"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $r['name'],
                'description' => $r['name'] === $server ? 'server encoding' : null,
                'default_collation' => null,
            ];
        }
        return $out;
    }

    public function engines(): array
    {
        throw ApiException::unsupported('PostgreSQL has no storage engines');
    }

    public function dataTypes(): array
    {
        $types = [
            'Numeric' => ['smallint', 'integer', 'bigint', 'numeric', 'real', 'double precision',
                          'smallserial', 'serial', 'bigserial', 'money'],
            'String' => ['character varying', 'character', 'text', 'citext'],
            'Date/Time' => ['date', 'time without time zone', 'time with time zone', 'timestamp without time zone',
                            'timestamp with time zone', 'interval'],
            'Boolean' => ['boolean'],
            'Binary' => ['bytea', 'bit', 'bit varying'],
            'JSON' => ['json', 'jsonb', 'jsonpath'],
            'UUID' => ['uuid'],
            'Network' => ['inet', 'cidr', 'macaddr', 'macaddr8'],
            'Geometric' => ['point', 'line', 'lseg', 'box', 'path', 'polygon', 'circle'],
            'Range' => ['int4range', 'int8range', 'numrange', 'tsrange', 'tstzrange', 'daterange'],
            'Text search' => ['tsvector', 'tsquery'],
            'Array' => ['integer[]', 'bigint[]', 'text[]', 'character varying[]', 'numeric[]', 'boolean[]',
                        'uuid[]', 'jsonb[]', 'timestamp with time zone[]'],
            'Other' => ['xml', 'oid', 'pg_lsn', 'txid_snapshot'],
        ];
        try {
            $user = $this->fetchAll(
                "SELECT format_type(t.oid, NULL) AS name FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 LEFT JOIN pg_class c ON c.oid = t.typrelid
                 WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_%'
                   AND (t.typtype IN ('e', 'd', 'r') OR (t.typtype = 'c' AND c.relkind = 'c'))
                   AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_type'::regclass
                                   AND d.objid = t.oid AND d.deptype = 'e')
                 ORDER BY 1"
            );
            $names = array_values(array_unique(array_map(static function (array $r): string {
                return (string) $r['name'];
            }, $user)));
            if ($names) {
                $types['User-defined'] = $names;
            }
        } catch (\PDOException $e) {
            // ignore
        }
        return $types;
    }

    public function completionWords(): array
    {
        $keywords = ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'LIKE', 'ILIKE', 'SIMILAR TO',
            'BETWEEN', 'EXISTS', 'ANY', 'ALL', 'SOME', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'OUTER', 'CROSS',
            'LATERAL', 'NATURAL', 'ON', 'USING', 'GROUP BY', 'HAVING', 'ORDER BY', 'ASC', 'DESC', 'NULLS FIRST',
            'NULLS LAST', 'LIMIT', 'OFFSET', 'FETCH', 'UNION', 'INTERSECT', 'EXCEPT', 'DISTINCT', 'DISTINCT ON', 'AS',
            'WITH', 'RECURSIVE', 'INSERT INTO', 'VALUES', 'DEFAULT VALUES', 'RETURNING', 'ON CONFLICT', 'DO NOTHING',
            'DO UPDATE SET', 'UPDATE', 'SET', 'DELETE FROM', 'TRUNCATE', 'RESTART IDENTITY', 'CASCADE', 'RESTRICT',
            'CREATE', 'ALTER', 'DROP', 'TABLE', 'VIEW', 'MATERIALIZED VIEW', 'INDEX', 'CONCURRENTLY', 'SEQUENCE',
            'SCHEMA', 'DATABASE', 'FUNCTION', 'PROCEDURE', 'TRIGGER', 'TYPE', 'DOMAIN', 'EXTENSION', 'ROLE', 'USER',
            'GRANT', 'REVOKE', 'PRIMARY KEY', 'FOREIGN KEY', 'REFERENCES', 'UNIQUE', 'CHECK', 'CONSTRAINT',
            'NOT NULL', 'DEFAULT', 'GENERATED ALWAYS AS IDENTITY', 'GENERATED BY DEFAULT AS IDENTITY', 'STORED',
            'COLLATE', 'IF EXISTS', 'IF NOT EXISTS', 'ADD COLUMN', 'DROP COLUMN', 'ALTER COLUMN', 'RENAME TO',
            'RENAME COLUMN', 'COMMENT ON', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'EXPLAIN', 'ANALYZE', 'VACUUM',
            'REINDEX', 'CLUSTER', 'COPY', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'CAST', 'OVER', 'PARTITION BY',
            'WINDOW', 'FILTER', 'WITHIN GROUP', 'LANGUAGE', 'RETURNS', 'SECURITY DEFINER', 'TRUE', 'FALSE',
            'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER', 'SESSION_USER', 'LOCALTIMESTAMP'];
        $functions = ['abs', 'ceil', 'floor', 'round', 'trunc', 'mod', 'power', 'sqrt', 'random', 'greatest', 'least',
            'coalesce', 'nullif', 'length', 'char_length', 'lower', 'upper', 'initcap', 'trim', 'btrim', 'ltrim',
            'rtrim', 'substring', 'substr', 'position', 'strpos', 'replace', 'translate', 'concat', 'concat_ws',
            'format', 'left', 'right', 'lpad', 'rpad', 'repeat', 'reverse', 'split_part', 'string_agg',
            'regexp_replace', 'regexp_matches', 'regexp_split_to_table', 'regexp_split_to_array', 'md5', 'encode',
            'decode', 'to_char', 'to_date', 'to_timestamp', 'to_number', 'now', 'clock_timestamp', 'age',
            'date_trunc', 'date_part', 'extract', 'make_date', 'make_interval', 'justify_interval', 'count', 'sum',
            'avg', 'min', 'max', 'array_agg', 'json_agg', 'jsonb_agg', 'json_object_agg', 'jsonb_object_agg',
            'bool_and', 'bool_or', 'every', 'stddev', 'variance', 'percentile_cont', 'percentile_disc', 'mode',
            'row_number', 'rank', 'dense_rank', 'percent_rank', 'cume_dist', 'ntile', 'lag', 'lead', 'first_value',
            'last_value', 'nth_value', 'array_length', 'array_append', 'array_prepend', 'array_cat',
            'array_position', 'array_remove', 'array_to_string', 'string_to_array', 'unnest', 'cardinality',
            'generate_series', 'json_build_object', 'jsonb_build_object', 'json_build_array', 'jsonb_build_array',
            'to_json', 'to_jsonb', 'jsonb_set', 'jsonb_insert', 'jsonb_pretty', 'jsonb_each', 'jsonb_each_text',
            'jsonb_array_elements', 'jsonb_array_elements_text', 'jsonb_typeof', 'jsonb_strip_nulls',
            'jsonb_path_query', 'json_extract_path_text', 'gen_random_uuid', 'nextval', 'currval', 'setval',
            'lastval', 'pg_get_serial_sequence', 'current_database', 'current_schema', 'version', 'pg_typeof',
            'pg_size_pretty', 'pg_total_relation_size', 'pg_relation_size', 'pg_database_size', 'pg_sleep',
            'pg_cancel_backend', 'pg_terminate_backend', 'to_tsvector', 'to_tsquery', 'plainto_tsquery',
            'websearch_to_tsquery', 'ts_rank', 'ts_headline'];
        return $this->mergeWords(parent::completionWords(), $keywords, array_map('strtoupper', $functions));
    }

    public function normalizeValue($value, ?string $type = null)
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return parent::normalizeValue($value, $type);
    }
}
