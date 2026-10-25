<?php

declare(strict_types=1);

namespace Ldb\Diff;

use Ldb\Db\DriverInterface;
use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use PDO;

/**
 * Reads a SchemaSnapshot of the driver's currently selected database/schema through the
 * DriverInterface (listTables/tableInfo/createStatement/list*), plus a few catalog queries the
 * interface has no method for (CHECK constraints, PostgreSQL index definitions). All values are
 * bound; nothing is modified.
 */
final class SchemaReader
{
    private DriverInterface $db;

    public function __construct(DriverInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{tables?:bool,views?:bool,routines?:bool,triggers?:bool,events?:bool} $include
     */
    public function read(array $include = []): SchemaSnapshot
    {
        $inc = $include + ['tables' => true, 'views' => true, 'routines' => true, 'triggers' => true, 'events' => true];
        $db = $this->db;
        $driver = $db::id();
        $caps = $db->capabilities();
        $schema = !empty($caps['schemas']) ? ($db->currentSchema() ?? ($driver === 'pgsql' ? 'public' : null)) : null;
        $data = [
            'driver' => $driver,
            'database' => $db->currentDatabase(),
            'schema' => $schema,
            'tables' => [],
            'views' => [],
            'routines' => [],
            'triggers' => [],
            'events' => [],
        ];

        $tables = [];
        foreach ($db->listTables() as $t) {
            $name = (string) $t['name'];
            if (($t['type'] ?? 'table') === 'view') {
                if ($inc['views']) {
                    $data['views'][$name] = ['name' => $name, 'ddl' => $this->ddl($name, 'view')];
                }
            } elseif ($inc['tables'] && ($t['engine'] ?? null) !== 'foreign table') {
                $tables[] = $name;
            }
        }
        if ($tables) {
            $checks = $this->checks();
            $indexDdl = $driver === 'pgsql' ? $this->pgIndexDefs() : [];
            foreach ($tables as $name) {
                $info = $db->tableInfo($name);
                $indexes = [];
                foreach ($info['indexes'] ?? [] as $ix) {
                    if ($driver === 'pgsql') {
                        $ix['ddl'] = $indexDdl[$name][$ix['name']] ?? null;
                    } elseif ($driver === 'sqlite') {
                        $complex = !empty($ix['partial']) || in_array('(expression)', (array) $ix['columns'], true);
                        $ix['ddl'] = $complex && ($ix['origin'] ?? 'c') === 'c' ? ($ix['sql'] ?? null) : null;
                        $ix['auto_name'] = ($ix['origin'] ?? 'c') !== 'c';
                    }
                    unset($ix['sql'], $ix['origin'], $ix['partial']);
                    $indexes[] = $ix;
                }
                $data['tables'][$name] = [
                    'name' => $name,
                    'columns' => $info['columns'] ?? [],
                    'indexes' => $indexes,
                    'foreign_keys' => $info['foreign_keys'] ?? [],
                    'checks' => $checks[$name] ?? [],
                    'options' => $info['options'] ?? [],
                ];
            }
        }

        if ($inc['routines'] && !empty($caps['routines'])) {
            foreach ($this->optional(fn () => $db->listRoutines()) as $r) {
                $type = strtoupper((string) ($r['type'] ?? 'FUNCTION'));
                if ($driver === 'pgsql') {
                    $sig = (string) ($r['signature'] ?? $r['name'] . '(' . ($r['arguments'] ?? '') . ')');
                    $data['routines'][$type . ':' . $sig] = [
                        'name' => $r['name'], 'type' => $type, 'arguments' => (string) ($r['arguments'] ?? ''),
                        'ddl' => $this->ddl($sig, 'routine'),
                    ];
                } else {
                    $data['routines'][$type . ':' . $r['name']] = [
                        'name' => $r['name'], 'type' => $type, 'ddl' => $this->ddl((string) $r['name'], strtolower($type)),
                    ];
                }
            }
        }
        if ($inc['triggers'] && !empty($caps['triggers'])) {
            foreach ($this->optional(fn () => $db->listTriggers()) as $tr) {
                $ddl = $driver === 'pgsql' ? (string) $tr['statement'] : $this->ddl((string) $tr['name'], 'trigger');
                $data['triggers'][$tr['table'] . '.' . $tr['name']] = ['name' => $tr['name'], 'table' => $tr['table'], 'ddl' => $ddl];
            }
        }
        if ($inc['events'] && !empty($caps['events'])) {
            foreach ($this->optional(fn () => $db->listEvents()) as $e) {
                $data['events'][$e['name']] = ['name' => $e['name'], 'ddl' => $this->ddl((string) $e['name'], 'event')];
            }
        }
        return new SchemaSnapshot($data);
    }

    /** createStatement() or null when the definition is not readable (privileges, unsupported). */
    private function ddl(string $name, string $type): ?string
    {
        try {
            return $this->db->createStatement($name, $type);
        } catch (ApiException $e) {
            return null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    private function optional(callable $fn): array
    {
        try {
            return $fn();
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'unsupported') {
                return [];
            }
            throw $e;
        }
    }

    /** @return array<string,array<int,array{name:string,definition:string}>> table => checks */
    private function checks(): array
    {
        $db = $this->db;
        $out = [];
        try {
            switch ($db::id()) {
                case 'mysql':
                    $schema = (string) $db->currentDatabase();
                    if ($db instanceof MySqlDriver && !$db->capabilities()['check_constraints']) {
                        return []; // MySQL < 8.0.16 parses CHECK but neither enforces nor stores it
                    }
                    if ($db instanceof MySqlDriver && $db->isMariaDb()) {
                        if ($this->hasColumn('CHECK_CONSTRAINTS', 'LEVEL')) {
                            $sql = "SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause
                                    FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND LEVEL = 'Table'";
                        } else {
                            // MariaDB < 10.5.10 / 10.4.19 / 10.3.29 has no LEVEL column. Column-level
                            // constraints are named after their column there, which tells them apart.
                            $sql = "SELECT cc.TABLE_NAME AS tbl, cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause
                                    FROM information_schema.CHECK_CONSTRAINTS cc
                                    WHERE cc.CONSTRAINT_SCHEMA = ? AND NOT EXISTS (
                                        SELECT 1 FROM information_schema.COLUMNS col
                                        WHERE col.TABLE_SCHEMA = cc.CONSTRAINT_SCHEMA AND col.TABLE_NAME = cc.TABLE_NAME
                                          AND col.COLUMN_NAME = cc.CONSTRAINT_NAME)";
                        }
                    } else {
                        $sql = "SELECT tc.TABLE_NAME AS tbl, cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause
                                FROM information_schema.TABLE_CONSTRAINTS tc
                                JOIN information_schema.CHECK_CONSTRAINTS cc
                                  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                                WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.CONSTRAINT_TYPE = 'CHECK'";
                    }
                    foreach ($this->fetch($sql, [$schema]) as $r) {
                        $out[(string) $r['tbl']][] = ['name' => (string) $r['name'], 'definition' => 'CHECK (' . $r['clause'] . ')'];
                    }
                    break;
                case 'pgsql':
                    $sql = "SELECT c.relname AS tbl, con.conname AS name, pg_get_constraintdef(con.oid, true) AS def
                            FROM pg_constraint con
                            JOIN pg_class c ON c.oid = con.conrelid
                            JOIN pg_namespace n ON n.oid = c.relnamespace
                            WHERE con.contype = 'c' AND n.nspname = ?
                            ORDER BY c.relname, con.conname";
                    foreach ($this->fetch($sql, [(string) ($db->currentSchema() ?? 'public')]) as $r) {
                        $out[(string) $r['tbl']][] = ['name' => (string) $r['name'], 'definition' => (string) $r['def']];
                    }
                    break;
            }
        } catch (\PDOException $e) {
            return []; // engine without CHECK metadata (MySQL < 8.0.16)
        }
        return $out;
    }

    /** Whether an information_schema table has a column (differs between server versions). */
    private function hasColumn(string $table, string $column): bool
    {
        return $this->fetch(
            "SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        ) !== [];
    }

    /** @return array<string,array<string,string>> table => index name => CREATE INDEX … */
    private function pgIndexDefs(): array
    {
        $out = [];
        $sql = 'SELECT c.relname AS tbl, i.relname AS name, pg_get_indexdef(ix.indexrelid) AS def
                FROM pg_index ix
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_class c ON c.oid = ix.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = ?';
        foreach ($this->fetch($sql, [(string) ($this->db->currentSchema() ?? 'public')]) as $r) {
            $out[(string) $r['tbl']][(string) $r['name']] = (string) $r['def'];
        }
        return $out;
    }

    private function fetch(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }
}
