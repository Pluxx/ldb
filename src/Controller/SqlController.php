<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;
use Ldb\Sql\ReadOnlyGuard;
use Ldb\Sql\RunRegistry;
use Ldb\Sql\ScriptRunner;
use Ldb\Sql\SqlFormatter;
use Ldb\Sql\SqlSplitter;
use Ldb\Sql\TransactionPolicy;

final class SqlController extends AbstractController
{
    private const MAX_LIMIT = 100000;

    /**
     * Run a script. Optional `run_id` makes it cancellable via sql.cancel; `tx` = none (auto-commit,
     * default) | atomic (COMMIT only if every statement succeeds, else ROLLBACK; stops on the
     * first error) | dryrun (always ROLLBACK). One request = one connection, so a transaction
     * never spans requests.
     * @return array{results:array<int,array<string,mixed>>,tx:string,rolled_back:bool,cancelled:bool,warnings:string[]}
     */
    public function execute(): array
    {
        $db = $this->db();
        $sql = (string) $this->request->param('sql', '');
        if (trim($sql) === '') {
            throw ApiException::badRequest('Missing parameter: sql');
        }
        $limit = min(self::MAX_LIMIT, max(1, $this->request->int('limit', 1000)));
        $stopOnError = $this->request->bool('stop_on_error', true);
        $tx = $this->txMode();
        $runId = $this->runId();
        @set_time_limit(0);

        $statements = (new SqlSplitter($db::id()))->split($sql);
        $readOnly = $this->isReadOnly();
        if ($readOnly) {
            // Reject the whole batch up front: nothing runs when any statement is not read-only.
            $guard = new ReadOnlyGuard($db::id());
            foreach ($statements as $statement) {
                $guard->assertReadOnly($statement);
            }
        }
        $run = $this->startRun($db, $runId);
        $runner = new ScriptRunner($db, function () use ($run): bool {
            return $this->runCancelled($run);
        });
        try {
            return $runner->run($statements, [
                'limit' => $limit,
                'stop_on_error' => $stopOnError,
                'tx' => $tx,
                'read_only' => $readOnly,
                'on_result' => function (array $result, string $statement) use ($db): array {
                    if (!empty($result['is_result_set']) && $result['editable'] === null) {
                        $result['editable'] = $this->detectEditable($db, $statement, $result['columns'] ?? []);
                    }
                    return $result;
                },
            ]);
        } finally {
            $this->endRun($run);
        }
    }

    /** @return array<string,mixed> QueryResult */
    public function explain(): array
    {
        $db = $this->db();
        $this->requireCapability($db, 'explain');
        $sql = trim($this->request->string('sql'));
        $runId = $this->runId();
        $statements = (new SqlSplitter($db::id()))->split($sql);
        $sql = $statements[0] ?? $sql;
        if ($this->isReadOnly()) {
            (new ReadOnlyGuard($db::id()))->assertReadOnly($sql);
            $db->setReadOnly(true);
        }
        $run = $this->startRun($db, $runId);
        $start = microtime(true);
        try {
            $result = $db->explain($sql);
        } catch (\PDOException $e) {
            if ((new ScriptRunner($db, fn (): bool => $this->runCancelled($run)))->isCancellation($e)) {
                throw new ApiException('Query cancelled', 'cancelled', 409, $sql, $e);
            }
            throw ApiException::dbError($e->getMessage(), $sql, $e);
        } finally {
            $this->endRun($run);
        }
        $result['sql'] = $sql;
        $result['time_ms'] = self::elapsedMs($start);
        return $result;
    }

