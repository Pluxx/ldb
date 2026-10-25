<?php

declare(strict_types=1);

namespace Ldb\Routine;

use Ldb\Db\AbstractPdoDriver;
use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;

/**
 * Structured editing of stored routines, triggers and events (routine.* / trigger.* / event.*).
 *
 * One subclass per engine: it reads definitions from the catalog (info*), renders CREATE /
 * replace / drop plans from a definition (build*, pure: no database access, unit-testable
 * with a fixed server version) and executes them (save* / drop* / execute).
 *
 * Plans: {sql:string[], warnings:string[], transactional:bool, restore:string[]}
 *  - warnings are stable codes the UI translates: grants_lost (DROP + CREATE: privileges on the
 *    object are gone), not_atomic (MySQL DROP + CREATE cannot run in a transaction; the old
 *    definition is restored automatically when CREATE fails), replace_creates_overload …
 *  - transactional: all statements run inside one transaction (PostgreSQL, SQL Server, SQLite DDL)
 *  - restore: statements that recreate the original object when a later statement fails
 *    (MySQL DROP + CREATE)
 *
 * Bodies are user SQL by design and passed verbatim; names, parameter modes, types and
 * characteristics are validated / quoted here.
 */
abstract class RoutineEditor
{
    public const WARN_GRANTS_LOST = 'grants_lost';
    public const WARN_NOT_ATOMIC = 'not_atomic';
    public const WARN_SQL_MODE = 'sql_mode_restored';

    /** Connected driver (null in unit tests that only render SQL). */
    protected ?DriverInterface $db;

    /** @var array<string,mixed> server facts used by the SQL builders (version, flags, current user …) */
    protected array $server;

    /** @param array<string,mixed> $server overrides of detected server facts (tests) */
    public function __construct(?DriverInterface $db, array $server = [])
    {
        $this->db = $db;
        $this->server = $server + ($db !== null ? $this->detectServer() : []);
    }

    /** Editor for a connected driver. */
    public static function forDriver(DriverInterface $db): self
    {
        switch ($db::id()) {
            case 'mysql':
                return new MySqlRoutineEditor($db);
            case 'pgsql':
                return new PgSqlRoutineEditor($db);
            case 'sqlsrv':
                return new SqlSrvRoutineEditor($db);
            case 'sqlite':
                return new SqliteRoutineEditor($db);
        }
        throw ApiException::unsupported('Routine editing is not supported by this driver');
    }

