<?php

declare(strict_types=1);

namespace Ldb\Diff;

/**
 * Compares a SOURCE snapshot (the desired state) with a TARGET snapshot (the database to
 * migrate) and lists the changes that make the target match the source. Pure: no database access.
 *
 * Change = {id, kind, object_type, table, object, fields, before, after, destructive, warnings}
 *   kind: table_added|table_removed|column_added|column_removed|column_changed|column_renamed|
 *         index_added|index_removed|index_changed|fk_added|fk_removed|fk_changed|
 *         check_added|check_removed|check_changed|option_changed|
 *         view_*|routine_*|trigger_*|event_* (added|removed|changed)
 *   before = target side, after = source side (display data; the planner reads the snapshots).
 *   The primary key is reported as index_* with object "PRIMARY".
 *   warnings: narrowing | not_null (target column nullable, source NOT NULL: the caller may count
 *   NULL rows and set destructive) | definition_missing
 */
final class SchemaComparer
{
    public const DEFAULT_OPTIONS = [
        'include' => ['tables' => true, 'views' => true, 'routines' => true, 'triggers' => true, 'events' => true],
        'ignore' => ['comments' => false, 'auto_increment' => true, 'collation' => false, 'column_order' => false, 'definer' => true],
        'detect_renames' => false,
    ];

    /** Drivers that can reorder columns (MySQL AFTER/FIRST, SQLite rebuild). */
    private const ORDERED_COLUMNS = ['mysql', 'sqlite'];

    private array $opts = self::DEFAULT_OPTIONS;
    private string $driver = 'mysql';
    private SchemaSnapshot $src;
    private SchemaSnapshot $tgt;
    /** @var array<int,array<string,mixed>> */
    private array $changes = [];

    /** Normalise user options (unknown keys dropped, values cast to bool). */
    public static function options(array $o): array
    {
        $out = self::DEFAULT_OPTIONS;
        foreach (['include', 'ignore'] as $group) {
            $given = isset($o[$group]) && is_array($o[$group]) ? $o[$group] : [];
            foreach ($out[$group] as $k => $v) {
                if (array_key_exists($k, $given)) {
                    $out[$group][$k] = self::truthy($given[$k]);
                }
            }
        }
        if (array_key_exists('detect_renames', $o)) {
            $out['detect_renames'] = self::truthy($o['detect_renames']);
        }
        return $out;
    }

    private static function truthy($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
    }

    /** @return array<int,array<string,mixed>> */
    public function compare(SchemaSnapshot $source, SchemaSnapshot $target, array $options = []): array
    {
        $this->opts = self::options($options);
        $this->driver = $target->driver();
        $this->src = $source;
        $this->tgt = $target;
        $this->changes = [];
        $inc = $this->opts['include'];

        if ($inc['tables']) {
            $this->compareTables();
        }
        if ($inc['views']) {
            $this->compareObjects('view', $source->views(), $target->views(), null);
        }
        if ($inc['routines']) {
            $this->compareObjects('routine', $source->routines(), $target->routines(), null);
        }
        if ($inc['triggers']) {
            $this->compareObjects('trigger', $source->triggers(), $target->triggers(), 'table');
        }
        if ($inc['events']) {
            $this->compareObjects('event', $source->events(), $target->events(), null);
        }
        return $this->changes;
    }

    /**
     * Counts per object type: {tables:{added,removed,changed}, views:{…}, routines, triggers,
     * events, total, destructive}. Column/index/… changes count their table as changed.
     */
    public static function summary(array $changes): array
    {
        $out = [];
        foreach (['tables', 'views', 'routines', 'triggers', 'events'] as $k) {
            $out[$k] = ['added' => 0, 'removed' => 0, 'changed' => 0];
        }
        $changedTables = [];
        $destructive = 0;
        foreach ($changes as $c) {
            if (!empty($c['destructive'])) {
                $destructive++;
            }
            $type = (string) $c['object_type'];
            $group = $type . 's';
            $kind = (string) $c['kind'];
            if ($type === 'table') {
                if ($kind === 'table_added') {
                    $out['tables']['added']++;
                } elseif ($kind === 'table_removed') {
                    $out['tables']['removed']++;
                } else {
                    $changedTables[(string) $c['table']] = true;
                }
                continue;
            }
            if (!isset($out[$group])) {
                continue;
            }
            $suffix = substr($kind, (int) strrpos($kind, '_') + 1);
            if (isset($out[$group][$suffix])) {
                $out[$group][$suffix]++;
            }
        }
        $out['tables']['changed'] = count($changedTables);
        $out['total'] = count($changes);
        $out['destructive'] = $destructive;
        return $out;
    }

