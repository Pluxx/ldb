<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\PgSqlDriver;
use Ldb\Http\ApiException;
use Ldb\Privileges\PrivilegeManager;
use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL privilege manager against a live server. Env: LDB_TEST_PGSQL_DSN / _USER / _PASS
 * (the user needs CREATEROLE + CREATEDB, e.g. postgres). Creates a throwaway database and roles.
 */
final class PrivilegesPgSqlTest extends TestCase
{
    private static ?PgSqlDriver $d = null;
    private static ?PrivilegeManager $m = null;
    private static array $params = [];
    private static string $db = '';
    private static string $user = '';
    private static string $login = '';
    /** @var string[] */
    private static array $roles = [];

    public static function setUpBeforeClass(): void
    {
        self::$d = null;
        $dsn = getenv('LDB_TEST_PGSQL_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^pgsql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        self::$login = (string) getenv('LDB_TEST_PGSQL_USER');
        self::$params = [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => self::$login,
            'password' => (string) getenv('LDB_TEST_PGSQL_PASS'),
            'database' => $parts['dbname'] ?? 'postgres',
            'options' => [],
        ];
        $d = new PgSqlDriver();
        $d->connect(self::$params);
        $rand = bin2hex(random_bytes(4));
        self::$db = 'ldb_priv_' . $rand;
        self::$user = 'ldb_t"é_' . $rand;
        self::$roles = [];
        $d->createDatabase(self::$db);
        $d->select(self::$db, 'public');
        $d->pdo()->exec('CREATE TABLE customers (id int primary key, name varchar(50), email varchar(50))');
        $d->pdo()->exec('CREATE SEQUENCE ldb_seq');
        $d->saveUser(self::$user, null, 'secret-pw', [], true);
        self::$roles[] = self::$user;
        self::$d = $d;
        self::$m = PrivilegeManager::for($d);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d === null) {
            return;
        }
        self::$m = null;
        self::$d = null;
        gc_collect_cycles();
        try {
            $d = new PgSqlDriver();
            $d->connect(self::$params);
            $d->select(self::$db, 'public');
            foreach (self::$roles as $r) {
                try {
                    $d->pdo()->exec('DROP OWNED BY ' . $d->quoteIdentifier($r));
                } catch (\Throwable $e) {
                }
            }
            $d->select('postgres', 'public');
            foreach (self::$roles as $r) {
                try {
                    $d->pdo()->exec('DROP ROLE IF EXISTS ' . $d->quoteIdentifier($r));
                } catch (\Throwable $e) {
                }
            }
            $d->pdo()->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
                . $d->pdo()->quote(self::$db) . ' AND pid <> pg_backend_pid()');
            $d->dropDatabase(self::$db);
        } catch (\Throwable $e) {
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set LDB_TEST_PGSQL_DSN/USER/PASS to run PostgreSQL integration tests');
        }
        // clean slate: no object privileges, memberships or attributes beyond the defaults
        $q = self::$d->quoteIdentifier(self::$user);
        self::$d->select(self::$db, 'public');
        self::$d->pdo()->exec("DROP OWNED BY $q");
        self::$d->pdo()->exec("ALTER ROLE $q WITH LOGIN NOSUPERUSER NOCREATEDB CONNECTION LIMIT -1 VALID UNTIL 'infinity'");
        foreach (self::$d->pdo()->query('SELECT g.rolname FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE u.rolname = ' . self::$d->pdo()->quote(self::$user))->fetchAll(\PDO::FETCH_COLUMN) as $g) {
            self::$d->pdo()->exec('REVOKE ' . self::$d->quoteIdentifier($g) . " FROM $q");
        }
    }

    private function applyOk(array $changes, ?string $user = null): array
    {
        $r = self::$m->apply($user ?? self::$user, null, $changes, false);
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        return $r;
    }

    /** Scalar bool/string from a query in the throwaway database. */
    private function scalar(string $sql, array $params = [])
    {
        self::$d->select(self::$db, 'public');
        $st = self::$d->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_COLUMN)[0] ?? null;
    }

    private function hasTable(string $priv, string $table = 'customers'): bool
    {
        return (bool) $this->scalar('SELECT has_table_privilege(?, ?, ?)', [self::$user, 'public.' . $table, $priv]);
    }

    private static function find(array $rows, array $match): ?array
    {
        foreach ($rows as $row) {
            $ok = true;
            foreach ($match as $k => $v) {
                if (($row[$k] ?? null) !== $v) {
                    $ok = false;
                }
            }
            if ($ok) {
                return $row;
            }
        }
        return null;
    }

    private function objectChanges(): array
    {
        return [
            'databases' => [['db' => self::$db, 'add' => ['CONNECT', 'TEMP']]],
            'schemas' => [['db' => self::$db, 'schema' => 'public', 'add' => ['USAGE']]],
            'tables' => [
                ['db' => self::$db, 'schema' => 'public', 'table' => 'customers', 'add' => ['SELECT', 'INSERT']],
                ['db' => self::$db, 'schema' => 'public', 'table' => 'ldb_seq', 'kind' => 'sequence', 'add' => ['USAGE']],
            ],
            'columns' => [['db' => self::$db, 'schema' => 'public', 'table' => 'customers', 'column' => 'name', 'add' => ['UPDATE']]],
        ];
    }

    public function testObjectPrivilegesGrantReadAndNoOp(): void
    {
        $this->assertFalse($this->hasTable('SELECT'));
        $this->applyOk($this->objectChanges());

        $this->assertTrue($this->hasTable('SELECT'));
        $this->assertTrue($this->hasTable('INSERT'));
        $this->assertFalse($this->hasTable('DELETE'));
        $this->assertFalse($this->hasTable('UPDATE'), 'only the column has UPDATE');
        $this->assertTrue((bool) $this->scalar('SELECT has_column_privilege(?, ?, ?, ?)', [self::$user, 'public.customers', 'name', 'UPDATE']));
        $this->assertFalse((bool) $this->scalar('SELECT has_column_privilege(?, ?, ?, ?)', [self::$user, 'public.customers', 'email', 'UPDATE']));
        $this->assertTrue((bool) $this->scalar('SELECT has_sequence_privilege(?, ?, ?)', [self::$user, 'public.ldb_seq', 'USAGE']));
        $this->assertTrue((bool) $this->scalar('SELECT has_schema_privilege(?, ?, ?)', [self::$user, 'public', 'USAGE']));
        $this->assertTrue((bool) $this->scalar('SELECT has_database_privilege(?, ?, ?)', [self::$user, self::$db, 'TEMPORARY']));

        $state = self::$m->read(self::$user, null, self::$db);
        $this->assertTrue($state['account']['exists']);
        $this->assertFalse($state['self']);
        $db = self::find($state['databases'], ['db' => self::$db]);
        $this->assertEqualsCanonicalizing(['CONNECT', 'TEMPORARY'], $db['privileges']);
        $sc = self::find($state['schemas'], ['schema' => 'public']);
        $this->assertSame(['USAGE'], $sc['privileges']);
        $tb = self::find($state['tables'], ['schema' => 'public', 'table' => 'customers']);
        $this->assertEqualsCanonicalizing(['SELECT', 'INSERT'], $tb['privileges']);
        $sq = self::find($state['tables'], ['table' => 'ldb_seq']);
        $this->assertSame('sequence', $sq['kind']);
        $this->assertSame(['USAGE'], $sq['privileges']);
        $col = self::find($state['columns'], ['table' => 'customers', 'column' => 'name']);
        $this->assertSame(['UPDATE'], $col['privileges']);

        // the same change set plans nothing now, and applying it again executes nothing
        $plan = self::$m->plan(self::$user, null, $this->objectChanges());
        $this->assertSame([], $plan['statements']);
        $this->assertSame([], $plan['self_lockout']);
        $again = self::$m->apply(self::$user, null, $this->objectChanges(), false);
        $this->assertSame(0, $again['executed']);
    }

    public function testRevokeObjectPrivileges(): void
    {
        $this->applyOk($this->objectChanges());
        $this->applyOk([
            'databases' => [['db' => self::$db, 'remove' => ['TEMPORARY']]],
            'tables' => [['db' => self::$db, 'table' => 'customers', 'remove' => ['INSERT']]],
            'columns' => [['db' => self::$db, 'table' => 'customers', 'column' => 'name', 'remove' => ['UPDATE']]],
        ]);
        $this->assertTrue($this->hasTable('SELECT'));
        $this->assertFalse($this->hasTable('INSERT'));
        $this->assertFalse((bool) $this->scalar('SELECT has_column_privilege(?, ?, ?, ?)', [self::$user, 'public.customers', 'name', 'UPDATE']));
        $state = self::$m->read(self::$user, null, self::$db);
        $this->assertSame(['CONNECT'], self::find($state['databases'], ['db' => self::$db])['privileges']);
        $this->assertSame([], array_values(array_filter($state['columns'], fn ($c) => $c['table'] === 'customers')));
        $this->applyOk(['tables' => [['db' => self::$db, 'table' => 'customers', 'remove' => ['SELECT']]]]);
        $this->assertFalse($this->hasTable('SELECT'));
    }

    public function testAttributes(): void
    {
        $this->applyOk(['attributes' => ['createdb' => true, 'connection_limit' => 7, 'valid_until' => '2031-01-01']]);
        $a = self::$m->read(self::$user, null)['account']['attributes'];
        $this->assertTrue($a['createdb']);
        $this->assertSame(7, $a['connection_limit']);
        $this->assertStringStartsWith('2031-01-01', (string) $a['valid_until']);
        $this->assertFalse($a['superuser']);
        $this->assertSame([], self::$m->plan(self::$user, null, ['attributes' => ['createdb' => true, 'connection_limit' => 7]])['statements']);

        $this->applyOk(['attributes' => ['createdb' => false, 'connection_limit' => -1, 'valid_until' => null]]);
        $a = self::$m->read(self::$user, null)['account']['attributes'];
        $this->assertFalse($a['createdb']);
        $this->assertSame(-1, $a['connection_limit']);
        $this->assertNull($a['valid_until'] === 'infinity' ? null : $a['valid_until']);
    }

    public function testLockAndUnlock(): void
    {
        $this->assertFalse(self::$m->read(self::$user, null)['account']['locked']);
        $this->applyOk(['account' => ['lock' => true]]);
        $this->assertTrue(self::$m->read(self::$user, null)['account']['locked']);
        $this->applyOk(['account' => ['unlock' => true]]);
        $this->assertFalse(self::$m->read(self::$user, null)['account']['locked']);
    }

    public function testRoleMembership(): void
    {
        $role = 'ldb_r"é_' . bin2hex(random_bytes(3));
        self::$roles[] = $role;
        $r = self::$m->run(self::$m->createRole($role, null));
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        $state = self::$m->read(self::$user, null);
        $this->assertContains($role, array_column($state['roles']['available'], 'name'));
        $this->assertSame([], $state['roles']['granted']);

        $this->applyOk(['roles' => ['add' => [$role]]]);
        $this->assertSame([$role], array_column(self::$m->read(self::$user, null)['roles']['granted'], 'name'));
        $this->assertSame([], self::$m->plan(self::$user, null, ['roles' => ['add' => [$role]]])['statements']);
        $this->assertTrue((bool) $this->scalar('SELECT pg_has_role(?, ?, ?)', [self::$user, $role, 'MEMBER']));

        // privileges granted to the role are inherited by the member
        $this->applyOk(['tables' => [['db' => self::$db, 'table' => 'customers', 'add' => ['DELETE']]]], $role);
        $this->assertTrue($this->hasTable('DELETE'));

        $this->applyOk(['roles' => ['remove' => [$role]]]);
        $this->assertSame([], self::$m->read(self::$user, null)['roles']['granted']);
        $this->assertFalse($this->hasTable('DELETE'));

        try {
            self::$m->plan(self::$user, null, ['roles' => ['add' => ['ldb_ghost_role']]]);
            $this->fail('Expected bad_request');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
        }

        self::$d->select(self::$db, 'public');
        self::$d->pdo()->exec('DROP OWNED BY ' . self::$d->quoteIdentifier($role));
        $r = self::$m->run(self::$m->dropRole($role, null));
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        self::$roles = array_values(array_diff(self::$roles, [$role]));
    }

    public function testRenameAndRenameBack(): void
    {
        $new = self::$user . '_ren';
        self::$roles[] = $new;
        $this->applyOk(['rename' => ['user' => $new]]);
        $this->assertFalse(self::$m->read(self::$user, null)['account']['exists']);
        $this->assertTrue(self::$m->read($new, null)['account']['exists']);
        $this->applyOk(['rename' => ['user' => self::$user]], $new);
        $this->assertTrue(self::$m->read(self::$user, null)['account']['exists']);
        $this->assertFalse(self::$m->read($new, null)['account']['exists']);
    }

    public function testCloneFrom(): void
    {
        $other = 'ldb_o"_' . bin2hex(random_bytes(3));
        self::$roles[] = $other;
        self::$d->saveUser($other, null, 'pw', [], true);
        $this->applyOk(['tables' => [['db' => self::$db, 'table' => 'customers', 'add' => ['SELECT']]]]);
        $this->applyOk(['clone_from' => ['user' => self::$user, 'db' => self::$db]], $other);
        $this->assertTrue((bool) $this->scalar('SELECT has_table_privilege(?, ?, ?)', [$other, 'public.customers', 'SELECT']));
    }

    public function testInvalidChangesAreRejected(): void
    {
        foreach ([
            ['attributes' => ['valid_until' => "2030'; DROP"]],
            ['tables' => [['db' => self::$db, 'table' => 'customers', 'add' => ['DROP']]]],
            ['bogus' => 1],
        ] as $changes) {
            try {
                self::$m->plan(self::$user, null, $changes);
                $this->fail('Expected bad_request for ' . json_encode($changes));
            } catch (ApiException $e) {
                $this->assertSame('bad_request', $e->getErrorCode());
            }
        }
        try {
            self::$m->plan('ldb_no_such_role_' . bin2hex(random_bytes(3)), null, ['attributes' => ['createdb' => true]]);
            $this->fail('Expected not_found');
        } catch (ApiException $e) {
            $this->assertSame('not_found', $e->getErrorCode());
        }
    }

    public function testSelfLockoutIsRefusedAndNothingExecuted(): void
    {
        $state = self::$m->read(self::$login, null);
        $this->assertTrue($state['self']);
        $this->assertTrue($state['account']['attributes']['superuser']);

        foreach ([
            [['attributes' => ['superuser' => false]], 'revoke_superuser'],
            [['attributes' => ['login' => false]], 'revoke_login'],
            [['account' => ['lock' => true]], 'revoke_login'],
            [['rename' => ['user' => self::$login . '_renamed']], 'rename_self'],
        ] as [$changes, $reason]) {
            try {
                self::$m->apply(self::$login, null, $changes, false);
                $this->fail('Expected self_lockout for ' . $reason);
            } catch (ApiException $e) {
                $this->assertSame('self_lockout', $e->getErrorCode());
                $this->assertContains($reason, $e->getDetails()['reasons']);
            }
            $a = self::$m->read(self::$login, null);
            $this->assertTrue($a['account']['attributes']['superuser'], 'nothing must have been executed');
            $this->assertTrue($a['account']['attributes']['login']);
            $this->assertTrue($a['account']['exists']);
        }
        $plan = self::$m->plan(self::$login, null, ['attributes' => ['superuser' => false]]);
        $this->assertTrue($plan['self']);
        $this->assertSame(['revoke_superuser'], $plan['self_lockout']);
        $this->assertSame(['ALTER ROLE ' . self::$d->quoteIdentifier(self::$login) . ' WITH NOSUPERUSER'], array_column($plan['statements'], 'sql'));
    }

    public function testRunCollectsErrors(): void
    {
        $r = self::$m->run([
            ['sql' => 'GRANT SELECT ON TABLE "public"."nope" TO "ldb_ghost"', 'db' => self::$db],
            ['sql' => 'SELECT 1', 'db' => null],
        ]);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, $r['executed']);
        $this->assertFalse($r['results'][0]['ok']);
        $this->assertNotEmpty($r['results'][0]['error']);
    }

    public function testObjects(): void
    {
        $o = self::$m->objects(self::$db);
        $this->assertContains('public', $o['schemas']);
        $t = self::find($o['tables'], ['name' => 'customers']);
        $this->assertSame(['id', 'name', 'email'], $t['columns']);
        $this->assertSame('sequence', self::find($o['tables'], ['name' => 'ldb_seq'])['kind']);
    }
}
