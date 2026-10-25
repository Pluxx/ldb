<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\PgSqlDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Database settings on PostgreSQL (env LDB_TEST_PGSQL_DSN / _USER / _PASS): rename, comment,
 * owner, connection limit and CREATE DATABASE … TEMPLATE on throwaway databases.
 */
final class PgSqlDatabaseSettingsTest extends TestCase
{
    private array $params = [];
    private string $db = '';
    private string $role = '';

    protected function setUp(): void
    {
        $dsn = getenv('LDB_TEST_PGSQL_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('Set LDB_TEST_PGSQL_DSN/USER/PASS to run PostgreSQL integration tests');
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^pgsql:/', '', (string) $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $this->params = [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => (string) getenv('LDB_TEST_PGSQL_USER'),
            'password' => (string) getenv('LDB_TEST_PGSQL_PASS'),
            'database' => $parts['dbname'] ?? 'postgres',
            'options' => [],
        ];
        $suffix = bin2hex(random_bytes(4));
        $this->db = 'ldb_ds_' . $suffix;
        $this->role = 'ldb_ds_role_' . $suffix;
        $d = $this->driver();
        $d->createDatabase($this->db);
        $d->pdo()->exec('CREATE ROLE "' . $this->role . '" NOLOGIN');
        $d->select($this->db);
        $d->pdo()->exec('CREATE TABLE t (id int PRIMARY KEY, v text)');
        $d->pdo()->exec("INSERT INTO t VALUES (1, 'eins'), (2, 'zwei')");
    }

    private function driver(?string $db = null): PgSqlDriver
    {
        $d = new PgSqlDriver();
        $p = $this->params;
        if ($db !== null) {
            $p['database'] = $db;
        }
        $d->connect($p);
        return $d;
    }

    protected function tearDown(): void
    {
        if ($this->params === []) {
            return;
        }
        gc_collect_cycles();
        $d = $this->driver();
        foreach ([$this->db, $this->db . '_renamed', $this->db . '_copy'] as $db) {
            $d->pdo()->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ' . $d->pdo()->quote($db) . ' AND pid <> pg_backend_pid()');
            $d->pdo()->exec('DROP DATABASE IF EXISTS "' . $db . '"');
        }
        $d->pdo()->exec('DROP ROLE IF EXISTS "' . $this->role . '"');
    }

    public function testInfoCommentOwnerLimitAndRename(): void
    {
        $d = $this->driver();
        $info = $d->databaseInfo($this->db);
        $this->assertSame('UTF8', $info['settings']['encoding']);
        $this->assertSame(-1, $info['settings']['connection_limit']);
        $this->assertNull($info['settings']['comment']);
        $this->assertContains($this->role, $info['roles']);

        $comment = "Kunden's \"DB\" – 日本語";
        $d->alterDatabase($this->db, ['comment' => $comment, 'owner' => $this->role, 'connection_limit' => 7]);
        $info = $d->databaseInfo($this->db);
        $this->assertSame($comment, $info['settings']['comment']);
        $this->assertSame($this->role, $info['settings']['owner']);
        $this->assertSame(7, $info['settings']['connection_limit']);

        // rename while this driver is connected to the database itself: it moves to "postgres" first
        $self = $this->driver($this->db);
        $self->pdo();
        unset($self);
        gc_collect_cycles();
        $onDb = $this->driver($this->db);
        $plan = $onDb->alterDatabase($this->db, ['rename' => $this->db . '_renamed', 'comment' => '']);
        $this->assertSame('ALTER DATABASE "' . $this->db . '" RENAME TO "' . $this->db . '_renamed"', end($plan['sql']));
        $info = $this->driver()->databaseInfo($this->db . '_renamed');
        $this->assertNull($info['settings']['comment']);
        $this->assertSame('2', (string) $this->driver($this->db . '_renamed')->query('SELECT count(*) FROM t', 1)['rows'][0][0]);
    }

    public function testRenameRefusedWhileOtherSessionsAreConnected(): void
    {
        $other = $this->driver($this->db);
        $other->pdo()->query('SELECT 1')->fetchAll();
        try {
            $this->driver()->alterDatabase($this->db, ['rename' => $this->db . '_renamed']);
            $this->fail('Expected in_use');
        } catch (ApiException $e) {
            $this->assertSame('in_use', $e->getErrorCode());
            $this->assertStringContainsString('other sessions', $e->getMessage());
        }
        unset($other);
    }

    public function testCopyWithTemplate(): void
    {
        $d = $this->driver($this->db); // connected to the source: copyDatabase moves away first
        $preview = $d->copyDatabase($this->db, $this->db . '_copy', ['preview' => true]);
        $this->assertSame(['CREATE DATABASE "' . $this->db . '_copy" TEMPLATE "' . $this->db . '"'], $preview['sql']);
        $res = $d->copyDatabase($this->db, $this->db . '_copy');
        $this->assertTrue($res['executed']);
        $this->assertSame('zwei', $this->driver($this->db . '_copy')->query('SELECT v FROM t WHERE id = 2', 1)['rows'][0][0]);
        $this->expectException(ApiException::class);
        $d->copyDatabase($this->db, $this->db . '_x', ['drop_source' => true]);
    }
}
