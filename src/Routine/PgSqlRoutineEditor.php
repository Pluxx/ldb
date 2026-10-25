<?php

declare(strict_types=1);

namespace Ldb\Routine;

use Ldb\Http\ApiException;
use PDO;

/**
 * PostgreSQL: functions and procedures (overloads identified by their identity argument list,
 * pg_get_function_identity_arguments) and triggers (EXECUTE FUNCTION reference). No events.
 *
 * Replacing a routine: CREATE OR REPLACE keeps grants but cannot change the argument list or
 * the return type (it would create an overload / fail), so such edits are DROP + CREATE in one
 * transaction (grants lost). A pure rename uses ALTER … RENAME TO. Triggers: CREATE OR REPLACE
 * TRIGGER on PostgreSQL 14+ (same table), otherwise DROP + CREATE in a transaction.
 */
final class PgSqlRoutineEditor extends RoutineEditor
{
    private const MODES = ['IN', 'OUT', 'INOUT', 'VARIADIC'];
    private const VOLATILITY = ['VOLATILE', 'STABLE', 'IMMUTABLE'];
    private const PARALLEL = ['UNSAFE', 'RESTRICTED', 'SAFE'];
    private const MAX_NAME = 63;

    /** Reserved key words that must be quoted as parameter names. */
    private const RESERVED = ['all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'authorization',
        'binary', 'both', 'case', 'cast', 'check', 'collate', 'collation', 'column', 'concurrently', 'constraint', 'create',
        'cross', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time', 'current_timestamp',
        'current_user', 'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end', 'except', 'false', 'fetch', 'for',
        'foreign', 'freeze', 'from', 'full', 'grant', 'group', 'having', 'ilike', 'in', 'initially', 'inner', 'intersect',
        'into', 'is', 'isnull', 'join', 'lateral', 'leading', 'left', 'like', 'limit', 'localtime', 'localtimestamp',
        'natural', 'not', 'notnull', 'null', 'offset', 'on', 'only', 'or', 'order', 'outer', 'overlaps', 'placing',
        'primary', 'references', 'returning', 'right', 'select', 'session_user', 'similar', 'some', 'symmetric',
        'system_user', 'table', 'tablesample', 'then', 'to', 'trailing', 'true', 'union', 'unique', 'user', 'using',
        'variadic', 'verbose', 'when', 'where', 'window', 'with', 'out', 'inout'];

    protected function detectServer(): array
    {
        $pdo = $this->requireDb()->pdo();
        $row = $pdo->query('SELECT current_setting(\'server_version_num\')::int AS v, current_user AS u')->fetch(PDO::FETCH_ASSOC);
        return ['pg_version' => (int) ($row['v'] ?? 0), 'current_user' => (string) ($row['u'] ?? '')];
    }

    private function pgv(): int
    {
        return (int) ($this->server['pg_version'] ?? 0);
    }

    // ------------------------------------------------------------------ quoting

