<?php

declare(strict_types=1);

namespace Ldb\Diff;

/**
 * Structure of one database/schema as plain arrays, the input of SchemaComparer and
 * MigrationPlanner. Built from a live driver by SchemaReader, or by hand in tests.
 *
 *   driver, database, schema
 *   tables:   name => {name, columns:[Column], indexes:[Index (+ddl?)], foreign_keys:[ForeignKey],
 *                      checks:[{name, definition}], options:{engine, collation, comment, auto_increment}}
 *   views:    name => {name, ddl}
 *   routines: key  => {name, type:PROCEDURE|FUNCTION, arguments?, ddl}   key = TYPE:name | signature
 *   triggers: key  => {name, table, ddl}                                  key = table.name
 *   events:   name => {name, ddl}
 * Column / Index / ForeignKey use the TableInfo shapes from ARCHITECTURE.md. A ddl of null means
 * the definition could not be read (privileges).
 */
final class SchemaSnapshot
{
    private string $driver;
    private ?string $database;
    private ?string $schema;
    /** @var array<string,array<string,mixed>> */
    private array $tables = [];
    /** @var array<string,array<string,mixed>> */
    private array $views = [];
    /** @var array<string,array<string,mixed>> */
    private array $routines = [];
    /** @var array<string,array<string,mixed>> */
    private array $triggers = [];
    /** @var array<string,array<string,mixed>> */
    private array $events = [];

    public function __construct(array $data)
    {
        $this->driver = (string) ($data['driver'] ?? 'mysql');
        $this->database = isset($data['database']) && $data['database'] !== '' ? (string) $data['database'] : null;
        $this->schema = isset($data['schema']) && $data['schema'] !== '' ? (string) $data['schema'] : null;
        foreach ((array) ($data['tables'] ?? []) as $key => $t) {
            $t = (array) $t;
            $name = (string) ($t['name'] ?? $key);
            $this->tables[$name] = self::buildTable($name, $t);
        }
        foreach ((array) ($data['views'] ?? []) as $key => $v) {
            $v = (array) $v;
            $name = (string) ($v['name'] ?? $key);
            $this->views[$name] = ['name' => $name, 'ddl' => isset($v['ddl']) ? (string) $v['ddl'] : null];
        }
        foreach ((array) ($data['routines'] ?? []) as $key => $r) {
            $r = (array) $r;
            $type = strtoupper((string) ($r['type'] ?? 'FUNCTION'));
            $name = (string) ($r['name'] ?? $key);
            $k = is_string($key) ? $key : $type . ':' . $name;
            $this->routines[$k] = [
                'name' => $name,
                'type' => $type,
                'arguments' => isset($r['arguments']) ? (string) $r['arguments'] : null,
                'ddl' => isset($r['ddl']) ? (string) $r['ddl'] : null,
            ];
        }
        foreach ((array) ($data['triggers'] ?? []) as $key => $tr) {
            $tr = (array) $tr;
            $name = (string) ($tr['name'] ?? $key);
            $table = (string) ($tr['table'] ?? '');
            $this->triggers[is_string($key) ? $key : $table . '.' . $name] = ['name' => $name, 'table' => $table, 'ddl' => isset($tr['ddl']) ? (string) $tr['ddl'] : null];
        }
        foreach ((array) ($data['events'] ?? []) as $key => $e) {
            $e = (array) $e;
            $name = (string) ($e['name'] ?? $key);
            $this->events[$name] = ['name' => $name, 'ddl' => isset($e['ddl']) ? (string) $e['ddl'] : null];
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    private static function buildTable(string $name, array $t): array
    {
        $columns = [];
        foreach ((array) ($t['columns'] ?? []) as $c) {
            $c = (array) $c;
            $columns[] = $c + [
                'name' => '', 'type' => '', 'full_type' => '', 'length' => null, 'unsigned' => false,
                'nullable' => false, 'default' => null, 'default_kind' => 'none', 'auto_increment' => false,
                'primary' => false, 'comment' => null, 'collation' => null, 'extra' => null, 'generated' => null,
            ];
        }
        $indexes = [];
        foreach ((array) ($t['indexes'] ?? []) as $ix) {
            $ix = (array) $ix;
            $indexes[] = $ix + ['name' => '', 'columns' => [], 'unique' => false, 'primary' => false, 'type' => null, 'ddl' => null];
        }
        $fks = [];
        foreach ((array) ($t['foreign_keys'] ?? []) as $fk) {
            $fk = (array) $fk;
            $fks[] = $fk + ['name' => '', 'columns' => [], 'ref_schema' => null, 'ref_table' => '', 'ref_columns' => [], 'on_delete' => null, 'on_update' => null];
        }
        $checks = [];
        foreach ((array) ($t['checks'] ?? []) as $ck) {
            $ck = (array) $ck;
            $checks[] = ['name' => (string) ($ck['name'] ?? ''), 'definition' => (string) ($ck['definition'] ?? '')];
        }
        $opts = (array) ($t['options'] ?? []);
        return [
            'name' => $name,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $fks,
            'checks' => $checks,
            'options' => [
                'engine' => $opts['engine'] ?? null,
                'collation' => $opts['collation'] ?? null,
                'comment' => isset($opts['comment']) && $opts['comment'] !== '' ? (string) $opts['comment'] : null,
                'auto_increment' => isset($opts['auto_increment']) ? (int) $opts['auto_increment'] : null,
            ],
        ];
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function database(): ?string
    {
        return $this->database;
    }

    public function schema(): ?string
    {
        return $this->schema;
    }

    /** @return array<string,array<string,mixed>> */
    public function tables(): array
    {
        return $this->tables;
    }

    public function table(string $name): ?array
    {
        return $this->tables[$name] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function views(): array
    {
        return $this->views;
    }

    /** @return array<string,array<string,mixed>> */
    public function routines(): array
    {
        return $this->routines;
    }

    /** @return array<string,array<string,mixed>> */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /** @return array<string,array<string,mixed>> */
    public function events(): array
    {
        return $this->events;
    }

    /** Column of a table by name, or null. */
    public function column(string $table, string $column): ?array
    {
        foreach ($this->tables[$table]['columns'] ?? [] as $c) {
            if ($c['name'] === $column) {
                return $c;
            }
        }
        return null;
    }

    /** Primary key columns of a table (from the primary index, else the columns' primary flag). */
    public function primaryKey(string $table): array
    {
        $t = $this->tables[$table] ?? null;
        if ($t === null) {
            return [];
        }
        foreach ($t['indexes'] as $ix) {
            if (!empty($ix['primary'])) {
                return array_values(array_map('strval', (array) $ix['columns']));
            }
        }
        $pk = [];
        foreach ($t['columns'] as $c) {
            if (!empty($c['primary'])) {
                $pk[] = (string) $c['name'];
            }
        }
        return $pk;
    }

    /** Name of the primary index of a table ("PRIMARY", "t_pkey", …) or null. */
    public function primaryIndexName(string $table): ?string
    {
        foreach ($this->tables[$table]['indexes'] ?? [] as $ix) {
            if (!empty($ix['primary'])) {
                return (string) $ix['name'];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'database' => $this->database,
            'schema' => $this->schema,
            'tables' => $this->tables,
            'views' => $this->views,
            'routines' => $this->routines,
            'triggers' => $this->triggers,
            'events' => $this->events,
        ];
    }
}
