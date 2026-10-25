<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Db\AbstractPdoDriver;
use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;

final class TableController extends AbstractController
{
    private const MAINTENANCE_OPS = ['optimize', 'analyze', 'check', 'repair', 'vacuum'];
    private const MAX_BATCH_CHANGES = 1000;
    private const DDL_TYPES = ['table', 'view', 'routine', 'function', 'procedure', 'trigger', 'event'];

    /** @return array<string,mixed> TableInfo */
    public function info(): array
    {
        return $this->dbWithDatabase()->tableInfo($this->table());
    }

    /** @return array{sql:string} */
    public function ddl(): array
    {
        $type = (string) $this->request->param('type', 'table');
        if (!in_array($type, self::DDL_TYPES, true)) {
            throw ApiException::badRequest('Invalid object type');
        }
        return ['sql' => $this->dbWithDatabase()->createStatement($this->table(), $type)];
    }

    /** @return array<string,mixed> BrowseResult */
    public function browse(): array
    {
        $db = $this->dbWithDatabase();
        $max = max(1, $this->config->int('max_rows_browse', 1000));
        $opts = [
            'page' => max(1, $this->request->int('page', 1)),
            'per_page' => min($max, max(1, $this->request->int('per_page', min(50, $max)))),
            'order' => $this->request->arr('order'),
            'filters' => $this->request->arr('filters'),
            'search' => $this->request->optString('search'),
        ];
        return $db->browse($this->table(), $opts);
    }

    /** @return array{affected:int,insert_id:?string} */
    public function insert(): array
    {
        $db = $this->dbWithDatabase();
        return $db->insertRow($this->table(), $this->request->arr('values', true));
    }

    /** @return array{affected:int} */
    public function update(): array
    {
        $db = $this->dbWithDatabase();
        $key = $this->request->arr('key', true);
        if ($key === []) {
            throw ApiException::badRequest('Empty row key');
        }
        return ['affected' => $db->updateRow($this->table(), $key, $this->request->arr('values', true))];
    }

    /** @return array{affected:int} */
    public function delete(): array
    {
        $db = $this->dbWithDatabase();
        $keys = array_values(array_filter($this->request->arr('keys', true), static fn ($k): bool => is_array($k) && $k !== []));
        if ($keys === []) {
            throw ApiException::badRequest('No rows given');
        }
        return ['affected' => $db->deleteRows($this->table(), $keys)];
    }

    /**
     * Batched grid edits: [{op:update,key,values}|{op:insert,values}|{op:delete,key}] applied in
     * one transaction (all or nothing). preview=true only renders the display SQL.
     * @return array{sql:string[],affected:int[],insert_ids:array,executed:bool}
     */
    public function applyChanges(): array
    {
        $db = $this->dbWithDatabase();
        $changes = array_values($this->request->arr('changes', true));
        if ($changes === []) {
            throw ApiException::badRequest('No changes given');
        }
        if (count($changes) > self::MAX_BATCH_CHANGES) {
            throw ApiException::badRequest('Too many changes in one batch (max ' . self::MAX_BATCH_CHANGES . ')');
        }
        if (!$db instanceof AbstractPdoDriver) {
            throw ApiException::unsupported();
        }
        return $db->applyRowChanges($this->table(), $changes, $this->request->bool('preview'));
    }

    /** @return array{sql:string[],executed:bool} */
    public function create(): array
    {
        $db = $this->dbWithDatabase();
        $sqls = $db->buildCreateTable($this->request->arr('definition', true));
        $preview = $this->request->bool('preview');
        if (!$preview) {
            $this->runStatements($db, $sqls);
        }
        return ['sql' => $sqls, 'executed' => !$preview];
    }

    /** @return array{sql:string[],executed:bool} */
    public function alter(): array
    {
        $db = $this->dbWithDatabase();
        $sqls = $db->buildAlterTable($this->table(), $this->request->arr('changes', true));
        $preview = $this->request->bool('preview');
        if (!$preview) {
            $this->runStatements($db, $sqls);
        }
        return ['sql' => $sqls, 'executed' => !$preview];
    }

    /** @return array{affected:int} */
    public function drop(): array
    {
        $db = $this->dbWithDatabase();
        $types = [];
        foreach ($db->listTables() as $t) {
            $types[(string) $t['name']] = $t['type'] ?? 'table';
        }
        $n = 0;
        foreach ($this->tablesParam() as $table) {
            if (!isset($types[$table])) {
                throw ApiException::notFound('Table not found: ' . $table);
            }
            $db->dropTable($table, $types[$table] === 'view');
            $n++;
        }
        return ['affected' => $n];
    }

    /** @return array{affected:int} */
    public function truncate(): array
    {
        $db = $this->dbWithDatabase();
        $n = 0;
        foreach ($this->tablesParam() as $table) {
            $db->truncateTable($table);
            $n++;
        }
        return ['affected' => $n];
    }

    /** DELETE all rows (keeps auto-increment counters, fires triggers). @return array{affected:int} */
    public function empty(): array
    {
        $db = $this->dbWithDatabase();
        $affected = 0;
        foreach ($this->tablesParam() as $table) {
            $sql = 'DELETE FROM ' . $db->quoteTable($table);
            try {
                $affected += (int) $db->pdo()->exec($sql);
            } catch (\PDOException $e) {
                throw ApiException::dbError($e->getMessage(), $sql, $e);
            }
        }
        return ['affected' => $affected];
    }

