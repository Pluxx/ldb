<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;

final class DatabaseController extends AbstractController
{
    /** Column types considered searchable text. */
    private const TEXT_TYPE_PATTERN = '/char|text|clob|string|uuid|json|enum|set\b|xml|citext|name|varchar|nvarchar/i';

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        return $this->db(false)->listDatabases();
    }

    public function create(): bool
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'create_database');
        $db->createDatabase($this->request->string('name'), $this->request->optString('collation'));
        return true;
    }

    public function drop(): bool
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'create_database');
        $db->dropDatabase($this->request->string('name'));
        return true;
    }

    /**
     * db.info: database-level settings (DriverInterface::databaseInfo) plus `login_database`
     * (the connection logs in to this database, so it cannot be renamed/moved from here).
     * @return array<string,mixed>
     */
    public function info(): array
    {
        $name = $this->request->string('db');
        $db = $this->dbWithDatabase();
        $info = $db->databaseInfo($name);
        $info['login_database'] = $this->isLoginDatabase($name);
        return $info;
    }

    /**
     * db.alter: `changes` = DatabaseChanges (per driver, see ARCHITECTURE.md); `preview: true`
     * only renders the SQL. @return array{sql:string[],warnings:string[],executed:bool}
     */
    public function alter(): array
    {
        $name = $this->request->string('db');
        $db = $this->db(false);
        $this->requireCapability($db, 'db_alter');
        $changes = $this->request->arr('changes', true);
        if (isset($changes['rename']) && $changes['rename'] !== null && $changes['rename'] !== '' && $changes['rename'] !== $name) {
            $this->requireCapability($db, 'db_rename');
            $this->refuseLoginDatabase($name);
        }
        $preview = $this->request->bool('preview');
        if ($preview) {
            $plan = $db->buildAlterDatabase($name, $changes);
        } else {
            @set_time_limit(0);
            $plan = $db->alterDatabase($name, $changes);
        }
        return ['sql' => $plan['sql'], 'warnings' => $plan['warnings'], 'executed' => !$preview];
    }

    /**
     * db.copy: copy `db` to `target` (structure, data, objects best effort); `drop_source`
     * drops the source afterwards (MySQL "rename"); `preview: true` returns the plan.
     */
    public function copy(): array
    {
        $name = $this->request->string('db');
        $target = trim($this->request->string('target'));
        $db = $this->db(false);
        $this->requireCapability($db, 'db_copy');
        $drop = $this->request->bool('drop_source');
        if ($drop) {
            $this->requireCapability($db, 'db_rename');
            $this->refuseLoginDatabase($name);
        }
        $preview = $this->request->bool('preview');
        if (!$preview) {
            @set_time_limit(0);
        }
        return $db->copyDatabase($name, $target, ['preview' => $preview, 'drop_source' => $drop]);
    }

    /** True when the request's connection was opened with $name as its login database. */
    private function isLoginDatabase(string $name): bool
    {
        $params = $this->session->connectionParams($this->connId()) ?? [];
        $login = isset($params['database']) && is_string($params['database']) ? $params['database'] : '';
        return $login !== '' && $login === $name;
    }

    private function refuseLoginDatabase(string $name): void
    {
        if ($this->isLoginDatabase($name)) {
            throw ApiException::badRequest(
                'This connection logs in to database "' . $name . '", so it cannot be renamed from this connection. '
                . 'Log in without a database (or to another one) to rename it.'
            );
        }
    }

    /** @return string[] */
    public function schemas(): array
    {
        $db = $this->dbWithDatabase();
        $this->requireCapability($db, 'schemas');
        return $db->listSchemas();
    }

    /** @return array<string,array> */
    public function objects(): array
    {
        $db = $this->dbWithDatabase();
        $caps = $db->capabilities();
        $tables = [];
        $views = [];
        foreach ($db->listTables() as $t) {
            if (($t['type'] ?? 'table') === 'view') {
                $views[] = $t;
            } else {
                $tables[] = $t;
            }
        }
        return [
            'tables' => $tables,
            'views' => $views,
            'routines' => !empty($caps['routines']) ? $this->optional(fn () => $db->listRoutines()) : [],
            'triggers' => !empty($caps['triggers']) ? $this->optional(fn () => $db->listTriggers()) : [],
            'events' => !empty($caps['events']) ? $this->optional(fn () => $db->listEvents()) : [],
        ];
    }

    /**
     * Full-database search: counts matches per text column with one prepared query per table.
     * @return array<int,array{table:string,column_matches:array<string,int>,count:int}>
     */
    public function search(): array
    {
        $db = $this->dbWithDatabase();
        $term = (string) $this->request->param('term', '');
        if ($term === '') {
            throw ApiException::badRequest('Missing parameter: term');
        }
        $mode = (string) $this->request->param('mode', 'contains');
        if (!in_array($mode, ['contains', 'exact', 'regex'], true)) {
            throw ApiException::badRequest('Invalid search mode');
        }
        [$operator, $value] = $this->searchOperator($db, $mode, $term);

        $tables = $this->stringList('tables', false);
        if ($tables === []) {
            foreach ($db->listTables() as $t) {
                if (($t['type'] ?? 'table') === 'table') {
                    $tables[] = (string) $t['name'];
                }
            }
        }

        $results = [];
        foreach ($tables as $table) {
            $info = $db->tableInfo($table);
            $columns = [];
            foreach ($info['columns'] ?? [] as $col) {
                if (preg_match(self::TEXT_TYPE_PATTERN, (string) ($col['type'] ?? '') . ' ' . ($col['full_type'] ?? ''))) {
                    $columns[] = (string) $col['name'];
                }
            }
            if ($columns === []) {
                continue;
            }

            $conds = [];
            $sums = [];
            foreach ($columns as $i => $col) {
                $cond = sprintf($operator, self::textExpr($db, $db->quoteIdentifier($col)));
                $conds[] = $cond;
                $sums[] = 'SUM(CASE WHEN ' . $cond . ' THEN 1 ELSE 0 END) AS ' . $db->quoteIdentifier('m' . $i);
            }
            $sql = 'SELECT COUNT(*) AS ' . $db->quoteIdentifier('total') . ', ' . implode(', ', $sums)
                . ' FROM ' . $db->quoteTable($table) . ' WHERE ' . implode(' OR ', $conds);
            $params = array_fill(0, count($columns) * 2, $value);

            try {
                $stmt = $db->pdo()->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                $stmt->closeCursor();
            } catch (\PDOException $e) {
                throw ApiException::dbError($e->getMessage(), $sql, $e);
            }
            $count = (int) ($row['total'] ?? 0);
            if ($count === 0) {
                continue;
            }
            $matches = [];
            foreach ($columns as $i => $col) {
                $n = (int) ($row['m' . $i] ?? 0);
                if ($n > 0) {
                    $matches[$col] = $n;
                }
            }
            $results[] = ['table' => $table, 'column_matches' => (object) $matches, 'count' => $count];
        }
        return $results;
    }

    /** @return array{tables:array,relations:array} */
    public function erd(): array
    {
        $db = $this->dbWithDatabase();
        $tables = [];
        $relations = [];
        foreach ($db->listTables() as $t) {
            if (($t['type'] ?? 'table') !== 'table') {
                continue;
            }
            $info = $db->tableInfo((string) $t['name']);
            $columns = [];
            foreach ($info['columns'] ?? [] as $c) {
                $columns[] = [
                    'name' => $c['name'],
                    'type' => $c['full_type'] ?? $c['type'],
                    'primary' => (bool) ($c['primary'] ?? false),
                    'nullable' => (bool) ($c['nullable'] ?? false),
                ];
            }
            $tables[] = ['name' => (string) $t['name'], 'columns' => $columns];
            foreach ($info['foreign_keys'] ?? [] as $fk) {
                $relations[] = [
                    'from_table' => (string) $t['name'],
                    'from_columns' => $fk['columns'],
                    'to_table' => $fk['ref_table'],
                    'to_columns' => $fk['ref_columns'],
                    'name' => $fk['name'],
                    'on_delete' => $fk['on_delete'] ?? null,
                    'on_update' => $fk['on_update'] ?? null,
                ];
            }
        }
        return ['tables' => $tables, 'relations' => $relations];
    }

    /** @return array{0:string,1:string} sprintf pattern for the condition and the bound value */
    private function searchOperator(DriverInterface $db, string $mode, string $term): array
    {
        $driver = $db::id();
        if ($mode === 'exact') {
            return ['%s = ?', $term];
        }
        if ($mode === 'regex') {
            switch ($driver) {
                case 'mysql':
                    return ['%s REGEXP ?', $term];
                case 'pgsql':
                    return ['%s ~* ?', $term];
                default:
                    throw ApiException::unsupported('Regular expression search is not supported by this driver');
            }
        }
        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        return ["%s $like ? ESCAPE '!'", '%' . self::likeEscape($term) . '%'];
    }

    /** Swallow 'unsupported' errors for optional object lists. */
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
}