    /** @return array<string,mixed> */
    protected function detectServer(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ contract

    /** @return array<string,mixed> RoutineDefinition + {sql} */
    public function routineInfo(string $name, ?string $type = null, ?string $signature = null): array
    {
        throw ApiException::unsupported('Stored routines are not supported by this driver');
    }

    /**
     * @param array<string,mixed> $def RoutineDefinition
     * @param array<string,mixed>|null $original {name,type,signature?} of the routine being edited; null = new
     * @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]}
     */
    public function buildRoutineSave(array $def, ?array $original): array
    {
        throw ApiException::unsupported('Stored routines are not supported by this driver');
    }

    /** @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]} */
    public function buildRoutineDrop(string $name, string $type, ?string $signature = null): array
    {
        throw ApiException::unsupported('Stored routines are not supported by this driver');
    }

    /**
     * Statement(s) calling a routine with bound values.
     * @param array<string,mixed> $info routineInfo()
     * @param array<int,array{value:mixed,null?:bool,default?:bool}> $args by parameter position
     * @return array{steps:array<int,array{sql:string,params:array,role:string}>}
     *         role: setup (no result kept) | call (result sets) | out (OUT values, one row)
     */
    public function buildExecute(array $info, array $args): array
    {
        throw ApiException::unsupported('Executing routines is not supported by this driver');
    }

    /** @return array<string,mixed> TriggerDefinition + {sql} */
    public function triggerInfo(string $name, ?string $table = null): array
    {
        throw ApiException::unsupported('Triggers are not supported by this driver');
    }

    /** @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]} */
    public function buildTriggerSave(array $def, ?array $original): array
    {
        throw ApiException::unsupported('Triggers are not supported by this driver');
    }

    /** @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]} */
    public function buildTriggerDrop(string $name, ?string $table = null): array
    {
        throw ApiException::unsupported('Triggers are not supported by this driver');
    }

    /** @return array<string,mixed> EventDefinition + {sql} */
    public function eventInfo(string $name): array
    {
        throw ApiException::unsupported('Events are not supported by this driver');
    }

    /** @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]} */
    public function buildEventSave(array $def, ?array $original): array
    {
        throw ApiException::unsupported('Events are not supported by this driver');
    }

    /** @return array{sql:string[],warnings:string[],transactional:bool,restore:string[]} */
    public function buildEventDrop(string $name): array
    {
        throw ApiException::unsupported('Events are not supported by this driver');
    }

    /**
     * Hook run before a save plan executes (e.g. MySQL: capture the current definition so a failed
     * DROP + CREATE can be undone). May extend $plan['restore'].
     * @param 'routine'|'trigger'|'event' $kind
     */
    public function prepareSave(string $kind, array $plan, ?array $original): array
    {
        return $plan;
    }

    // ------------------------------------------------------------------ execution

    /**
     * Run a plan. Transactional plans run in one transaction (rolled back on error); otherwise a
     * failing statement triggers the plan's restore statements (best effort) before the error is
     * re-thrown (as db_error with the failing SQL; message notes whether the original was restored).
     */
    public function executePlan(array $plan): void
    {
        $db = $this->requireDb();
        $pdo = $db->pdo();
        $tx = !empty($plan['transactional']) && count($plan['sql']) > 1;
        if ($tx) {
            $pdo->beginTransaction();
        }
        $dropped = false;
        foreach ($plan['sql'] as $sql) {
            try {
                $db->query($sql, 0);
                if (preg_match('/^\s*DROP\b/i', $sql)) {
                    $dropped = true;
                }
            } catch (\PDOException $e) {
                if ($tx) {
                    try {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    } catch (\Throwable $e2) {
                    }
                    throw ApiException::dbError($e->getMessage(), $sql, $e);
                }
                $message = $e->getMessage();
                if ($dropped && !empty($plan['restore'])) {
                    $restored = true;
                    foreach ($plan['restore'] as $r) {
                        try {
                            $db->query($r, 0);
                        } catch (\Throwable $e2) {
                            $restored = false;
                            break;
                        }
                    }
                    $message .= $restored
                        ? ' (the original definition was restored)'
                        : ' (WARNING: the original definition could not be restored)';
                }
                throw ApiException::dbError($message, $sql, $e);
            }
        }
        if ($tx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Execute a routine: runs the steps of buildExecute() and collects result sets / OUT values.
     * @return array{results:array<int,array<string,mixed>>,out:array<int,array{name:string,value:mixed}>,sql:string}
     */
    public function execute(array $info, array $args, int $limit, bool $readOnly): array
    {
        $db = $this->requireDb();
        if (!$db instanceof AbstractPdoDriver) {
            throw ApiException::unsupported('Executing routines is not supported by this driver');
        }
        $plan = $this->buildExecute($info, $args);
        $results = [];
        $out = [];
        $display = [];
        foreach ($plan['steps'] as $step) {
            $shown = $db->renderDisplaySql($step['sql'], $step['params']);
            $display[] = $shown;
            if ($readOnly) {
                $db->setReadOnly(true);
            }
            $start = microtime(true);
            try {
                $res = $db->queryWithParams($step['sql'], $step['params'], $limit);
            } catch (\PDOException $e) {
                throw ApiException::dbError($e->getMessage(), $shown, $e);
            }
            $res['sql'] = $shown;
            $res['time_ms'] = round((microtime(true) - $start) * 1000, 2);
            $res += ['error' => null, 'editable' => null];
            $res['editable'] = null;
            if ($step['role'] === 'out') {
                $out = array_merge($out, self::outValues($res));
                continue;
            }
            if ($step['role'] === 'call_out') {
                // last result set of the batch carries the OUT values (SQL Server)
                [$res, $values] = self::splitLastSet($res);
                $out = array_merge($out, $values);
            }
            if ($step['role'] === 'call' || $step['role'] === 'call_out') {
                $results[] = $res;
            }
        }
        return ['results' => $results, 'out' => $out, 'sql' => implode(";\n", $display)];
    }

    /** @return array<int,array{name:string,value:mixed}> */
    private static function outValues(array $res): array
    {
        $out = [];
        if (empty($res['is_result_set'])) {
            return $out;
        }
        $row = $res['rows'][0] ?? [];
        foreach ($res['columns'] as $i => $c) {
            $out[] = ['name' => (string) $c['name'], 'value' => $row[$i] ?? null];
        }
        return $out;
    }

    /**
     * Remove the last result set of a batch result and return its first row as OUT values.
     * @return array{0:array<string,mixed>,1:array<int,array{name:string,value:mixed}>}
     */
    private static function splitLastSet(array $res): array
    {
        if (empty($res['is_result_set'])) {
            return [$res, []];
        }
        $more = $res['more_results'] ?? [];
        if ($more) {
            $last = array_pop($more);
            if ($more) {
                $res['more_results'] = $more;
            } else {
                unset($res['more_results']);
            }
            return [$res, self::outValues($last)];
        }
        $values = self::outValues($res);
        $res['is_result_set'] = false;
        $res['columns'] = [];
        $res['rows'] = [];
        return [$res, $values];
    }

    protected function requireDb(): DriverInterface
    {
        if ($this->db === null) {
            throw new ApiException('Not connected', 'internal', 500);
        }
        return $this->db;
    }

    // ------------------------------------------------------------------ validation helpers

    /** Validated object name (trimmed, non-empty, max length, no control characters). */
    protected static function name($value, string $what = 'name', int $max = 64): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '') {
            throw ApiException::badRequest('Missing ' . $what);
        }
        if (mb_strlen($name, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/', $name) || !mb_check_encoding($name, 'UTF-8')) {
            throw ApiException::badRequest('Invalid ' . $what . ': ' . $name);
        }
        return $name;
    }

    /** One of $allowed (case-insensitive, whitespace collapsed); $default when empty. */
    protected static function choice($value, array $allowed, string $what, ?string $default = null): string
    {
        $v = is_string($value) ? strtoupper(trim((string) preg_replace('/\s+/', ' ', $value))) : '';
        if ($v === '' && $default !== null) {
            return $default;
        }
        if (!in_array($v, $allowed, true)) {
            throw ApiException::badRequest('Invalid ' . $what . ': ' . (is_scalar($value) ? (string) $value : gettype($value)));
        }
        return $v;
    }

    protected static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected static function optStr($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(self::str($value));
        return $s === '' ? null : $s;
    }

    /**
     * Validated data type expression ("INT UNSIGNED", "DECIMAL(12,2)", "character varying(20)[]",
     * "ENUM('a','b')", "timestamp(3) with time zone", "public.my_type", "tbl.col%TYPE"). Rejects
     * anything that could end the declaration (";", comments, unbalanced quotes/parentheses).
     */
    protected static function type($value, string $what = 'type'): string
    {
        $type = trim((string) preg_replace('/\s+/', ' ', self::str($value)));
        $ok = $type !== '' && strlen($type) <= 200
            && preg_match('/^[A-Za-z_"][A-Za-z0-9_ ."$]*'
                . "(\\(\\s*(?:[0-9]+(?:\\s*,\\s*-?[0-9]+)?|max|MAX|'(?:[^'\\\\]|'')*'(?:\\s*,\\s*'(?:[^'\\\\]|'')*')*)\\s*\\))?"
                . '[A-Za-z0-9_ ]*(\[\d*\])*(%(?:ROW)?TYPE)?$/', $type)
            && substr_count($type, '"') % 2 === 0;
        if (!$ok) {
            throw ApiException::badRequest('Invalid ' . $what . ': ' . $type);
        }
        return $type;
    }

    /**
     * A user SQL fragment kept verbatim (default expressions, WHEN conditions): must not contain
     * a statement terminator or comment outside of string literals / quoted identifiers.
     */
    protected static function fragment($value, string $what): string
    {
        $s = trim(self::str($value));
        if ($s === '' || !self::isSingleExpression($s)) {
            throw ApiException::badRequest('Invalid ' . $what . ': ' . $s);
        }
        return $s;
    }

    /** True when $s has balanced parentheses and no ";" / "--" / "/*" / "#" outside quotes. */
    protected static function isSingleExpression(string $s): bool
    {
        $len = strlen($s);
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === '\\' && $c === "'") {
                        $j += 2;
                        continue;
                    }
                    if ($s[$j] === $c) {
                        if (($s[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                if ($j >= $len) {
                    return false;
                }
                $i = $j;
                continue;
            }
            if ($c === '[') {
                // SQL Server [identifiers]: "]]" escapes; array subscripts elsewhere are harmless
                $j = strpos($s, ']', $i + 1);
                if ($j === false) {
                    return false;
                }
                $i = $j;
                continue;
            }
            if ($c === ';' || $c === '#' || ($c === '-' && ($s[$i + 1] ?? '') === '-') || ($c === '/' && ($s[$i + 1] ?? '') === '*')) {
                return false;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if (--$depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /** Validated datetime literal "YYYY-MM-DD[ HH:MM[:SS[.ffffff]]]". */
    protected static function datetime($value, string $what): string
    {
        $s = trim(str_replace('T', ' ', self::str($value)));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?$/', $s)) {
            throw ApiException::badRequest('Invalid ' . $what . ': ' . $s);
        }
        if (strlen($s) === 16) {
            $s .= ':00';
        }
        return $s;
    }

    /** Body (user SQL, verbatim) — only trimmed and required. */
    protected static function body($value, string $what = 'body'): string
    {
        $body = rtrim(self::str($value));
        $body = (string) preg_replace('/^\s*\n/', '', $body);
        if (trim($body) === '') {
            throw ApiException::badRequest('Missing ' . $what);
        }
        return $body;
    }

    /** Normalized text for comparing type lists ("INT  UNSIGNED" == "int unsigned"). */
    protected static function norm(?string $s): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $s)));
    }

    protected static function plan(array $sql, array $warnings = [], bool $transactional = false, array $restore = []): array
    {
        return ['sql' => array_values($sql), 'warnings' => array_values(array_unique($warnings)), 'transactional' => $transactional, 'restore' => array_values($restore)];
    }

    /** Statement list as one script (for "Copy as SQL"). */
    public function script(array $plan): string
    {
        return implode("\n\n", array_map(static fn (string $s): string => rtrim($s, "; \n") . ';', $plan['sql']));
    }

    /**
     * Parameter values from the request: [{value, null?:bool, default?:bool}] by position.
     * @return array<int,array{value:?string,null:bool,default:bool}>
     */
    public static function normalizeArgs(array $args): array
    {
        $out = [];
        foreach (array_values($args) as $a) {
            if (!is_array($a)) {
                $a = ['value' => $a];
            }
            $v = $a['value'] ?? null;
            if (is_array($v) || is_object($v)) {
                throw ApiException::badRequest('Invalid parameter value');
            }
            $out[] = [
                'value' => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v),
                'null' => !empty($a['null']) || $v === null,
                'default' => !empty($a['default']),
            ];
        }
        return $out;
    }

    /**
     * Tokens of a DDL text with byte offsets, skipping whitespace and comments:
     * [type, text, start, end, depth] — type w (word / @variable), q (quoted identifier), s (string),
     * p (punctuation); depth = parenthesis depth before the token. [brackets] are identifiers
     * when $brackets (SQL Server, SQLite).
     * @return array<int,array{0:string,1:string,2:int,3:int,4:int}>
     */
    public static function scan(string $sql, bool $brackets = true): array
    {
        $out = [];
        $len = strlen($sql);
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '-' && $n === '-') {
                $e = strpos($sql, "\n", $i);
                $i = $e === false ? $len : $e + 1;
                continue;
            }
            if ($c === '/' && $n === '*') {
                $e = strpos($sql, '*/', $i + 2);
                $i = $e === false ? $len : $e + 2;
                continue;
            }
            $start = $i;
            if ($c === "'" || $c === '"' || $c === '`' || ($c === '[' && $brackets)) {
                $close = $c === '[' ? ']' : $c;
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === $close) {
                        if (($sql[$j + 1] ?? '') === $close) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $i = min($len, $j + 1);
                $out[] = [$c === "'" ? 's' : 'q', substr($sql, $start, $i - $start), $start, $i, $depth];
                continue;
            }
            if (preg_match('/[A-Za-z0-9_@#$\x80-\xFF]/', $c)) {
                $j = $i + 1;
                while ($j < $len && preg_match('/[A-Za-z0-9_@#$\x80-\xFF]/', $sql[$j])) {
                    $j++;
                }
                $out[] = ['w', strtoupper(substr($sql, $i, $j - $i)), $i, $j, $depth];
                $i = $j;
                continue;
            }
            if ($c === ')') {
                $depth = max(0, $depth - 1);
            }
            $out[] = ['p', $c, $i, $i + 1, $depth];
            if ($c === '(') {
                $depth++;
            }
            $i++;
        }
        return $out;
    }

    /** Unquote [x] / "x" / `x` identifiers. */
    public static function unquoteIdent(string $s): string
    {
        $f = $s[0] ?? '';
        if ($f === '[') {
            return str_replace(']]', ']', substr($s, 1, -1));
        }
        if ($f === '"' || $f === '`') {
            return str_replace($f . $f, $f, substr($s, 1, -1));
        }
        return $s;
    }

    /** Split "a, f(b, c), 'x,y'" at top-level commas (quotes, parentheses and brackets aware). */
    public static function splitTopLevel(string $s, string $sep = ','): array
    {
        $parts = [];
        $depth = 0;
        $cur = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === $c) {
                        if (($s[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $cur .= substr($s, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            }
            if ($c === $sep && $depth === 0) {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') {
            $parts[] = trim($cur);
        }
        return $parts;
    }
}
