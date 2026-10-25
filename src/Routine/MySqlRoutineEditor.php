<?php

declare(strict_types=1);

namespace Ldb\Routine;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use PDO;

/**
 * MySQL / MariaDB / Percona: procedures, functions, triggers and events.
 *
 * Replacing: MariaDB >= 10.1.3 (routines, events) / 10.1.4 (triggers) has CREATE OR REPLACE;
 * MySQL has no such statement for routines/triggers, so edits are DROP + CREATE (not atomic:
 * the original definition is captured first and restored when CREATE fails; privileges granted
 * on the routine are lost). Events are edited with ALTER EVENT (atomic, supports rename).
 */
final class MySqlRoutineEditor extends RoutineEditor
{
    private const DATA_ACCESS = ['CONTAINS SQL', 'NO SQL', 'READS SQL DATA', 'MODIFIES SQL DATA'];
    private const INTERVAL_FIELDS = ['YEAR', 'QUARTER', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'WEEK', 'SECOND',
        'YEAR_MONTH', 'DAY_HOUR', 'DAY_MINUTE', 'DAY_SECOND', 'HOUR_MINUTE', 'HOUR_SECOND', 'MINUTE_SECOND'];
    private const STATUS = ['ENABLE', 'DISABLE', 'DISABLE ON SLAVE', 'DISABLE ON REPLICA'];
    private const MAX_NAME = 64;

