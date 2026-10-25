<?php

declare(strict_types=1);

namespace Ldb\Sql;

use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;

/**
 * Runs the SQL editor's statements on one connection (sql.execute), with the optional
 * transaction wrapper and cooperative cancellation:
 *
 *  - tx none:   every statement auto-commits (stop_on_error decides whether to go on)
 *  - tx atomic: BEGIN; all statements; COMMIT only if all succeeded, else ROLLBACK. Always stops
 *               at the first error.
 *  - tx dryrun: BEGIN; all statements; always ROLLBACK (results are still returned)
 *
 * TransactionPolicy is checked before anything runs. $isCancelled (sql.cancel requested) is
 * polled before every statement and after errors: a cancel ends the batch, marks the
 * interrupted statement with error_code "cancelled" and rolls an atomic run back.
 */
final class ScriptRunner
{
    private DriverInterface $db;
    /** @var callable():bool */
    private $isCancelled;

    /** @param (callable():bool)|null $isCancelled */
    public function __construct(DriverInterface $db, ?callable $isCancelled = null)
    {
        $this->db = $db;
        $this->isCancelled = $isCancelled ?? static fn (): bool => false;
    }

    /**
     * @param string[] $statements
     * @param array{limit?:int,stop_on_error?:bool,tx?:string,read_only?:bool,on_result?:callable} $opts
     *        on_result(array $result, string $statement): array — post-processes a successful QueryResult
     * @return array{results:array<int,array<string,mixed>>,tx:string,rolled_back:bool,cancelled:bool,warnings:string[]}
     * @throws ApiException when the transaction mode cannot be honoured (nothing ran)
     */
    public function run(array $statements, array $opts = []): array
    {
        $db = $this->db;
        $limit = (int) ($opts['limit'] ?? 1000);
        $tx = (string) ($opts['tx'] ?? TransactionPolicy::NONE);
        if (!TransactionPolicy::isMode($tx)) {
            throw ApiException::badRequest('Invalid parameter: tx (none, atomic or dryrun)');
        }
        $stopOnError = $tx === TransactionPolicy::ATOMIC || ($opts['stop_on_error'] ?? true);
        $readOnly = !empty($opts['read_only']);
        $onResult = $opts['on_result'] ?? null;

        // Before anything runs: statements that would break or escape the transaction.
        $policy = new TransactionPolicy($db::id());
        $warnings = $policy->check($statements, $tx);

        $results = [];
        $failed = false;
        $cancelled = false;
        $rolledBack = false;
        try {
            if ($tx !== TransactionPolicy::NONE) {
                $db->pdo()->beginTransaction();
            }
            foreach ($statements as $statement) {
                if (($this->isCancelled)()) {
                    $cancelled = true;
                    break;
                }
                $start = microtime(true);
                try {
                    if ($readOnly) {
                        // Re-assert the session mode before every statement (defence in depth).
                        $db->setReadOnly(true);
                    }
                    $result = $db->query($statement, $limit);
                    $result['sql'] = $statement;
                    $result['time_ms'] = self::elapsedMs($start);
                    $result += ['error' => null, 'editable' => null];
                    if ($onResult !== null) {
                        $result = $onResult($result, $statement);
                    }
                    $results[] = $result;
                } catch (\PDOException | ApiException $e) {
                    $isCancel = $this->isCancellation($e);
                    $results[] = [
                        'sql' => $statement,
                        'is_result_set' => false,
                        'columns' => [],
                        'rows' => [],
                        'affected' => null,
                        'insert_id' => null,
                        'time_ms' => self::elapsedMs($start),
                        'truncated' => false,
                        'error' => $e->getMessage(),
                        'error_code' => $isCancel ? 'cancelled' : null,
                        'editable' => null,
                    ];
                    if ($isCancel) {
                        $cancelled = true;
                        break;
                    }
                    $failed = true;
                    if ($stopOnError) {
                        break;
                    }
                }
            }
            // A cancel can also end a statement "successfully" (MySQL: an interrupted SLEEP() returns 1).
            $cancelled = $cancelled || ($this->isCancelled)();
            if ($tx === TransactionPolicy::DRYRUN || ($tx === TransactionPolicy::ATOMIC && ($failed || $cancelled))) {
                $warnings = array_merge($warnings, $this->rollback());
                // After an implicit commit (MySQL DDL) the wrapper transaction is gone: nothing was rolled back.
                $rolledBack = !$this->implicitCommitRan($policy, $statements, count($results));
            } elseif ($tx === TransactionPolicy::ATOMIC) {
                $pdo = $db->pdo();
                if ($pdo->inTransaction()) { // false after a MySQL implicit commit (PHP 8)
                    $pdo->commit();
                }
            }
        } catch (\Throwable $e) {
            if ($tx !== TransactionPolicy::NONE) {
                $this->rollback(); // never leave the wrapper transaction open
            }
            throw $e;
        }
        return [
            'results' => $results,
            'tx' => $tx,
            'rolled_back' => $rolledBack,
            'cancelled' => $cancelled,
            'warnings' => $warnings,
        ];
    }

    /** Whether one of the first $executed statements committed the transaction implicitly. */
    private function implicitCommitRan(TransactionPolicy $policy, array $statements, int $executed): bool
    {
        foreach (array_slice(array_values($statements), 0, $executed) as $sql) {
            if ($policy->classify($sql)['kind'] === TransactionPolicy::IMPLICIT_COMMIT) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an error is the result of a cancel: requested via sql.cancel for this run, or the
     * engine's "interrupted by user" error (MySQL 1317, PostgreSQL 57014 "… user request").
     */
    public function isCancellation(\Throwable $e): bool
    {
        if (($this->isCancelled)()) {
            return true;
        }
        if (!$e instanceof \PDOException) {
            return false;
        }
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        if ((int) ($info[1] ?? 0) === 1317) {
            return true;
        }
        return (string) ($info[0] ?? $e->getCode()) === '57014' && stripos($e->getMessage(), 'user request') !== false;
    }

    /**
     * Roll the wrapper transaction back; never throws (the connection may be gone, e.g. after
     * SQL Server KILL — the server then rolls back itself).
     * @return string[] warnings (MySQL: non-transactional tables were changed)
     */
    private function rollback(): array
    {
        try {
            $pdo = $this->db->pdo();
            if (!$pdo->inTransaction()) {
                return [];
            }
            $pdo->rollBack();
            if ($this->db::id() === 'mysql') {
                // 1196: "Some non-transactional changed tables couldn't be rolled back" (MyISAM, …)
                $stmt = $pdo->query('SHOW WARNINGS');
                $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                foreach ($rows as $w) {
                    if ((int) ($w['Code'] ?? 0) === 1196) {
                        return ['Some changed tables are not transactional (e.g. MyISAM): their changes could NOT be rolled back.'];
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    private static function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
