<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Monitor;

use Ldb\Monitor\MetricsNormalizer;
use PHPUnit\Framework\TestCase;

final class MetricsNormalizerTest extends TestCase
{
    /** SHOW GLOBAL STATUS fixture as MySQL 8 returns it (mixed case, string values). */
    public static function mysqlStatus(array $over = []): array
    {
        return array_merge([
            'Uptime' => '90000', 'Questions' => '500000', 'Com_select' => '300000', 'Com_insert' => '1000',
            'Com_insert_select' => '10', 'Com_replace' => '5', 'Com_update' => '2000', 'Com_update_multi' => '3',
            'Com_delete' => '400', 'Com_delete_multi' => '1', 'Slow_queries' => '12', 'Connections' => '5000',
            'Aborted_connects' => '7', 'Threads_connected' => '20', 'Threads_running' => '3',
            'Max_used_connections' => '42', 'Bytes_received' => '123456789', 'Bytes_sent' => '987654321',
            'Innodb_buffer_pool_read_requests' => '1000000', 'Innodb_buffer_pool_reads' => '500',
            'Innodb_buffer_pool_pages_dirty' => '10', 'Innodb_buffer_pool_pages_total' => '8192',
            'Innodb_buffer_pool_pages_free' => '100', 'Innodb_row_lock_waits' => '0',
            'Created_tmp_tables' => '2000', 'Created_tmp_disk_tables' => '100', 'Select_full_join' => '0',
            'Select_scan' => '50', 'Table_open_cache_hits' => '9000', 'Table_open_cache_misses' => '100',
            'Opened_tables' => '300',
        ], $over);
    }

    public static function mysqlVars(array $over = []): array
    {
        return array_merge([
            'max_connections' => '151', 'slow_query_log' => 'ON', 'long_query_time' => '10.000000',
            'log_output' => 'TABLE', 'performance_schema' => 'ON', 'innodb_buffer_pool_size' => '134217728',
            'tmp_table_size' => '16777216', 'max_heap_table_size' => '16777216', 'table_open_cache' => '4000',
        ], $over);
    }

    public function testMySqlSnapshot(): void
    {
        $s = MetricsNormalizer::mysql(self::mysqlStatus(), self::mysqlVars(), [], 1700000000.5);
        $this->assertSame('mysql', $s['driver']);
        $this->assertSame('mysql', $s['flavor']);
        $this->assertSame(1700000000500, $s['ts']);
        $this->assertSame(90000, $s['uptime']);
        $this->assertSame('statements', $s['mix_unit']);
        $c = (array) $s['counters'];
        $g = (array) $s['gauges'];
        $this->assertSame(500000, $c['queries']);
        $this->assertSame(1015, $c['insert']);          // insert + insert_select + replace
        $this->assertSame(2003, $c['update']);
        $this->assertSame(401, $c['delete']);
        $this->assertSame(123456789, $c['bytes_in']);
        $this->assertSame(1000000, $c['cache_requests']);
        $this->assertSame(500, $c['cache_misses']);
        $this->assertSame(20, $g['connections']);
        $this->assertSame(151, $g['max_connections']);
        $this->assertSame(99.95, $g['cache_hit_ratio']);
        $this->assertSame(10, ((array) $s['settings'])['long_query_time']);
        $this->assertSame('TABLE', ((array) $s['settings'])['log_output']);
    }

    public function testMariaDbAndMissingKeysAreOmitted(): void
    {
        $status = self::mysqlStatus();
        unset($status['Table_open_cache_hits'], $status['Table_open_cache_misses'], $status['Innodb_buffer_pool_reads']);
        $s = MetricsNormalizer::mysql($status, [], [], 1.0, true);
        $this->assertSame('mariadb', $s['flavor']);
        $c = (array) $s['counters'];
        $g = (array) $s['gauges'];
        $this->assertArrayNotHasKey('table_cache_hits', $c);
        $this->assertArrayNotHasKey('cache_misses', $c);
        $this->assertArrayNotHasKey('cache_hit_ratio', $g);
        $this->assertArrayNotHasKey('max_connections', $g);
        $this->assertSame('{}', json_encode($s['settings']));
    }

