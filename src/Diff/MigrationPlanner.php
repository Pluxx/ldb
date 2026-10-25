<?php

declare(strict_types=1);

namespace Ldb\Diff;

use Ldb\Db\DriverInterface;
use Ldb\Db\MySqlDriver;
use Ldb\Sql\SqlSplitter;

/**
 * Turns selected SchemaComparer changes into an ordered SQL script for the TARGET, using the
 * target driver's own DDL builders (buildCreateTable / buildAlterTable with an AlterSpec) and the
 * source's DDL text for views, routines, triggers and events (DEFINER stripped unless kept,
 * qualifiers moved to the target database/schema).
 *
 * Phases (in this order):
 *   drop_fk        foreign keys that are removed/changed, reference dropped tables, or use columns
 *                  that change type/are dropped/renamed (re-added in add_fk) — not on SQLite
 *   drop_trigger, drop_routine, drop_event, drop_view (removed/changed; on PostgreSQL also views
 *                  that depend on tables whose columns change, recreated afterwards)
 *   drop_check, drop_table, create_table, alter_table (one AlterSpec per table; SQLite rebuilds),
 *   add_index (raw index DDL: PostgreSQL, SQLite expression/partial indexes), add_check, add_fk,
 *   create_view (dependency order), create_routine, create_trigger, create_event
 *
 * The driver must have the target database/schema selected. buildAlterTable() of PostgreSQL
 * (modify / drop_index) and SQLite reads the target's current structure, so each table gets one
 * AlterSpec (SQLite: including its foreign keys).
 */
final class MigrationPlanner
{
    private const STRUCTURAL_FIELDS = ['type', 'collation', 'nullable', 'auto_increment', 'generated'];

    private DriverInterface $db;
    private string $driver;
    private SchemaSnapshot $src;
    private SchemaSnapshot $tgt;
    private array $opts;

    /** @var array<int,array<string,mixed>> */
    private array $out = [];
    /** @var array<int,array<string,mixed>> */
    private array $warnings = [];

    /** @var array<string,true> */
    private array $tablesAdded = [];
    /** @var array<string,true> */
    private array $tablesRemoved = [];
    /** @var array<string,array<string,mixed>> per-table buckets of selected changes */
    private array $per = [];
    /** @var array<string,array<string,array<string,array>>> object type => added|removed|changed => key => change */
    private array $objects = [];
    /** @var array<string,array<int,array>> foreign keys dropped only to let column changes through */
    private array $readdFks = [];

    public function __construct(DriverInterface $target, SchemaSnapshot $source, SchemaSnapshot $targetSnapshot, array $options = [])
    {
        $this->db = $target;
        $this->driver = $target::id();
        $this->src = $source;
        $this->tgt = $targetSnapshot;
        $this->opts = SchemaComparer::options($options) + ['exclude_destructive' => !empty($options['exclude_destructive'])];
    }

    /**
     * @param array<int,array<string,mixed>> $changes  from SchemaComparer::compare()
     * @param string[]|null $selected change ids to include (null = all)
     * @return array{statements:array<int,array{sql:string,phase:string,changes:string[],tx_control:bool,destructive:bool}>,
     *               sql:string[],warnings:array<int,array<string,mixed>>,included:string[],excluded:string[]}
     */
    public function plan(array $changes, ?array $selected = null): array
    {
        $this->out = [];
        $this->warnings = [];
        $sel = $selected === null ? null : array_flip(array_map('strval', $selected));
        $included = [];
        $excluded = [];
        foreach ($changes as $c) {
            $skip = ($sel !== null && !isset($sel[$c['id']])) || ($this->opts['exclude_destructive'] && !empty($c['destructive']));
            if ($skip) {
                $excluded[] = $c['id'];
                continue;
            }
            $included[] = $c;
        }
        $this->index($included);
        foreach ($included as $c) {
            $this->changeWarnings($c);
        }

        if ($this->driver !== 'sqlite') {
            $this->dropForeignKeys();
        }
        $this->dropObjects('trigger');
        $this->dropObjects('routine');
        $this->dropObjects('event');
        $recreateViews = $this->dropViews();
        if ($this->driver !== 'sqlite') {
            $this->dropChecks();
        }
        $this->dropTables();
        $this->createTables();
        $this->alterTables();
        if ($this->driver !== 'sqlite') {
            $this->addChecks();
            $this->addForeignKeys();
        }
        $this->createViews($recreateViews);
        $this->createObjects('routine');
        $this->createObjects('trigger');
        $this->createObjects('event');

        if ($this->driver === 'sqlite' && $this->out) {
            array_unshift($this->out, ['sql' => 'PRAGMA foreign_keys = OFF', 'phase' => 'setup', 'changes' => [], 'tx_control' => true, 'destructive' => false]);
            $this->out[] = ['sql' => 'PRAGMA foreign_keys = ON', 'phase' => 'setup', 'changes' => [], 'tx_control' => true, 'destructive' => false];
        }

        return [
            'statements' => $this->out,
            'sql' => array_map(static fn (array $s): string => $s['sql'], $this->out),
            'warnings' => $this->warnings,
            'included' => array_map(static fn (array $c): string => (string) $c['id'], $included),
            'excluded' => $excluded,
        ];
    }

