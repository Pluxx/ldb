<?php

declare(strict_types=1);

namespace Ldb\Sql;

use Ldb\Http\ApiException;

/**
 * Statement filter for read-only connections (SQL editor / EXPLAIN).
 *
 * Allows only statements whose first keyword is SELECT, WITH, SHOW, EXPLAIN, DESCRIBE/DESC,
 * VALUES or TABLE (plus USE on MySQL) and rejects any statement that contains a data-modifying
 * or session-changing keyword outside of string literals, quoted identifiers and comments
 * (INSERT/UPDATE/DELETE/…/SET/…), SELECT … INTO, locking reads (FOR UPDATE/SHARE, LOCK IN
 * SHARE MODE), EXPLAIN ANALYZE (executes the statement) and a short list of functions with
 * side effects.
 *
 * Lexing ambiguity is resolved conservatively: the input is scanned under every plausible
 * interpretation for the dialect (backslash escapes on/off — server sql_mode or
 * standard_conforming_strings are not known here —, MySQL "--" with/without the mandatory
 * blank, nested/flat block comments) and rejected when ANY interpretation finds a violation.
 * Because the server's real interpretation is among them, hiding a keyword inside what only
 * looks like a string or comment does not work.
 *
 * This is a guard-rail, not a sandbox: the driver additionally switches the session to
 * read-only where the engine supports it (DriverInterface::setReadOnly). The real security
 * boundary is a database account with SELECT privileges only.
 */
final class ReadOnlyGuard
{
    /** Allowed first keywords of a statement. */
    private const ALLOWED_FIRST = ['SELECT', 'WITH', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'VALUES', 'TABLE'];

