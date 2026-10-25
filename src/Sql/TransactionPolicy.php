<?php

declare(strict_types=1);

namespace Ldb\Sql;

use Ldb\Http\ApiException;

/**
 * Decides which statements may run inside the SQL editor's transaction wrapper
 * (sql.execute `tx` = atomic | dryrun; one request = one connection = one transaction).
 *
 * Kinds (per statement, first keywords outside literals/comments, via SqlLexer; the strictest
 * verdict over all lexer variants wins):
 *  - tx_control      BEGIN / START TRANSACTION / COMMIT / ROLLBACK / SAVEPOINT / RELEASE / END /
 *                    ABORT / XA / PREPARE TRANSACTION / SET autocommit / SET IMPLICIT_TRANSACTIONS
 *                    → refused in both modes (would break the wrapper)
 *  - not_in_tx       cannot run inside a transaction block → refused in both modes:
 *                    PostgreSQL VACUUM, CREATE/DROP DATABASE|TABLESPACE, ALTER SYSTEM,
 *                    CREATE/DROP INDEX CONCURRENTLY, REINDEX … CONCURRENTLY|DATABASE|SYSTEM,
 *                    CREATE/DROP SUBSCRIPTION, DISCARD ALL; SQLite VACUUM; SQL Server
 *                    CREATE/ALTER/DROP DATABASE, BACKUP, RESTORE, RECONFIGURE, … FULLTEXT …
 *  - implicit_commit MySQL/MariaDB statements that commit implicitly (DDL except TEMPORARY
 *                    tables, RENAME, TRUNCATE, GRANT/REVOKE, LOCK/UNLOCK TABLES, ANALYZE/
 *                    OPTIMIZE/REPAIR/CHECK TABLE, FLUSH, …) → refused in dry-run (would persist),
 *                    warning in atomic mode
 *  - may_commit      procedure calls (MySQL CALL, SQL Server EXEC) whose body may commit → warning
 *  - ok              everything else (PostgreSQL/SQLite/SQL Server DDL is transactional)
 *
 * SQL Server needs no semicolons between statements, so there every token of a batch is
 * scanned (except bodies of CREATE/ALTER PROCEDURE|FUNCTION|TRIGGER, which are not executed).
 */
final class TransactionPolicy
{
    public const NONE = 'none';
    public const ATOMIC = 'atomic';
    public const DRYRUN = 'dryrun';
    public const MODES = [self::NONE, self::ATOMIC, self::DRYRUN];

    public const OK = 'ok';
    public const TX_CONTROL = 'tx_control';
    public const NOT_IN_TX = 'not_in_tx';
    public const IMPLICIT_COMMIT = 'implicit_commit';
    public const MAY_COMMIT = 'may_commit';

    private const SEVERITY = [
        self::OK => 0, self::MAY_COMMIT => 1, self::IMPLICIT_COMMIT => 2, self::NOT_IN_TX => 3, self::TX_CONTROL => 4,
    ];

    /** MySQL/MariaDB first keywords that cause an implicit commit. */
    private const MYSQL_IMPLICIT = [
        'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'ANALYZE',
        'OPTIMIZE', 'REPAIR', 'CHECK', 'CACHE', 'FLUSH', 'RESET', 'INSTALL', 'UNINSTALL', 'IMPORT', 'CHANGE',
        'STOP', 'PURGE',
    ];

    private string $dialect;
    private SqlLexer $lexer;
    /** Keyword(s) that decided a SQL Server batch (anywhere in it), for messages. */
    private string $hit = '';

    public function __construct(string $dialect = 'mysql')
    {
        $this->dialect = $dialect;
        $this->lexer = new SqlLexer($dialect);
    }

