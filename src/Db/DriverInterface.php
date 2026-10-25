<?php

declare(strict_types=1);

namespace Ldb\Db;

use PDO;

/**
 * Contract every database engine implements. Array shapes are documented in ARCHITECTURE.md.
 * Methods for unsupported features throw Ldb\Http\ApiException with code 'unsupported'.
 */
interface DriverInterface
{
    /** Short id: mysql | pgsql | sqlite | sqlsrv */
    public static function id(): string;

    /** Human label, e.g. "MySQL / MariaDB". */
    public static function label(): string;

    /** True when the required PDO extension is loaded. */
    public static function isAvailable(): bool;

    /** Default TCP port or null. */
    public static function defaultPort(): ?int;

    /**
     * @param array $params driver,host,port,user,password,database,file(sqlite),options
     * @throws \PDOException on failure
     */
    public function connect(array $params): void;

    public function pdo(): PDO;

    /**
     * Switch the session to read-only (or back) where the engine supports it. May be called
     * before the physical connection exists (lazy drivers apply it on connect) and is re-applied
     * on every reconnect. Also set when connect() receives params['read_only'] = true.
     *  - MySQL/MariaDB: SET SESSION TRANSACTION READ ONLY
     *  - PostgreSQL:    SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY (default_transaction_read_only)
     *  - SQLite:        SQLITE_OPEN_READONLY (PHP >= 8.1, decided at connect) + PRAGMA query_only
     *  - SQL Server:    no session-level switch (no-op) — use a read-only database login
     * Added for read-only connections (deliberate contract extension); it is a guard-rail on top
     * of Kernel/ReadOnlyGuard checks, not a replacement for a SELECT-only account.
     */
    public function setReadOnly(bool $readOnly): void;

    /** Select current database (and schema where applicable). May reconnect (pgsql). */
    public function select(?string $database, ?string $schema = null): void;

    public function currentDatabase(): ?string;

    public function currentSchema(): ?string;

    public function quoteIdentifier(string $name): string;

    /** Qualified, quoted name of a table in the current db/schema. */
    public function quoteTable(string $table): string;

    /**
     * Feature flags. Keys (bool): schemas, users, processes, variables, status, engines,
     * collations, charsets, routines, triggers, events, foreign_keys, alter_column,
     * drop_column, rename_table, copy_table, unsigned, auto_increment, comments,
     * maintenance, explain, create_database, kill, cancel (backendId()/cancelBackend()),
     * db_alter (alterDatabase()), db_copy (copyDatabase()), db_rename (rename a database:
     * native on PostgreSQL/SQL Server, copy + drop on MySQL), monitor (server.metrics/slowLog/advice
     * via Ldb\Monitor\ServerMonitor; false on SQLite)
     *
     * Version-dependent keys (decided from serverVersion(), driver specific, absent = not applicable):
     *  check_constraints, generated_columns, functional_indexes, default_expressions,
     *  invisible_columns, rename_column (native statement), drop_column_native, explain_tree,
     *  explain_analyze, roles, account_lock, password_expire, sequences, create_or_replace_trigger,
     *  create_or_alter, reindex_concurrently, procedures, strict_tables, returning, upsert,
     *  window_functions. Features with a false flag are hidden in the UI and refused by the backend
     *  with 'unsupported' (or done with a fallback, e.g. SQLite table rebuild).
     */
    public function capabilities(): array;

    /**
     * {name, version, version_comment, host, user, charset?, uptime?, driver,
     *  version_number, version_supported, min_version, version_flavor} (see ServerVersion: servers older than the
     * minimum are usable, but version_supported is false and the UI shows a warning)
     */
    public function serverInfo(): array;

    /** Normalized numeric server version, e.g. "8.0.11", "10.3.39", "11.22", "15.0.4415.2", "3.22.0". */
    public function serverVersion(): string;

    /** [{name, collation?, tables?, size?}] */
    public function listDatabases(): array;

    public function createDatabase(string $name, ?string $collation = null): void;

    public function dropDatabase(string $name): void;

    /**
     * Database-level settings for the settings page (deliberate contract extension, db.info).
     * {driver, name, settings:{…driver specific…}, …} — see ARCHITECTURE.md "DatabaseInfo".
     * Throws not_found for an unknown database.
     */
    public function databaseInfo(string $name): array;

    /**
     * SQL implementing a DatabaseChanges spec (db.alter), validated against the server
     * (charset/collation lists, base tables, whitelisted PRAGMA values). Never executes.
     * @return array{sql:string[],warnings:string[]} warnings are stable codes (UI translates)
     */
    public function buildAlterDatabase(string $name, array $changes): array;

    /**
     * Execute buildAlterDatabase($name, $changes); switches to a maintenance database first where
     * the engine needs it (PostgreSQL rename, SQL Server collation/rename).
     * @return array{sql:string[],warnings:string[]}
     */
    public function alterDatabase(string $name, array $changes): array;