    /**
     * Cancel a running sql.execute/sql.explain of THIS session (registry entries are keyed by
     * session id + run id). Opens a second connection with the same stored credentials and
     * interrupts the backend (DriverInterface::cancelBackend). Allowed on read-only connections:
     * it only stops the user's own query.
     * @return array{cancelled:bool,found:bool}
     */
    public function cancel(): array
    {
        $runId = $this->runId();
        if ($runId === null) {
            throw ApiException::badRequest('Missing parameter: run_id');
        }
        $conn = $this->connId();
        $params = $this->session->connectionParams($conn);
        if ($params === null) {
            throw ApiException::authRequired('Unknown or expired connection');
        }
        $registry = $this->runRegistry();
        $sid = $this->session->id();
        $entry = $registry->lookup($sid, $runId);
        if ($entry === null || $entry['conn'] !== $conn || $entry['driver'] !== (string) ($params['driver'] ?? '')) {
            return ['cancelled' => false, 'found' => false]; // not started yet, finished, or not ours
        }
        // Flag first, so the running request reports the resulting error as a cancel.
        $registry->markCancelled($sid, $runId);
        $db = $this->factory->connect($params);
        return ['cancelled' => $db->cancelBackend($entry['backend']), 'found' => true];
    }

    private function txMode(): string
    {
        $tx = $this->request->optString('tx') ?? TransactionPolicy::NONE;
        if (!TransactionPolicy::isMode($tx)) {
            throw ApiException::badRequest('Invalid parameter: tx (none, atomic or dryrun)');
        }
        return $tx;
    }

    private function runId(): ?string
    {
        $runId = $this->request->optString('run_id');
        if ($runId === null || $runId === '') {
            return null;
        }
        if (!RunRegistry::isValidRunId($runId)) {
            throw ApiException::badRequest('Invalid parameter: run_id');
        }
        return $runId;
    }

    private function runRegistry(): RunRegistry
    {
        $dir = $this->config->get('run_registry_dir');
        return new RunRegistry(is_string($dir) && $dir !== '' ? $dir : null);
    }

    /**
     * Register the backend running this request's statements (no-op without run_id or when
     * the engine cannot cancel). @return array{0:RunRegistry,1:string,2:string}|null
     */
    private function startRun(DriverInterface $db, ?string $runId): ?array
    {
        if ($runId === null) {
            return null;
        }
        try {
            $backend = $db->backendId();
        } catch (\Throwable $e) {
            $backend = null;
        }
        if ($backend === null || $backend === '') {
            return null;
        }
        $registry = $this->runRegistry();
        $sid = $this->session->id();
        $ok = $registry->register($sid, $runId, ['driver' => $db::id(), 'backend' => $backend, 'conn' => $this->connId()]);
        return $ok ? [$registry, $sid, $runId] : null;
    }

    /** @param array{0:RunRegistry,1:string,2:string}|null $run */
    private function runCancelled(?array $run): bool
    {
        return $run !== null && $run[0]->isCancelled($run[1], $run[2]);
    }

    /** @param array{0:RunRegistry,1:string,2:string}|null $run */
    private function endRun(?array $run): void
    {
        if ($run !== null) {
            $run[0]->remove($run[1], $run[2]);
        }
    }

    /** @return array{sql:string} */
    public function format(): array
    {
        $sql = (string) $this->request->param('sql', '');
        return ['sql' => (new SqlFormatter())->format($sql)];
    }

    /** @return array{tables:object,functions:array,keywords:array} */
    public function completions(): array
    {
        $db = $this->dbWithDatabase();
        $tables = $this->bulkColumns($db);
        if ($tables === null) {
            $tables = [];
            foreach (array_slice($db->listTables(), 0, 500) as $t) {
                $name = (string) $t['name'];
                try {
                    $info = $db->tableInfo($name);
                    $tables[$name] = array_map(static fn (array $c): string => (string) $c['name'], $info['columns'] ?? []);
                } catch (\Throwable $e) {
                    $tables[$name] = [];
                }
            }
        }

        $words = $db->completionWords();
        if (isset($words['keywords']) || isset($words['functions'])) {
            $keywords = array_values((array) ($words['keywords'] ?? []));
            $functions = array_values((array) ($words['functions'] ?? []));
        } else {
            $keywords = array_values($words);
            $functions = [];
        }
        return ['tables' => (object) $tables, 'functions' => $functions, 'keywords' => $keywords];
    }