    // ------------------------------------------------------------------ bookkeeping

    private function index(array $changes): void
    {
        $this->tablesAdded = [];
        $this->tablesRemoved = [];
        $this->per = [];
        $this->objects = [];
        foreach ($changes as $c) {
            $kind = (string) $c['kind'];
            $type = (string) $c['object_type'];
            if ($type !== 'table') {
                $action = substr($kind, (int) strrpos($kind, '_') + 1);
                $this->objects[$type][$action][(string) ($c['key'] ?? $c['object'])] = $c;
                continue;
            }
            $table = (string) $c['table'];
            if ($kind === 'table_added') {
                $this->tablesAdded[$table] = true;
                continue;
            }
            if ($kind === 'table_removed') {
                $this->tablesRemoved[$table] = true;
                continue;
            }
            $this->per[$table][$kind][] = $c;
        }
    }

    /** @return array<int,array> selected changes of a kind for a table */
    private function bucket(string $table, string $kind): array
    {
        return $this->per[$table][$kind] ?? [];
    }

    /** Old name => new name of selected renames in a table. */
    private function renames(string $table): array
    {
        $map = [];
        foreach ($this->bucket($table, 'column_renamed') as $c) {
            $map[(string) $c['before']['name']] = (string) $c['after']['name'];
        }
        return $map;
    }

    /** Target column names of a table whose definition changes in a way foreign keys care about. */
    private function affectedColumns(string $table): array
    {
        $cols = [];
        foreach ($this->bucket($table, 'column_changed') as $c) {
            if (array_intersect((array) $c['fields'], self::STRUCTURAL_FIELDS)) {
                $cols[(string) $c['object']] = true;
            }
        }
        foreach ($this->bucket($table, 'column_removed') as $c) {
            $cols[(string) $c['object']] = true;
        }
        foreach ($this->renames($table) as $old => $new) {
            $cols[$old] = true;
        }
        return $cols;
    }

    private function emit(string $phase, array $sqls, array $changeIds = [], bool $destructive = false): void
    {
        foreach ($sqls as $sql) {
            $sql = rtrim(trim((string) $sql), ';');
            if ($sql === '') {
                continue;
            }
            $tx = $this->driver === 'sqlite' && (preg_match('/^BEGIN\b.*ldb:rebuild/s', $sql) || preg_match('/^COMMIT$/i', $sql));
            if ($this->driver === 'sqlite' && preg_match('/^PRAGMA\s+foreign_keys\s*=\s*(ON|OFF)$/i', $sql)) {
                continue; // one OFF/ON pair around the whole plan instead
            }
            $this->out[] = ['sql' => $sql, 'phase' => $phase, 'changes' => array_values($changeIds), 'tx_control' => (bool) $tx, 'destructive' => $destructive];
        }
    }

    private function warn(string $code, array $params = [], ?string $change = null): void
    {
        $this->warnings[] = ['code' => $code, 'params' => $params, 'change' => $change];
    }

    private function changeWarnings(array $c): void
    {
        $id = (string) $c['id'];
        $table = (string) ($c['table'] ?? '');
        switch ($c['kind']) {
            case 'table_removed':
                $this->warn('drop_table', ['table' => $table], $id);
                return;
            case 'column_removed':
                $this->warn('drop_column', ['table' => $table, 'column' => $c['object']], $id);
                return;
            case 'column_changed':
                if (in_array('narrowing', (array) $c['warnings'], true)) {
                    $this->warn('narrowing', ['table' => $table, 'column' => $c['object'], 'from' => $c['before']['type'] ?? '', 'to' => $c['after']['type'] ?? ''], $id);
                }
                if (in_array('not_null', (array) $c['warnings'], true)) {
                    $rows = $c['null_rows'] ?? null;
                    if ($rows === null || $rows > 0) {
                        $this->warn($rows === null ? 'not_null' : 'not_null_nulls', ['table' => $table, 'column' => $c['object'], 'rows' => $rows], $id);
                    }
                }
                if ($this->driver === 'pgsql' && in_array('generated', (array) $c['fields'], true)) {
                    $this->warn('generated_unsupported', ['table' => $table, 'column' => $c['object']], $id);
                }
                return;
            case 'column_added':
                $col = $this->src->column($table, (string) $c['object']);
                if ($this->driver === 'pgsql' && $col !== null && Normalizer::generated($col) !== null) {
                    $this->warn('generated_unsupported', ['table' => $table, 'column' => $c['object']], $id);
                }
                return;
        }
        if (in_array('definition_missing', (array) ($c['warnings'] ?? []), true)) {
            $this->warn('definition_missing', ['object' => $c['object'], 'type' => $c['object_type']], $id);
        }
    }