    /** Keywords that are never allowed anywhere in a read-only statement (outside literals). */
    private const BLOCKED = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'UPSERT', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
        'GRANT', 'REVOKE', 'CALL', 'EXEC', 'EXECUTE', 'DO', 'LOCK', 'UNLOCK', 'COPY', 'LOAD', 'HANDLER',
        'OUTFILE', 'DUMPFILE', 'INTO', 'SET', 'RENAME', 'ATTACH', 'DETACH', 'VACUUM', 'REINDEX', 'CLUSTER',
        'REFRESH', 'NOTIFY', 'LISTEN', 'UNLISTEN', 'PREPARE', 'DEALLOCATE', 'PRAGMA', 'KILL', 'FLUSH',
        'PURGE', 'RESET', 'SHUTDOWN', 'INSTALL', 'UNINSTALL', 'IMPORT', 'COMMENT', 'SECURITY', 'DISCARD',
        'CHECKPOINT', 'BEGIN', 'START', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'XA',
        // SQL Server locking hints / dynamic SQL
        'UPDLOCK', 'XLOCK', 'TABLOCKX', 'HOLDLOCK', 'WAITFOR', 'BACKUP', 'RESTORE', 'DBCC', 'BULK', 'DENY',
        'SETUSER', 'REVERT',
    ];

    /**
     * Keywords that double as ordinary functions when directly followed by "(" — e.g. MySQL
     * REPLACE(str,a,b), INSERT(str,pos,len,new), TRUNCATE(x,d). None of them can start a
     * statement with a "(" right after it.
     */
    private const FUNCTION_LIKE = ['REPLACE', 'INSERT', 'TRUNCATE'];

    /** Functions with side effects (only when called, i.e. followed by "("). Best effort. */
    private const BLOCKED_FUNCTIONS = [
        // PostgreSQL
        'SET_CONFIG', 'NEXTVAL', 'SETVAL', 'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND', 'PG_RELOAD_CONF',
        'PG_ROTATE_LOGFILE', 'PG_SWITCH_WAL', 'PG_CREATE_RESTORE_POINT', 'PG_PROMOTE', 'PG_STAT_RESET',
        'PG_ADVISORY_LOCK', 'PG_ADVISORY_XACT_LOCK', 'PG_ADVISORY_LOCK_SHARED', 'PG_TRY_ADVISORY_LOCK',
        'PG_FILE_WRITE', 'PG_READ_FILE', 'PG_READ_BINARY_FILE', 'PG_LS_DIR', 'LO_IMPORT', 'LO_EXPORT',
        'LO_UNLINK', 'LO_CREATE', 'LO_CREAT', 'LO_FROM_BYTEA', 'LO_PUT', 'LO_TRUNCATE', 'DBLINK',
        'DBLINK_EXEC', 'DBLINK_CONNECT', 'DBLINK_SEND_QUERY', 'QUERY_TO_XML', 'QUERY_TO_XMLSCHEMA',
        'QUERY_TO_XML_AND_XMLSCHEMA', 'PG_LOGICAL_EMIT_MESSAGE',
        // MySQL / MariaDB
        'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'LOAD_FILE', 'SYS_EXEC',
        'SYS_EVAL', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT',
        // SQL Server
        'OPENQUERY', 'OPENROWSET', 'OPENDATASOURCE',
    ];

    private string $dialect;
    private SqlLexer $lexer;

    public function __construct(string $dialect = 'mysql')
    {
        $this->dialect = $dialect;
        $this->lexer = new SqlLexer($dialect);
    }

    /**
     * Throws ApiException (code read_only, HTTP 403) when $sql is not allowed.
     * @throws ApiException
     */
    public function assertReadOnly(string $sql): void
    {
        $reason = $this->violation($sql);
        if ($reason !== null) {
            throw new ApiException('Read-only connection: ' . $reason, 'read_only', 403);
        }
    }

    /** True when every statement in $sql is allowed. */
    public function isAllowed(string $sql): bool
    {
        return $this->violation($sql) === null;
    }

    /** Human readable reason why $sql is rejected, or null when it is allowed. */
    public function violation(string $sql): ?string
    {
        foreach ($this->lexer->tokenizeAll($sql) as $tokens) {
            $reason = $this->checkTokens($tokens);
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * @param array<int,array{0:string,1:string}> $tokens
     */
    private function checkTokens(array $tokens): ?string
    {
        // Split into statements at ";" and check each one.
        $stmt = [];
        foreach ($tokens as $tok) {
            if ($tok[0] === 'p' && $tok[1] === ';') {
                $reason = $this->checkStatement($stmt);
                if ($reason !== null) {
                    return $reason;
                }
                $stmt = [];
                continue;
            }
            $stmt[] = $tok;
        }
        return $this->checkStatement($stmt);
    }

    /**
     * @param array<int,array{0:string,1:string}> $tokens one statement
     */
    private function checkStatement(array $tokens): ?string
    {
        // Leading parentheses: "(SELECT 1) UNION (SELECT 2)"
        $start = 0;
        $count = count($tokens);
        while ($start < $count && $tokens[$start][0] === 'p' && $tokens[$start][1] === '(') {
            $start++;
        }
        if ($start >= $count) {
            return $count === 0 ? null : 'empty or unsupported statement';
        }
        $first = $tokens[$start];
        if ($first[0] !== 'w') {
            return 'only SELECT, WITH, SHOW, EXPLAIN, DESCRIBE, VALUES and TABLE statements are allowed';
        }
        $kw = $first[1];
        if ($kw === 'USE' && $this->dialect === 'mysql') {
            // Switching the default database is harmless: "USE db" only.
            return $count - $start <= 2 ? null : 'unexpected tokens after USE';
        }
        if (!in_array($kw, self::ALLOWED_FIRST, true)) {
            return $kw . ' statements are not allowed';
        }

        for ($k = $start; $k < $count; $k++) {
            [$type, $word] = $tokens[$k];
            if ($type !== 'w' && $type !== 'q') {
                continue;
            }
            $prev = $tokens[$k - 1] ?? null;
            $next = $tokens[$k + 1] ?? null;
            $isCall = $next !== null && $next[0] === 'p' && $next[1] === '(';
            // Checked before the qualified-name exemption: pg_catalog.set_config(…), "nextval"(…)
            if ($isCall && in_array($word, self::BLOCKED_FUNCTIONS, true)) {
                return 'function ' . strtolower($word) . '() is not allowed';
            }
            if ($type === 'q') {
                continue;
            }
            if ($prev !== null && $prev[0] === 'p' && $prev[1] === '.') {
                continue; // qualified name part: t.delete, x.update
            }
            if (($word === 'ANALYZE' || $word === 'ANALYSE') && $kw === 'EXPLAIN') {
                return 'EXPLAIN ANALYZE executes the statement';
            }
            if ($word === 'FOR' && $next !== null && $next[0] === 'w'
                && in_array($next[1], ['UPDATE', 'SHARE', 'NO', 'KEY'], true)) {
                return 'locking reads (FOR UPDATE / FOR SHARE) are not allowed';
            }
            if (!in_array($word, self::BLOCKED, true)) {
                continue;
            }
            if ($isCall && in_array($word, self::FUNCTION_LIKE, true)) {
                continue;
            }
            if ($word === 'SET' && $this->dialect === 'mysql' && $prev !== null && $prev[0] === 'w' && ($prev[1] === 'CHARACTER' || $prev[1] === 'CHAR')) {
                continue; // CHARACTER SET utf8mb4 (CAST/CONVERT, SHOW CHARACTER SET)
            }
            if ($word === 'CREATE' && $kw === 'SHOW' && $k === $start + 1) {
                continue; // SHOW CREATE TABLE …
            }
            if ($word === 'INTO') {
                return 'SELECT … INTO is not allowed';
            }
            return $word . ' is not allowed';
        }
        return null;
    }
}