    /**
     * One information_schema query for all columns where the engine supports it cheaply.
     * @return array<string,string[]>|null null → caller falls back to per-table introspection
     */
    private function bulkColumns(DriverInterface $db): ?array
    {
        switch ($db::id()) {
            case 'mysql':
                $sql = 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION';
                $params = [$db->currentDatabase()];
                break;
            case 'pgsql':
                $sql = 'SELECT table_name, column_name FROM information_schema.columns'
                    . ' WHERE table_schema = ? ORDER BY table_name, ordinal_position';
                $params = [$db->currentSchema() ?? 'public'];
                break;
            case 'sqlsrv':
                $sql = 'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION';
                $params = [$db->currentSchema() ?? 'dbo'];
                break;
            default:
                return null;
        }
        if (in_array(null, $params, true)) {
            return null;
        }
        try {
            $stmt = $db->pdo()->prepare($sql);
            $stmt->execute($params);
            $tables = [];
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $tables[(string) $row[0]][] = (string) $row[1];
            }
            return $tables;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Decide whether a result set can be edited inline: a plain single-table SELECT of
     * `*` or bare column names that includes the table's full primary key.
     * @param array<int,array<string,mixed>> $columns result columns
     * @return array{table:string,primary_key:string[]}|null
     */
    private function detectEditable(DriverInterface $db, string $sql, array $columns): ?array
    {
        if ($db->currentDatabase() === null && $db::id() !== 'sqlite') {
            return null;
        }
        $sql = self::stripComments($sql);
        $ident = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z_\x80-\xFF][A-Za-z0-9_$\x80-\xFF]*)';
        $re = '/^\s*SELECT\s+(?<cols>\*|' . $ident . '(?:\s*,\s*' . $ident . ')*)\s+FROM\s+(?<table>' . $ident . ')'
            . '(?:\s+(?:AS\s+)?(?!WHERE\b|ORDER\b|LIMIT\b|OFFSET\b|FETCH\b)' . $ident . ')?'
            . '(?<rest>\s+(?:WHERE|ORDER\s+BY|LIMIT|OFFSET|FETCH)\b.*)?\s*;?\s*$/is';
        if (!preg_match($re, $sql, $m)) {
            return null;
        }
        $rest = $m['rest'] ?? '';
        if (preg_match('/\b(?:JOIN|GROUP\s+BY|HAVING|UNION|INTERSECT|EXCEPT|DISTINCT|INTO|FOR\s+UPDATE)\b|\(\s*SELECT\b/i', $rest)) {
            return null;
        }
        $table = self::unquote($m['table']);
        try {
            $info = $db->tableInfo($table);
        } catch (\Throwable $e) {
            return null;
        }
        if (($info['type'] ?? 'table') !== 'table') {
            return null;
        }
        $pk = [];
        foreach ($info['columns'] ?? [] as $c) {
            if (!empty($c['primary'])) {
                $pk[] = (string) $c['name'];
            }
        }
        if ($pk === []) {
            return null;
        }
        $resultNames = array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $columns);
        if (count($resultNames) !== count(array_unique($resultNames)) || array_diff($pk, $resultNames) !== []) {
            return null;
        }
        $tableColumns = array_map(static fn (array $c): string => (string) $c['name'], $info['columns']);
        if (array_diff($resultNames, $tableColumns) !== []) {
            return null;
        }
        return ['table' => (string) ($info['name'] ?? $table), 'primary_key' => $pk];
    }

    private static function unquote(string $ident): string
    {
        $first = $ident[0];
        if ($first === '`' || $first === '"' || $first === '[') {
            return substr($ident, 1, -1);
        }
        return $ident;
    }

    /** Remove comments outside of string literals (for the editability heuristic only). */
    private static function stripComments(string $sql): string
    {
        return (string) preg_replace_callback(
            "/('(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"]|\"\")*\")|--[^\\n]*|#[^\\n]*|\\/\\*.*?\\*\\//s",
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? $m[1] : ' ',
            $sql
        );
    }
}