    // ------------------------------------------------------------------ definitions

    /** ColumnDefinition for the target builders from a source column (ignored attributes kept from the target). */
    private function columnDef(array $c, ?array $current): array
    {
        $ignore = $this->opts['ignore'];
        $extra = (string) ($c['extra'] ?? '');
        $def = [
            'name' => (string) $c['name'],
            'type' => (string) $c['type'],
            'length' => $c['length'],
            'unsigned' => (bool) $c['unsigned'],
            'nullable' => (bool) $c['nullable'],
            'default_kind' => (string) $c['default_kind'],
            'default' => $c['default'],
            'auto_increment' => (bool) $c['auto_increment'],
            'comment' => $c['comment'],
            'collation' => $c['collation'],
            'generated' => $c['generated'],
            'extra' => $c['extra'],
        ];
        if ($this->driver === 'sqlite') {
            $full = (string) ($c['full_type'] ?? '');
            $def['type'] = $full === '' ? '' : trim((string) preg_replace('/\(.*$/s', '', $full));
            $hasKeyword = stripos($extra, 'AUTOINCREMENT') !== false;
            $def['nullable'] = (bool) $c['nullable'] || (!empty($c['auto_increment']) && !$hasKeyword);
            $def['auto_increment'] = $hasKeyword;
        }
        if ($ignore['collation']) {
            $def['collation'] = $current['collation'] ?? null;
        }
        if ($ignore['comments'] && $current !== null) {
            $def['comment'] = $current['comment'] ?? null;
        }
        if ($this->driver === 'mysql' && $def['collation'] !== null && !preg_match('/char|text|enum|set/i', $def['type'])) {
            $def['collation'] = null;
        }
        return $def;
    }

    /** Source foreign key adapted to the target namespace. */
    private function fkDef(array $fk, SchemaSnapshot $from): array
    {
        $own = $this->driver === 'mysql' ? $from->database() : $from->schema();
        $ref = $fk['ref_schema'] ?? null;
        if ($ref === null || $ref === '' || $ref === $own) {
            $ref = $this->driver === 'pgsql' ? $this->tgt->schema() : null;
        }
        return [
            'name' => $this->driver === 'sqlite' ? null : (string) $fk['name'],
            'columns' => array_values((array) $fk['columns']),
            'ref_schema' => $ref,
            'ref_table' => (string) $fk['ref_table'],
            'ref_columns' => array_values((array) $fk['ref_columns']),
            'on_delete' => $fk['on_delete'] ?? null,
            'on_update' => $fk['on_update'] ?? null,
        ];
    }

    private function findIndex(SchemaSnapshot $snap, string $table, string $name): ?array
    {
        foreach ($snap->table($table)['indexes'] ?? [] as $ix) {
            if ((string) $ix['name'] === $name && empty($ix['primary'])) {
                return $ix;
            }
        }
        return null;
    }

    private function findFk(SchemaSnapshot $snap, string $table, string $name): ?array
    {
        foreach ($snap->table($table)['foreign_keys'] ?? [] as $fk) {
            if ((string) $fk['name'] === $name) {
                return $fk;
            }
        }
        return null;
    }

    private function findCheck(SchemaSnapshot $snap, string $table, string $name): ?array
    {
        foreach ($snap->table($table)['checks'] ?? [] as $ck) {
            if ($ck['name'] === $name) {
                return $ck;
            }
        }
        return null;
    }

    /** Index for buildCreateTable/AlterSpec, or null when it must be created from its raw DDL. */
    private function indexDef(array $ix, string $table): ?array
    {
        if (!empty($ix['ddl']) && $this->driver !== 'mysql') {
            return null;
        }
        $auto = !empty($ix['auto_name']) || Normalizer::isAutoName((string) $ix['name'], $table, $this->driver, 'index');
        return [
            'name' => $auto && $this->driver === 'sqlite' ? null : (string) $ix['name'],
            'columns' => array_values((array) $ix['columns']),
            'unique' => (bool) $ix['unique'],
            'type' => $ix['type'] ?? null,
        ];
    }

    /** Raw index DDL from the source adapted to the target (PostgreSQL / SQLite complex indexes). */
    private function indexDdl(array $ix): array
    {
        $sql = Normalizer::retarget((string) $ix['ddl'], $this->driver, $this->src->database(), $this->src->schema(), $this->targetDatabase(), $this->targetSchema());
        return [$sql];
    }

    private function targetDatabase(): ?string
    {
        return $this->db->currentDatabase() ?? $this->tgt->database();
    }

    private function targetSchema(): ?string
    {
        return $this->tgt->schema() ?? $this->db->currentSchema();
    }