    protected function detectServer(): array
    {
        $db = $this->requireDb();
        $user = null;
        $mode = null;
        try {
            $row = $db->pdo()->query('SELECT CURRENT_USER() AS u, @@SESSION.sql_mode AS m')->fetch(PDO::FETCH_ASSOC);
            $user = $row['u'] ?? null;
            $mode = $row['m'] ?? null;
        } catch (\PDOException $e) {
        }
        $version = $db instanceof MySqlDriver ? $db->serverVersion() : '';
        return [
            'version' => preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) ? $m[1] : '0.0.0',
            'mariadb' => $db instanceof MySqlDriver && $db->isMariaDb(),
            'current_user' => $user,
            'sql_mode' => $mode,
        ];
    }

    private function isMaria(): bool
    {
        return !empty($this->server['mariadb']);
    }

    private function versionAtLeast(string $v): bool
    {
        return version_compare((string) ($this->server['version'] ?? '0.0.0'), $v, '>=');
    }

    /** CREATE OR REPLACE PROCEDURE/FUNCTION/EVENT (MariaDB 10.1.3+). */
    public function canReplaceRoutine(): bool
    {
        return $this->isMaria() && $this->versionAtLeast('10.1.3');
    }

    /** CREATE OR REPLACE TRIGGER (MariaDB 10.1.4+). */
    public function canReplaceTrigger(): bool
    {
        return $this->isMaria() && $this->versionAtLeast('10.1.4');
    }

    // ------------------------------------------------------------------ quoting

    private static function q(string $name): string
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            throw ApiException::badRequest('Invalid identifier');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** String literal safe with and without NO_BACKSLASH_ESCAPES. */
    private static function lit(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }

    private function database(): string
    {
        $db = $this->db !== null ? $this->db->currentDatabase() : ($this->server['database'] ?? null);
        if ($db === null || $db === '') {
            throw ApiException::badRequest('No database selected');
        }
        return (string) $db;
    }

    private function qualified(string $name): string
    {
        return self::q($this->database()) . '.' . self::q($name);
    }

    /**
     * DEFINER clause: omitted for empty / CURRENT_USER / the connected account (that is the
     * default); otherwise 'user'@'host' (validated).
     */
    private function definerClause($definer): string
    {
        $d = self::optStr($definer);
        if ($d === null || preg_match('/^CURRENT_USER(\(\))?$/i', $d)) {
            return '';
        }
        if (!preg_match('/^`?\'?([^\'`@]*)\'?`?@`?\'?([^\'`@]*)\'?`?$/', $d, $m) || $m[1] === '' || $m[2] === ''
            || preg_match('/[\x00-\x1F]/', $d)) {
            throw ApiException::badRequest('Invalid definer (expected user@host): ' . $d);
        }
        $cur = (string) ($this->server['current_user'] ?? '');
        if ($cur !== '' && $cur === $m[1] . '@' . $m[2]) {
            return '';
        }
        return 'DEFINER = ' . self::lit($m[1]) . '@' . self::lit($m[2]) . ' ';
    }

    // ------------------------------------------------------------------ routines: read

    public function routineInfo(string $name, ?string $type = null, ?string $signature = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $sql = 'SELECT ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER, ROUTINE_DEFINITION, IS_DETERMINISTIC, SQL_DATA_ACCESS,
                       SECURITY_TYPE, DEFINER, ROUTINE_COMMENT, SQL_MODE
                FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ?';
        $params = [$this->database(), $name];
        if ($type !== null && $type !== '') {
            $sql .= ' AND ROUTINE_TYPE = ?';
            $params[] = self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type');
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY ROUTINE_TYPE DESC LIMIT 1');
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Routine "' . $name . '" not found');
        }
        $rtype = strtoupper((string) $r['ROUTINE_TYPE']);
        $stmt = $pdo->prepare('SELECT ORDINAL_POSITION, PARAMETER_MODE, PARAMETER_NAME, DTD_IDENTIFIER
            FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = ? AND SPECIFIC_NAME = ? AND ROUTINE_TYPE = ?
            ORDER BY ORDINAL_POSITION');
        $stmt->execute([$this->database(), $r['ROUTINE_NAME'], $rtype]);
        $params = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if ((int) $p['ORDINAL_POSITION'] === 0) {
                continue; // function return value
            }
            $params[] = [
                'mode' => $rtype === 'FUNCTION' ? 'IN' : strtoupper((string) ($p['PARAMETER_MODE'] ?: 'IN')),
                'name' => (string) $p['PARAMETER_NAME'],
                'type' => (string) $p['DTD_IDENTIFIER'],
            ];
        }
        $body = $r['ROUTINE_DEFINITION'];
        $ddl = null;
        try {
            $ddl = $this->requireDb()->createStatement((string) $r['ROUTINE_NAME'], strtolower($rtype));
        } catch (\Throwable $e) {
        }
        if ($body === null && $ddl !== null) {
            $body = self::bodyFromDdl($ddl);
        }
        return [
            'kind' => 'routine',
            'name' => (string) $r['ROUTINE_NAME'],
            'type' => $rtype,
            'parameters' => $params,
            'returns' => $rtype === 'FUNCTION' ? (string) $r['DTD_IDENTIFIER'] : null,
            'body' => $body === null ? null : (string) $body,
            'deterministic' => strtoupper((string) $r['IS_DETERMINISTIC']) === 'YES',
            'data_access' => strtoupper((string) $r['SQL_DATA_ACCESS']),
            'security' => strtoupper((string) $r['SECURITY_TYPE']),
            'definer' => (string) $r['DEFINER'],
            'comment' => (string) $r['ROUTINE_COMMENT'],
            'sql_mode' => (string) $r['SQL_MODE'],
            'sql' => $ddl,
        ];
    }

    /** Body of a SHOW CREATE PROCEDURE/FUNCTION statement (fallback when ROUTINE_DEFINITION is hidden). */
    private static function bodyFromDdl(string $ddl): ?string
    {
        return preg_match('/\)\s*(?:RETURNS\s+.+?\s+)?(?:(?:LANGUAGE\s+SQL|NOT\s+DETERMINISTIC|DETERMINISTIC|CONTAINS\s+SQL|NO\s+SQL|READS\s+SQL\s+DATA|MODIFIES\s+SQL\s+DATA|SQL\s+SECURITY\s+\w+|COMMENT\s+\'(?:[^\'\\\\]|\\\\.|\'\')*\')\s+)*(.*)$/is', $ddl, $m)
            ? $m[1] : null;
    }

    // ------------------------------------------------------------------ routines: build

    /** CREATE statement for a RoutineDefinition (no OR REPLACE). */
    public function createRoutineSql(array $def, bool $orReplace = false): string
    {
        $type = self::choice($def['type'] ?? '', ['PROCEDURE', 'FUNCTION'], 'routine type');
        $name = self::name($def['name'] ?? '', 'routine name', self::MAX_NAME);
        $params = [];
        $seen = [];
        foreach (array_values((array) ($def['parameters'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                throw ApiException::badRequest('Invalid parameter #' . ($i + 1));
            }
            $pname = self::name($p['name'] ?? '', 'parameter name', self::MAX_NAME);
            $key = mb_strtolower($pname, 'UTF-8');
            if (isset($seen[$key])) {
                throw ApiException::badRequest('Duplicate parameter name: ' . $pname);
            }
            $seen[$key] = true;
            $ptype = self::type($p['type'] ?? '', 'type of parameter ' . $pname);
            if ($type === 'FUNCTION') {
                $mode = self::choice($p['mode'] ?? 'IN', ['IN'], 'parameter mode (functions only take IN parameters)', 'IN');
                $params[] = self::q($pname) . ' ' . $ptype;
            } else {
                $mode = self::choice($p['mode'] ?? 'IN', ['IN', 'OUT', 'INOUT'], 'parameter mode', 'IN');
                $params[] = $mode . ' ' . self::q($pname) . ' ' . $ptype;
            }
        }
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . $this->definerClause($def['definer'] ?? null)
            . $type . ' ' . $this->qualified($name) . '(' . implode(', ', $params) . ')';
        if ($type === 'FUNCTION') {
            $sql .= ' RETURNS ' . self::type($def['returns'] ?? '', 'return type');
        }
        $chars = [];
        if (self::optStr($def['comment'] ?? null) !== null) {
            $chars[] = 'COMMENT ' . self::lit((string) $def['comment']);
        }
        $chars[] = !empty($def['deterministic']) ? 'DETERMINISTIC' : 'NOT DETERMINISTIC';
        $chars[] = self::choice($def['data_access'] ?? '', self::DATA_ACCESS, 'SQL data access', 'CONTAINS SQL');
        $chars[] = 'SQL SECURITY ' . self::choice($def['security'] ?? '', ['DEFINER', 'INVOKER'], 'SQL security', 'DEFINER');
        return $sql . "\n" . implode("\n", $chars) . "\n" . self::body($def['body'] ?? '');
    }

    private function dropRoutineSql(string $type, string $name): string
    {
        return 'DROP ' . self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type') . ' IF EXISTS ' . $this->qualified($name);
    }

    public function buildRoutineSave(array $def, ?array $original): array
    {
        $create = $this->createRoutineSql($def);
        $type = strtoupper(trim((string) $def['type']));
        $name = trim((string) $def['name']);
        $pre = $this->sqlModeStatements($def);
        if ($original === null) {
            return self::plan(array_merge($pre['sql'], [$create]), $pre['warnings']);
        }
        $oname = self::name($original['name'] ?? '', 'original routine name', self::MAX_NAME);
        $otype = self::choice($original['type'] ?? $type, ['PROCEDURE', 'FUNCTION'], 'original routine type');
        $same = $oname === $name && $otype === $type;
        if ($same && $this->canReplaceRoutine()) {
            return self::plan(array_merge($pre['sql'], [$this->createRoutineSql($def, true)]), $pre['warnings']);
        }
        if (!$same && $this->canReplaceRoutine()) {
            // MariaDB rename / type change: create the new one first, then drop the old one.
            return self::plan(array_merge($pre['sql'], [$create, $this->dropRoutineSql($otype, $oname)]), array_merge($pre['warnings'], [self::WARN_GRANTS_LOST]));
        }
        return self::plan(
            array_merge($pre['sql'], [$this->dropRoutineSql($otype, $oname), $create]),
            array_merge($pre['warnings'], [self::WARN_GRANTS_LOST, self::WARN_NOT_ATOMIC])
        );
    }

    /**
     * Routines keep the sql_mode of their creation (it changes how the body is parsed, e.g.
     * ANSI_QUOTES): an edit re-applies the original mode when it differs from the session's.
     * @return array{sql:string[],warnings:string[]}
     */
    private function sqlModeStatements(array $def): array
    {
        $mode = self::optStr($def['sql_mode'] ?? null);
        $cur = (string) ($this->server['sql_mode'] ?? '');
        if ($mode === null || strcasecmp($mode, $cur) === 0) {
            return ['sql' => [], 'warnings' => []];
        }
        if (!preg_match('/^[A-Z_,]*$/i', $mode)) {
            throw ApiException::badRequest('Invalid sql_mode');
        }
        return ['sql' => ['SET SESSION sql_mode = ' . self::lit($mode)], 'warnings' => [self::WARN_SQL_MODE]];
    }

    public function buildRoutineDrop(string $name, string $type, ?string $signature = null): array
    {
        $type = self::choice($type, ['PROCEDURE', 'FUNCTION'], 'routine type');
        return self::plan(['DROP ' . $type . ' ' . $this->qualified(self::name($name, 'routine name', self::MAX_NAME))]);
    }

    public function prepareSave(string $kind, array $plan, ?array $original): array
    {
        if ($original === null || !in_array(self::WARN_NOT_ATOMIC, $plan['warnings'], true)) {
            return $plan;
        }
        // Capture the current definition so a failing CREATE after the DROP can be undone.
        $db = $this->requireDb();
        try {
            if ($kind === 'routine') {
                $type = strtolower((string) ($original['type'] ?? 'procedure'));
                $ddl = $db->createStatement((string) $original['name'], $type === 'function' ? 'function' : 'procedure');
                $plan['restore'] = [$ddl];
            } elseif ($kind === 'trigger') {
                $plan['restore'] = [$db->createStatement((string) $original['name'], 'trigger')];
            }
        } catch (\Throwable $e) {
            // object missing or definition hidden: nothing to restore
        }
        return $plan;
    }

    // ------------------------------------------------------------------ routines: execute

    public function buildExecute(array $info, array $args): array
    {
        $args = self::normalizeArgs($args);
        $name = $this->qualified((string) $info['name']);
        $steps = [];
        if (($info['type'] ?? '') === 'FUNCTION') {
            $marks = [];
            $params = [];
            foreach ($info['parameters'] as $i => $p) {
                $marks[] = '?';
                $params[] = self::argValue($args[$i] ?? null);
            }
            $steps[] = ['sql' => 'SELECT ' . $name . '(' . implode(', ', $marks) . ') AS ' . self::q((string) $info['name']), 'params' => $params, 'role' => 'call'];
            return ['steps' => $steps];
        }
        $marks = [];
        $params = [];
        $outs = [];
        foreach ($info['parameters'] as $i => $p) {
            $mode = strtoupper((string) ($p['mode'] ?? 'IN'));
            if ($mode === 'IN') {
                $marks[] = '?';
                $params[] = self::argValue($args[$i] ?? null);
                continue;
            }
            $var = '@ldb_p' . ($i + 1);
            $steps[] = ['sql' => 'SET ' . $var . ' = ' . ($mode === 'INOUT' ? '?' : 'NULL'), 'params' => $mode === 'INOUT' ? [self::argValue($args[$i] ?? null)] : [], 'role' => 'setup'];
            $marks[] = $var;
            $outs[] = $var . ' AS ' . self::q((string) $p['name']);
        }
        $steps[] = ['sql' => 'CALL ' . $name . '(' . implode(', ', $marks) . ')', 'params' => $params, 'role' => 'call'];
        if ($outs) {
            $steps[] = ['sql' => 'SELECT ' . implode(', ', $outs), 'params' => [], 'role' => 'out'];
        }
        return ['steps' => $steps];
    }

    /** @param array{value:?string,null:bool,default:bool}|null $a */
    private static function argValue(?array $a): ?string
    {
        return $a === null || $a['null'] ? null : $a['value'];
    }

    // ------------------------------------------------------------------ triggers

    public function triggerInfo(string $name, ?string $table = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $stmt = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT, ACTION_TIMING,
                ACTION_ORDER, DEFINER, SQL_MODE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?');
        $stmt->execute([$this->database(), $name]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Trigger "' . $name . '" not found');
        }
        $ddl = null;
        try {
            $ddl = $this->requireDb()->createStatement($name, 'trigger');
        } catch (\Throwable $e) {
        }
        return [
            'kind' => 'trigger',
            'name' => (string) $r['TRIGGER_NAME'],
            'table' => (string) $r['EVENT_OBJECT_TABLE'],
            'timing' => strtoupper((string) $r['ACTION_TIMING']),
            'events' => [strtoupper((string) $r['EVENT_MANIPULATION'])],
            'level' => 'ROW',
            'body' => (string) $r['ACTION_STATEMENT'],
            'definer' => (string) $r['DEFINER'],
            'action_order' => (int) $r['ACTION_ORDER'],
            'sql_mode' => (string) $r['SQL_MODE'],
            'sql' => $ddl,
        ];
    }

    public function createTriggerSql(array $def, bool $orReplace = false): string
    {
        $name = self::name($def['name'] ?? '', 'trigger name', self::MAX_NAME);
        $table = self::name($def['table'] ?? '', 'table', self::MAX_NAME);
        $timing = self::choice($def['timing'] ?? '', ['BEFORE', 'AFTER'], 'trigger timing');
        $events = array_values(array_unique(array_map(static fn ($e): string => self::choice($e, ['INSERT', 'UPDATE', 'DELETE'], 'trigger event'), (array) ($def['events'] ?? []))));
        if (count($events) !== 1) {
            throw ApiException::badRequest('MySQL triggers fire on exactly one event (INSERT, UPDATE or DELETE)');
        }
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . $this->definerClause($def['definer'] ?? null) . 'TRIGGER ' . $this->qualified($name)
            . ' ' . $timing . ' ' . $events[0] . ' ON ' . self::q($table) . "\nFOR EACH ROW";
        $order = $def['order'] ?? null;
        if (is_array($order) && self::optStr($order['trigger'] ?? null) !== null) {
            $sql .= ' ' . self::choice($order['kind'] ?? '', ['FOLLOWS', 'PRECEDES'], 'trigger order') . ' ' . self::q(self::name($order['trigger'], 'trigger', self::MAX_NAME));
        }
        return $sql . "\n" . self::body($def['body'] ?? '');
    }

    public function buildTriggerSave(array $def, ?array $original): array
    {
        $pre = $this->sqlModeStatements($def);
        if ($original === null) {
            return self::plan(array_merge($pre['sql'], [$this->createTriggerSql($def)]), $pre['warnings']);
        }
        $oname = self::name($original['name'] ?? '', 'original trigger name', self::MAX_NAME);
        if ($oname === trim((string) ($def['name'] ?? '')) && $this->canReplaceTrigger()) {
            return self::plan(array_merge($pre['sql'], [$this->createTriggerSql($def, true)]), $pre['warnings']);
        }
        if ($this->canReplaceTrigger()) {
            return self::plan(array_merge($pre['sql'], [$this->createTriggerSql($def), 'DROP TRIGGER IF EXISTS ' . $this->qualified($oname)]), $pre['warnings']);
        }
        return self::plan(
            array_merge($pre['sql'], ['DROP TRIGGER IF EXISTS ' . $this->qualified($oname), $this->createTriggerSql($def)]),
            array_merge($pre['warnings'], [self::WARN_NOT_ATOMIC])
        );
    }

    public function buildTriggerDrop(string $name, ?string $table = null): array
    {
        return self::plan(['DROP TRIGGER ' . $this->qualified(self::name($name, 'trigger name', self::MAX_NAME))]);
    }

    // ------------------------------------------------------------------ events

    public function eventInfo(string $name): array
    {
        $pdo = $this->requireDb()->pdo();
        $stmt = $pdo->prepare('SELECT EVENT_NAME, DEFINER, EVENT_DEFINITION, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE, INTERVAL_FIELD,
                STARTS, ENDS, STATUS, ON_COMPLETION, EVENT_COMMENT, SQL_MODE
            FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? AND EVENT_NAME = ?');
        $stmt->execute([$this->database(), $name]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r) {
            throw ApiException::notFound('Event "' . $name . '" not found');
        }
        $recurring = strtoupper((string) $r['EVENT_TYPE']) === 'RECURRING';
        $status = strtoupper((string) $r['STATUS']);
        $status = $status === 'ENABLED' ? 'ENABLE' : ($status === 'DISABLED' ? 'DISABLE' : 'DISABLE ON SLAVE');
        $ddl = null;
        try {
            $ddl = $this->requireDb()->createStatement($name, 'event');
        } catch (\Throwable $e) {
        }
        $scheduler = null;
        try {
            $scheduler = (string) $pdo->query('SELECT @@GLOBAL.event_scheduler')->fetchColumn();
        } catch (\PDOException $e) {
        }
        return [
            'kind' => 'event',
            'name' => (string) $r['EVENT_NAME'],
            'schedule' => $recurring ? [
                'type' => 'every',
                'interval_value' => trim((string) $r['INTERVAL_VALUE'], "'"),
                'interval_field' => strtoupper((string) $r['INTERVAL_FIELD']),
                'starts' => $r['STARTS'] === null ? null : (string) $r['STARTS'],
                'ends' => $r['ENDS'] === null ? null : (string) $r['ENDS'],
            ] : [
                'type' => 'at',
                'at' => $r['EXECUTE_AT'] === null ? null : (string) $r['EXECUTE_AT'],
            ],
            'preserve' => strtoupper((string) $r['ON_COMPLETION']) === 'PRESERVE',
            'status' => $status,
            'comment' => (string) $r['EVENT_COMMENT'],
            'definer' => (string) $r['DEFINER'],
            'body' => (string) $r['EVENT_DEFINITION'],
            'sql_mode' => (string) $r['SQL_MODE'],
            'scheduler' => $scheduler,
            'sql' => $ddl,
        ];
    }

    /** ON SCHEDULE … clause. */
    public function scheduleSql($schedule): string
    {
        if (!is_array($schedule)) {
            throw ApiException::badRequest('Missing event schedule');
        }
        $type = strtolower(self::str($schedule['type'] ?? ''));
        if ($type === 'at') {
            return 'AT ' . self::lit(self::datetime($schedule['at'] ?? '', 'execution time'));
        }
        if ($type !== 'every') {
            throw ApiException::badRequest('Invalid schedule type (at or every)');
        }
        $field = self::choice($schedule['interval_field'] ?? '', self::INTERVAL_FIELDS, 'interval unit');
        $value = trim(self::str($schedule['interval_value'] ?? ''));
        if (strpos($field, '_') === false) {
            if (!preg_match('/^[1-9]\d{0,9}$/', $value)) {
                throw ApiException::badRequest('Invalid interval (positive whole number expected): ' . $value);
            }
            $sql = 'EVERY ' . $value . ' ' . $field;
        } else {
            if (!preg_match('/^\d+(?:[ :.\-]\d+){0,3}$/', $value)) {
                throw ApiException::badRequest('Invalid interval for ' . $field . ': ' . $value);
            }
            $sql = 'EVERY ' . self::lit($value) . ' ' . $field;
        }
        if (self::optStr($schedule['starts'] ?? null) !== null) {
            $sql .= ' STARTS ' . self::lit(self::datetime($schedule['starts'], 'start time'));
        }
        if (self::optStr($schedule['ends'] ?? null) !== null) {
            $sql .= ' ENDS ' . self::lit(self::datetime($schedule['ends'], 'end time'));
        }
        return $sql;
    }

    /** Clauses after the name shared by CREATE and ALTER EVENT. */
    private function eventClauses(array $def): string
    {
        $sql = "\nON SCHEDULE " . $this->scheduleSql($def['schedule'] ?? null)
            . "\nON COMPLETION " . (!empty($def['preserve']) ? 'PRESERVE' : 'NOT PRESERVE');
        return $sql;
    }

    private function eventTail(array $def): string
    {
        $status = self::choice($def['status'] ?? '', self::STATUS, 'event status', 'ENABLE');
        if ($status !== 'ENABLE' && $status !== 'DISABLE') {
            // MySQL 8.0.22+ spells it ON REPLICA (ON SLAVE deprecated); MariaDB only knows ON SLAVE
            $status = !$this->isMaria() && $this->versionAtLeast('8.0.22') ? 'DISABLE ON REPLICA' : 'DISABLE ON SLAVE';
        }
        $sql = "\n" . $status;
        $comment = self::str($def['comment'] ?? '');
        if ($comment !== '' || array_key_exists('comment', $def)) {
            $sql .= "\nCOMMENT " . self::lit($comment);
        }
        return $sql . "\nDO " . self::body($def['body'] ?? '');
    }

    public function createEventSql(array $def): string
    {
        $name = self::name($def['name'] ?? '', 'event name', self::MAX_NAME);
        return 'CREATE ' . $this->definerClause($def['definer'] ?? null) . 'EVENT ' . $this->qualified($name)
            . $this->eventClauses($def) . $this->eventTail($def);
    }

    public function buildEventSave(array $def, ?array $original): array
    {
        $pre = $this->sqlModeStatements($def);
        if ($original === null) {
            return self::plan(array_merge($pre['sql'], [$this->createEventSql($def)]), $pre['warnings']);
        }
        $oname = self::name($original['name'] ?? '', 'original event name', self::MAX_NAME);
        $name = self::name($def['name'] ?? '', 'event name', self::MAX_NAME);
        $sql = 'ALTER ' . $this->definerClause($def['definer'] ?? null) . 'EVENT ' . $this->qualified($oname)
            . $this->eventClauses($def)
            . ($oname !== $name ? "\nRENAME TO " . $this->qualified($name) : '')
            . $this->eventTail($def);
        return self::plan(array_merge($pre['sql'], [$sql]), $pre['warnings']);
    }

    public function buildEventDrop(string $name): array
    {
        return self::plan(['DROP EVENT ' . $this->qualified(self::name($name, 'event name', self::MAX_NAME))]);
    }

    /** "Copy as SQL": DELIMITER-wrapped script that the mysql client and the SQL editor accept. */
    public function script(array $plan): string
    {
        $out = [];
        $needs = false;
        foreach ($plan['sql'] as $s) {
            if (preg_match('/^\s*(CREATE|ALTER)\b/i', $s) && strpos($s, ';') !== false) {
                $needs = true;
            }
        }
        if (!$needs) {
            return parent::script($plan);
        }
        $out[] = 'DELIMITER $$';
        foreach ($plan['sql'] as $s) {
            $out[] = rtrim($s, "; \n") . '$$';
        }
        $out[] = 'DELIMITER ;';
        return implode("\n", $out);
    }
}
