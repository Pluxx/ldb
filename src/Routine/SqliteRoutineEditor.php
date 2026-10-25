<?php

declare(strict_types=1);

namespace Ldb\Routine;

use Ldb\Http\ApiException;
use PDO;

/**
 * SQLite: triggers only (no stored routines or events). Edits are DROP + CREATE in one
 * transaction (SQLite DDL is transactional).
 */
final class SqliteRoutineEditor extends RoutineEditor
{
    private static function q(string $name): string
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            throw ApiException::badRequest('Invalid identifier');
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Parse CREATE TRIGGER text.
     * @return array{timing:string,events:string[],columns:string[],level:string,when:?string,body:string}|null
     */
    public static function parseTrigger(string $sql): ?array
    {
        $tok = self::scan($sql, true);
        $n = count($tok);
        $k = 0;
        while ($k < $n && !($tok[$k][0] === 'w' && $tok[$k][1] === 'TRIGGER')) {
            $k++;
        }
        $k++;
        if ($k + 2 < $n && $tok[$k][1] === 'IF' && $tok[$k + 1][1] === 'NOT' && $tok[$k + 2][1] === 'EXISTS') {
            $k += 3;
        }
        // name (schema.name)
        $k++;
        if (isset($tok[$k]) && $tok[$k][1] === '.') {
            $k += 2;
        }
        $timing = 'BEFORE';
        if (isset($tok[$k]) && in_array($tok[$k][1], ['BEFORE', 'AFTER'], true)) {
            $timing = $tok[$k][1];
            $k++;
        } elseif (isset($tok[$k + 1]) && $tok[$k][1] === 'INSTEAD' && $tok[$k + 1][1] === 'OF') {
            $timing = 'INSTEAD OF';
            $k += 2;
        }
        $event = $tok[$k][1] ?? '';
        if (!in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            return null;
        }
        $k++;
        $columns = [];
        if ($event === 'UPDATE' && isset($tok[$k]) && $tok[$k][1] === 'OF') {
            $k++;
            while (isset($tok[$k]) && $tok[$k][1] !== 'ON') {
                if ($tok[$k][0] === 'w' || $tok[$k][0] === 'q') {
                    $columns[] = $tok[$k][0] === 'q' ? self::unquoteIdent($tok[$k][1]) : substr($sql, $tok[$k][2], $tok[$k][3] - $tok[$k][2]);
                }
                $k++;
            }
        }
        $begin = null;
        $when = null;
        $whenStart = null;
        $level = 'ROW';
        for ($i = $k; $i < $n; $i++) {
            if ($tok[$i][0] !== 'w' || $tok[$i][4] !== 0) {
                continue;
            }
            if ($tok[$i][1] === 'WHEN' && $whenStart === null) {
                $whenStart = $tok[$i][3];
            } elseif ($tok[$i][1] === 'BEGIN') {
                $begin = $i;
                break;
            }
        }
        if ($begin === null) {
            return null;
        }
        if ($whenStart !== null) {
            $when = trim(substr($sql, $whenStart, $tok[$begin][2] - $whenStart));
        }
        $endPos = null;
        for ($i = $n - 1; $i > $begin; $i--) {
            if ($tok[$i][0] === 'w' && $tok[$i][1] === 'END') {
                $endPos = $tok[$i][2];
                break;
            }
        }
        if ($endPos === null) {
            return null;
        }
        $body = trim(substr($sql, $tok[$begin][3], $endPos - $tok[$begin][3]));
        return ['timing' => $timing, 'events' => [$event], 'columns' => $columns, 'level' => $level, 'when' => $when, 'body' => $body];
    }

    public function triggerInfo(string $name, ?string $table = null): array
    {
        $pdo = $this->requireDb()->pdo();
        $stmt = $pdo->prepare("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger' AND name = ?");
        $stmt->execute([$name]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$r || $r['sql'] === null) {
            throw ApiException::notFound('Trigger "' . $name . '" not found');
        }
        $p = self::parseTrigger((string) $r['sql']);
        if ($p === null) {
            throw ApiException::badRequest('The definition of trigger "' . $name . '" could not be parsed');
        }
        return [
            'kind' => 'trigger',
            'name' => (string) $r['name'],
            'table' => (string) $r['tbl_name'],
            'timing' => $p['timing'],
            'events' => $p['events'],
            'columns' => $p['columns'],
            'level' => 'ROW',
            'when' => $p['when'],
            'body' => $p['body'],
            'sql' => rtrim((string) $r['sql']) . ';',
        ];
    }

    public function createTriggerSql(array $def): string
    {
        $name = self::name($def['name'] ?? '', 'trigger name', 1000);
        $table = self::name($def['table'] ?? '', 'table', 1000);
        $timing = self::choice($def['timing'] ?? '', ['BEFORE', 'AFTER', 'INSTEAD OF'], 'trigger timing');
        $events = array_values(array_unique(array_map(static fn ($e): string => self::choice($e, ['INSERT', 'UPDATE', 'DELETE'], 'trigger event'), (array) ($def['events'] ?? []))));
        if (count($events) !== 1) {
            throw ApiException::badRequest('SQLite triggers fire on exactly one event (INSERT, UPDATE or DELETE)');
        }
        $ev = $events[0];
        if ($ev === 'UPDATE' && !empty($def['columns'])) {
            $ev .= ' OF ' . implode(', ', array_map(static fn ($c): string => self::q(self::name($c, 'column', 1000)), (array) $def['columns']));
        }
        $sql = 'CREATE TRIGGER ' . self::q($name) . ' ' . $timing . ' ' . $ev . ' ON ' . self::q($table) . "\nFOR EACH ROW";
        $when = self::optStr($def['when'] ?? null);
        if ($when !== null) {
            $sql .= "\nWHEN " . self::fragment($when, 'WHEN condition');
        }
        $body = self::body($def['body'] ?? '');
        if (substr(rtrim($body), -1) !== ';') {
            $body = rtrim($body) . ';';
        }
        return $sql . "\nBEGIN\n" . $body . "\nEND";
    }

    public function buildTriggerSave(array $def, ?array $original): array
    {
        if ($original === null) {
            return self::plan([$this->createTriggerSql($def)]);
        }
        $oname = self::name($original['name'] ?? '', 'original trigger name', 1000);
        return self::plan(['DROP TRIGGER ' . self::q($oname), $this->createTriggerSql($def)], [], true);
    }

    public function buildTriggerDrop(string $name, ?string $table = null): array
    {
        return self::plan(['DROP TRIGGER ' . self::q(self::name($name, 'trigger name', 1000))]);
    }
}