    /** Positions (after/first) for added and moved columns, following the source column order. */
    private function positions(string $table, array $added, array $moved): array
    {
        if ($this->opts['ignore']['column_order'] || !in_array($this->driver, ['mysql', 'sqlite'], true)) {
            return [];
        }
        $order = array_map(static fn (array $c): string => (string) $c['name'], $this->src->table($table)['columns'] ?? []);
        $isNew = array_flip($added);
        $lastOld = -1;
        foreach ($order as $i => $name) {
            if (!isset($isNew[$name])) {
                $lastOld = $i;
            }
        }
        $pos = [];
        foreach ($order as $i => $name) {
            if (isset($isNew[$name]) && $i > $lastOld && !$moved) {
                continue; // trailing new columns are appended in order anyway
            }
            if (isset($isNew[$name])) {
                $pos[$name] = $i === 0 ? ['first' => true] : ['after' => $order[$i - 1]];
            } elseif (isset($moved[$name])) {
                $prev = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (!isset($isNew[$order[$j]])) {
                        $prev = $order[$j];
                        break;
                    }
                }
                $pos[$name] = $prev === null ? ['first' => true] : ['after' => $prev];
            }
        }
        return $pos;
    }

    // ------------------------------------------------------------------ phases

    private function dropForeignKeys(): void
    {
        $readd = [];
        foreach ($this->tgt->tables() as $table => $t) {
            if (isset($this->tablesRemoved[$table])) {
                continue;
            }
            $removed = [];
            foreach ($this->bucket($table, 'fk_removed') as $c) {
                $removed[(string) $c['before']['name']] = (string) $c['id'];
            }
            foreach ($this->bucket($table, 'fk_changed') as $c) {
                $removed[(string) $c['before']['name']] = (string) $c['id'];
            }
            $affected = $this->affectedColumns($table);
            $droppedIndexCols = [];
            if ($this->driver === 'mysql') {
                foreach (array_merge($this->bucket($table, 'index_removed'), $this->bucket($table, 'index_changed')) as $c) {
                    $droppedIndexCols[] = array_values((array) ($c['before']['columns'] ?? []));
                }
            }
            $drop = [];
            $ids = [];
            foreach ($t['foreign_keys'] as $fk) {
                $name = (string) $fk['name'];
                if (isset($removed[$name])) {
                    $drop[] = $name;
                    $ids[] = $removed[$name];
                    continue;
                }
                $refTable = (string) $fk['ref_table'];
                $local = empty($fk['ref_schema']) || $fk['ref_schema'] === ($this->driver === 'mysql' ? $this->tgt->database() : $this->tgt->schema());
                if ($local && isset($this->tablesRemoved[$refTable]) && $refTable !== $table) {
                    $drop[] = $name;
                    $this->warn('fk_dropped', ['table' => $table, 'name' => $name, 'ref' => $refTable]);
                    continue;
                }
                $refAffected = $local ? $this->affectedColumns($refTable) : [];
                $hit = array_intersect_key(array_flip((array) $fk['columns']), $affected)
                    || array_intersect_key(array_flip((array) $fk['ref_columns']), $refAffected);
                if (!$hit) {
                    foreach ($droppedIndexCols as $cols) {
                        if (array_slice($cols, 0, count((array) $fk['columns'])) === array_values((array) $fk['columns'])) {
                            $hit = true;
                        }
                    }
                }
                if (!$hit) {
                    continue;
                }
                $drop[] = $name;
                $def = $this->recreatedFk($table, $fk);
                if ($def === null) {
                    $this->warn('fk_dropped', ['table' => $table, 'name' => $name, 'ref' => $refTable]);
                } else {
                    $readd[$table][] = $def;
                    $this->warn('fk_recreated', ['table' => $table, 'name' => $name]);
                }
            }
            if ($drop) {
                $this->emit('drop_fk', $this->db->buildAlterTable($table, ['drop_fk' => $drop]), $ids);
            }
        }
        $this->readdFks = $readd;
    }

    /** Target FK re-added after column changes (renames applied); null when a column is dropped. */
    private function recreatedFk(string $table, array $fk): ?array
    {
        $map = function (array $cols, string $t): ?array {
            $removed = [];
            foreach ($this->bucket($t, 'column_removed') as $c) {
                $removed[(string) $c['object']] = true;
            }
            $ren = $this->renames($t);
            $out = [];
            foreach ($cols as $c) {
                if (isset($removed[$c])) {
                    return null;
                }
                $out[] = $ren[$c] ?? $c;
            }
            return $out;
        };
        $cols = $map(array_values((array) $fk['columns']), $table);
        $refCols = $map(array_values((array) $fk['ref_columns']), (string) $fk['ref_table']);
        if ($cols === null || $refCols === null) {
            return null;
        }
        $def = $this->fkDef($fk, $this->tgt);
        $def['columns'] = $cols;
        $def['ref_columns'] = $refCols;
        return $def;
    }

    private function dropObjects(string $type): void
    {
        $removed = $this->objects[$type]['removed'] ?? [];
        // PostgreSQL routines are replaced in place (CREATE OR REPLACE): dropping would fail while triggers use them.
        $changed = ($type === 'routine' && $this->driver === 'pgsql') ? [] : ($this->objects[$type]['changed'] ?? []);
        foreach ([$removed, $changed] as $list) {
            foreach ($list as $key => $c) {
                $obj = $this->targetObject($type, (string) $key);
                if ($obj === null) {
                    continue;
                }
                if ($type === 'trigger' && isset($this->tablesRemoved[(string) $obj['table']]) && $this->driver === 'sqlite') {
                    continue;
                }
                $this->emit('drop_' . $type, [$this->dropSql($type, $obj)], [(string) $c['id']]);
            }
        }
    }

    private function targetObject(string $type, string $key): ?array
    {
        switch ($type) {
            case 'view':
                return $this->tgt->views()[$key] ?? null;
            case 'routine':
                return $this->tgt->routines()[$key] ?? null;
            case 'trigger':
                return $this->tgt->triggers()[$key] ?? null;
            case 'event':
                return $this->tgt->events()[$key] ?? null;
        }
        return null;
    }

    private function sourceObject(string $type, string $key): ?array
    {
        switch ($type) {
            case 'view':
                return $this->src->views()[$key] ?? null;
            case 'routine':
                return $this->src->routines()[$key] ?? null;
            case 'trigger':
                return $this->src->triggers()[$key] ?? null;
            case 'event':
                return $this->src->events()[$key] ?? null;
        }
        return null;
    }

    private function dropSql(string $type, array $obj): string
    {
        $db = $this->db;
        $name = (string) $obj['name'];
        switch ($type) {
            case 'view':
                $mat = $this->driver === 'pgsql' && preg_match('/^\s*CREATE\s+MATERIALIZED\s+VIEW/i', (string) ($obj['ddl'] ?? ''));
                return 'DROP ' . ($mat ? 'MATERIALIZED VIEW ' : 'VIEW ') . $db->quoteTable($name);
            case 'routine':
                $kind = strtoupper((string) $obj['type']) === 'PROCEDURE' ? 'PROCEDURE' : 'FUNCTION';
                if ($this->driver === 'pgsql') {
                    return 'DROP ' . $kind . ' ' . $db->quoteTable($name) . '(' . (string) ($obj['arguments'] ?? '') . ')';
                }
                return 'DROP ' . $kind . ' ' . $db->quoteTable($name);
            case 'trigger':
                if ($this->driver === 'pgsql') {
                    return 'DROP TRIGGER ' . $db->quoteIdentifier($name) . ' ON ' . $db->quoteTable((string) $obj['table']);
                }
                return 'DROP TRIGGER ' . ($this->driver === 'mysql' ? $db->quoteTable($name) : $db->quoteIdentifier($name));
            case 'event':
                return 'DROP EVENT ' . $db->quoteTable($name);
        }
        return '';
    }

    /**
     * Drop removed/changed views (+ PostgreSQL views depending on structurally changed tables,
     * and views depending on dropped views), dependents first.
     * @return array<string,string> view name => DDL to recreate (target version) for dependents
     */
    private function dropViews(): array
    {
        $views = $this->tgt->views();
        $drop = [];
        $ids = [];
        foreach (['removed', 'changed'] as $a) {
            foreach ($this->objects['view'][$a] ?? [] as $key => $c) {
                if (isset($views[$key])) {
                    $drop[$key] = true;
                    $ids[$key] = (string) $c['id'];
                }
            }
        }
        $recreate = [];
        if ($this->driver === 'pgsql') {
            $touched = $this->tablesRemoved;
            foreach ($this->per as $table => $b) {
                if ($this->affectedColumns($table)) {
                    $touched[$table] = true;
                }
            }
            $grow = true;
            while ($grow) {
                $grow = false;
                foreach ($views as $name => $v) {
                    if (isset($drop[$name]) || $v['ddl'] === null) {
                        continue;
                    }
                    $body = (string) preg_replace('/^.*?\bAS\b/is', '', (string) $v['ddl']);
                    foreach (array_merge(array_keys($touched), array_keys($drop)) as $dep) {
                        if (Normalizer::mentions($body, (string) $dep)) {
                            $drop[$name] = true;
                            $recreate[$name] = (string) $v['ddl'];
                            $this->warn('view_recreated', ['view' => $name]);
                            $grow = true;
                            break;
                        }
                    }
                }
            }
        }
        $names = array_map('strval', array_keys($drop));
        foreach (array_reverse($this->orderViews($names, $views)) as $name) {
            $this->emit('drop_view', [$this->dropSql('view', $views[$name])], isset($ids[$name]) ? [$ids[$name]] : []);
        }
        return $recreate;
    }

    /** Views ordered so that a view comes after the views its definition mentions. */
    private function orderViews(array $names, array $ddlByName): array
    {
        $deps = [];
        foreach ($names as $n) {
            $body = (string) preg_replace('/^.*?\bAS\b/is', '', (string) ($ddlByName[$n]['ddl'] ?? $ddlByName[$n] ?? ''));
            $deps[$n] = [];
            foreach ($names as $m) {
                if ($m !== $n && Normalizer::mentions($body, $m)) {
                    $deps[$n][] = $m;
                }
            }
        }
        $out = [];
        $state = [];
        $visit = function (string $n) use (&$visit, &$out, &$state, $deps): void {
            if (isset($state[$n])) {
                return; // done, or a cycle (keep input order)
            }
            $state[$n] = 1;
            foreach ($deps[$n] as $d) {
                $visit($d);
            }
            $out[] = $n;
        };
        foreach ($names as $n) {
            $visit($n);
        }
        return $out;
    }

    private function dropChecks(): void
    {
        foreach ($this->per as $table => $b) {
            foreach (array_merge($this->bucket($table, 'check_removed'), $this->bucket($table, 'check_changed')) as $c) {
                $name = (string) $c['before']['name'];
                $mariaDb = $this->db instanceof MySqlDriver && $this->db->isMariaDb();
                $verb = $this->driver === 'mysql' && !$mariaDb ? 'DROP CHECK ' : 'DROP CONSTRAINT ';
                $this->emit('drop_check', ['ALTER TABLE ' . $this->db->quoteTable($table) . ' ' . $verb . $this->db->quoteIdentifier($name)], [(string) $c['id']]);
            }
        }
    }

    private function dropTables(): void
    {
        $names = array_map('strval', array_keys($this->tablesRemoved));
        if ($this->driver === 'sqlite') {
            // referencing tables first
            $names = array_reverse($this->orderByForeignKeys($names, $this->tgt));
        }
        foreach ($names as $table) {
            $this->emit('drop_table', ['DROP TABLE ' . $this->db->quoteTable($table)], ['table_removed:' . $table . ':' . $table], true);
        }
    }

    /** Tables ordered so that referenced tables come first. */
    private function orderByForeignKeys(array $names, SchemaSnapshot $snap): array
    {
        $set = array_flip($names);
        $out = [];
        $state = [];
        $visit = function (string $n) use (&$visit, &$out, &$state, $set, $snap): void {
            if (isset($state[$n])) {
                return;
            }
            $state[$n] = 1;
            foreach ($snap->table($n)['foreign_keys'] ?? [] as $fk) {
                $r = (string) $fk['ref_table'];
                if ($r !== $n && isset($set[$r])) {
                    $visit($r);
                }
            }
            $out[] = $n;
        };
        foreach ($names as $n) {
            $visit($n);
        }
        return $out;
    }

    private function createTables(): void
    {
        $names = array_map('strval', array_keys($this->tablesAdded));
        $names = $this->orderByForeignKeys($names, $this->src);
        foreach ($names as $table) {
            $t = $this->src->table($table);
            if ($t === null) {
                continue;
            }
            $id = 'table_added:' . $table . ':' . $table;
            $columns = [];
            foreach ($t['columns'] as $c) {
                $columns[] = $this->columnDef($c, null);
                if ($this->driver === 'pgsql' && Normalizer::generated($c) !== null) {
                    $this->warn('generated_unsupported', ['table' => $table, 'column' => $c['name']], $id);
                }
            }
            $indexes = [];
            $raw = [];
            foreach ($t['indexes'] as $ix) {
                if (!empty($ix['primary'])) {
                    continue;
                }
                $d = $this->indexDef($ix, $table);
                if ($d === null) {
                    $raw[] = $ix;
                } else {
                    $indexes[] = $d;
                }
            }
            $ignore = $this->opts['ignore'];
            $def = [
                'name' => $table,
                'columns' => $columns,
                'primary_key' => $this->src->primaryKey($table),
                'indexes' => $indexes,
                'foreign_keys' => [],
                'options' => [
                    'engine' => $this->driver === 'mysql' ? ($t['options']['engine'] ?? null) : null,
                    'collation' => $this->driver === 'mysql' && !$ignore['collation'] ? ($t['options']['collation'] ?? null) : null,
                    'comment' => $t['options']['comment'] ?? null,
                ],
            ];
            if ($this->driver === 'sqlite') {
                foreach ($t['foreign_keys'] as $fk) {
                    $def['foreign_keys'][] = $this->fkDef($fk, $this->src);
                }
            }
            $this->emit('create_table', $this->db->buildCreateTable($def), [$id]);
            foreach ($raw as $ix) {
                $this->emit('add_index', $this->indexDdl($ix), [$id]);
            }
        }
    }

    private function alterTables(): void
    {
        foreach ($this->per as $table => $b) {
            if (isset($this->tablesAdded[$table]) || isset($this->tablesRemoved[$table]) || $this->tgt->table($table) === null) {
                continue;
            }
            $ids = [];
            $spec = [];
            $raw = [];
            $destructive = false;
            $collect = function (string $kind) use ($table, &$ids, &$destructive): array {
                $list = $this->bucket($table, $kind);
                foreach ($list as $c) {
                    $ids[] = (string) $c['id'];
                    $destructive = $destructive || !empty($c['destructive']);
                }
                return $list;
            };

            // indexes / primary key to drop
            foreach (array_merge($collect('index_removed'), $collect('index_changed')) as $c) {
                if ($c['object'] === 'PRIMARY' || !empty($c['before']['primary'])) {
                    $spec['drop_index'][] = $this->driver === 'pgsql' ? ($this->tgt->primaryIndexName($table) ?? 'PRIMARY') : 'PRIMARY';
                } else {
                    $spec['drop_index'][] = (string) $c['before']['name'];
                }
            }
            foreach ($collect('column_removed') as $c) {
                $spec['drop'][] = (string) $c['object'];
            }
            $added = array_map(static fn (array $c): string => (string) $c['object'], $collect('column_added'));
            $moved = [];
            $changed = $collect('column_changed');
            foreach ($changed as $c) {
                if (in_array('position', (array) $c['fields'], true)) {
                    $moved[(string) $c['object']] = true;
                }
            }
            $pos = $this->positions($table, $added, $moved);
            $modifyFirst = [];
            $modifyMoving = [];
            foreach ($collect('column_renamed') as $c) {
                $old = (string) $c['before']['name'];
                $new = (string) $c['after']['name'];
                $col = $this->src->column($table, $new);
                if ($col !== null) {
                    $modifyFirst[] = ['column' => $old, 'definition' => $this->columnDef($col, $this->tgt->column($table, $old))];
                }
            }
            foreach ($changed as $c) {
                $name = (string) $c['object'];
                $col = $this->src->column($table, $name);
                if ($col === null) {
                    continue;
                }
                $def = $this->columnDef($col, $this->tgt->column($table, $name));
                if (isset($pos[$name])) {
                    $modifyMoving[$name] = ['column' => $name, 'definition' => $def + $pos[$name]] + $pos[$name];
                } else {
                    $modifyFirst[] = ['column' => $name, 'definition' => $def];
                }
            }
            if ($modifyMoving) {
                // in source column order, so every AFTER refers to a column already in place
                $order = array_map(static fn (array $c): string => (string) $c['name'], $this->src->table($table)['columns']);
                $sorted = [];
                foreach ($order as $n) {
                    if (isset($modifyMoving[$n])) {
                        $sorted[] = $modifyMoving[$n];
                    }
                }
                $modifyFirst = array_merge($modifyFirst, $sorted);
            }
            if ($modifyFirst) {
                $spec['modify'] = $modifyFirst;
            }
            if ($added) {
                $order = array_map(static fn (array $c): string => (string) $c['name'], $this->src->table($table)['columns']);
                foreach ($order as $n) {
                    if (in_array($n, $added, true)) {
                        $col = $this->src->column($table, $n);
                        $spec['add'][] = $this->columnDef($col, null) + ($pos[$n] ?? []);
                    }
                }
            }
            foreach (array_merge($collect('index_added'), $this->bucket($table, 'index_changed')) as $c) {
                if ($c['object'] === 'PRIMARY' || !empty($c['after']['primary'])) {
                    $spec['add_index'][] = [
                        'primary' => true,
                        'columns' => $this->src->primaryKey($table),
                        'name' => $this->driver === 'pgsql' ? ($this->src->primaryIndexName($table) ?? null) : null,
                    ];
                    continue;
                }
                $ix = $this->findIndex($this->src, $table, (string) $c['after']['name']);
                if ($ix === null) {
                    continue;
                }
                $d = $this->indexDef($ix, $table);
                if ($d === null) {
                    $raw[] = $ix;
                } else {
                    $spec['add_index'][] = $d;
                }
            }
            foreach ($collect('option_changed') as $c) {
                foreach ((array) $c['fields'] as $f) {
                    $spec['options'][$f] = $c['after'][$f] ?? null;
                }
            }
            if ($this->driver === 'sqlite') {
                foreach (array_merge($collect('fk_removed'), $this->bucket($table, 'fk_changed')) as $c) {
                    $spec['drop_fk'][] = (string) $c['before']['name'];
                }
                foreach (array_merge($collect('fk_added'), $this->bucket($table, 'fk_changed')) as $c) {
                    $fk = $this->findFk($this->src, $table, (string) $c['after']['name']);
                    if ($fk !== null) {
                        $spec['add_fk'][] = $this->fkDef($fk, $this->src);
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            if ($spec) {
                $sqls = $this->db->buildAlterTable($table, $spec);
                foreach ($sqls as $s) {
                    if ($this->driver === 'sqlite' && strpos((string) $s, 'ldb:rebuild') !== false) {
                        $this->warn('sqlite_rebuild', ['table' => $table]);
                        break;
                    }
                }
                $this->emit('alter_table', $sqls, $ids, $destructive);
            }
            foreach ($raw as $ix) {
                $this->emit('add_index', $this->indexDdl($ix), $ids);
            }
        }
    }

    private function addChecks(): void
    {
        $add = function (string $table, array $ck, string $id): void {
            $this->emit('add_check', ['ALTER TABLE ' . $this->db->quoteTable($table) . ' ADD CONSTRAINT '
                . $this->db->quoteIdentifier((string) $ck['name']) . ' ' . trim((string) $ck['definition'])], [$id]);
        };
        foreach (array_keys($this->tablesAdded) as $table) {
            foreach ($this->src->table((string) $table)['checks'] ?? [] as $ck) {
                $add((string) $table, $ck, 'table_added:' . $table . ':' . $table);
            }
        }
        foreach ($this->per as $table => $b) {
            foreach (array_merge($this->bucket($table, 'check_added'), $this->bucket($table, 'check_changed')) as $c) {
                $ck = $this->findCheck($this->src, $table, (string) $c['after']['name']);
                if ($ck !== null) {
                    $add($table, $ck, (string) $c['id']);
                }
            }
        }
    }

    /** Whether a table exists once the plan ran (kept target table or created source table). */
    private function willExist(string $table): bool
    {
        return isset($this->tablesAdded[$table]) || ($this->tgt->table($table) !== null && !isset($this->tablesRemoved[$table]));
    }

    private function addForeignKeys(): void
    {
        $byTable = [];
        $push = function (string $table, array $def, ?string $id) use (&$byTable): void {
            $ref = $def['ref_schema'];
            $local = $this->driver === 'pgsql' ? ($ref === null || $ref === $this->targetSchema()) : ($ref === null || $ref === '');
            if ($local && !$this->willExist((string) $def['ref_table'])) {
                $this->warn('fk_missing_table', ['table' => $table, 'name' => $def['name'], 'ref' => $def['ref_table']], $id);
                return;
            }
            $byTable[$table]['defs'][] = $def;
            if ($id !== null) {
                $byTable[$table]['ids'][] = $id;
            }
        };
        foreach (array_keys($this->tablesAdded) as $table) {
            foreach ($this->src->table((string) $table)['foreign_keys'] ?? [] as $fk) {
                $push((string) $table, $this->fkDef($fk, $this->src), 'table_added:' . $table . ':' . $table);
            }
        }
        foreach ($this->readdFks as $table => $defs) {
            foreach ($defs as $def) {
                $push((string) $table, $def, null);
            }
        }
        foreach ($this->per as $table => $b) {
            foreach (array_merge($this->bucket($table, 'fk_added'), $this->bucket($table, 'fk_changed')) as $c) {
                $fk = $this->findFk($this->src, $table, (string) $c['after']['name']);
                if ($fk !== null) {
                    $push($table, $this->fkDef($fk, $this->src), (string) $c['id']);
                }
            }
        }
        foreach ($byTable as $table => $entry) {
            $this->emit('add_fk', $this->db->buildAlterTable((string) $table, ['add_fk' => $entry['defs']]), array_values(array_unique($entry['ids'] ?? [])));
        }
    }

    /** Source DDL adapted to the target: DEFINER stripped (unless kept), namespace moved, split into statements. */
    private function adaptDdl(string $ddl): array
    {
        $s = $ddl;
        if ($this->opts['ignore']['definer'] && $this->driver === 'mysql') {
            $s = Normalizer::stripDefiner($s);
        }
        $s = Normalizer::retarget($s, $this->driver, $this->src->database(), $this->src->schema(), $this->targetDatabase(), $this->targetSchema());
        return (new SqlSplitter($this->driver))->split($s);
    }

    private function createViews(array $recreate): void
    {
        $ddl = [];
        $ids = [];
        foreach (['added', 'changed'] as $a) {
            foreach ($this->objects['view'][$a] ?? [] as $key => $c) {
                $v = $this->sourceObject('view', (string) $key);
                if ($v === null || $v['ddl'] === null) {
                    continue;
                }
                $ddl[(string) $key] = ['ddl' => $v['ddl'], 'source' => true];
                $ids[(string) $key] = (string) $c['id'];
            }
        }
        foreach ($recreate as $name => $text) {
            if (!isset($ddl[$name])) {
                $ddl[(string) $name] = ['ddl' => $text, 'source' => false];
            }
        }
        $names = array_map('strval', array_keys($ddl));
        foreach ($this->orderViews($names, $ddl) as $name) {
            $e = $ddl[$name];
            $sqls = $e['source'] ? $this->adaptDdl($e['ddl']) : (new SqlSplitter($this->driver))->split($e['ddl']);
            $this->emit('create_view', $sqls, isset($ids[$name]) ? [$ids[$name]] : []);
        }
    }

    private function createObjects(string $type): void
    {
        foreach (['added', 'changed'] as $a) {
            foreach ($this->objects[$type][$a] ?? [] as $key => $c) {
                $o = $this->sourceObject($type, (string) $key);
                if ($o === null || $o['ddl'] === null) {
                    continue;
                }
                $sqls = $this->adaptDdl((string) $o['ddl']);
                $this->emit('create_' . $type, $sqls, [(string) $c['id']]);
            }
        }
    }
}
