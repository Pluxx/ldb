<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Db\DriverInterface;
use Ldb\Diff\MigrationPlanner;
use Ldb\Diff\PlanExecutor;
use Ldb\Diff\SchemaComparer;
use Ldb\Diff\SchemaReader;
use Ldb\Diff\SchemaSnapshot;
use Ldb\Http\ApiException;
use Ldb\Sql\TransactionPolicy;

/**
 * Schema diff: compare two databases (same or different connections of this session, same
 * driver) and plan/apply the script that makes the TARGET match the SOURCE.
 *
 *  diff.compare  {source:{conn,db,schema?}, target:{conn,db,schema?}, options} → {changes, summary, …}
 *  diff.plan     same + selected?:[change ids], exclude_destructive? → {statements, sql, warnings, hash}
 *  diff.apply    same + conn (= target.conn, the WRITE check runs on it), hash (from diff.plan),
 *                tx: atomic|none → ScriptRunner result + plan
 * The plan is always recomputed on the server from fresh snapshots; apply refuses (409 "stale")
 * when it no longer matches the reviewed hash.
 */
final class DiffController extends AbstractController
{
    /** @return array<string,mixed> */
    public function compare(): array
    {
        $ctx = $this->context();
        return [
            'changes' => $ctx['changes'],
            'summary' => SchemaComparer::summary($ctx['changes']),
            'driver' => $ctx['driver'],
            'options' => $ctx['options'],
            'source' => $ctx['source_info'],
            'target' => $ctx['target_info'],
        ];
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $ctx = $this->context();
        return $this->buildPlan($ctx) + ['driver' => $ctx['driver'], 'summary' => SchemaComparer::summary($ctx['changes'])];
    }