    /**
     * Copy a whole database (db.copy, capability db_copy). $opts: preview(bool), drop_source(bool,
     * "move/rename"; MySQL only). Unsupported engines throw 'unsupported'.
     * @return array{sql:string[],warnings:string[],executed:bool,copied:array<string,int>,
     *               skipped:array<int,array{type:string,name:string,error:string}>,dropped:bool}
     */
    public function copyDatabase(string $source, string $target, array $opts = []): array;

    /** [string] — empty for engines without schemas */
    public function listSchemas(): array;

    /** [TableSummary] tables AND views of current db/schema */
    public function listTables(): array;

    /** [{name, type:'PROCEDURE'|'FUNCTION', returns?, definer?}] */
    public function listRoutines(): array;

    /** [{name, table, timing, event, statement}] */
    public function listTriggers(): array;

    /** [{name, schedule, status}] */
    public function listEvents(): array;

    /** TableInfo */
    public function tableInfo(string $table): array;

    /** @param string $type table|view|routine|function|procedure|trigger|event */
    public function createStatement(string $name, string $type = 'table'): string;

    /**
     * @param array $opts page(1-based), per_page, order[{column,dir}], filters[{column,op,value}], search
     * @return array BrowseResult
     */
    public function browse(string $table, array $opts): array;

    /** @return array{affected:int, insert_id:?string} */
    public function insertRow(string $table, array $values): array;

    /** @param array $key column=>value identifying exactly one row */
    public function updateRow(string $table, array $key, array $values): int;

    /** @param array $keys list of column=>value maps */
    public function deleteRows(string $table, array $keys): int;

    /** @return string[] SQL statements implementing a TableDefinition */
    public function buildCreateTable(array $definition): array;

    /** @return string[] SQL statements implementing an AlterSpec */
    public function buildAlterTable(string $table, array $spec): array;

    public function dropTable(string $table, bool $isView = false): void;

    public function truncateTable(string $table): void;

    public function renameTable(string $table, string $newName): void;

    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void;

    /** @return array{columns:array, rows:array} */
    public function maintenance(array $tables, string $op): array;

    /**
     * Execute ONE statement. Returns QueryResult (without 'sql'/'time_ms' — controller adds them).
     * Fetches at most $limit rows and sets truncated=true if more exist.
     */
    public function query(string $sql, int $limit): array;

    /** QueryResult for EXPLAIN of $sql */
    public function explain(string $sql): array;

    /** [{name, value}] */
    public function variables(?string $filter = null): array;

    /** [{name, value}] */
    public function status(): array;

    /** {columns, rows} with a column named 'id' usable by kill() */
    public function processes(): array;

    public function kill(string $id): void;

    /**
     * Id of the server backend executing this connection's statements, usable by
     * cancelBackend() from ANOTHER connection; null when the engine cannot cancel (SQLite).
     *  - MySQL/MariaDB: CONNECTION_ID()   - PostgreSQL: pg_backend_pid()   - SQL Server: @@SPID
     * Added for the SQL editor's "Stop" (deliberate contract extension, capability `cancel`).
     */
    public function backendId(): ?string;

    /**
     * Interrupt the statement currently running on backend $id (obtained via backendId() on
     * another connection with the same credentials). Returns false when nothing was cancelled
     * (unknown/finished backend, no privilege, unsupported engine); never throws for that.
     *  - MySQL/MariaDB: KILL QUERY id — only the running statement, the connection survives
     *  - PostgreSQL:    pg_cancel_backend(pid) — only the running statement
     *  - SQL Server:    KILL spid — terminates the whole SESSION (T-SQL has no statement-only
     *                   cancel from another session); its open transaction is rolled back.
     *                   Needs ALTER ANY CONNECTION.
     *  - SQLite:        unsupported (in-process engine), returns false
     */
    public function cancelBackend(string $id): bool;

    /** [{user, host?, ...extra}] */
    public function users(): array;

    /** @return string[] */
    public function userGrants(string $user, ?string $host): array;

    /** Create or update user; $grants are full GRANT statements to run afterwards. */
    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void;

    public function dropUser(string $user, ?string $host): void;

    /** [{name, charset?, default?}] */
    public function collations(): array;

    /** [{name, description?, default_collation?}] */
    public function charsets(): array;

    /** [{name, support, comment}] */
    public function engines(): array;

    /** Grouped type list for the column editor: {"Numeric":["INT",…],"String":[…],…} */
    public function dataTypes(): array;

    /** SQL keywords + builtin function names for autocompletion. */
    public function completionWords(): array;

    /** Convert a PHP value from PDO into a JSON-safe cell (binary handling per ARCHITECTURE.md). */
    public function normalizeValue($value, ?string $type = null);
}
