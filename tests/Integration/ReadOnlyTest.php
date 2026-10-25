<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Config;
use Ldb\Db\AbstractPdoDriver;
use Ldb\Db\DriverFactory;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqliteDriver;
use PHPUnit\Framework\TestCase;

/**
 * Proves the driver-level read-only session (DriverInterface::setReadOnly) rejects writes even
 * when SQL is sent straight to the driver, bypassing Kernel route flags and ReadOnlyGuard.
 * Server engines use LDB_TEST_{MYSQL,MARIADB,PGSQL}_{DSN,USER,PASS}; SQLite always runs.
 */
final class ReadOnlyTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public function serverProvider(): array
    {
        return ['mysql' => ['LDB_TEST_MYSQL'], 'mariadb' => ['LDB_TEST_MARIADB'], 'pgsql' => ['LDB_TEST_PGSQL']];
    }

    /** @dataProvider serverProvider */
    public function testServerSessionRejectsWrites(string $prefix): void
    {
        $params = $this->serverParams($prefix);
        $pg = $params['driver'] === 'pgsql';
        $table = 'ldb_ro_' . bin2hex(random_bytes(4));

        $rw = $pg ? new PgSqlDriver() : new MySqlDriver();
        $rw->connect($params);
        $q = $rw->quoteIdentifier($table);
        $rw->pdo()->exec('CREATE TABLE ' . $q . ' (id INT PRIMARY KEY, v VARCHAR(20))');
        $rw->pdo()->exec('INSERT INTO ' . $q . " VALUES (1, 'a')");
        if ($pg) {
            $rw->pdo()->exec('CREATE SEQUENCE ' . $rw->quoteIdentifier($table . '_seq'));
        }
        try {
            // 1) read_only passed through connect params
            $ro = $pg ? new PgSqlDriver() : new MySqlDriver();
            $ro->connect($params + ['read_only' => true]);
            $this->assertWritesFail($ro, $q, $pg ? $rw->quoteIdentifier($table . '_seq') : null);
            $res = $ro->query('SELECT v FROM ' . $q, 10);
            $this->assertSame([['a']], $res['rows']);

            // 2) setReadOnly(true) after connecting
            $ro2 = $pg ? new PgSqlDriver() : new MySqlDriver();
            $ro2->connect($params);
            $ro2->setReadOnly(true);
            $this->assertWritesFail($ro2, $q, null);

            // 3) survives a database switch (PostgreSQL reconnects lazily)
            if ($pg) {
                $ro2->select((string) $params['database'], 'public');
                $this->assertWritesFail($ro2, $q, null);
            }

            // 4) setReadOnly(false) restores write access
            $ro2->setReadOnly(false);
            $this->assertSame(1, $ro2->pdo()->exec('UPDATE ' . $q . " SET v = 'b'"));
        } finally {
            $rw->pdo()->exec('DROP TABLE ' . $q);
            if ($pg) {
                $rw->pdo()->exec('DROP SEQUENCE ' . $rw->quoteIdentifier($table . '_seq'));
            }
        }
    }

    public function testSqliteSessionRejectsWrites(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $dir = sys_get_temp_dir() . '/oph_ro_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/ro.sqlite';
        try {
            $rw = new SqliteDriver();
            $rw->connect(['driver' => 'sqlite', 'file' => $file, 'create' => true, 'options' => []]);
            $rw->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
            $rw->pdo()->exec("INSERT INTO t VALUES (1, 'a')");

            // Through DriverFactory, as the app does: a read_only preset forces it.
            $factory = new DriverFactory(new Config([
                'sqlite_paths' => [$dir],
                'servers' => [['id' => 'lite', 'driver' => 'sqlite', 'read_only' => true]],
            ]));
            $this->assertTrue($factory->isReadOnly(['preset' => 'lite']));
            $this->assertFalse($factory->isReadOnly(['preset' => 'other']));
            $ro = $factory->connect(['driver' => 'sqlite', 'file' => $file, 'preset' => 'lite', 'options' => []]);
            $this->assertInstanceOf(AbstractPdoDriver::class, $ro);
            $this->assertWritesFail($ro, 't', null);
            $this->assertSame([['a']], $ro->query('SELECT v FROM t', 10)['rows']);

            // Global switch
            $global = new DriverFactory(new Config(['sqlite_paths' => [$dir], 'read_only' => true]));
            $this->assertTrue($global->isReadOnly([]));
            $this->assertWritesFail($global->connect(['driver' => 'sqlite', 'file' => $file, 'options' => []]), 't', null);

            // Default: writable
            $plain = new DriverFactory(new Config(['sqlite_paths' => [$dir]]));
            $this->assertFalse($plain->isReadOnly([]));
            $this->assertSame(1, $plain->connect(['driver' => 'sqlite', 'file' => $file, 'options' => []])
                ->pdo()->exec("UPDATE t SET v = 'b'"));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /** @param \Ldb\Db\DriverInterface $d */
    private function assertWritesFail($d, string $qTable, ?string $qSeq): void
    {
        $writes = [
            'INSERT INTO ' . $qTable . " VALUES (2, 'x')",
            'UPDATE ' . $qTable . " SET v = 'y'",
            'DELETE FROM ' . $qTable,
            'CREATE TABLE ' . $qTable . '_x (a INT)',
        ];
        if ($qSeq !== null) {
            $writes[] = "SELECT nextval('" . trim($qSeq, '"') . "')";
        }
        foreach ($writes as $sql) {
            try {
                $d->query($sql, 10);
                $this->fail('Write succeeded on a read-only session: ' . $sql);
            } catch (\PDOException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string,mixed> */
    private function serverParams(string $prefix): array
    {
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
