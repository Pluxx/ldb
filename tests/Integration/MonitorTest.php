<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\DriverInterface;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Monitor\ServerMonitor;
use PHPUnit\Framework\TestCase;

/**
 * server.metrics / slowLog / advice shapes against real servers (LDB_TEST_MYSQL_*,
 * LDB_TEST_MARIADB_*, LDB_TEST_PGSQL_*; each skipped when its DSN is not set).
 */
final class MonitorTest extends TestCase
{
    /** @return array<string,array{string}> */
    public function engines(): array
    {
        return ['mysql' => ['MYSQL'], 'mariadb' => ['MARIADB'], 'pgsql' => ['PGSQL']];
    }

    private function connect(string $prefix): DriverInterface
    {
        $dsn = (string) getenv('LDB_TEST_' . $prefix . '_DSN');
        if ($dsn === '') {
            $this->markTestSkipped("LDB_TEST_{$prefix}_DSN not set");
        }
        $pg = strpos($dsn, 'pgsql:') === 0;
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^\w+:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $d = $pg ? new PgSqlDriver() : new MySqlDriver();
        $d->connect([
            'driver' => $pg ? 'pgsql' : 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? ($pg ? 5432 : 3306)),
            'user' => (string) getenv('LDB_TEST_' . $prefix . '_USER'),
            'password' => (string) getenv('LDB_TEST_' . $prefix . '_PASS'),
            'database' => $pg ? ($parts['dbname'] ?? 'postgres') : null,
            'options' => [],
        ]);
        $this->assertTrue($d->capabilities()['monitor']);
        return $d;
    }

    /** @dataProvider engines */
    public function testMetricsShape(string $prefix): void
    {
        $d = $this->connect($prefix);
        $m = ServerMonitor::for($d);
        $a = $m->metrics();
        $d->query('SELECT 1', 1);
        $b = $m->metrics();
        $this->assertSame($d::id(), $a['driver']);
        $this->assertGreaterThan(0, $a['uptime']);
        $this->assertGreaterThanOrEqual($a['ts'], $b['ts']);
        $ca = (array) $a['counters'];
        $cb = (array) $b['counters'];
        $g = (array) $b['gauges'];
        foreach (['queries', 'select', 'insert', 'update', 'delete', 'cache_requests', 'cache_misses'] as $k) {
            $this->assertArrayHasKey($k, $cb, $k);
            $this->assertIsInt($cb[$k], $k);
        }
        $this->assertGreaterThanOrEqual($ca['queries'], $cb['queries']);
        foreach (['connections', 'max_connections', 'running'] as $k) {
            $this->assertArrayHasKey($k, $g, $k);
        }
        $this->assertGreaterThanOrEqual(1, $g['connections']);
        if ($d::id() === 'mysql') {
            $this->assertArrayHasKey('bytes_in', $cb);
            $this->assertArrayHasKey('slow_queries', $cb);
            $this->assertSame('statements', $b['mix_unit']);
        } else {
            $this->assertArrayHasKey('idle_in_tx', $g);
            $this->assertSame('rows', $b['mix_unit']);
        }
        $this->assertIsArray($b['long']);
        $json = json_encode($b);
        $this->assertIsString($json);
    }

    /** @dataProvider engines */
    public function testSlowLogAndAdvice(string $prefix): void
    {
        $m = ServerMonitor::for($this->connect($prefix));
        $s = $m->slowLog(5, 'auto', 'avg');
        $this->assertContains($s['source'], ['slow_log', 'digest', 'pg_stat_statements', 'none']);
        $this->assertLessThanOrEqual(5, count($s['rows']));
        if ($s['source'] === 'none') {
            $this->assertNotNull($s['hint']);
            $this->assertSame([], $s['rows']);
        }
        foreach ($s['rows'] as $r) {
            $this->assertSame(['text', 'sql', 'db', 'user', 'count', 'total_ms', 'avg_ms', 'max_ms', 'rows_examined', 'rows_sent', 'no_index', 'last_seen'], array_keys($r));
        }
        $adv = $m->advice();
        $this->assertIsArray($adv['hints']);
        foreach ($adv['hints'] as $h) {
            $this->assertContains($h['severity'], ['critical', 'warning', 'info']);
            $this->assertMatchesRegularExpression('/^[a-z_.]+$/', $h['id']);
        }
    }
}
