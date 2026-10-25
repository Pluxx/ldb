<?php

declare(strict_types=1);

namespace Ldb\Routine;

use Ldb\Http\ApiException;
use PDO;

/**
 * SQL Server: procedures, scalar / inline / multi-statement table-valued functions and DML
 * triggers. Definitions come from OBJECT_DEFINITION (header parsed for defaults / options).
 * Edits use CREATE OR ALTER (SQL Server 2016 SP1+) or ALTER (both keep permissions); renames
 * and type changes are DROP + CREATE in one transaction. No events.
 */
final class SqlSrvRoutineEditor extends RoutineEditor
{
    private const MAX_NAME = 128;

    protected function detectServer(): array
    {
        $pdo = $this->requireDb()->pdo();
        $v = (string) $pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(64))")->fetchColumn();
        return ['version' => $v];
    }

    /** CREATE OR ALTER exists from 13.0.4001 (2016 SP1). */
    public function canCreateOrAlter(): bool
    {
        return version_compare((string) ($this->server['version'] ?? '0'), '13.0.4001', '>=');
    }

    private static function q(string $name): string
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            throw ApiException::badRequest('Invalid identifier');
        }
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private static function lit(string $s): string
    {
        return "N'" . str_replace("'", "''", $s) . "'";
    }

    private function schema(): string
    {
        $s = $this->db !== null ? $this->db->currentSchema() : ($this->server['schema'] ?? null);
        return $s !== null && $s !== '' ? (string) $s : 'dbo';
    }

    private function qualified(string $name): string
    {
        return self::q($this->schema()) . '.' . self::q($name);
    }

    private static function paramName($value): string
    {
        $n = trim(self::str($value));
        if ($n !== '' && $n[0] !== '@') {
            $n = '@' . $n;
        }
        if (!preg_match('/^@[A-Za-z_\x80-\xFF][A-Za-z0-9_@$#\x80-\xFF]{0,127}$/', $n)) {
            throw ApiException::badRequest('Invalid parameter name: ' . $n);
        }
        return $n;
    }

    /** Full type from sys.parameters / sys.columns facts. */
    private static function typeOf(string $type, int $maxLength, int $precision, int $scale): string
    {
        $t = strtolower($type);
        if (in_array($t, ['varchar', 'char', 'varbinary', 'binary'], true)) {
            return $t . '(' . ($maxLength === -1 ? 'max' : (string) $maxLength) . ')';
        }
        if (in_array($t, ['nvarchar', 'nchar'], true)) {
            return $t . '(' . ($maxLength === -1 ? 'max' : (string) intdiv($maxLength, 2)) . ')';
        }
        if (in_array($t, ['decimal', 'numeric'], true)) {
            return $t . '(' . $precision . ',' . $scale . ')';
        }
        if (in_array($t, ['datetime2', 'time', 'datetimeoffset'], true)) {
            return $t . '(' . $scale . ')';
        }
        return $t;
    }

    // ------------------------------------------------------------------ definition parsing

    /**
     * Split a CREATE PROCEDURE/FUNCTION/TRIGGER text into header parts and body.
     * @return array{params:string,returns:?string,options:string,body:string,head:string}|null
     */
    public static function parseDefinition(string $def): ?array
    {
        $tok = self::scan($def, true);
        $n = count($tok);
        $k = 0;
        while ($k < $n && !($tok[$k][0] === 'w' && in_array($tok[$k][1], ['PROCEDURE', 'PROC', 'FUNCTION', 'TRIGGER'], true))) {
            $k++;
        }
        if ($k >= $n) {
            return null;
        }
        $kind = $tok[$k][1];
        $k++;
        // name: part(.part)*
        while ($k < $n && ($tok[$k][0] === 'w' || $tok[$k][0] === 'q')) {
            $k++;
            if ($k < $n && $tok[$k][0] === 'p' && $tok[$k][1] === '.') {
                $k++;
                continue;
            }
            break;
        }
        $headStart = $k;
        // first top-level AS that ends the header
        $as = null;
        for ($i = $k; $i < $n; $i++) {
            $t = $tok[$i];
            if ($t[0] !== 'w' || $t[1] !== 'AS' || $t[4] !== 0) {
                continue;
            }
            $prev = $tok[$i - 1] ?? null;
            if ($prev !== null && $prev[0] === 'w' && ($prev[1] === 'EXECUTE' || $prev[1] === 'EXEC' || $prev[1][0] === '@')) {
                continue;
            }
            $as = $i;
            break;
        }
        if ($as === null) {
            return null;
        }
        $body = ltrim(substr($def, $tok[$as][3]), " \t");
        $body = (string) preg_replace('/^\r?\n/', '', $body);
        $params = '';
        $returns = null;
        $options = '';
        if ($kind === 'FUNCTION') {
            if (isset($tok[$headStart]) && $tok[$headStart][1] === '(') {
                $close = null;
                for ($i = $headStart + 1; $i < $as; $i++) {
                    if ($tok[$i][0] === 'p' && $tok[$i][1] === ')' && $tok[$i][4] === 0) {
                        $close = $i;
                        break;
                    }
                }
                if ($close === null) {
                    return null;
                }
                $params = trim(substr($def, $tok[$headStart][3], $tok[$close][2] - $tok[$headStart][3]));
                $headStart = $close + 1;
            }
            $with = null;
            $ret = null;
            for ($i = $headStart; $i < $as; $i++) {
                if ($tok[$i][0] === 'w' && $tok[$i][4] === 0) {
                    if ($tok[$i][1] === 'RETURNS' && $ret === null) {
                        $ret = $i;
                    } elseif ($tok[$i][1] === 'WITH' && $ret !== null && $with === null) {
                        $with = $i;
                    }
                }
            }
            if ($ret !== null) {
                $end = $with ?? $as;
                $returns = trim(substr($def, $tok[$ret][3], $tok[$end][2] - $tok[$ret][3]));
            }
            if ($with !== null) {
                $options = trim(substr($def, $tok[$with][3], $tok[$as][2] - $tok[$with][3]));
            }
        } elseif ($kind === 'TRIGGER') {
            $params = trim(substr($def, $tok[$headStart][2] ?? 0, $tok[$as][2] - ($tok[$headStart][2] ?? 0)));
        } else {
            $end = $as;
            $with = null;
            for ($i = $headStart; $i < $as; $i++) {
                if ($tok[$i][0] === 'w' && $tok[$i][4] === 0 && ($tok[$i][1] === 'WITH' || $tok[$i][1] === 'FOR')) {
                    $with = $i;
                    break;
                }
            }
            if ($with !== null) {
                $end = $with;
                $options = trim(substr($def, $tok[$with][3], $tok[$as][2] - $tok[$with][3]));
            }
            if ($end > $headStart) {
                $params = trim(substr($def, $tok[$headStart][2], $tok[$end][2] - $tok[$headStart][2]));
                if ($params !== '' && $params[0] === '(' && substr($params, -1) === ')') {
                    $params = trim(substr($params, 1, -1));
                }
            }
        }
        return ['params' => $params, 'returns' => $returns, 'options' => $options, 'body' => $body,
            'head' => trim(substr($def, 0, $tok[$as][2]))];
    }

    /** @return array<string,array{default:?string,readonly:bool}> keyed by lower-case @name */
    private static function parseParams(string $params): array
    {
        $out = [];
        foreach (self::splitTopLevel($params) as $p) {
            if (preg_match('/^(@[^\s=]+)\s+(?:AS\s+)?(.+?)(?:\s*=\s*(.+?))?(?:\s+(?:OUT|OUTPUT))?(?:\s+(READONLY))?\s*$/is', $p, $m)) {
                $out[strtolower($m[1])] = ['default' => isset($m[3]) && $m[3] !== '' ? trim($m[3]) : null, 'readonly' => !empty($m[4])];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ routines: read

    public function routineInfo(string $name, ?string $type = null, ?string $signature = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $stmt = $pdo->prepare("SELECT o.object_id, o.name, o.type, OBJECT_DEFINITION(o.object_id) AS def,
                CAST(ep.value AS nvarchar(4000)) AS comment, USER_NAME(OBJECTPROPERTY(o.object_id, 'OwnerId')) AS owner
            FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id
            LEFT JOIN sys.extended_properties ep ON ep.major_id = o.object_id AND ep.minor_id = 0 AND ep.class = 1 AND ep.name = 'MS_Description'
            WHERE s.name = ? AND o.name = ? AND o.type IN ('P', 'FN', 'IF', 'TF')");
        $stmt->execute([$this->schema(), $name]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Routine "' . $name . '" not found');
        }
        $otype = trim((string) $r['type']);
        $isProc = $otype === 'P';
        if ($type !== null && $type !== '' && ($isProc ? 'PROCEDURE' : 'FUNCTION') !== strtoupper($type)) {
            throw ApiException::notFound('Routine "' . $name . '" not found');
        }
        $def = $r['def'];
        $parsed = $def !== null ? self::parseDefinition((string) $def) : null;
        $defaults = $parsed !== null ? self::parseParams($parsed['params']) : [];
        $stmt = $pdo->prepare('SELECT p.parameter_id, p.name, TYPE_NAME(p.user_type_id) AS type, p.max_length, p.precision, p.scale,
                p.is_output, p.is_readonly FROM sys.parameters p WHERE p.object_id = ? ORDER BY p.parameter_id');
        $stmt->execute([(int) $r['object_id']]);
        $params = [];
        $returns = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $full = self::typeOf((string) $p['type'], (int) $p['max_length'], (int) $p['precision'], (int) $p['scale']);
            if ((int) $p['parameter_id'] === 0) {
                $returns = $full;
                continue;
            }
            $key = strtolower((string) $p['name']);
            $params[] = [
                'mode' => $p['is_output'] ? 'OUT' : 'IN',
                'name' => (string) $p['name'],
                'type' => $full . (!empty($p['is_readonly']) ? ' READONLY' : ''),
                'default' => $defaults[$key]['default'] ?? null,
            ];
        }
        if (!$isProc && $otype !== 'FN') {
            $returns = $parsed['returns'] ?? 'TABLE';
        }
        $options = (string) ($parsed['options'] ?? '');
        $executeAs = null;
        if (preg_match("/EXECUTE\s+AS\s+(CALLER|SELF|OWNER|'[^']*')/i", $options, $m)) {
            $executeAs = $m[1][0] === "'" ? substr($m[1], 1, -1) : strtoupper($m[1]);
        }
        return [
            'kind' => 'routine',
            'name' => (string) $r['name'],
            'type' => $isProc ? 'PROCEDURE' : 'FUNCTION',
            'function_kind' => ['P' => null, 'FN' => 'scalar', 'IF' => 'inline', 'TF' => 'multi'][$otype] ?? null,
            'parameters' => $params,
            'returns' => $returns,
            'body' => $parsed['body'] ?? null,
            'execute_as' => $executeAs,
            'schemabinding' => stripos($options, 'SCHEMABINDING') !== false,
            'recompile' => stripos($options, 'RECOMPILE') !== false,
            'definer' => (string) $r['owner'],
            'comment' => (string) ($r['comment'] ?? ''),
            'encrypted' => $def === null,
            'sql' => $def === null ? null : rtrim((string) $def),
        ];
    }

    // ------------------------------------------------------------------ routines: build

    public function createRoutineSql(array $def, string $verb = 'CREATE', ?string $asName = null): string
    {
        $type = self::choice($def['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'routine type');
        $name = $asName ?? self::name($def['name'] ?? '', 'routine name', self::MAX_NAME);
        $params = [];
        $seen = [];
        foreach (array_values((array) ($def['parameters'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                throw ApiException::badRequest('Invalid parameter #' . ($i + 1));
            }
            $pname = self::paramName($p['name'] ?? '');
            if (isset($seen[strtolower($pname)])) {
                throw ApiException::badRequest('Duplicate parameter name: ' . $pname);
            }
            $seen[strtolower($pname)] = true;
            $mode = self::choice($p['mode'] ?? 'IN', $type === 'FUNCTION' ? ['IN'] : ['IN', 'OUT', 'INOUT'], 'parameter mode', 'IN');
            $sql = $pname . ' ' . self::type($p['type'] ?? '', 'type of parameter ' . $pname);
            $default = self::optStr($p['default'] ?? null);
            if ($default !== null) {
                $sql .= ' = ' . self::fragment($default, 'default of parameter ' . $pname);
            }
            if ($mode !== 'IN') {
                $sql .= ' OUTPUT';
            }
            $params[] = $sql;
        }
        $options = [];
        if ($type === 'FUNCTION' && !empty($def['schemabinding'])) {
            $options[] = 'SCHEMABINDING';
        }
        if ($type === 'PROCEDURE' && !empty($def['recompile'])) {
            $options[] = 'RECOMPILE';
        }
        $executeAs = self::optStr($def['execute_as'] ?? null);
        if ($executeAs !== null && strtoupper($executeAs) !== 'CALLER') {
            if (in_array(strtoupper($executeAs), ['SELF', 'OWNER'], true)) {
                $options[] = 'EXECUTE AS ' . strtoupper($executeAs);
            } else {
                $options[] = 'EXECUTE AS ' . self::lit(trim($executeAs, "'"));
            }
        }
        $sql = $verb . ' ' . $type . ' ' . $this->qualified($name);
        if ($type === 'FUNCTION') {
            $sql .= '(' . implode(', ', $params) . ')' . "\nRETURNS " . self::returnsSql($def['returns'] ?? '');
        } elseif ($params) {
            $sql .= "\n    " . implode(",\n    ", $params);
        }
        if ($options) {
            $sql .= "\nWITH " . implode(', ', $options);
        }
        return $sql . "\nAS\n" . self::body($def['body'] ?? '');
    }

    private static function returnsSql($value): string
    {
        $s = trim(self::str($value));
        if (strcasecmp($s, 'TABLE') === 0) {
            return 'TABLE';
        }
        if (preg_match('/^(@[A-Za-z_][A-Za-z0-9_@$#]*)\s+TABLE\s*\((.*)\)$/is', $s, $m)) {
            if (!self::isSingleExpression($m[2])) {
                throw ApiException::badRequest('Invalid RETURNS table definition');
            }
            return $m[1] . ' TABLE (' . trim($m[2]) . ')';
        }
        return self::type($s, 'return type');
    }

    private function commentSql(string $type, string $name, ?string $comment, bool $exists): array
    {
        $args = "N'SCHEMA', " . self::lit($this->schema()) . ', ' . self::lit($type) . ', ' . self::lit($name);
        if ($comment === null || $comment === '') {
            return $exists ? ["EXEC sys.sp_dropextendedproperty @name = N'MS_Description', @level0type = N'SCHEMA', @level0name = " . self::lit($this->schema())
                . ', @level1type = ' . self::lit($type) . ', @level1name = ' . self::lit($name)] : [];
        }
        return ['EXEC sys.' . ($exists ? 'sp_updateextendedproperty' : 'sp_addextendedproperty')
            . " @name = N'MS_Description', @value = " . self::lit($comment)
            . ', @level0type = N\'SCHEMA\', @level0name = ' . self::lit($this->schema())
            . ', @level1type = ' . self::lit($type) . ', @level1name = ' . self::lit($name)];
    }

    public function buildRoutineSave(array $def, ?array $original): array
    {
        $type = self::choice($def['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'routine type');
        $name = self::name($def['name'] ?? '', 'routine name', self::MAX_NAME);
        $comment = self::optStr($def['comment'] ?? null);
        if ($original === null) {
            $sql = array_merge([$this->createRoutineSql($def)], $this->commentSql($type, $name, $comment, false));
            return self::plan($sql, [], count($sql) > 1);
        }
        $oname = self::name($original['name'] ?? '', 'original routine name', self::MAX_NAME);
        $otype = self::choice($original['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'original routine type');
        $ocomment = self::optStr($original['comment'] ?? null);
        $okind = $original['function_kind'] ?? null;
        $nkind = $type === 'FUNCTION' ? self::functionKind(self::str($def['returns'] ?? '')) : null;
        if ($oname === $name && $otype === $type && $okind === $nkind) {
            $verb = $this->canCreateOrAlter() ? 'CREATE OR ALTER' : 'ALTER';
            $sql = [$this->createRoutineSql($def, $verb)];
            if ($comment !== $ocomment) {
                $sql = array_merge($sql, $this->commentSql($type, $name, $comment, $ocomment !== null));
            }
            return self::plan($sql, [], count($sql) > 1);
        }
        $sql = array_merge(
            ['DROP ' . $otype . ' ' . $this->qualified($oname), $this->createRoutineSql($def)],
            $this->commentSql($type, $name, $comment, false)
        );
        return self::plan($sql, [self::WARN_GRANTS_LOST], true);
    }

    /** scalar | inline | multi from a RETURNS clause (ALTER cannot change the function kind). */
    private static function functionKind(string $returns): string
    {
        $r = trim($returns);
        if (strcasecmp($r, 'TABLE') === 0) {
            return 'inline';
        }
        return preg_match('/^@\S+\s+TABLE\b/i', $r) ? 'multi' : 'scalar';
    }

    public function buildRoutineDrop(string $name, string $type, ?string $signature = null): array
    {
        return self::plan(['DROP ' . self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type') . ' ' . $this->qualified(self::name($name, 'routine name', self::MAX_NAME))]);
    }

    // ------------------------------------------------------------------ routines: execute

    public function buildExecute(array $info, array $args): array
    {
        $args = self::normalizeArgs($args);
        $name = $this->qualified((string) $info['name']);
        if (($info['type'] ?? '') === 'FUNCTION') {
            $items = [];
            $params = [];
            foreach ($info['parameters'] as $i => $p) {
                $a = $args[$i] ?? ['value' => null, 'null' => true, 'default' => false];
                if ($a['default']) {
                    $items[] = 'DEFAULT';
                    continue;
                }
                $items[] = 'CAST(? AS ' . self::type(preg_replace('/\s+READONLY$/i', '', (string) $p['type'])) . ')';
                $params[] = $a['null'] ? null : $a['value'];
            }
            $call = $name . '(' . implode(', ', $items) . ')';
            $sql = ($info['function_kind'] ?? 'scalar') === 'scalar'
                ? 'SELECT ' . $call . ' AS ' . self::q((string) $info['name'])
                : 'SELECT * FROM ' . $call;
            return ['steps' => [['sql' => $sql, 'params' => $params, 'role' => 'call']]];
        }
        $decl = ['SET NOCOUNT ON;', 'DECLARE @ldb_rc int;'];
        $declParams = [];
        $exec = [];
        $execParams = [];
        $outs = ['@ldb_rc AS [RETURN_VALUE]'];
        foreach ($info['parameters'] as $i => $p) {
            $a = $args[$i] ?? ['value' => null, 'null' => true, 'default' => false];
            $pname = self::paramName($p['name']);
            $type = self::type(preg_replace('/\s+READONLY$/i', '', (string) $p['type']));
            if (($p['mode'] ?? 'IN') === 'IN') {
                if ($a['default']) {
                    continue;
                }
                $exec[] = $pname . ' = CAST(? AS ' . $type . ')';
                $execParams[] = $a['null'] ? null : $a['value'];
                continue;
            }
            $var = '@ldb_p' . ($i + 1);
            $decl[] = 'DECLARE ' . $var . ' ' . $type . ' = ' . ($a['null'] ? 'NULL' : '?') . ';';
            if (!$a['null']) {
                $declParams[] = $a['value'];
            }
            $exec[] = $pname . ' = ' . $var . ' OUTPUT';
            $outs[] = $var . ' AS ' . self::q($pname);
        }
        $sql = implode("\n", $decl) . "\nEXEC @ldb_rc = " . $name . ($exec ? ' ' . implode(', ', $exec) : '') . ";\nSELECT " . implode(', ', $outs) . ';';
        return ['steps' => [['sql' => $sql, 'params' => array_merge($declParams, $execParams), 'role' => 'call_out']]];
    }

    // ------------------------------------------------------------------ triggers

    public function triggerInfo(string $name, ?string $table = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $stmt = $pdo->prepare('SELECT t.object_id, t.name, OBJECT_NAME(t.parent_id) AS tbl, t.is_instead_of_trigger, t.is_disabled,
                OBJECT_DEFINITION(t.object_id) AS def
            FROM sys.triggers t JOIN sys.objects p ON p.object_id = t.parent_id JOIN sys.schemas s ON s.schema_id = p.schema_id
            WHERE t.parent_class = 1 AND s.name = ? AND t.name = ?');
        $stmt->execute([$this->schema(), $name]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Trigger "' . $name . '" not found');
        }
        $events = [];
        $stmt = $pdo->prepare('SELECT type_desc FROM sys.trigger_events WHERE object_id = ? ORDER BY type');
        $stmt->execute([(int) $r['object_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $events[] = strtoupper((string) $e);
        }
        $parsed = $r['def'] !== null ? self::parseDefinition((string) $r['def']) : null;
        return [
            'kind' => 'trigger',
            'name' => (string) $r['name'],
            'table' => (string) $r['tbl'],
            'timing' => $r['is_instead_of_trigger'] ? 'INSTEAD OF' : 'AFTER',
            'events' => $events,
            'level' => 'STATEMENT',
            'body' => $parsed['body'] ?? null,
            'enabled' => !$r['is_disabled'],
            'sql' => $r['def'] === null ? null : rtrim((string) $r['def']),
        ];
    }

    public function createTriggerSql(array $def, string $verb = 'CREATE'): string
    {
        $name = self::name($def['name'] ?? '', 'trigger name', self::MAX_NAME);
        $table = self::name($def['table'] ?? '', 'table', self::MAX_NAME);
        $timing = self::choice($def['timing'] ?? '', ['AFTER', 'INSTEAD OF', 'FOR'], 'trigger timing');
        $events = [];
        foreach ((array) ($def['events'] ?? []) as $e) {
            $events[self::choice($e, ['INSERT', 'UPDATE', 'DELETE'], 'trigger event')] = true;
        }
        if (!$events) {
            throw ApiException::badRequest('Choose at least one trigger event');
        }
        $list = array_values(array_filter(['INSERT', 'UPDATE', 'DELETE'], static fn (string $e): bool => isset($events[$e])));
        return $verb . ' TRIGGER ' . $this->qualified($name) . ' ON ' . $this->qualified($table)
            . "\n" . ($timing === 'FOR' ? 'AFTER' : $timing) . ' ' . implode(', ', $list) . "\nAS\n" . self::body($def['body'] ?? '');
    }

    public function buildTriggerSave(array $def, ?array $original): array
    {
        if ($original === null) {
            return self::plan([$this->createTriggerSql($def)]);
        }
        $oname = self::name($original['name'] ?? '', 'original trigger name', self::MAX_NAME);
        $otable = self::name($original['table'] ?? '', 'original table', self::MAX_NAME);
        if ($oname === trim(self::str($def['name'] ?? '')) && $otable === trim(self::str($def['table'] ?? ''))) {
            return self::plan([$this->createTriggerSql($def, $this->canCreateOrAlter() ? 'CREATE OR ALTER' : 'ALTER')]);
        }
        return self::plan(['DROP TRIGGER ' . $this->qualified($oname), $this->createTriggerSql($def)], [], true);
    }

    public function buildTriggerDrop(string $name, ?string $table = null): array
    {
        return self::plan(['DROP TRIGGER ' . $this->qualified(self::name($name, 'trigger name', self::MAX_NAME))]);
    }
}