    public static function isMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * Classify one statement (as returned by SqlSplitter).
     * @return array{kind:string,head:string}
     */
    public function classify(string $sql): array
    {
        $kind = self::OK;
        $head = '';
        foreach ($this->lexer->tokenizeAll($sql) as $tokens) {
            $stmt = [];
            // SqlSplitter yields single statements; ";" is only re-checked for safety. A SQL
            // Server batch is scanned as a whole; so is a routine definition (CREATE PROCEDURE …
            // BEGIN …; …; END — the body does not run now; only its head is classified).
            $whole = $this->dialect === 'sqlsrv' || SqlSplitter::isRoutineDefinition($tokens, $this->dialect);
            $tokens[] = ['p', ';'];
            $last = count($tokens) - 1;
            foreach ($tokens as $i => $tok) {
                $end = $tok[0] === 'p' && $tok[1] === ';' && (!$whole || $i === $last);
                if (!$end) {
                    $stmt[] = $tok;
                    continue;
                }
                if ($stmt !== []) {
                    $this->hit = '';
                    $k = $this->classifyTokens($stmt);
                    if ($head === '' || self::SEVERITY[$k] > self::SEVERITY[$kind]) {
                        $head = $this->hit !== '' ? $this->hit : self::head($stmt);
                    }
                    if (self::SEVERITY[$k] > self::SEVERITY[$kind]) {
                        $kind = $k;
                    }
                }
                $stmt = [];
            }
        }
        return ['kind' => $kind, 'head' => $head];
    }

    /**
     * Check a whole script before anything runs. Throws when the mode cannot be honoured;
     * returns warnings for the response otherwise.
     * @param string[] $statements
     * @return string[]
     * @throws ApiException bad_request
     */
    public function check(array $statements, string $mode): array
    {
        if ($mode === self::NONE) {
            return [];
        }
        $label = $mode === self::DRYRUN ? 'Dry run' : 'Atomic mode';
        $warnings = [];
        foreach (array_values($statements) as $i => $sql) {
            $c = $this->classify($sql);
            $what = $c['head'] !== '' ? $c['head'] : 'statement';
            switch ($c['kind']) {
                case self::TX_CONTROL:
                    throw new ApiException($label . ' refused: ' . $what . ' (transaction control) is not allowed — the editor '
                        . 'already wraps the script in a transaction. Remove it or use Auto-commit. Nothing was executed.', 'bad_request', 400, $sql);
                case self::NOT_IN_TX:
                    throw new ApiException($label . ' refused: ' . $what . ' cannot run inside a transaction. '
                        . 'Use Auto-commit for it. Nothing was executed.', 'bad_request', 400, $sql);
                case self::IMPLICIT_COMMIT:
                    if ($mode === self::DRYRUN) {
                        throw new ApiException('Dry run refused: ' . $what . ' causes an implicit commit on MySQL/MariaDB, '
                            . 'so it would be applied for real and could not be rolled back. Nothing was executed.', 'bad_request', 400, $sql);
                    }
                    $warnings[] = 'Statement ' . ($i + 1) . ' (' . $what . ') causes an implicit commit on MySQL/MariaDB: '
                        . 'it ends the transaction, so it, the statements before it and the ones after it are committed '
                        . 'and cannot be rolled back.';
                    break;
                case self::MAY_COMMIT:
                    $warnings[] = 'Statement ' . ($i + 1) . ' (' . $what . ') calls a procedure that may commit on its own; '
                        . 'such changes are not rolled back.';
                    break;
            }
        }
        return $warnings;
    }

    /** @param array<int,array{0:string,1:string}> $t one statement */
    private function classifyTokens(array $t): string
    {
        while ($t !== [] && $t[0][0] === 'p' && $t[0][1] === '(') {
            array_shift($t);
        }
        if ($t === [] || $t[0][0] !== 'w') {
            return self::OK;
        }
        $w0 = $t[0][1];
        $w1 = self::word($t, 1);
        switch ($this->dialect) {
            case 'mysql':
                return $this->mysql($t, $w0, $w1);
            case 'pgsql':
                return $this->pgsql($t, $w0, $w1);
            case 'sqlite':
                if (in_array($w0, ['BEGIN', 'COMMIT', 'END', 'ROLLBACK', 'SAVEPOINT', 'RELEASE'], true)) {
                    return self::TX_CONTROL;
                }
                return $w0 === 'VACUUM' ? self::NOT_IN_TX : self::OK;
            case 'sqlsrv':
                return $this->sqlsrv($t, $w0, $w1);
            default:
                // Unknown engine: strictest union.
                $k = $this->mysql($t, $w0, $w1);
                $p = $this->pgsql($t, $w0, $w1);
                return self::SEVERITY[$p] > self::SEVERITY[$k] ? $p : $k;
        }
    }