    // ------------------------------------------------------------------ tables

    private function compareTables(): void
    {
        $s = $this->src->tables();
        $t = $this->tgt->tables();
        foreach ($s as $name => $table) {
            if (!isset($t[$name])) {
                $this->add('table_added', 'table', $name, $name, [], null, [
                    'columns' => count($table['columns']),
                    'indexes' => count($table['indexes']),
                    'foreign_keys' => count($table['foreign_keys']),
                ]);
            }
        }
        foreach ($t as $name => $table) {
            if (!isset($s[$name])) {
                $this->add('table_removed', 'table', $name, $name, [], [
                    'columns' => count($table['columns']),
                    'rows' => $table['options']['rows'] ?? null,
                ], null, true);
            }
        }
        foreach ($s as $name => $table) {
            if (isset($t[$name])) {
                $this->compareTable($name, $table, $t[$name]);
            }
        }
    }

    private function compareTable(string $name, array $s, array $t): void
    {
        $ignore = $this->opts['ignore'];
        $sCols = [];
        foreach ($s['columns'] as $i => $c) {
            $sCols[$c['name']] = $i;
        }
        $tCols = [];
        foreach ($t['columns'] as $i => $c) {
            $tCols[$c['name']] = $i;
        }
        $added = array_values(array_diff_key($sCols, $tCols) ? array_keys(array_diff_key($sCols, $tCols)) : []);
        $removed = array_values(array_diff_key($tCols, $sCols) ? array_keys(array_diff_key($tCols, $sCols)) : []);

        // rename heuristic: one removed + one added at the same position with the same definition
        $renamed = [];
        if ($this->opts['detect_renames']) {
            foreach ($added as $ai => $a) {
                foreach ($removed as $ri => $r) {
                    if ($sCols[$a] === $tCols[$r]
                        && $this->columnKey($s['columns'][$sCols[$a]]) == $this->columnKey($t['columns'][$tCols[$r]])) {
                        $renamed[$r] = $a;
                        unset($added[$ai], $removed[$ri]);
                        break;
                    }
                }
            }
            $added = array_values($added);
            $removed = array_values($removed);
        }

        foreach ($added as $a) {
            $col = $s['columns'][$sCols[$a]];
            $this->add('column_added', 'table', $name, $a, [], null, $this->describeColumn($col, $sCols[$a]));
        }
        foreach ($removed as $r) {
            $col = $t['columns'][$tCols[$r]];
            $this->add('column_removed', 'table', $name, $r, [], $this->describeColumn($col, $tCols[$r]), null, true);
        }
        foreach ($renamed as $old => $new) {
            $this->add('column_renamed', 'table', $name, $new, ['name'], $this->describeColumn($t['columns'][$tCols[$old]], $tCols[$old]) + ['name' => $old],
                $this->describeColumn($s['columns'][$sCols[$new]], $sCols[$new]) + ['name' => $new]);
        }

        $moved = [];
        if (!$ignore['column_order'] && in_array($this->driver, self::ORDERED_COLUMNS, true)) {
            $moved = $this->movedColumns($s['columns'], $t['columns'], $added, $removed, $renamed);
        }

        foreach ($s['columns'] as $i => $sc) {
            $cname = $sc['name'];
            if (!isset($tCols[$cname])) {
                continue;
            }
            $tc = $t['columns'][$tCols[$cname]];
            $ks = $this->columnKey($sc);
            $kt = $this->columnKey($tc);
            $fields = [];
            foreach ($ks as $f => $v) {
                if ($v !== $kt[$f]) {
                    $fields[] = $f;
                }
            }
            if (isset($moved[$cname])) {
                $fields[] = 'position';
            }
            if (!$fields) {
                continue;
            }
            $warnings = [];
            $destructive = false;
            if (in_array('type', $fields, true) && self::isNarrowing($kt['type'], $ks['type'], $this->driver)) {
                $warnings[] = 'narrowing';
                $destructive = true;
            }
            if (in_array('nullable', $fields, true) && $kt['nullable'] && !$ks['nullable']) {
                $warnings[] = 'not_null';
            }
            $this->add('column_changed', 'table', $name, $cname, $fields,
                $this->describeColumn($tc, $tCols[$cname]), $this->describeColumn($sc, $i), $destructive, $warnings);
        }

        $this->comparePrimaryKey($name);
        $this->compareIndexes($name, $s, $t);
        $this->compareForeignKeys($name, $s, $t);
        $this->compareChecks($name, $s, $t);

        $fields = [];
        $before = [];
        $after = [];
        $so = $s['options'];
        $to = $t['options'];
        $optFields = ['engine' => $this->driver === 'mysql'];
        $optFields['collation'] = !$ignore['collation'];
        $optFields['comment'] = !$ignore['comments'];
        $optFields['auto_increment'] = !$ignore['auto_increment'];
        foreach ($optFields as $f => $on) {
            if (!$on) {
                continue;
            }
            $a = $so[$f] ?? null;
            $b = $to[$f] ?? null;
            $na = is_string($a) && $f !== 'comment' ? strtolower($a) : $a;
            $nb = is_string($b) && $f !== 'comment' ? strtolower($b) : $b;
            if ($f === 'auto_increment' && ($a === null || $b === null)) {
                continue;
            }
            if ($na !== $nb) {
                $fields[] = $f;
                $before[$f] = $b;
                $after[$f] = $a;
            }
        }
        if ($fields) {
            $this->add('option_changed', 'table', $name, $name, $fields, $before, $after);
        }
    }