    /** @return array<string,mixed> */
    public function apply(): array
    {
        $target = $this->side('target');
        $conn = $this->request->optString('conn');
        if ($conn === null || $conn !== $target['conn']) {
            throw ApiException::badRequest('Parameter conn must name the target connection');
        }
        if ($this->isReadOnly()) {
            throw new ApiException('The target connection is read-only', 'read_only', 403);
        }
        $tx = (string) $this->request->param('tx', TransactionPolicy::ATOMIC);
        if (!in_array($tx, [TransactionPolicy::ATOMIC, TransactionPolicy::NONE], true)) {
            throw ApiException::badRequest('Invalid parameter: tx (atomic or none)');
        }
        @set_time_limit(0);
        $ctx = $this->context();
        $plan = $this->buildPlan($ctx);
        $hash = (string) $this->request->param('hash', '');
        if ($hash === '' || !hash_equals($plan['hash'], $hash)) {
            throw new ApiException('The schemas changed since the script was generated. Compare again and review the new script.', 'stale', 409);
        }
        if (!$plan['statements']) {
            throw ApiException::badRequest('Nothing to apply');
        }
        $statements = $plan['statements'];
        $result = PlanExecutor::run($ctx['target_db'], $statements, $tx);
        $result['statements'] = $statements;
        $result['plan_warnings'] = $plan['warnings'];
        return $result;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{conn:string,db:?string,schema:?string} */
    private function side(string $name): array
    {
        $p = $this->request->arr($name, true);
        $conn = isset($p['conn']) && is_scalar($p['conn']) ? trim((string) $p['conn']) : '';
        if ($conn === '') {
            throw ApiException::badRequest("Missing parameter: $name.conn");
        }
        $str = static fn ($v): ?string => is_scalar($v) && trim((string) $v) !== '' ? (string) $v : null;
        return ['conn' => $conn, 'db' => $str($p['db'] ?? null), 'schema' => $str($p['schema'] ?? null)];
    }

    /** Connected driver for one side, with its database/schema selected. */
    private function connectSide(array $side, string $label): array
    {
        $params = $this->session->connectionParams($side['conn']);
        if ($params === null) {
            throw ApiException::badRequest("Unknown or expired $label connection");
        }
        $driver = (string) ($params['driver'] ?? '');
        if ($side['db'] === null && $driver !== 'sqlite') {
            throw ApiException::badRequest("Missing parameter: $label.db");
        }
        $db = $this->factory->connect($params);
        $db->select($side['db'], $side['schema']);
        return [$db, $params];
    }

    /**
     * Snapshots of both sides and the comparison.
     * @return array<string,mixed>
     */
    private function context(): array
    {
        $source = $this->side('source');
        $target = $this->side('target');
        $sp = $this->session->connectionParams($source['conn']);
        $tp = $this->session->connectionParams($target['conn']);
        if ($sp === null || $tp === null) {
            // not auth_required: that would end the whole UI session for a stale link parameter
            throw ApiException::badRequest('Unknown or expired ' . ($sp === null ? 'source' : 'target') . ' connection');
        }
        if ((string) ($sp['driver'] ?? '') !== (string) ($tp['driver'] ?? '')) {
            throw ApiException::badRequest(sprintf(
                'Source (%s) and target (%s) use different database engines. Schema comparison only works between connections of the same driver.',
                (string) ($sp['driver'] ?? '?'),
                (string) ($tp['driver'] ?? '?')
            ));
        }
        if (in_array((string) $tp['driver'], ['sqlsrv'], true)) {
            throw ApiException::unsupported('Schema comparison is not supported for SQL Server yet');
        }
        $options = SchemaComparer::options($this->request->arr('options'));
        $include = $options['include'];

        [$sdb] = $this->connectSide($source, 'source');
        [$tdb] = $this->connectSide($target, 'target');
        $srcSnap = (new SchemaReader($sdb))->read($include);
        $tgtSnap = (new SchemaReader($tdb))->read($include);
        $changes = (new SchemaComparer())->compare($srcSnap, $tgtSnap, $options);
        $changes = $this->probeNulls($tdb, $changes);

        return [
            'driver' => $tdb::id(),
            'options' => $options,
            'changes' => $changes,
            'source' => $srcSnap,
            'target' => $tgtSnap,
            'target_db' => $tdb,
            'source_info' => $this->info($source, $srcSnap),
            'target_info' => $this->info($target, $tgtSnap),
        ];
    }

    private function info(array $side, SchemaSnapshot $snap): array
    {
        return [
            'conn' => $side['conn'],
            'db' => $snap->database() ?? $side['db'],
            'schema' => $snap->schema(),
            'tables' => count($snap->tables()),
            'views' => count($snap->views()),
        ];
    }

    /**
     * Count NULLs in target columns that become NOT NULL: such changes are destructive (they fail
     * or turn NULLs into implicit defaults).
     */
    private function probeNulls(DriverInterface $db, array $changes): array
    {
        foreach ($changes as &$c) {
            if ($c['kind'] !== 'column_changed' || !in_array('not_null', (array) $c['warnings'], true)) {
                continue;
            }
            $rows = null;
            try {
                $sql = 'SELECT COUNT(*) FROM ' . $db->quoteTable((string) $c['table']) . ' WHERE ' . $db->quoteIdentifier((string) $c['object']) . ' IS NULL';
                $stmt = $db->pdo()->query($sql);
                $rows = $stmt !== false ? (int) $stmt->fetchColumn() : null;
            } catch (\PDOException $e) {
                $rows = null;
            }
            $c['null_rows'] = $rows;
            if ($rows === null || $rows > 0) {
                $c['destructive'] = true;
            }
        }
        unset($c);
        return $changes;
    }

    /** @return array<string,mixed> */
    private function buildPlan(array $ctx): array
    {
        $selected = $this->request->has('selected') ? $this->stringList('selected', false) : null;
        $options = $ctx['options'] + ['exclude_destructive' => $this->request->bool('exclude_destructive')];
        $planner = new MigrationPlanner($ctx['target_db'], $ctx['source'], $ctx['target'], $options);
        $plan = $planner->plan($ctx['changes'], $selected);
        $plan['hash'] = hash('sha256', (string) json_encode($plan['sql'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $plan;
    }
}