    /** @param array<int,array{0:string,1:string}> $t */
    private function mysql(array $t, string $w0, string $w1): string
    {
        if ($w0 === 'BEGIN' && $w1 === 'NOT' && self::word($t, 2) === 'ATOMIC') {
            // MariaDB anonymous compound statement: its statements run now
            return $this->mysqlBlock(array_slice($t, 3));
        }
        if (in_array($w0, ['BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'XA'], true)) {
            return self::TX_CONTROL;
        }
        if ($w0 === 'START') {
            return $w1 === 'TRANSACTION' ? self::TX_CONTROL : self::IMPLICIT_COMMIT; // START REPLICA/SLAVE/GROUP_REPLICATION
        }
        if ($w0 === 'SET') {
            if (in_array(['w', 'AUTOCOMMIT'], $t, true)) { // also @@session.autocommit
                return self::TX_CONTROL;
            }
            return $w1 === 'PASSWORD' ? self::IMPLICIT_COMMIT : self::OK;
        }
        if (($w0 === 'CREATE' || $w0 === 'DROP') && self::temporaryTable($t)) {
            return self::OK; // CREATE/DROP TEMPORARY TABLE: no implicit commit
        }
        if ($w0 === 'LOAD') {
            return $w1 === 'INDEX' ? self::IMPLICIT_COMMIT : self::OK;
        }
        if (in_array($w0, self::MYSQL_IMPLICIT, true)) {
            return self::IMPLICIT_COMMIT;
        }
        return $w0 === 'CALL' ? self::MAY_COMMIT : self::OK;
    }

    /**
     * Strictest kind of the statements inside a MariaDB BEGIN NOT ATOMIC … END block: transaction
     * control anywhere (also in handlers / IF branches), otherwise each ";"-separated piece by its
     * first keyword after block words and labels.
     * @param array<int,array{0:string,1:string}> $t
     */
    private function mysqlBlock(array $t): string
    {
        foreach ($t as $k => $tok) {
            if ($tok[0] === 'w' && !($k > 0 && $t[$k - 1] === ['p', '.'])
                && (in_array($tok[1], ['COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'XA'], true)
                    || ($tok[1] === 'START' && self::word($t, $k + 1) === 'TRANSACTION'))) {
                $this->hit = $tok[1] === 'START' ? 'START TRANSACTION' : $tok[1];
                return self::TX_CONTROL;
            }
        }
        $kind = self::OK;
        $stmt = [];
        $t[] = ['p', ';'];
        foreach ($t as $tok) {
            if ($tok !== ['p', ';']) {
                $stmt[] = $tok;
                continue;
            }
            while ($stmt !== [] && $stmt[0][0] === 'w'
                && (in_array($stmt[0][1], ['BEGIN', 'END', 'NOT', 'ATOMIC', 'ELSE', 'LOOP', 'REPEAT', 'DO'], true)
                    || (isset($stmt[1]) && $stmt[1] === ['p', ':']))) {
                array_splice($stmt, 0, isset($stmt[1]) && $stmt[1] === ['p', ':'] ? 2 : 1);
            }
            if ($stmt !== [] && !in_array(self::word($stmt, 0), ['IF', 'ELSEIF', 'WHILE', 'UNTIL', 'DECLARE', 'CASE', 'WHEN'], true)) {
                $k = $this->mysql($stmt, self::word($stmt, 0), self::word($stmt, 1));
                if (self::SEVERITY[$k] > self::SEVERITY[$kind]) {
                    $this->hit = self::head($stmt);
                    $kind = $k;
                }
            }
            $stmt = [];
        }
        return $kind;
    }

    /** @param array<int,array{0:string,1:string}> $t */
    private function pgsql(array $t, string $w0, string $w1): string
    {
        if (in_array($w0, ['BEGIN', 'START', 'COMMIT', 'END', 'ROLLBACK', 'ABORT', 'SAVEPOINT', 'RELEASE'], true)) {
            return self::TX_CONTROL;
        }
        if ($w0 === 'PREPARE' && $w1 === 'TRANSACTION') {
            return self::TX_CONTROL;
        }
        if ($w0 === 'VACUUM') {
            return self::NOT_IN_TX;
        }
        if (($w0 === 'CREATE' || $w0 === 'DROP') && in_array($w1, ['DATABASE', 'TABLESPACE', 'SUBSCRIPTION'], true)) {
            return self::NOT_IN_TX;
        }
        if ($w0 === 'ALTER' && $w1 === 'SYSTEM') {
            return self::NOT_IN_TX;
        }
        if ($w0 === 'DISCARD' && $w1 === 'ALL') {
            return self::NOT_IN_TX;
        }
        if ($w0 === 'REINDEX') {
            return self::hasWord($t, 'CONCURRENTLY') || self::hasWord($t, 'DATABASE') || self::hasWord($t, 'SYSTEM')
                ? self::NOT_IN_TX : self::OK;
        }
        if (($w0 === 'CREATE' || $w0 === 'DROP') && self::hasWord($t, 'INDEX') && self::hasWord($t, 'CONCURRENTLY')) {
            return self::NOT_IN_TX;
        }
        return self::OK;
    }

    /** @param array<int,array{0:string,1:string}> $t */
    private function sqlsrv(array $t, string $w0, string $w1): string
    {
        if ((($w0 === 'CREATE' || $w0 === 'ALTER') && in_array($w1, ['PROC', 'PROCEDURE', 'FUNCTION', 'TRIGGER'], true))
            || ($w0 === 'CREATE' && $w1 === 'OR')) {
            return self::OK; // module definition: the body does not run now
        }
        $kind = self::OK;
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k][0] !== 'w' || ($k > 0 && $t[$k - 1][0] === 'p' && $t[$k - 1][1] === '.')) {
                continue;
            }
            $w = $t[$k][1];
            $next = self::word($t, $k + 1);
            if ((($w === 'BEGIN' || $w === 'SAVE') && in_array($next, ['TRAN', 'TRANSACTION', 'DISTRIBUTED'], true))
                || $w === 'COMMIT' || $w === 'ROLLBACK'
                || ($w === 'SET' && $next === 'IMPLICIT_TRANSACTIONS')) {
                $this->hit = trim($w . ' ' . ($w === 'COMMIT' || $w === 'ROLLBACK' ? '' : $next));
                return self::TX_CONTROL;
            }
            if ((in_array($w, ['CREATE', 'ALTER', 'DROP'], true) && in_array($next, ['DATABASE', 'FULLTEXT'], true))
                || in_array($w, ['BACKUP', 'RESTORE', 'RECONFIGURE'], true)) {
                $this->hit = trim($w . ' ' . $next);
                $kind = self::NOT_IN_TX;
            } elseif (($w === 'EXEC' || $w === 'EXECUTE') && $kind === self::OK) {
                $kind = self::MAY_COMMIT;
            }
        }
        return $kind;
    }

    /** MySQL CREATE [OR REPLACE] TEMPORARY TABLE / DROP TEMPORARY TABLE. @param array<int,array{0:string,1:string}> $t */
    private static function temporaryTable(array $t): bool
    {
        for ($k = 1; $k <= 3; $k++) {
            if (self::word($t, $k) === 'TEMPORARY') {
                return self::word($t, $k + 1) === 'TABLE';
            }
        }
        return false;
    }

    /** Upper-cased word at token $k ('' when it is not a bare word). @param array<int,array{0:string,1:string}> $t */
    private static function word(array $t, int $k): string
    {
        return isset($t[$k]) && $t[$k][0] === 'w' ? $t[$k][1] : '';
    }

    /** Bare (unquoted, unqualified) word anywhere in the statement. @param array<int,array{0:string,1:string}> $t */
    private static function hasWord(array $t, string $word): bool
    {
        foreach ($t as $k => $tok) {
            if ($tok[0] === 'w' && $tok[1] === $word && !($k > 0 && $t[$k - 1][0] === 'p' && $t[$k - 1][1] === '.')) {
                return true;
            }
        }
        return false;
    }

    /** "CREATE TABLE", "VACUUM", "START TRANSACTION" … for messages. @param array<int,array{0:string,1:string}> $t */
    private static function head(array $t): string
    {
        while ($t !== [] && $t[0][0] === 'p' && $t[0][1] === '(') {
            array_shift($t);
        }
        $words = [];
        foreach (array_slice($t, 0, 2) as $tok) {
            if ($tok[0] !== 'w') {
                break;
            }
            $words[] = $tok[1];
        }
        return implode(' ', $words);
    }
}
