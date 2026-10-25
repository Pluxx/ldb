<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Monitor;

use Ldb\Monitor\Advisor;
use Ldb\Monitor\MetricsNormalizer;
use PHPUnit\Framework\TestCase;

final class AdvisorTest extends TestCase
{
    /** @return string[] */
    private static function ids(array $hints): array
    {
        return array_map(static fn (array $h): string => $h['id'], $hints);
    }

    private static function find(array $hints, string $id): ?array
    {
        foreach ($hints as $h) {
            if ($h['id'] === $id) {
                return $h;
            }
        }
        return null;
    }

    public function testHealthyMySqlHasNoHints(): void
    {
        $s = MetricsNormalizer::mysql(MetricsNormalizerTest::mysqlStatus(), MetricsNormalizerTest::mysqlVars(), [], 1.0);
        $this->assertSame([], Advisor::advise($s));
    }

    public function testMySqlRules(): void
    {
        $status = MetricsNormalizerTest::mysqlStatus([
            'Innodb_buffer_pool_reads' => '60000',      // 94 % hit ratio → critical
            'Created_tmp_disk_tables' => '1000',        // 50 % of 2000
            'Select_full_join' => '5000',               // 200 / hour over 25 h
            'Threads_connected' => '130',               // 86 % of 151
            'Slow_queries' => '20000',                  // 4 % of questions
            'Table_open_cache_misses' => '5000',
            'Innodb_row_lock_waits' => '50000',
            'Aborted_connects' => '1000',
        ]);
        $hints = Advisor::advise(MetricsNormalizer::mysql($status, MetricsNormalizerTest::mysqlVars(), [
            ['id' => 1, 'time' => 75, 'query' => 'SELECT SLEEP(100)'],
            ['id' => 2, 'time' => 12, 'query' => 'SELECT 1'],
        ], 1.0));
        $ids = self::ids($hints);
        foreach (['mysql.bp_hit', 'mysql.tmp_disk', 'mysql.full_join', 'conn.near_max', 'mysql.slow_ratio', 'mysql.table_cache', 'mysql.row_locks', 'mysql.aborted', 'long_running'] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame('critical', $hints[0]['severity']);
        $this->assertSame('critical', self::find($hints, 'mysql.bp_hit')['severity']);
        $this->assertSame(94, self::find($hints, 'mysql.bp_hit')['value']);
        $this->assertSame(134217728, ((array) self::find($hints, 'mysql.bp_hit')['params'])['size_bytes']);
        $this->assertSame(50, ((array) self::find($hints, 'mysql.tmp_disk')['params'])['pct']);
        $this->assertSame(['count' => 1, 'seconds' => 75], (array) self::find($hints, 'long_running')['params']);
        $this->assertNotContains('mysql.max_used', $ids); // covered by conn.near_max
        $this->assertNotContains('uptime_short', $ids);   // 25 h uptime
        // Sorted: critical, warning…, info…
        $sev = array_map(static fn (array $h): string => $h['severity'], $hints);
        $sorted = $sev;
        usort($sorted, static fn ($a, $b) => ['critical' => 0, 'warning' => 1, 'info' => 2][$a] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b]);
        $this->assertSame($sorted, $sev);
    }

    public function testMySqlSmallSamplesAndShortUptime(): void
    {
        // Same bad ratios but tiny samples: no noise, and no "uptime short" without other hints.
        $status = MetricsNormalizerTest::mysqlStatus([
            'Uptime' => '600', 'Innodb_buffer_pool_read_requests' => '500', 'Innodb_buffer_pool_reads' => '400',
            'Created_tmp_tables' => '10', 'Created_tmp_disk_tables' => '9', 'Select_full_join' => '5',
        ]);
        $this->assertSame([], Advisor::advise(MetricsNormalizer::mysql($status, MetricsNormalizerTest::mysqlVars(), [], 1.0)));

        $status['Max_used_connections'] = '140';
        $hints = Advisor::advise(MetricsNormalizer::mysql($status, MetricsNormalizerTest::mysqlVars(['slow_query_log' => 'OFF', 'performance_schema' => 'OFF']), [], 1.0));
        $this->assertSame(['mysql.max_used', 'mysql.no_query_stats', 'uptime_short'], self::ids($hints));
    }

    public function testPgRules(): void
    {
        $raw = [
            'xact_commit' => '900', 'xact_rollback' => '200', 'blks_read' => '2000', 'blks_hit' => '18000',
            'deadlocks' => '3', 'temp_bytes' => (string) (5 * 1073741824), 'total' => '85', 'active' => '5',
            'idle_in_tx' => '3', 'longest_idle_tx' => '120', 'max_connections' => '100', 'uptime' => '172800',
            'checkpoints_timed' => '4', 'checkpoints_req' => '20', 'replication_lag' => '45',
            'shared_buffers' => '128MB', 'work_mem' => '4MB', 'max_wal_size' => '1GB',
            'idle_in_transaction_session_timeout' => '0',
        ];
        $hints = Advisor::advise(MetricsNormalizer::pgsql($raw, [], 1.0));
        $ids = self::ids($hints);
        foreach (['pg.cache_hit', 'pg.idle_in_tx', 'pg.deadlocks', 'pg.temp', 'pg.checkpoints', 'pg.rollbacks', 'pg.replication_lag', 'conn.near_max'] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame('warning', self::find($hints, 'pg.cache_hit')['severity']); // exactly 90 %: critical only below
    }

    public function testHealthyPgHasNoHints(): void
    {
        $raw = [
            'xact_commit' => '100000', 'xact_rollback' => '10', 'blks_read' => '10', 'blks_hit' => '100000',
            'deadlocks' => '0', 'temp_bytes' => '0', 'total' => '5', 'active' => '1', 'idle_in_tx' => '1',
            'longest_idle_tx' => '2', 'max_connections' => '100', 'uptime' => '172800',
            'checkpoints_timed' => '50', 'checkpoints_req' => '1',
        ];
        $this->assertSame([], Advisor::advise(MetricsNormalizer::pgsql($raw, [], 1.0)));
    }

    public function testSqlSrvRules(): void
    {
        $perf = [
            ['SQLServer:Buffer Manager', 'Page life expectancy', '', '120'],
            ['SQLServer:Buffer Manager', 'Buffer cache hit ratio', '', '80'],
            ['SQLServer:Buffer Manager', 'Buffer cache hit ratio base', '', '100'],
            ['SQLServer:General Statistics', 'Processes blocked', '', '2'],
        ];
        $ids = self::ids(Advisor::advise(MetricsNormalizer::sqlsrv($perf, ['uptime' => 10], [], 1.0)));
        sort($ids);
        $this->assertSame(['mssql.blocked', 'mssql.cache_hit', 'mssql.ple'], $ids);
    }

    public function testUnknownDriver(): void
    {
        $this->assertSame([], Advisor::advise(['driver' => 'sqlite']));
    }
}