    private static function q(string $name): string
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            throw ApiException::badRequest('Invalid identifier');
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** Quote only when needed (like quote_ident) so parameter names stay usable unquoted in bodies. */
    private static function qIfNeeded(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_$]*$/', $name) && !in_array($name, self::RESERVED, true)) {
            return $name;
        }
        return self::q($name);
    }

    private static function lit(string $s): string
    {
        if (strpos($s, '\\') !== false) {
            return "E'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
        }
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function schema(): string
    {
        $s = $this->db !== null ? $this->db->currentSchema() : ($this->server['schema'] ?? null);
        return $s !== null && $s !== '' ? (string) $s : 'public';
    }

    private function qualified(string $name): string
    {
        return self::q($this->schema()) . '.' . self::q($name);
    }

    /** $$ dollar quote with a tag that does not occur in the body. */
    public static function dollarQuote(string $body): string
    {
        $tag = '$$';
        $n = 0;
        while (strpos($body, $tag) !== false) {
            $tag = '$ldb' . ($n === 0 ? '' : (string) $n) . '$';
            $n++;
        }
        return $tag . "\n" . $body . "\n" . $tag;
    }

    // ------------------------------------------------------------------ routines: read

    public function routineInfo(string $name, ?string $type = null, ?string $signature = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $v = $this->pgv();
        $kind = $v >= 110000 ? 'p.prokind' : "CASE WHEN p.proisagg THEN 'a' WHEN p.proiswindow THEN 'w' ELSE 'f' END";
        $parallel = $v >= 90600 ? 'p.proparallel' : "'u'";
        $atomic = $v >= 140000 ? 'p.prosqlbody IS NOT NULL' : 'false';
        $sql = "SELECT p.oid, p.proname, $kind AS kind, l.lanname, p.provolatile, p.proisstrict, p.prosecdef, p.proleakproof,
                       $parallel AS parallel, p.procost, p.prorows, p.proretset, pg_get_function_result(p.oid) AS result,
                       pg_get_function_identity_arguments(p.oid) AS identity, p.pronargdefaults,
                       pg_get_expr(p.proargdefaults, 0) AS defaults, p.prosrc, $atomic AS atomic,
                       obj_description(p.oid, 'pg_proc') AS comment, pg_get_userbyid(p.proowner) AS owner,
                       array_to_json(p.proconfig) AS config
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
                WHERE n.nspname = ? AND p.proname = ?";
        $params = [$this->schema(), $name];
        if ($signature !== null) {
            $sql .= ' AND pg_get_function_identity_arguments(p.oid) = ?';
            $params[] = $signature;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY p.oid');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($type !== null && $type !== '') {
            $want = self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type') === 'PROCEDURE' ? 'p' : 'f';
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === $want));
        }
        if (!$rows) {
            throw ApiException::notFound('Routine "' . $name . ($signature !== null ? '(' . $signature . ')' : '') . '" not found');
        }
        $r = $rows[0];
        if (!in_array($r['kind'], ['f', 'p'], true)) {
            throw ApiException::unsupported('Aggregate and window functions cannot be edited here');
        }
        $oid = (int) $r['oid'];
        $stmt = $pdo->prepare("SELECT a.ord, format_type(a.t, NULL) AS type, COALESCE(p.proargmodes[a.ord], 'i') AS mode,
                p.proargnames[a.ord] AS name
            FROM pg_proc p, unnest(COALESCE(p.proallargtypes, p.proargtypes::oid[])) WITH ORDINALITY AS a(t, ord)
            WHERE p.oid = ? ORDER BY a.ord");
        $stmt->execute([$oid]);
        $args = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $defaults = $r['defaults'] !== null ? self::splitTopLevel((string) $r['defaults']) : [];
        $inputIdx = [];
        foreach ($args as $i => $a) {
            if (in_array($a['mode'], ['i', 'b', 'v'], true)) {
                $inputIdx[] = $i;
            }
        }
        $withDefault = array_slice($inputIdx, count($inputIdx) - count($defaults));
        $modeMap = ['i' => 'IN', 'o' => 'OUT', 'b' => 'INOUT', 'v' => 'VARIADIC'];
        $params = [];
        $hasOut = false;
        foreach ($args as $i => $a) {
            if ($a['mode'] === 't') {
                continue; // RETURNS TABLE column (part of the result)
            }
            $p = ['mode' => $modeMap[$a['mode']] ?? 'IN', 'name' => (string) ($a['name'] ?? ''), 'type' => (string) $a['type']];
            $k = array_search($i, $withDefault, true);
            if ($k !== false) {
                $p['default'] = $defaults[$k] ?? null;
            }
            if ($p['mode'] === 'OUT' || $p['mode'] === 'INOUT') {
                $hasOut = true;
            }
            $params[] = $p;
        }
        $isProc = $r['kind'] === 'p';
        $returns = $isProc ? null : (string) $r['result'];
        if (!$isProc && $hasOut && strtolower((string) $returns) === 'record') {
            $returns = null; // implied by the OUT parameters
        }
        $ddl = null;
        try {
            $ddl = rtrim((string) $pdo->query('SELECT pg_get_functiondef(' . $oid . ')')->fetchColumn()) . ';';
        } catch (\PDOException $e) {
        }
        $atomicBody = $r['atomic'] === true || $r['atomic'] === 't' || $r['atomic'] === 1 || $r['atomic'] === '1';
        $body = (string) preg_replace('/^[ \t]*\r?\n/', '', rtrim((string) $r['prosrc']));
        if ($atomicBody && $ddl !== null && preg_match('/^(BEGIN ATOMIC\b.*|\s?RETURN\s.*)\z/ms', rtrim($ddl, ';'), $m)) {
            $body = trim($m[1]);
        }
        $config = json_decode((string) $r['config'], true);
        $vol = ['v' => 'VOLATILE', 's' => 'STABLE', 'i' => 'IMMUTABLE'];
        $par = ['u' => 'UNSAFE', 'r' => 'RESTRICTED', 's' => 'SAFE'];
        return [
            'kind' => 'routine',
            'name' => (string) $r['proname'],
            'type' => $isProc ? 'PROCEDURE' : 'FUNCTION',
            'signature' => (string) $r['identity'],
            'parameters' => $params,
            'returns' => $returns,
            'returns_set' => (bool) $r['proretset'],
            'body' => $body,
            'atomic_body' => $atomicBody,
            'language' => (string) $r['lanname'],
            'volatility' => $vol[$r['provolatile']] ?? 'VOLATILE',
            'strict' => self::bool($r['proisstrict']),
            'security' => self::bool($r['prosecdef']) ? 'DEFINER' : 'INVOKER',
            'leakproof' => self::bool($r['proleakproof']),
            'parallel' => $par[$r['parallel']] ?? 'UNSAFE',
            'cost' => $r['procost'] === null ? null : (float) $r['procost'],
            'rows' => $r['prorows'] === null ? null : (float) $r['prorows'],
            'config' => is_array($config) ? array_values($config) : [],
            'definer' => (string) $r['owner'],
            'comment' => $r['comment'] === null ? '' : (string) $r['comment'],
            'overloads' => count($rows),
            'sql' => $ddl,
        ];
    }

    private static function bool($v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    // ------------------------------------------------------------------ routines: build

    /**
     * @return array{type:string,name:string,params:string[],sig:string}
     */
    private function routineHead(array $def): array
    {
        $type = self::choice($def['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'routine type');
        $name = self::name($def['name'] ?? '', 'routine name', self::MAX_NAME);
        $params = [];
        $sig = [];
        $seen = [];
        foreach (array_values((array) ($def['parameters'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                throw ApiException::badRequest('Invalid parameter #' . ($i + 1));
            }
            $mode = self::choice($p['mode'] ?? 'IN', self::MODES, 'parameter mode', 'IN');
            if ($type === 'PROCEDURE' && $mode === 'OUT' && $this->pgv() > 0 && $this->pgv() < 140000) {
                throw ApiException::badRequest('Procedures support OUT parameters from PostgreSQL 14 (use INOUT)');
            }
            $pname = trim(self::str($p['name'] ?? ''));
            if ($pname !== '') {
                $pname = self::name($pname, 'parameter name', self::MAX_NAME);
                if (isset($seen[$pname])) {
                    throw ApiException::badRequest('Duplicate parameter name: ' . $pname);
                }
                $seen[$pname] = true;
            }
            $ptype = self::type($p['type'] ?? '', 'type of parameter ' . ($pname !== '' ? $pname : '#' . ($i + 1)));
            $sql = ($mode === 'IN' ? '' : $mode . ' ') . ($pname !== '' ? self::qIfNeeded($pname) . ' ' : '') . $ptype;
            $default = self::optStr($p['default'] ?? null);
            if ($default !== null) {
                if ($mode === 'OUT') {
                    throw ApiException::badRequest('OUT parameters cannot have a default');
                }
                $sql .= ' DEFAULT ' . self::fragment($default, 'default of parameter ' . $pname);
            }
            $params[] = $sql;
            $sig[] = ($mode === 'IN' ? '' : $mode . ' ') . $ptype;
        }
        return ['type' => $type, 'name' => $name, 'params' => $params, 'sig' => implode(', ', $sig)];
    }

    /** "SETOF integer", "TABLE(id integer, name text)", "trigger", "void", "numeric(10,2)" … */
    private static function returnsSql($value): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', self::str($value)));
        if (preg_match('/^TABLE\s*\((.*)\)$/is', $s, $m)) {
            $cols = [];
            foreach (self::splitTopLevel($m[1]) as $c) {
                if (!preg_match('/^("(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)\s+(.+)$/s', trim($c), $cm)) {
                    throw ApiException::badRequest('Invalid RETURNS TABLE column: ' . $c);
                }
                $cols[] = $cm[1] . ' ' . self::type($cm[2], 'type of result column ' . $cm[1]);
            }
            if (!$cols) {
                throw ApiException::badRequest('RETURNS TABLE needs at least one column');
            }
            return 'TABLE(' . implode(', ', $cols) . ')';
        }
        if (preg_match('/^SETOF\s+(.+)$/i', $s, $m)) {
            return 'SETOF ' . self::type($m[1], 'return type');
        }
        return self::type($s, 'return type');
    }

    public function createRoutineSql(array $def, bool $orReplace = false, ?string $asName = null): array
    {
        $head = $this->routineHead($def);
        $name = $asName ?? $head['name'];
        $language = strtolower(trim(self::str($def['language'] ?? 'plpgsql')));
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $language)) {
            throw ApiException::badRequest('Invalid language: ' . $language);
        }
        if (in_array($language, ['c', 'internal'], true)) {
            throw ApiException::badRequest('Functions in language ' . $language . ' cannot be edited here');
        }
        $isFn = $head['type'] === 'FUNCTION';
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . $head['type'] . ' ' . $this->qualified($name)
            . '(' . implode(', ', $head['params']) . ')';
        if ($isFn) {
            $ret = self::optStr($def['returns'] ?? null);
            $hasOut = false;
            foreach ((array) ($def['parameters'] ?? []) as $p) {
                $m = strtoupper(trim(self::str($p['mode'] ?? 'IN')));
                $hasOut = $hasOut || $m === 'OUT' || $m === 'INOUT';
            }
            if ($ret === null && !$hasOut) {
                throw ApiException::badRequest('Missing return type');
            }
            if ($ret !== null) {
                $sql .= "\n RETURNS " . self::returnsSql($ret);
            }
        }
        $sql .= "\n LANGUAGE " . $language;
        if ($isFn) {
            $sql .= "\n " . self::choice($def['volatility'] ?? '', self::VOLATILITY, 'volatility', 'VOLATILE');
            if (!empty($def['strict'])) {
                $sql .= "\n STRICT";
            }
            if (!empty($def['leakproof'])) {
                $sql .= "\n LEAKPROOF";
            }
            $parallel = self::choice($def['parallel'] ?? '', self::PARALLEL, 'parallel safety', 'UNSAFE');
            if ($parallel !== 'UNSAFE') {
                $sql .= "\n PARALLEL " . $parallel;
            }
            foreach (['cost' => 'COST', 'rows' => 'ROWS'] as $k => $kw) {
                if (isset($def[$k]) && $def[$k] !== '' && $def[$k] !== null) {
                    if (!is_numeric($def[$k]) || (float) $def[$k] < 0) {
                        throw ApiException::badRequest('Invalid ' . $k . ': ' . self::str($def[$k]));
                    }
                    $n = (float) $def[$k];
                    $default = $k === 'cost' ? (in_array($language, ['c', 'internal'], true) ? 1.0 : 100.0) : 1000.0;
                    if ($k === 'rows' && empty($def['returns_set']) && !preg_match('/^(SETOF|TABLE)\b/i', (string) ($def['returns'] ?? ''))) {
                        continue;
                    }
                    if ($n !== $default) {
                        $sql .= "\n " . $kw . ' ' . rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
                    }
                }
            }
        }
        if (self::choice($def['security'] ?? '', ['DEFINER', 'INVOKER'], 'security', 'INVOKER') === 'DEFINER') {
            $sql .= "\n SECURITY DEFINER";
        }
        foreach ((array) ($def['config'] ?? []) as $cfg) {
            $sql .= "\n SET " . self::configSql(self::str($cfg));
        }
        $body = self::body($def['body'] ?? '');
        if (!empty($def['atomic_body'])) {
            $sql .= "\n" . $body;
        } else {
            $sql .= "\nAS " . self::dollarQuote($body);
        }
        return ['sql' => $sql, 'type' => $head['type'], 'name' => $name, 'sig' => $head['sig']];
    }

    /** "search_path=public, pg_temp" → search_path TO public, pg_temp (value kept, validated). */
    private static function configSql(string $cfg): string
    {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.]*)\s*(?:=|\bTO\b)\s*(.+?)\s*$/is', $cfg, $m)) {
            throw ApiException::badRequest('Invalid configuration setting (name=value): ' . $cfg);
        }
        $value = $m[2];
        if (!self::isSingleExpression($value) || preg_match('/[\r\n]/', $value)) {
            throw ApiException::badRequest('Invalid configuration value: ' . $value);
        }
        // proconfig stores plain text: quote values that are not lists of simple words/numbers
        if (!preg_match('/^(?:[A-Za-z0-9_$."\-]+|\'(?:[^\']|\'\')*\')(?:\s*,\s*(?:[A-Za-z0-9_$."\-]+|\'(?:[^\']|\'\')*\'))*$/', $value)) {
            $value = self::lit($value);
        }
        return $m[1] . ' TO ' . $value;
    }

    private function commentSql(string $type, string $name, string $sig, ?string $comment): string
    {
        return 'COMMENT ON ' . $type . ' ' . $this->qualified($name) . '(' . $sig . ') IS '
            . ($comment === null || $comment === '' ? 'NULL' : self::lit($comment));
    }

    /** Parameter list + return type of a definition in comparable form. */
    private static function shape(array $def): string
    {
        $parts = [strtoupper(trim(self::str($def['type'] ?? '')))];
        foreach ((array) ($def['parameters'] ?? []) as $p) {
            $parts[] = strtoupper(trim(self::str($p['mode'] ?? 'IN')) ?: 'IN') . ' ' . self::norm(self::str($p['name'] ?? ''))
                . ' ' . self::norm(self::str($p['type'] ?? '')) . ' = ' . self::norm(self::str($p['default'] ?? ''));
        }
        $parts[] = 'RETURNS ' . self::norm(self::str($def['returns'] ?? ''));
        return implode('|', $parts);
    }

    public function buildRoutineSave(array $def, ?array $original): array
    {
        $comment = self::optStr($def['comment'] ?? null);
        if ($original === null) {
            $c = $this->createRoutineSql($def);
            $sql = [$c['sql']];
            if ($comment !== null) {
                $sql[] = $this->commentSql($c['type'], $c['name'], $c['sig'], $comment);
            }
            $sql = array_merge($sql, $this->ownerSql($c, $def, null));
            return self::plan($sql, [], count($sql) > 1);
        }
        $oname = self::name($original['name'] ?? '', 'original routine name', self::MAX_NAME);
        $otype = self::choice($original['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'original routine type');
        $osig = self::str($original['signature'] ?? '');
        if (!self::isSingleExpression($osig === '' ? '1' : $osig)) {
            throw ApiException::badRequest('Invalid routine signature');
        }
        $ocomment = self::optStr($original['comment'] ?? null);
        $sameShape = self::shape($def) === self::shape($original)
            && strtolower(trim(self::str($def['language'] ?? ''))) === strtolower(trim(self::str($original['language'] ?? '')))
            && !empty($def['atomic_body']) === !empty($original['atomic_body']);
        $warnings = [];
        if ($sameShape) {
            $c = $this->createRoutineSql($def, true, $oname);
            $sql = [$c['sql']];
            $name = trim((string) $def['name']);
            if ($name !== $oname) {
                $sql[] = 'ALTER ' . $c['type'] . ' ' . $this->qualified($oname) . '(' . $osig . ') RENAME TO ' . self::q($name);
                $c['name'] = $name;
            }
        } else {
            $c = $this->createRoutineSql($def);
            $sql = ['DROP ' . $otype . ' ' . $this->qualified($oname) . '(' . $osig . ')', $c['sql']];
            $warnings[] = self::WARN_GRANTS_LOST;
            $ocomment = null;
        }
        if ($comment !== $ocomment) {
            $sql[] = $this->commentSql($c['type'], $c['name'], $c['sig'], $comment);
        }
        $sql = array_merge($sql, $this->ownerSql($c, $def, $sameShape ? self::optStr($original['definer'] ?? null) : null));
        return self::plan($sql, $warnings, true);
    }

    /** ALTER … OWNER TO when the requested owner differs from the current one (or from current_user for new routines). */
    private function ownerSql(array $c, array $def, ?string $currentOwner): array
    {
        $owner = self::optStr($def['definer'] ?? null);
        $base = $currentOwner ?? (string) ($this->server['current_user'] ?? '');
        if ($owner === null || $owner === $base) {
            return [];
        }
        self::name($owner, 'owner', self::MAX_NAME);
        return ['ALTER ' . $c['type'] . ' ' . $this->qualified($c['name']) . '(' . $c['sig'] . ') OWNER TO ' . self::q($owner)];
    }

    public function buildRoutineDrop(string $name, string $type, ?string $signature = null): array
    {
        $type = self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type');
        $sig = (string) $signature;
        if (!self::isSingleExpression($sig === '' ? '1' : $sig)) {
            throw ApiException::badRequest('Invalid routine signature');
        }
        return self::plan(['DROP ' . $type . ' ' . $this->qualified(self::name($name, 'routine name', self::MAX_NAME)) . '(' . $sig . ')']);
    }

    // ------------------------------------------------------------------ routines: execute

    public function buildExecute(array $info, array $args): array
    {
        $args = self::normalizeArgs($args);
        $isProc = ($info['type'] ?? '') === 'PROCEDURE';
        if (!$isProc && in_array(strtolower((string) ($info['returns'] ?? '')), ['trigger', 'event_trigger'], true)) {
            throw ApiException::badRequest('Trigger functions cannot be called directly');
        }
        $items = [];
        $params = [];
        $named = false;
        foreach ($info['parameters'] as $i => $p) {
            $mode = (string) ($p['mode'] ?? 'IN');
            if ($mode === 'OUT' && !$isProc) {
                continue; // function OUT parameters are result columns
            }
            $a = $args[$i] ?? ['value' => null, 'null' => true, 'default' => false];
            if ($mode !== 'OUT' && $a['default'] && array_key_exists('default', $p) && $p['default'] !== null) {
                $named = true;
                continue;
            }
            $type = self::type($p['type'], 'parameter type');
            $expr = $mode === 'OUT' ? 'NULL::' . $type : 'CAST(? AS ' . $type . ')';
            if ($mode === 'VARIADIC') {
                $expr = 'VARIADIC ' . $expr;
            }
            if ($mode !== 'OUT') {
                $params[] = $a['null'] ? null : $a['value'];
            }
            $items[] = ['name' => (string) ($p['name'] ?? ''), 'expr' => $expr];
        }
        if ($named) {
            foreach ($items as $it) {
                if ($it['name'] === '') {
                    throw ApiException::badRequest('Leaving out a parameter requires named parameters');
                }
            }
        }
        $list = implode(', ', array_map(static fn (array $it): string => ($named ? self::qIfNeeded($it['name']) . ' => ' : '') . $it['expr'], $items));
        $fn = $this->qualified((string) $info['name']) . '(' . $list . ')';
        $sql = $isProc ? 'CALL ' . $fn : 'SELECT * FROM ' . $fn;
        $hasOut = false;
        foreach ($info['parameters'] as $p) {
            $hasOut = $hasOut || in_array($p['mode'] ?? 'IN', ['OUT', 'INOUT'], true);
        }
        return ['steps' => [['sql' => $sql, 'params' => $params, 'role' => $isProc && $hasOut ? 'out' : 'call']]];
    }

    // ------------------------------------------------------------------ triggers

    public function triggerInfo(string $name, ?string $table = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $sql = 'SELECT t.oid, t.tgname, c.relname, c.relkind, t.tgtype, t.tgenabled, t.tgconstraint <> 0 AS is_constraint,
                    pn.nspname AS fn_schema, p.proname AS fn_name, pg_get_triggerdef(t.oid, true) AS def,
                    ARRAY(SELECT a.attname FROM pg_attribute a WHERE a.attrelid = t.tgrelid AND a.attnum = ANY(t.tgattr) ORDER BY a.attnum)::text[] AS cols
                FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_namespace pn ON pn.oid = p.pronamespace
                WHERE n.nspname = ? AND t.tgname = ? AND NOT t.tgisinternal';
        $params = [$this->schema(), $name];
        if ($table !== null && $table !== '') {
            $sql .= ' AND c.relname = ?';
            $params[] = $table;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY c.relname LIMIT 1');
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Trigger "' . $name . '" not found');
        }
        $type = (int) $r['tgtype'];
        $timing = ($type & 2) ? 'BEFORE' : (($type & 64) ? 'INSTEAD OF' : 'AFTER');
        $events = [];
        foreach ([4 => 'INSERT', 16 => 'UPDATE', 8 => 'DELETE', 32 => 'TRUNCATE'] as $bit => $ev) {
            if ($type & $bit) {
                $events[] = $ev;
            }
        }
        $def = (string) $r['def'];
        $when = null;
        if (preg_match('/\bFOR EACH (?:ROW|STATEMENT)\s+WHEN \((.*)\)\s+EXECUTE (?:FUNCTION|PROCEDURE)\s/s', $def, $m)) {
            $when = $m[1];
        }
        $arguments = [];
        if (preg_match('/\bEXECUTE (?:FUNCTION|PROCEDURE)\s+.*?\((.*)\)\s*$/s', $def, $m) && trim($m[1]) !== '') {
            foreach (self::splitTopLevel($m[1]) as $a) {
                $arguments[] = preg_match("/^E?'(.*)'$/s", $a, $am) ? str_replace("''", "'", $am[1]) : $a;
            }
        }
        $cols = self::pgTextArray($r['cols']);
        return [
            'kind' => 'trigger',
            'name' => (string) $r['tgname'],
            'table' => (string) $r['relname'],
            'timing' => $timing,
            'events' => $events,
            'columns' => $cols,
            'level' => ($type & 1) ? 'ROW' : 'STATEMENT',
            'when' => $when,
            'function' => (string) $r['fn_name'],
            'function_schema' => (string) $r['fn_schema'],
            'arguments' => $arguments,
            'enabled' => (string) $r['tgenabled'] !== 'D',
            'constraint' => self::bool($r['is_constraint']),
            'sql' => $def . ';',
        ];
    }

    /** Parse a PostgreSQL text[] value as returned by PDO ("{a,\"b c\"}"). */
    private static function pgTextArray($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $s = trim((string) $v);
        if ($s === '' || $s === '{}') {
            return [];
        }
        $s = substr($s, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $s, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out[] = isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]);
        }
        return $out;
    }

    public function createTriggerSql(array $def, bool $orReplace = false, ?string $asName = null): string
    {
        $name = $asName ?? self::name($def['name'] ?? '', 'trigger name', self::MAX_NAME);
        $table = self::name($def['table'] ?? '', 'table', self::MAX_NAME);
        $timing = self::choice($def['timing'] ?? '', ['BEFORE', 'AFTER', 'INSTEAD OF'], 'trigger timing');
        $level = self::choice($def['level'] ?? '', ['ROW', 'STATEMENT'], 'trigger level', 'ROW');
        $events = [];
        foreach ((array) ($def['events'] ?? []) as $e) {
            $events[self::choice($e, ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'], 'trigger event')] = true;
        }
        if (!$events) {
            throw ApiException::badRequest('Choose at least one trigger event');
        }
        $parts = [];
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $e) {
            if (!isset($events[$e])) {
                continue;
            }
            $part = $e;
            if ($e === 'UPDATE' && !empty($def['columns'])) {
                $cols = array_map(static fn ($c): string => self::q(self::name($c, 'column', self::MAX_NAME)), (array) $def['columns']);
                $part .= ' OF ' . implode(', ', $cols);
            }
            $parts[] = $part;
        }
        $fn = self::name($def['function'] ?? '', 'trigger function', self::MAX_NAME);
        $fnSchema = self::optStr($def['function_schema'] ?? null);
        $args = [];
        foreach ((array) ($def['arguments'] ?? []) as $a) {
            $args[] = self::lit(self::str($a));
        }
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . 'TRIGGER ' . self::q($name) . ' ' . $timing . ' ' . implode(' OR ', $parts)
            . ' ON ' . $this->qualified($table) . "\nFOR EACH " . $level;
        $when = self::optStr($def['when'] ?? null);
        if ($when !== null) {
            $sql .= "\nWHEN (" . self::fragment($when, 'WHEN condition') . ')';
        }
        $kw = $this->pgv() > 0 && $this->pgv() < 110000 ? 'PROCEDURE' : 'FUNCTION';
        return $sql . "\nEXECUTE " . $kw . ' ' . ($fnSchema !== null ? self::q($fnSchema) . '.' : '') . self::q($fn) . '(' . implode(', ', $args) . ')';
    }

    public function buildTriggerSave(array $def, ?array $original): array
    {
        if ($original === null) {
            return self::plan([$this->createTriggerSql($def)]);
        }
        if (!empty($original['constraint'])) {
            throw ApiException::badRequest('Constraint triggers cannot be edited here');
        }
        $oname = self::name($original['name'] ?? '', 'original trigger name', self::MAX_NAME);
        $otable = self::name($original['table'] ?? '', 'original table', self::MAX_NAME);
        $name = self::name($def['name'] ?? '', 'trigger name', self::MAX_NAME);
        $table = self::name($def['table'] ?? '', 'table', self::MAX_NAME);
        if ($table === $otable && $this->pgv() >= 140000) {
            $sql = [$this->createTriggerSql($def, true, $oname)];
            if ($name !== $oname) {
                $sql[] = 'ALTER TRIGGER ' . self::q($oname) . ' ON ' . $this->qualified($otable) . ' RENAME TO ' . self::q($name);
            }
            return self::plan($sql, [], true);
        }
        return self::plan(['DROP TRIGGER ' . self::q($oname) . ' ON ' . $this->qualified($otable), $this->createTriggerSql($def)], [], true);
    }

    public function buildTriggerDrop(string $name, ?string $table = null): array
    {
        if ($table === null || $table === '') {
            throw ApiException::badRequest('Missing parameter: table');
        }
        return self::plan(['DROP TRIGGER ' . self::q(self::name($name, 'trigger name', self::MAX_NAME)) . ' ON ' . $this->qualified(self::name($table, 'table', self::MAX_NAME))]);
    }
}