    /**
     * Columns (by source name) whose position differs once added/removed columns are accounted
     * for: the source order is compared with the target order (removed dropped, renames applied)
     * and everything outside a longest common subsequence counts as moved.
     * @return array<string,true>
     */
    private function movedColumns(array $sCols, array $tCols, array $added, array $removed, array $renamed): array
    {
        $sOrder = [];
        foreach ($sCols as $c) {
            if (!in_array($c['name'], $added, true)) {
                $sOrder[] = (string) $c['name'];
            }
        }
        $tOrder = [];
        foreach ($tCols as $c) {
            $n = (string) $c['name'];
            if (in_array($n, $removed, true)) {
                continue;
            }
            $tOrder[] = $renamed[$n] ?? $n;
        }
        if ($sOrder === $tOrder) {
            return [];
        }
        $keep = array_flip(self::lcs($sOrder, $tOrder));
        $moved = [];
        foreach ($sOrder as $n) {
            if (!isset($keep[$n])) {
                $moved[$n] = true;
            }
        }
        return $moved;
    }

    /** Longest common subsequence of two lists of distinct strings. @return string[] */
    public static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = $a[$i];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $out;
    }

    /** Normalised, comparable attributes of a column (respecting the ignore options). */
    private function columnKey(array $c): array
    {
        $ignore = $this->opts['ignore'];
        $k = [
            'type' => Normalizer::type($c, $this->driver),
            'nullable' => (bool) $c['nullable'],
            'default' => Normalizer::defaultValue($c),
            'auto_increment' => (bool) $c['auto_increment'],
            'generated' => Normalizer::generated($c),
            'on_update' => Normalizer::onUpdate($c),
        ];
        if (!$ignore['collation']) {
            $k['collation'] = isset($c['collation']) && $c['collation'] !== '' ? strtolower((string) $c['collation']) : null;
        }
        if (!$ignore['comments']) {
            $k['comment'] = isset($c['comment']) && $c['comment'] !== '' ? (string) $c['comment'] : null;
        }
        return $k;
    }

    /** Display data of a column: a readable definition plus the raw attributes. */
    private function describeColumn(array $c, int $position): array
    {
        $type = (string) ($c['full_type'] !== '' ? $c['full_type'] : Normalizer::type($c, $this->driver));
        $def = $type;
        $gen = Normalizer::generated($c);
        if ($gen !== null) {
            [$kind, $expr] = explode(':', $gen, 2);
            $def .= ' GENERATED ALWAYS AS (' . $expr . ') ' . strtoupper($kind);
        }
        $def .= !empty($c['nullable']) ? ' NULL' : ' NOT NULL';
        $d = Normalizer::defaultValue($c);
        if ($d === 'null' && !empty($c['nullable'])) {
            // implicit
        } elseif (strpos($d, 'v:') === 0) {
            $def .= " DEFAULT '" . str_replace("'", "''", substr($d, 2)) . "'";
        } elseif (strpos($d, 'e:') === 0) {
            $def .= ' DEFAULT ' . (string) $c['default'];
        }
        if (!empty($c['auto_increment'])) {
            $def .= ' AUTO_INCREMENT';
        }
        $ou = Normalizer::onUpdate($c);
        if ($ou !== null) {
            $def .= ' ON UPDATE ' . strtoupper($ou);
        }
        if (!empty($c['collation'])) {
            $def .= ' COLLATE ' . $c['collation'];
        }
        if (isset($c['comment']) && $c['comment'] !== null && $c['comment'] !== '') {
            $def .= " COMMENT '" . str_replace("'", "''", (string) $c['comment']) . "'";
        }
        return [
            'name' => (string) $c['name'],
            'definition' => $def,
            'type' => $type,
            'nullable' => (bool) $c['nullable'],
            'default' => $c['default'],
            'default_kind' => $c['default_kind'],
            'auto_increment' => (bool) $c['auto_increment'],
            'collation' => $c['collation'],
            'comment' => $c['comment'],
            'position' => $position + 1,
        ];
    }

    // ------------------------------------------------------------------ keys and constraints

    private function comparePrimaryKey(string $table): void
    {
        $s = $this->src->primaryKey($table);
        $t = $this->tgt->primaryKey($table);
        if ($s === $t) {
            return;
        }
        $before = $t ? ['columns' => $t, 'primary' => true, 'name' => $this->tgt->primaryIndexName($table) ?? 'PRIMARY'] : null;
        $after = $s ? ['columns' => $s, 'primary' => true, 'name' => $this->src->primaryIndexName($table) ?? 'PRIMARY'] : null;
        if (!$t) {
            $this->add('index_added', 'table', $table, 'PRIMARY', [], null, $after);
        } elseif (!$s) {
            $this->add('index_removed', 'table', $table, 'PRIMARY', [], $before, null);
        } else {
            $this->add('index_changed', 'table', $table, 'PRIMARY', ['columns'], $before, $after);
        }
    }

    private function indexSignature(array $ix, SchemaSnapshot $snap): string
    {
        $ddl = Normalizer::indexDdl($ix['ddl'] ?? null, $this->driver, $snap->schema());
        if ($ddl !== null) {
            return 'ddl:' . strtolower($ddl);
        }
        $type = strtoupper((string) ($ix['type'] ?? ''));
        if (!in_array($type, ['FULLTEXT', 'SPATIAL'], true) || $this->driver !== 'mysql') {
            $type = $this->driver === 'pgsql' && $type !== '' && $type !== 'BTREE' ? $type : '';
        }
        return implode(',', array_map('strval', (array) $ix['columns'])) . '|' . (!empty($ix['unique']) ? 'u' : '') . '|' . $type;
    }

    private function compareIndexes(string $table, array $s, array $t): void
    {
        $filter = static fn (array $list): array => array_values(array_filter($list, static fn (array $ix): bool => empty($ix['primary'])));
        $sIx = $filter($s['indexes']);
        $tIx = $filter($t['indexes']);
        $this->matchObjects('index', $table, $sIx, $tIx,
            fn (array $ix): string => $this->indexSignature($ix, $this->src),
            fn (array $ix): string => $this->indexSignature($ix, $this->tgt),
            fn (array $ix): array => ['name' => $ix['name'], 'columns' => array_values((array) $ix['columns']), 'unique' => (bool) $ix['unique'], 'type' => $ix['type'], 'ddl' => $ix['ddl'] ?? null],
            fn (array $ix): bool => !empty($ix['auto_name']) || Normalizer::isAutoName((string) $ix['name'], $table, $this->driver, 'index'));
    }

    private function fkSignature(array $fk, SchemaSnapshot $snap): string
    {
        $refSchema = (string) ($fk['ref_schema'] ?? '');
        $own = $this->driver === 'mysql' ? $snap->database() : $snap->schema();
        if ($refSchema === (string) $own) {
            $refSchema = '';
        }
        return implode(',', (array) $fk['columns']) . '>' . ($refSchema !== '' ? $refSchema . '.' : '') . $fk['ref_table']
            . '(' . implode(',', (array) $fk['ref_columns']) . ')|' . Normalizer::fkAction($fk['on_delete'] ?? null)
            . '|' . Normalizer::fkAction($fk['on_update'] ?? null);
    }

    private function compareForeignKeys(string $table, array $s, array $t): void
    {
        $this->matchObjects('fk', $table, $s['foreign_keys'], $t['foreign_keys'],
            fn (array $fk): string => $this->fkSignature($fk, $this->src),
            fn (array $fk): string => $this->fkSignature($fk, $this->tgt),
            static fn (array $fk): array => [
                'name' => $fk['name'], 'columns' => array_values((array) $fk['columns']), 'ref_table' => $fk['ref_table'],
                'ref_columns' => array_values((array) $fk['ref_columns']), 'on_delete' => $fk['on_delete'], 'on_update' => $fk['on_update'],
            ],
            fn (array $fk): bool => Normalizer::isAutoName((string) $fk['name'], $table, $this->driver, 'fk'));
    }

    private function compareChecks(string $table, array $s, array $t): void
    {
        $this->matchObjects('check', $table, $s['checks'], $t['checks'],
            static fn (array $c): string => Normalizer::check($c['definition']),
            static fn (array $c): string => Normalizer::check($c['definition']),
            static fn (array $c): array => ['name' => $c['name'], 'definition' => $c['definition']],
            fn (array $c): bool => Normalizer::isAutoName((string) $c['name'], $table, $this->driver, 'check'));
    }

    /**
     * Match named sub-objects: same name → compare signatures (…_changed); leftovers with equal
     * signatures are the same object under another name (equal when either name is generated,
     * otherwise …_changed with field "name"); the rest are added/removed.
     */
    private function matchObjects(string $kind, string $table, array $sList, array $tList, callable $sSig, callable $tSig, callable $describe, callable $isAuto): void
    {
        $tByName = [];
        foreach ($tList as $i => $o) {
            $tByName[(string) $o['name']] = $i;
        }
        $sLeft = [];
        $tUsed = [];
        foreach ($sList as $i => $o) {
            $n = (string) $o['name'];
            if ($n !== '' && isset($tByName[$n]) && !$isAuto($o)) {
                $ti = $tByName[$n];
                $tUsed[$ti] = true;
                if ($sSig($o) !== $tSig($tList[$ti])) {
                    $this->add($kind . '_changed', 'table', $table, $n, ['definition'], $describe($tList[$ti]), $describe($o));
                }
                continue;
            }
            $sLeft[] = $i;
        }
        $tLeft = [];
        foreach ($tList as $i => $o) {
            if (!isset($tUsed[$i])) {
                $tLeft[$i] = $tSig($o);
            }
        }
        foreach ($sLeft as $si) {
            $o = $sList[$si];
            $sig = $sSig($o);
            $ti = array_search($sig, $tLeft, true);
            if ($ti !== false) {
                unset($tLeft[$ti]);
                $other = $tList[$ti];
                if (!$isAuto($o) && !$isAuto($other) && (string) $o['name'] !== (string) $other['name']) {
                    $this->add($kind . '_changed', 'table', $table, (string) $o['name'], ['name'], $describe($other), $describe($o));
                }
                continue;
            }
            $this->add($kind . '_added', 'table', $table, (string) $o['name'], [], null, $describe($o));
        }
        foreach (array_keys($tLeft) as $ti) {
            $o = $tList[$ti];
            $this->add($kind . '_removed', 'table', $table, (string) $o['name'], [], $describe($o), null);
        }
    }

    // ------------------------------------------------------------------ views, routines, …

    private function compareObjects(string $type, array $s, array $t, ?string $tableKey): void
    {
        $strip = $this->opts['ignore']['definer'];
        $norm = function (?string $ddl, SchemaSnapshot $snap) use ($strip): ?string {
            $n = Normalizer::ddl($ddl, $this->driver, $snap->database(), $snap->schema(), $strip);
            if ($n !== null && $this->opts['ignore']['comments'] && $this->driver === 'pgsql') {
                $n = trim((string) preg_replace('/;?\s*COMMENT ON .*$/is', '', $n));
            }
            return $n;
        };
        foreach ($s as $key => $o) {
            $table = $tableKey !== null ? ($o[$tableKey] ?? null) : null;
            $name = (string) ($type === 'routine' && $this->driver === 'pgsql' ? $key : $o['name']);
            $name = $type === 'routine' ? (string) preg_replace('/^(PROCEDURE|FUNCTION):/', '', $name) : $name;
            $id = (string) $key;
            if (!isset($t[$key])) {
                $warn = $o['ddl'] === null ? ['definition_missing'] : [];
                $this->add($type . '_added', $type, $table, $name, [], null, ['ddl' => $o['ddl']], false, $warn, $id);
                continue;
            }
            $a = $norm($o['ddl'], $this->src);
            $b = $norm($t[$key]['ddl'], $this->tgt);
            if ($a === $b) {
                continue;
            }
            $warn = $o['ddl'] === null ? ['definition_missing'] : [];
            $this->add($type . '_changed', $type, $table, $name, ['definition'], ['ddl' => $t[$key]['ddl']], ['ddl' => $o['ddl']], false, $warn, $id);
        }
        foreach ($t as $key => $o) {
            if (isset($s[$key])) {
                continue;
            }
            $table = $tableKey !== null ? ($o[$tableKey] ?? null) : null;
            $name = $type === 'routine' ? (string) preg_replace('/^(PROCEDURE|FUNCTION):/', '', (string) ($this->driver === 'pgsql' ? $key : $o['name'])) : (string) $o['name'];
            $this->add($type . '_removed', $type, $table, $name, [], ['ddl' => $o['ddl']], null, false, [], (string) $key);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param mixed $before
     * @param mixed $after
     */
    private function add(string $kind, string $objectType, ?string $table, string $object, array $fields, $before, $after, bool $destructive = false, array $warnings = [], ?string $key = null): void
    {
        $id = $kind . ':' . ($objectType === 'table' ? $table . ':' . $object : ($key ?? $object));
        $this->changes[] = [
            'id' => $id,
            'kind' => $kind,
            'object_type' => $objectType,
            'table' => $table,
            'object' => $object,
            'key' => $key,
            'fields' => $fields,
            'before' => $before,
            'after' => $after,
            'destructive' => $destructive,
            'warnings' => $warnings,
        ];
    }

    /** Integer storage ranks (bigger = wider) of known integer type names. */
    private const INT_RANK = [
        'tinyint' => 1, 'smallint' => 2, 'int2' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'int4' => 4,
        'bigint' => 5, 'int8' => 5, 'serial' => 4, 'bigserial' => 5, 'smallserial' => 2,
    ];
    private const TEXT_LEN = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295, 'clob' => PHP_INT_MAX];
    private const BLOB_LEN = ['tinyblob' => 255, 'blob' => 65535, 'mediumblob' => 16777215, 'longblob' => 4294967295, 'bytea' => PHP_INT_MAX];

    /**
     * Whether changing a column type from $from to $to (normalised type strings) may lose data:
     * smaller integer/decimal/float, shorter strings, removed enum/set values, signedness changes,
     * fewer fractional seconds, or a change of type family (except to unbounded text).
     */
    public static function isNarrowing(string $from, string $to, string $driver): bool
    {
        if ($from === $to) {
            return false;
        }
        $a = self::parseType($from, $driver);
        $b = self::parseType($to, $driver);
        if ($b['family'] === 'string' && $a['family'] !== 'string') {
            // numbers, dates and enum values fit into any reasonably long string; JSON only into unbounded text
            $need = ['int' => 20, 'dec' => $a['precision'] + 2, 'float' => 32, 'date' => 32, 'json' => 4294967295, 'enum' => 255][$a['family']] ?? null;
            if ($need !== null && $b['size'] >= $need) {
                return false;
            }
        }
        if ($a['family'] !== $b['family'] || $a['family'] === 'other') {
            return $driver === 'sqlite' && $b['base'] === '' ? false : true;
        }
        switch ($a['family']) {
            case 'int':
                if ($a['unsigned'] === $b['unsigned']) {
                    return $b['size'] < $a['size'];
                }
                // unsigned → signed fits only in a wider type; signed → unsigned loses negatives
                return $b['unsigned'] || $b['size'] <= $a['size'];
            case 'dec':
                return ($b['precision'] - $b['scale']) < ($a['precision'] - $a['scale']) || $b['scale'] < $a['scale'] || ($b['unsigned'] && !$a['unsigned']);
            case 'float':
                return $b['size'] < $a['size'] || ($b['unsigned'] && !$a['unsigned']);
            case 'string':
            case 'binary':
                return $b['size'] < $a['size'];
            case 'enum':
                return array_diff($a['values'], $b['values']) !== [] || $a['base'] !== $b['base'];
            case 'date':
                if ($a['base'] === $b['base'] || ($a['base'] === 'date' && in_array($b['base'], ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'], true))) {
                    return $b['size'] < $a['size'];
                }
                return true;
            case 'json':
                return false;
        }
        return true;
    }

    /** @return array{family:string,base:string,size:int,precision:int,scale:int,unsigned:bool,values:string[]} */
    private static function parseType(string $type, string $driver): array
    {
        $out = ['family' => 'other', 'base' => '', 'size' => 0, 'precision' => 0, 'scale' => 0, 'unsigned' => false, 'values' => []];
        if (!preg_match('/^([a-z][a-z0-9_ ]*?)\s*(?:\((.*)\))?\s*((?:unsigned|signed|zerofill|\s)*)?(\[\])?$/s', $type, $m)) {
            return $out;
        }
        $base = trim($m[1]);
        $args = $m[2] ?? '';
        $out['base'] = $base;
        $out['unsigned'] = strpos($m[3] ?? '', 'unsigned') !== false;
        if (!empty($m[4])) {
            return $out; // arrays: compare as "other" (any change counts)
        }
        $num = static fn (string $s): array => array_map('intval', array_map('trim', explode(',', $s)));
        if (isset(self::INT_RANK[$base])) {
            $out['family'] = 'int';
            $out['size'] = self::INT_RANK[$base];
            if ($base === 'tinyint' && $args === '1') {
                $out['size'] = 1;
            }
            if ($driver === 'sqlite') {
                $out['size'] = 5; // INTEGER affinity: 64-bit
            }
        } elseif (in_array($base, ['decimal', 'numeric', 'dec', 'fixed', 'money'], true)) {
            $out['family'] = 'dec';
            $p = $args !== '' ? $num($args) : [$driver === 'pgsql' ? 1000 : 10, 0];
            $out['precision'] = $p[0];
            $out['scale'] = $p[1] ?? 0;
        } elseif (in_array($base, ['float', 'real', 'float4'], true)) {
            $out['family'] = 'float';
            $out['size'] = $driver === 'sqlite' ? 8 : 4;
        } elseif (in_array($base, ['double', 'double precision', 'float8'], true)) {
            $out['family'] = 'float';
            $out['size'] = 8;
        } elseif (in_array($base, ['char', 'varchar', 'character', 'character varying', 'nchar', 'nvarchar', 'bpchar', 'varchar2', 'citext', 'string'], true)) {
            $out['family'] = 'string';
            $out['size'] = $args !== '' && ctype_digit(trim($args)) ? (int) $args : (in_array($base, ['char', 'character', 'nchar', 'bpchar'], true) ? 1 : PHP_INT_MAX);
            if ($driver === 'sqlite') {
                $out['size'] = PHP_INT_MAX; // lengths are not enforced
            }
        } elseif (isset(self::TEXT_LEN[$base])) {
            $out['family'] = 'string';
            $out['size'] = $driver === 'mysql' ? self::TEXT_LEN[$base] : PHP_INT_MAX;
        } elseif (in_array($base, ['binary', 'varbinary'], true)) {
            $out['family'] = 'binary';
            $out['size'] = $args !== '' ? (int) $args : 1;
        } elseif (isset(self::BLOB_LEN[$base])) {
            $out['family'] = 'binary';
            $out['size'] = $driver === 'mysql' ? self::BLOB_LEN[$base] : PHP_INT_MAX;
        } elseif ($base === 'enum' || $base === 'set') {
            $out['family'] = 'enum';
            preg_match_all("/'((?:[^']|'')*)'/", $args, $vm);
            $out['values'] = $vm[1];
        } elseif (preg_match('/^(date|datetime|timestamp|time)( with(out)? time zone)?$/', $base)) {
            $out['family'] = 'date';
            $out['size'] = $args !== '' ? (int) $args : ($driver === 'pgsql' && $base !== 'date' ? 6 : 0);
        } elseif ($base === 'json' || $base === 'jsonb') {
            $out['family'] = 'json';
        }
        return $out;
    }
}
