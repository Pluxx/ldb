<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\DriverInterface;
use Ldb\Diff\MigrationPlanner;
use Ldb\Diff\PlanExecutor;
use Ldb\Diff\SchemaComparer;
use Ldb\Diff\SchemaReader;

/** Shared diff → plan → apply → re-diff checks for the per-driver schema diff tests. */
trait SchemaDiffAssertions
{
    /** @return array<int,array<string,mixed>> */
    private function diff(DriverInterface $src, DriverInterface $tgt, array $options = []): array
    {
        $s = (new SchemaReader($src))->read();
        $t = (new SchemaReader($tgt))->read();
        return (new SchemaComparer())->compare($s, $t, $options);
    }

    /**
     * Diff, plan (optionally without some changes), apply and re-diff.
     * @param string[] $expectIds change ids that must be found
     * @param string[] $exclude change ids left out of the plan (must remain afterwards)
     * @return array{changes:array,plan:array,after:array}
     */
    private function roundTrip(DriverInterface $src, DriverInterface $tgt, array $expectIds, string $tx, array $exclude = [], array $options = []): array
    {
        $s = (new SchemaReader($src))->read();
        $t = (new SchemaReader($tgt))->read();
        $changes = (new SchemaComparer())->compare($s, $t, $options);
        $ids = array_map(static fn (array $c): string => $c['id'], $changes);
        foreach ($expectIds as $id) {
            $this->assertContains($id, $ids, "expected change $id; got:\n" . implode("\n", $ids));
        }
        $selected = array_values(array_diff($ids, $exclude));
        $plan = (new MigrationPlanner($tgt, $s, $t, $options))->plan($changes, $selected);
        $this->assertNotEmpty($plan['statements']);
        $res = PlanExecutor::run($tgt, $plan['statements'], $tx);
        foreach ($res['results'] as $r) {
            $this->assertNull($r['error'], "statement failed: {$r['sql']}\n{$r['error']}\n\nplan:\n" . implode(";\n", $plan['sql']));
        }
        $this->assertFalse($res['failed']);
        if (getenv('LDB_DIFF_DUMP')) {
            fwrite(STDERR, "\n" . implode(";\n", $plan['sql']) . ";\n");
        }

        $after = $this->diff($src, $tgt, $options);
        $left = array_map(static fn (array $c): string => $c['id'], $after);
        sort($left);
        $want = array_values($exclude);
        sort($want);
        $this->assertSame($want, $left, "remaining differences after apply:\n" . json_encode($after, JSON_PRETTY_PRINT) . "\n\nplan:\n" . implode(";\n", $plan['sql']));
        return ['changes' => $changes, 'plan' => $plan, 'after' => $after];
    }
}