    public function testPgSnapshot(): void
    {
        $raw = [
            'xact_commit' => '9000', 'xact_rollback' => '1000', 'blks_read' => '100', 'blks_hit' => '9900',
            'tup_returned' => '50', 'tup_fetched' => '40', 'tup_inserted' => '30', 'tup_updated' => '20',
            'tup_deleted' => '10', 'deadlocks' => '0', 'temp_bytes' => '0', 'temp_files' => '0',
            'total' => 12, 'active' => 2, 'idle' => 8, 'idle_in_tx' => 2, 'waiting' => 0,
            'longest_idle_tx' => '75.5', 'max_connections' => '100', 'uptime' => '3600.25',
            'checkpoints_timed' => '5', 'checkpoints_req' => '1', 'shared_buffers' => '128MB',
            'idle_in_transaction_session_timeout' => '0',
        ];
        $s = MetricsNormalizer::pgsql($raw, [['id' => 42, 'user' => 'app', 'db' => 'x', 'time' => '12.345', 'state' => 'active', 'query' => str_repeat('é', 400)]], 2.0);
        $this->assertSame('pgsql', $s['driver']);
        $this->assertSame('rows', $s['mix_unit']);
        $this->assertSame(3600, $s['uptime']);
        $c = (array) $s['counters'];
        $g = (array) $s['gauges'];
        $this->assertSame(10000, $c['queries']);
        $this->assertSame(10000, $c['cache_requests']);
        $this->assertSame(40, $c['select']);
        $this->assertSame(99, $g['cache_hit_ratio']);
        $this->assertSame(75.5, $g['longest_idle_tx']);
        $this->assertSame(2, $g['idle_in_tx']);
        $this->assertSame('128MB', ((array) $s['settings'])['shared_buffers']);
        $this->assertSame('42', $s['long'][0]['id']);
        $this->assertSame(12.3, $s['long'][0]['time']);
        $this->assertSame(301, mb_strlen($s['long'][0]['query'], 'UTF-8'));
    }

    public function testSqlSrvSnapshot(): void
    {
        $perf = [
            ['SQLServer:SQL Statistics', 'Batch Requests/sec', '', '12345'],
            ['MSSQL$X:Buffer Manager', 'Page life expectancy', '', '250'],
            ['SQLServer:Buffer Node', 'Page life expectancy', '000', '999'],
            ['SQLServer:Buffer Manager', 'Buffer cache hit ratio', '', '45'],
            ['SQLServer:Buffer Manager', 'Buffer cache hit ratio base', '', '50'],
            ['SQLServer:General Statistics', 'User Connections', '', '7'],
            ['SQLServer:Databases', 'Transactions/sec', 'master', '1'],
            ['SQLServer:Databases', 'Transactions/sec', '_Total', '99'],
        ];
        $s = MetricsNormalizer::sqlsrv($perf, ['uptime' => 60, 'running' => 1, 'max_connections' => 32767], [], 1.0);
        $c = (array) $s['counters'];
        $g = (array) $s['gauges'];
        $this->assertSame(12345, $c['queries']);
        $this->assertSame(99, $c['transactions']);
        $this->assertSame(250, $g['page_life_expectancy']);
        $this->assertSame(90, $g['cache_hit_ratio']);
        $this->assertSame(7, $g['connections']);
        $this->assertNull($s['mix_unit']);
    }

    public function testTextSanitising(): void
    {
        $this->assertNull(MetricsNormalizer::text(null, 5));
        $this->assertSame('abcde…', MetricsNormalizer::text('abcdefgh', 5));
        $this->assertTrue(mb_check_encoding((string) MetricsNormalizer::text("a\xff\xfeb", 10), 'UTF-8'));
        $this->assertNull(MetricsNormalizer::num('abc'));
        $this->assertSame(1.5, MetricsNormalizer::num(' 1.5 '));
    }
}