    public function rename(): bool
    {
        $db = $this->dbWithDatabase();
        $this->requireCapability($db, 'rename_table');
        $db->renameTable($this->table(), $this->request->string('new_name'));
        return true;
    }

    public function copy(): bool
    {
        $db = $this->dbWithDatabase();
        $this->requireCapability($db, 'copy_table');
        $db->copyTable(
            $this->table(),
            $this->request->string('new_name'),
            $this->request->bool('with_data', true),
            $this->request->optString('target_db')
        );
        return true;
    }

    /** @return array{columns:array,rows:array} */
    public function maintenance(): array
    {
        $db = $this->dbWithDatabase();
        $this->requireCapability($db, 'maintenance');
        $op = (string) $this->request->param('op', '');
        if (!in_array($op, self::MAINTENANCE_OPS, true)) {
            throw ApiException::badRequest('Invalid maintenance operation');
        }
        $tables = $this->stringList('tables', false);
        if ($tables === [] && $this->request->optString('table') !== null) {
            $tables = [$this->table()];
        }
        if ($tables === [] && $op !== 'vacuum') {
            throw ApiException::badRequest('Missing parameter: tables');
        }
        return $db->maintenance($tables, $op);
    }

    /**
     * Values for a foreign-key dropdown: referenced key plus a descriptive label column.
     * @return array<int,array{value:mixed,label:?string}>
     */
    public function fkValues(): array
    {
        $db = $this->dbWithDatabase();
        $column = $this->request->string('column');
        $info = $db->tableInfo($this->table());

        $fk = null;
        foreach ($info['foreign_keys'] ?? [] as $candidate) {
            $idx = array_search($column, $candidate['columns'] ?? [], true);
            if ($idx !== false) {
                $fk = $candidate;
                $refColumn = (string) $candidate['ref_columns'][$idx];
                break;
            }
        }
        if ($fk === null || !isset($refColumn)) {
            throw ApiException::notFound('Column has no foreign key');
        }

        $refTable = (string) $fk['ref_table'];
        $refSchema = $fk['ref_schema'] ?? null;
        $from = ($refSchema !== null && $refSchema !== '' && $refSchema !== $db->currentSchema())
            ? $db->quoteIdentifier((string) $refSchema) . '.' . $db->quoteIdentifier($refTable)
            : $db->quoteTable($refTable);

        // Pick the first text-like column of the referenced table as label.
        $labelColumn = null;
        try {
            $refInfo = ($refSchema === null || $refSchema === '' || $refSchema === $db->currentSchema())
                ? $db->tableInfo($refTable) : null;
            foreach ($refInfo['columns'] ?? [] as $c) {
                if ($c['name'] !== $refColumn && preg_match('/char|text|string/i', (string) $c['type'])) {
                    $labelColumn = (string) $c['name'];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $labelColumn = null;
        }

        $valueExpr = $db->quoteIdentifier($refColumn);
        $select = $valueExpr . ' AS ' . $db->quoteIdentifier('value');
        $select .= $labelColumn !== null
            ? ', ' . $db->quoteIdentifier($labelColumn) . ' AS ' . $db->quoteIdentifier('label')
            : '';
        $where = '';
        $params = [];
        $term = $this->request->optString('term');
        if ($term !== null) {
            $like = $db::id() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $conds = [self::textExpr($db, $valueExpr) . " $like ? ESCAPE '!'"];
            $params[] = '%' . self::likeEscape($term) . '%';
            if ($labelColumn !== null) {
                $conds[] = self::textExpr($db, $db->quoteIdentifier($labelColumn)) . " $like ? ESCAPE '!'";
                $params[] = '%' . self::likeEscape($term) . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $conds);
        }
        $sql = $this->limited($db, $select . ' FROM ' . $from . $where . ' ORDER BY ' . $valueExpr, 100);

        try {
            $stmt = $db->pdo()->prepare($sql);
            $stmt->execute($params);
            $out = [];
            while (count($out) < 100 && ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $out[] = [
                    'value' => $db->normalizeValue($row['value']),
                    'label' => isset($row['label']) ? self::preview((string) $row['label'], 100) : null,
                ];
            }
            $stmt->closeCursor();
        } catch (\PDOException $e) {
            throw ApiException::dbError($e->getMessage(), $sql, $e);
        }
        return $out;
    }

    /** @return string[] */
    private function tablesParam(): array
    {
        $tables = $this->stringList('tables', false);
        if ($tables === [] && $this->request->optString('table') !== null) {
            $tables = [$this->table()];
        }
        if ($tables === []) {
            throw ApiException::badRequest('Missing parameter: tables');
        }
        return $tables;
    }

    /** Add a row limit using the engine's syntax. $sql must start with the select list (no SELECT). */
    private function limited(DriverInterface $db, string $sql, int $limit): string
    {
        if ($db::id() === 'sqlsrv') {
            return 'SELECT TOP ' . $limit . ' ' . $sql;
        }
        return 'SELECT ' . $sql . ' LIMIT ' . $limit;
    }
}
