<?php

declare(strict_types=1);

namespace Ldb\Diff;

use Ldb\Db\DriverInterface;
use Ldb\Sql\ScriptRunner;
use Ldb\Sql\TransactionPolicy;

/**
 * Runs a MigrationPlanner plan on the target through Sql\ScriptRunner (stops at the first error).
 *  - tx atomic: one transaction (PostgreSQL/SQLite DDL is transactional; MySQL commits implicitly
 *    after each DDL statement — ScriptRunner reports that as a warning). On SQLite the rebuild
 *    BEGIN/COMMIT markers and the foreign_keys switch (tx_control statements) are run around the
 *    wrapper transaction instead of inside it.
 *  - tx none: statement by statement, auto-commit.
 */
final class PlanExecutor
{
    /**
     * @param array<int,array{sql:string,tx_control?:bool}> $statements
     * @return array{results:array,tx:string,rolled_back:bool,cancelled:bool,warnings:string[],failed:bool}
     */
    public static function run(DriverInterface $db, array $statements, string $tx = TransactionPolicy::ATOMIC): array
    {
        $sqliteAtomic = $db::id() === 'sqlite' && $tx === TransactionPolicy::ATOMIC;
        if ($sqliteAtomic) {
            $statements = array_values(array_filter($statements, static fn (array $s): bool => empty($s['tx_control'])));
            $db->query('PRAGMA foreign_keys = OFF', 0);
        }
        try {
            $result = (new ScriptRunner($db))->run(array_map(static fn (array $s): string => (string) $s['sql'], $statements), [
                'limit' => 1,
                'stop_on_error' => true,
                'tx' => $tx,
            ]);
        } finally {
            if ($sqliteAtomic) {
                try {
                    $db->query('PRAGMA foreign_keys = ON', 0);
                } catch (\Throwable $e) {
                }
            }
        }
        $failed = false;
        foreach ($result['results'] as &$r) {
            unset($r['rows'], $r['columns'], $r['more_results'], $r['editable']);
            $failed = $failed || ($r['error'] ?? null) !== null;
        }
        unset($r);
        $result['failed'] = $failed;
        return $result;
    }
}
