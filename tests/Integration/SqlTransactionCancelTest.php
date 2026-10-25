<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\DriverInterface;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use Ldb\Sql\RunRegistry;
use Ldb\Sql\ScriptRunner;
use PHPUnit\Framework\TestCase;

/**
 * SQL editor transaction modes (ScriptRunner, as used by sql.execute) and query cancellation
 * (DriverInterface::backendId/cancelBackend + RunRegistry) against real engines.
 * Server engines use LDB_TEST_{MYSQL,MARIADB,PGSQL}_{DSN,USER,PASS}; SQLite always runs.
 */
final class SqlTransactionCancelTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public function engineProvider(): array
    {
        return [
            'mysql' => ['LDB_TEST_MYSQL'],
            'mariadb' => ['LDB_TEST_MARIADB'],
            'pgsql' => ['LDB_TEST_PGSQL'],
            'sqlite' => ['sqlite'],
        ];
    }

    /** @return array<string,array{0:string}> */
    public function serverProvider(): array
    {
        $all = $this->engineProvider();
        unset($all['sqlite']);
        return $all;
    }

    /** @var array<int,callable> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
            }
        }
        $this->cleanup = [];
    }

    /** @dataProvider engineProvider */
    public function testDryRunLeavesNoRows(string $engine): void
    {
        [$db, $q] = $this->tableFixture($engine);
        $res = (new ScriptRunner($db))->run([
            'INSERT INTO ' . $q . " VALUES (1, 'a')",
            'INSERT INTO ' . $q . " VALUES (2, 'b')",
            'SELECT COUNT(*) FROM ' . $q,
        ], ['tx' => 'dryrun']);
        $this->assertTrue($res['rolled_back']);
        $this->assertSame('dryrun', $res['tx']);
        $this->assertCount(3, $res['results']);
        $this->assertNull($res['results'][2]['error']);
        $this->assertEquals(2, $res['results'][2]['rows'][0][0], 'results are visible inside the transaction');
        $this->assertSame(0, $this->rowCount($db, $q), 'nothing persisted');
        $this->assertFalse($db->pdo()->inTransaction());
    }

    /** @dataProvider engineProvider */
    public function testAtomicRollsBackEverythingWhenTheThirdStatementFails(string $engine): void
    {
        [$db, $q] = $this->tableFixture($engine);
        $res = (new ScriptRunner($db))->run([
            'INSERT INTO ' . $q . " VALUES (1, 'a')",
            'UPDATE ' . $q . " SET v = 'changed' WHERE id = 1",
            'INSERT INTO ' . $q . " VALUES (1, 'duplicate key')",
            'INSERT INTO ' . $q . " VALUES (3, 'never runs')",
        ], ['tx' => 'atomic', 'stop_on_error' => false]);
        $this->assertTrue($res['rolled_back']);
        $this->assertCount(3, $res['results'], 'atomic stops at the first error regardless of stop_on_error');
        $this->assertNull($res['results'][1]['error']);
        $this->assertNotNull($res['results'][2]['error']);
        $this->assertSame(0, $this->rowCount($db, $q));
    }

    /** @dataProvider engineProvider */
    public function testAtomicCommitsWhenEverythingSucceeds(string $engine): void
    {
        [$db, $q] = $this->tableFixture($engine);
        $res = (new ScriptRunner($db))->run([
            'INSERT INTO ' . $q . " VALUES (1, 'a')",
            'INSERT INTO ' . $q . " VALUES (2, 'b')",
        ], ['tx' => 'atomic']);
        $this->assertFalse($res['rolled_back']);
        $this->assertSame(2, $this->rowCount($db, $q));
        // auto-commit (default) keeps going after an error when asked to
        $res = (new ScriptRunner($db))->run([
            'INSERT INTO ' . $q . " VALUES (1, 'dup')",
            'INSERT INTO ' . $q . " VALUES (5, 'e')",
        ], ['stop_on_error' => false]);
        $this->assertCount(2, $res['results']);
        $this->assertSame(3, $this->rowCount($db, $q));
    }

    /** @dataProvider engineProvider */
    public function testTransactionPolicyIsEnforcedBeforeAnythingRuns(string $engine): void
    {
        [$db, $q] = $this->tableFixture($engine);
        $insert = 'INSERT INTO ' . $q . " VALUES (1, 'a')";
        foreach (['atomic', 'dryrun'] as $tx) {
            try {
                (new ScriptRunner($db))->run([$insert, 'COMMIT'], ['tx' => $tx]);
                $this->fail('COMMIT inside the wrapper must be refused');
            } catch (ApiException $e) {
                $this->assertSame('bad_request', $e->getErrorCode());
            }
        }
        $ddl = 'CREATE TABLE ' . $db->quoteIdentifier('ldb_txddl_' . bin2hex(random_bytes(3))) . ' (a INT)';
        if ($db::id() === 'mysql') {
            try {
                (new ScriptRunner($db))->run([$insert, $ddl], ['tx' => 'dryrun']);
                $this->fail('MySQL DDL must be refused in a dry run');
            } catch (ApiException $e) {
                $this->assertStringContainsString('implicit commit', $e->getMessage());
            }
        } else {
            // transactional DDL: rolled back like everything else
            $res = (new ScriptRunner($db))->run([$insert, $ddl], ['tx' => 'dryrun']);
            $this->assertTrue($res['rolled_back']);
            $this->assertNull($res['results'][1]['error']);
        }
        $this->assertSame(0, $this->rowCount($db, $q));
    }

    /** @dataProvider serverProvider */
    public function testBackendIdAndCancellingAnIdleBackendIsHarmless(string $engine): void
    {
        $params = $this->serverParams($engine);
        $victim = $this->driver($params);
        $other = $this->driver($params);
        $id = $victim->backendId();
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $id);
        $this->assertNotSame($id, $other->backendId());
        $this->assertTrue($victim->capabilities()['cancel']);

        // Nothing running: cancel succeeds or reports false, but never breaks the victim.
        $other->cancelBackend((string) $id);
        $this->assertSame([[1]], array_map('array_values', $victim->query('SELECT 1', 10)['rows']));
        $this->assertFalse($other->cancelBackend('not-a-number'));
        $this->assertFalse($other->cancelBackend('2147480000'), 'unknown backend');
    }

    public function testSqliteCannotCancel(): void
    {
        [$db] = $this->tableFixture('sqlite');
        $this->assertNull($db->backendId());
        $this->assertFalse($db->cancelBackend('1'));
        $this->assertFalse($db->capabilities()['cancel']);
    }

    /**
     * Full chain without HTTP: a child process runs an atomic script containing a long sleep
     * through ScriptRunner and registers its backend in a RunRegistry; this process flags the
     * run as cancelled and interrupts the backend from a second connection (as sql.cancel does).
     * @dataProvider serverProvider
     */
    public function testCancelInterruptsARunningQueryFromAnotherConnection(string $engine): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }
        $params = $this->serverParams($engine);
        [$db, $q] = $this->tableFixture($engine);
        $pg = $params['driver'] === 'pgsql';
        $dir = sys_get_temp_dir() . '/oph_cancel_' . bin2hex(random_bytes(4));
        $runId = 'test_' . bin2hex(random_bytes(6));
        $registry = new RunRegistry($dir);
        $this->cleanup[] = static function () use ($dir): void {
            foreach ((array) glob($dir . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($dir);
        };

        $child = sys_get_temp_dir() . '/oph_cancel_child_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($child, self::CHILD);
        $this->cleanup[] = static function () use ($child): void {
            @unlink($child);
        };
        $config = json_encode([
            'autoload' => dirname(__DIR__, 2) . '/vendor/autoload.php',
            'params' => $params,
            'dir' => $dir,
            'run' => $runId,
            'statements' => [
                'INSERT INTO ' . $q . " VALUES (1, 'before sleep')",
                $pg ? 'SELECT pg_sleep(15)' : 'SELECT SLEEP(15)',
                'INSERT INTO ' . $q . " VALUES (2, 'after sleep')",
            ],
        ]);
        $proc = proc_open([PHP_BINARY, $child, (string) $config], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $ready = (string) fgets($pipes[1]);
        // (never read the child's stderr before it exits: that would block until the sleep ends)
        $this->assertSame("READY\n", $ready);
        usleep(1000000); // the sleep statement is running now

        $entry = $registry->lookup('test-session', $runId);
        $this->assertNotNull($entry, 'child registered its backend');
        $this->assertTrue($registry->markCancelled('test-session', $runId));
        $canceller = $this->driver($params);
        $t0 = microtime(true);
        $this->assertTrue($canceller->cancelBackend($entry['backend']));

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->assertLessThan(5.0, microtime(true) - $t0, 'query did not stop promptly');

        $res = json_decode($out, true);
        $this->assertIsArray($res, $out . $err);
        $this->assertTrue($res['cancelled']);
        $this->assertTrue($res['rolled_back']);
        $this->assertCount(2, $res['results'], 'nothing runs after the cancelled statement');
        if ($res['results'][1]['error'] !== null) {
            $this->assertSame('cancelled', $res['results'][1]['error_code']);
        }
        $this->assertSame(0, $this->rowCount($db, $q), 'atomic run rolled back after the cancel');
        $this->assertNull($registry->lookup('test-session', $runId), 'child removed its entry');
    }

    private const CHILD = <<<'PHP'
<?php
declare(strict_types=1);
$cfg = json_decode($argv[1], true);
require $cfg['autoload'];
$db = $cfg['params']['driver'] === 'pgsql' ? new Ldb\Db\PgSqlDriver() : new Ldb\Db\MySqlDriver();
$db->connect($cfg['params']);
$registry = new Ldb\Sql\RunRegistry($cfg['dir']);
$registry->register('test-session', $cfg['run'], ['driver' => $db::id(), 'backend' => (string) $db->backendId(), 'conn' => 'c1']);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$runner = new Ldb\Sql\ScriptRunner($db, static fn (): bool => $registry->isCancelled('test-session', $cfg['run']));
try {
    $res = $runner->run($cfg['statements'], ['tx' => 'atomic']);
} finally {
    $registry->remove('test-session', $cfg['run']);
}
echo json_encode($res);
PHP;

    // ------------------------------------------------------------------ fixtures

    /** @return array{0:DriverInterface,1:string} driver + quoted name of a fresh (id PK, v) table */
    private function tableFixture(string $engine): array
    {
        if ($engine === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->markTestSkipped('pdo_sqlite not available');
            }
            $dir = sys_get_temp_dir() . '/oph_tx_' . bin2hex(random_bytes(4));
            mkdir($dir);
            $file = $dir . '/tx.sqlite';
            $db = new SqliteDriver();
            $db->connect(['driver' => 'sqlite', 'file' => $file, 'create' => true, 'options' => []]);
            $this->cleanup[] = static function () use ($dir, $file): void {
                @unlink($file);
                @unlink($file . '-journal');
                @rmdir($dir);
            };
        } else {
            $db = $this->driver($this->serverParams($engine));
        }
        $table = 'ldb_tx_' . bin2hex(random_bytes(4));
        $q = $db->quoteIdentifier($table);
        $db->pdo()->exec('CREATE TABLE ' . $q . ' (id INT PRIMARY KEY, v VARCHAR(40))'
            . ($db::id() === 'mysql' ? ' ENGINE=InnoDB' : ''));
        $this->cleanup[] = static function () use ($db, $q): void {
            $db->pdo()->exec('DROP TABLE ' . $q);
        };
        return [$db, $q];
    }

    private function rowCount(DriverInterface $db, string $q): int
    {
        return (int) $db->pdo()->query('SELECT COUNT(*) FROM ' . $q)->fetchColumn();
    }

    /** @param array<string,mixed> $params */
    private function driver(array $params): DriverInterface
    {
        $db = $params['driver'] === 'pgsql' ? new PgSqlDriver() : new MySqlDriver();
        $db->connect($params);
        return $db;
    }

    /** @return array<string,mixed> */
    private function serverParams(string $prefix): array
    {
        if ($prefix === 'sqlite') {
            $this->fail('server engine expected');
        }
        $dsn = getenv($prefix . '_DSN');
        $pg = $prefix === 'LDB_TEST_PGSQL';
        if (!$dsn || !extension_loaded($pg ? 'pdo_pgsql' : 'pdo_mysql')) {
            $this->markTestSkipped('Set ' . $prefix . '_DSN/USER/PASS to run this test');
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^\w+:/', '', (string) $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        return [
            'driver' => $pg ? 'pgsql' : 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? ($pg ? 5432 : 3306)),
            'user' => (string) getenv($prefix . '_USER'),
            'password' => (string) getenv($prefix . '_PASS'),
            'database' => $parts['dbname'] ?? ($pg ? 'postgres' : null),
            'options' => [],
        ];
    }
}
