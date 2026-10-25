<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use Ldb\Privileges\PrivilegeManager;
use PHPUnit\Framework\TestCase;

/**
 * Shared MySQL-family tests of the privilege manager against a live server. Subclasses name the
 * env prefix (LDB_TEST_MYSQL_* / LDB_TEST_MARIADB_*; the user needs CREATE USER / GRANT OPTION /
 * CREATE DATABASE, e.g. root). A throwaway database, users and roles are created and dropped.
 */
abstract class PrivilegesMySqlTestCase extends TestCase
{
    protected static ?MySqlDriver $d = null;
    protected static ?PrivilegeManager $m = null;
    protected static string $db = '';
    protected static string $user = '';
    /** @var array<string,mixed> connection parameters of the admin connection (reused for the test user) */
    protected static array $conn = [];
    protected static string $other = '';
    /** @var array<int,array{0:string,1:string}> accounts to drop at the end */
    protected static array $accounts = [];
    /** @var string[] */
    protected static array $roles = [];

    abstract protected static function envPrefix(): string;

    public static function setUpBeforeClass(): void
    {
        self::$d = null;
        $dsn = getenv(static::envPrefix() . '_DSN');
        if (!$dsn || !extension_loaded('pdo_mysql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $d = new MySqlDriver();
        self::$conn = [
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => (string) getenv(static::envPrefix() . '_USER'),
            'password' => (string) getenv(static::envPrefix() . '_PASS'),
            'database' => null,
            'options' => [],
        ];
        $d->connect(self::$conn);
        $rand = bin2hex(random_bytes(4));
        self::$db = 'ldb_priv_' . $rand;
        self::$user = "ldb_t'é_" . $rand;
        self::$other = 'ldb_o`x_' . $rand;
        self::$accounts = [];
        self::$roles = [];
        $d->createDatabase(self::$db, 'utf8mb4_unicode_ci');
        $d->select(self::$db);
        $d->pdo()->exec('CREATE TABLE customers (id int primary key, name varchar(50), email varchar(50))');
        $d->saveUser(self::$user, '%', 'secret-pw', [], true);
        self::$accounts[] = [self::$user, '%'];
        self::$d = $d;
        self::$m = PrivilegeManager::for($d);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d === null) {
            return;
        }
        $d = self::$d;
        foreach (self::$roles as $r) {
            try {
                $d->pdo()->exec('DROP ROLE ' . self::q($r) . (self::$m !== null && !$d->isMariaDb() ? '@`%`' : ''));
            } catch (\Throwable $e) {
            }
        }
        foreach (self::$accounts as [$u, $h]) {
            try {
                $d->dropUser($u, $h);
            } catch (\Throwable $e) {
            }
        }
        try {
            $d->dropDatabase(self::$db);
        } catch (\Throwable $e) {
        }
        self::$d = null;
        self::$m = null;
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set ' . static::envPrefix() . '_DSN/USER/PASS to run these integration tests');
        }
        // every test starts from an account without privileges, role grants or options
        $acct = self::q(self::$user) . '@`%`';
        self::$d->pdo()->exec('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $acct);
        self::$d->pdo()->exec('ALTER USER ' . $acct . ' ACCOUNT UNLOCK');
    }

    protected static function q(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function manager(): PrivilegeManager
    {
        return self::$m;
    }

    /** Apply a change set to the throwaway user and assert that every statement succeeded. */
    protected function applyOk(array $changes, ?string $user = null, ?string $host = '%'): array
    {
        $r = $this->manager()->apply($user ?? self::$user, $host, $changes, false);
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        return $r;
    }

    protected function grantsText(?string $user = null, ?string $host = '%'): string
    {
        return implode("\n", self::$d->userGrants($user ?? self::$user, $host));
    }

    protected function roleHost(): ?string
    {
        return self::$d->isMariaDb() ? null : '%';
    }

    protected function newRole(string $prefix): string
    {
        $name = $prefix . bin2hex(random_bytes(3));
        $r = $this->manager()->run($this->manager()->createRole($name, '%'));
        self::$roles[] = $name;
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        return $name;
    }

    protected function dropRole(string $name): void
    {
        $r = $this->manager()->run($this->manager()->dropRole($name, '%'));
        $this->assertSame(0, $r['failed'], json_encode($r['results']));
        self::$roles = array_values(array_diff(self::$roles, [$name]));
    }

    protected static function find(array $rows, array $match): ?array
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
            'databases' => [['db' => self::$db, 'add' => ['SELECT']]],
            'tables' => [['db' => self::$db, 'table' => 'customers', 'add' => ['INSERT']]],
            'columns' => [['db' => self::$db, 'table' => 'customers', 'column' => 'name', 'add' => ['UPDATE']]],
        ];
    }

    public function testGrantsAtDatabaseTableAndColumnLevel(): void
    {
        $r = $this->applyOk($this->objectChanges());
        $this->assertSame(3, $r['executed']);

        $grants = $this->grantsText();
        $this->assertMatchesRegularExpression('/GRANT SELECT ON `ldb\\\\?_priv\\\\?_[0-9a-f]+`\.\* TO /', $grants);
        // table and column privileges of one table share a line: GRANT INSERT, UPDATE (`name`) ON …
        $this->assertStringContainsString('INSERT, UPDATE (`name`) ON `' . self::$db . '`.`customers` TO', $grants);
        $this->assertStringNotContainsString('SELECT (`name`)', $grants);

        $state = $this->manager()->read(self::$user, '%');
        $this->assertTrue($state['account']['exists']);
        $this->assertFalse($state['self']);
        $db = self::find($state['databases'], ['db' => self::$db]);
        $this->assertNotNull($db, 'database privileges missing (db name as reported: ' . json_encode($state['databases']) . ')');
        $this->assertSame(['SELECT'], $db['privileges']);
        $tb = self::find($state['tables'], ['db' => self::$db, 'table' => 'customers']);
        $this->assertSame(['INSERT'], $tb['privileges']);
        $col = self::find($state['columns'], ['db' => self::$db, 'table' => 'customers', 'column' => 'name']);
        $this->assertSame(['UPDATE'], $col['privileges']);
        $this->assertSame([], $state['global']);
    }

    /** Databases visible to the throwaway user (a second connection logged in as that user). */
    private function visibleDatabasesAsUser(): array
    {
        $u = new MySqlDriver();
        $u->connect(['user' => self::$user, 'password' => 'secret-pw'] + self::$conn);
        return array_map('strval', $u->pdo()->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDatabaseGrantEscapesWildcards(): void
    {
        $pdo = self::$d->pdo();
        $rand = bin2hex(random_bytes(3));
        $lit = 'ldb_it_' . $rand;
        $other = 'ldb_itX' . $rand;
        $pdo->exec('CREATE DATABASE ' . self::q($lit));
        $pdo->exec('CREATE DATABASE ' . self::q($other));
        try {
            $this->applyOk(['databases' => [['db' => $lit, 'add' => ['SELECT']]]]);
            $this->assertStringContainsString('ON `ldb\\_it\\_' . $rand . '`.*', $this->grantsText());

            $visible = $this->visibleDatabasesAsUser();
            $this->assertContains($lit, $visible);
            $this->assertNotContains($other, $visible, 'literal grant must not match a wildcard-equivalent database');
            $isg = $pdo->query("SELECT GRANTEE, TABLE_SCHEMA FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA IN ('"
                . $lit . "', '" . $other . "') AND PRIVILEGE_TYPE = 'SELECT'")->fetchAll(\PDO::FETCH_ASSOC);
            $schemas = array_column($isg, 'TABLE_SCHEMA');
            $this->assertNotContains($other, $schemas);

            // read-back shows the plain name, not a pattern; re-planning is a no-op
            $state = $this->manager()->read(self::$user, '%');
            $row = self::find($state['databases'], ['db' => $lit]);
            $this->assertNotNull($row, json_encode($state['databases']));
            $this->assertFalse($row['pattern']);
            $changes = ['databases' => [['db' => $lit, 'add' => ['SELECT']]]];
            $this->assertSame([], $this->manager()->plan(self::$user, '%', $changes)['statements'] ?? []);

            // revoke goes through the same escaping
            $this->applyOk(['databases' => [['db' => $lit, 'remove' => ['SELECT']]]]);
            $this->assertNotContains($lit, $this->visibleDatabasesAsUser());
        } finally {
            $pdo->exec('DROP DATABASE ' . self::q($lit));
            $pdo->exec('DROP DATABASE ' . self::q($other));
        }
    }

    public function testExistingWildcardPatternIsMarkedAndRevokedAsStored(): void
    {
        $pdo = self::$d->pdo();
        $rand = bin2hex(random_bytes(3));
        $pat = 'ldb_wp' . $rand . '%';
        $pdo->exec('GRANT SELECT ON ' . self::q($pat) . '.* TO ' . self::q(self::$user) . '@`%`');
        $state = $this->manager()->read(self::$user, '%');
        $row = self::find($state['databases'], ['db' => $pat]);
        $this->assertNotNull($row, json_encode($state['databases']));
        $this->assertTrue($row['pattern']);

        $r = $this->applyOk(['databases' => [['db' => $pat, 'pattern' => true, 'remove' => ['SELECT']]]]);
        $this->assertSame(1, $r['executed']);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertNull(self::find($state['databases'], ['db' => $pat]), json_encode($state['databases']));
    }

    public function testPlanOfAppliedChangesIsEmpty(): void
    {
        $this->applyOk($this->objectChanges());
        $plan = $this->manager()->plan(self::$user, '%', $this->objectChanges());
        $this->assertSame([], $plan['statements']);
        $this->assertSame([], $plan['self_lockout']);
        $this->assertFalse($plan['self']);
        // applying again is a no-op
        $r = $this->manager()->apply(self::$user, '%', $this->objectChanges(), false);
        $this->assertSame(0, $r['executed']);
        $this->assertSame(0, $r['failed']);
    }

    public function testRevoke(): void
    {
        $this->applyOk($this->objectChanges());
        $this->applyOk([
            'databases' => [['db' => self::$db, 'remove' => ['SELECT']]],
            'tables' => [['db' => self::$db, 'table' => 'customers', 'remove' => ['INSERT']]],
            'columns' => [['db' => self::$db, 'table' => 'customers', 'column' => 'name', 'remove' => ['UPDATE']]],
        ]);
        $grants = $this->grantsText();
        $this->assertStringNotContainsString(self::$db, str_replace('\\', '', $grants));
        $state = $this->manager()->read(self::$user, '%');
        $this->assertSame([], $state['databases']);
        $this->assertSame([], $state['tables']);
        $this->assertSame([], $state['columns']);
        // revoking what is not granted plans nothing
        $this->assertSame([], $this->manager()->plan(self::$user, '%', ['databases' => [['db' => self::$db, 'remove' => ['SELECT']]]])['statements']);
    }

    public function testGlobalAndGrantOption(): void
    {
        $this->applyOk(['global' => ['add' => ['SELECT', 'PROCESS', 'GRANT OPTION']]]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertEqualsCanonicalizing(['SELECT', 'PROCESS', 'GRANT OPTION'], $state['global']);
        $this->assertStringContainsString('WITH GRANT OPTION', $this->grantsText());
        $this->applyOk(['global' => ['remove' => ['SELECT', 'GRANT OPTION']]]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertSame(['PROCESS'], $state['global']);
    }

    public function testInvalidChangesAreRejected(): void
    {
        foreach ([
            ['global' => ['add' => ['DROP DATABASE']]],
            ['databases' => [['db' => self::$db, 'add' => ['SUPER']]]],
            ['bogus' => []],
        ] as $changes) {
            try {
                $this->manager()->plan(self::$user, '%', $changes);
                $this->fail('Expected bad_request for ' . json_encode($changes));
            } catch (ApiException $e) {
                $this->assertSame('bad_request', $e->getErrorCode());
            }
        }
        try {
            $this->manager()->plan('ldb_no_such_user_' . bin2hex(random_bytes(3)), '%', ['global' => ['add' => ['SELECT']]]);
            $this->fail('Expected not_found');
        } catch (ApiException $e) {
            $this->assertSame('not_found', $e->getErrorCode());
        }
    }

    public function testRolesGrantDefaultAndRevoke(): void
    {
        $role = $this->newRole('ldb_r_');
        $state = $this->manager()->read(self::$user, '%');
        $names = array_column($state['roles']['available'], 'name');
        $this->assertContains($role, $names);
        $this->assertSame([], $state['roles']['granted']);

        $this->applyOk(['roles' => ['add' => [['name' => $role, 'host' => '%']]]]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertNotNull(self::find($state['roles']['granted'], ['name' => $role]));
        $this->assertSame([], $state['roles']['default']);

        $this->applyOk(['roles' => ['default' => [['name' => $role, 'host' => '%']]]]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertSame([$role], array_column($state['roles']['default'], 'name'));
        $granted = self::find($state['roles']['granted'], ['name' => $role]);
        $this->assertTrue($granted['default']);
        // same default again plans nothing
        $this->assertSame([], $this->manager()->plan(self::$user, '%', ['roles' => ['default' => [['name' => $role, 'host' => '%']]]])['statements']);

        $this->applyOk(['roles' => ['default' => []]]);
        $this->assertSame([], $this->manager()->read(self::$user, '%')['roles']['default']);

        $this->applyOk(['roles' => ['remove' => [['name' => $role, 'host' => '%']]]]);
        $this->assertSame([], $this->manager()->read(self::$user, '%')['roles']['granted']);

        $this->dropRole($role);
        $this->assertNotContains($role, array_column($this->manager()->read(self::$user, '%')['roles']['available'], 'name'));
    }

    public function testUnknownRoleIsRejected(): void
    {
        try {
            $this->manager()->plan(self::$user, '%', ['roles' => ['add' => ['ldb_ghost_role']]]);
            $this->fail('Expected bad_request');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
        }
    }

    public function testPrivilegesOnARole(): void
    {
        $role = $this->newRole('ldb_rp_');
        $this->applyOk(['databases' => [['db' => self::$db, 'add' => ['SELECT']]]], $role, $this->roleHost());
        $state = $this->manager()->read($role, $this->roleHost());
        $this->assertTrue($state['account']['exists']);
        $this->assertNotNull(self::find($state['databases'], ['db' => $state['databases'][0]['db']]));
        $this->assertSame(['SELECT'], $state['databases'][0]['privileges']);
        // a user inherits it once the role is granted and default
        $this->applyOk(['roles' => ['add' => [['name' => $role, 'host' => '%']]]]);
        $this->dropRole($role);
    }

    public function testRenameAndRenameBack(): void
    {
        $new = self::$user . '_ren';
        self::$accounts[] = [$new, '%'];
        $this->applyOk(['rename' => ['user' => $new]]);
        $this->assertFalse($this->manager()->read(self::$user, '%')['account']['exists']);
        $this->assertTrue($this->manager()->read($new, '%')['account']['exists']);
        $this->applyOk(['rename' => ['user' => self::$user]], $new);
        $this->assertTrue($this->manager()->read(self::$user, '%')['account']['exists']);
        $this->assertFalse($this->manager()->read($new, '%')['account']['exists']);
    }

    public function testRenameHost(): void
    {
        self::$accounts[] = [self::$user, '10.0.%'];
        $this->applyOk(['rename' => ['host' => '10.0.%']]);
        $this->assertTrue($this->manager()->read(self::$user, '10.0.%')['account']['exists']);
        $this->applyOk(['rename' => ['host' => '%']], self::$user, '10.0.%');
        $this->assertTrue($this->manager()->read(self::$user, '%')['account']['exists']);
    }

    public function testLockAndUnlock(): void
    {
        $this->assertFalse($this->manager()->read(self::$user, '%')['account']['locked']);
        $this->applyOk(['account' => ['lock' => true]]);
        $this->assertTrue($this->manager()->read(self::$user, '%')['account']['locked']);
        // locking again is a no-op
        $this->assertSame([], $this->manager()->plan(self::$user, '%', ['account' => ['lock' => true]])['statements']);
        $this->applyOk(['account' => ['unlock' => true]]);
        $this->assertFalse($this->manager()->read(self::$user, '%')['account']['locked']);
    }

    public function testLimitsAndRequireSsl(): void
    {
        $this->applyOk(['limits' => ['max_user_connections' => 3, 'max_queries_per_hour' => 500], 'account' => ['require_ssl' => 'SSL']]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertSame(3, $state['account']['limits']['max_user_connections']);
        $this->assertSame(500, $state['account']['limits']['max_queries_per_hour']);
        $this->assertSame('SSL', $state['account']['require_ssl']);
        $this->applyOk(['limits' => ['max_user_connections' => 0, 'max_queries_per_hour' => 0], 'account' => ['require_ssl' => 'NONE']]);
        $state = $this->manager()->read(self::$user, '%');
        $this->assertSame(0, $state['account']['limits']['max_user_connections']);
        $this->assertSame('NONE', $state['account']['require_ssl']);
    }

    public function testCloneFrom(): void
    {
        self::$accounts[] = [self::$other, '%'];
        self::$d->saveUser(self::$other, '%', 'pw', [], true);
        $this->applyOk(['databases' => [['db' => self::$db, 'add' => ['SELECT']]], 'global' => ['add' => ['PROCESS']]]);
        $this->applyOk(['clone_from' => ['user' => self::$user, 'host' => '%']], self::$other);
        $state = $this->manager()->read(self::$other, '%');
        $this->assertSame(['PROCESS'], $state['global']);
        $this->assertSame(['SELECT'], $state['databases'][0]['privileges']);
    }

    public function testSelfLockoutIsRefusedAndNothingExecuted(): void
    {
        $cur = (string) self::$d->pdo()->query('SELECT CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN)[0];
        $pos = strrpos($cur, '@');
        $login = substr($cur, 0, $pos);
        $host = substr($cur, $pos + 1);
        $state = $this->manager()->read($login, $host);
        $this->assertTrue($state['self']);
        $this->assertContains('SUPER', $state['global']);

        foreach ([
            [['global' => ['remove' => ['SUPER']]], 'revoke_admin'],
            [['account' => ['lock' => true]], 'lock_self'],
            [['rename' => ['user' => $login . '_renamed']], 'rename_self'],
        ] as [$changes, $reason]) {
            try {
                $this->manager()->apply($login, $host, $changes, false);
                $this->fail('Expected self_lockout for ' . $reason);
            } catch (ApiException $e) {
                $this->assertSame('self_lockout', $e->getErrorCode());
                $this->assertContains($reason, $e->getDetails()['reasons']);
            }
            $after = $this->manager()->read($login, $host);
            $this->assertContains('SUPER', $after['global'], 'nothing must have been executed');
            $this->assertFalse($after['account']['locked']);
        }
        $plan = $this->manager()->plan($login, $host, ['global' => ['remove' => ['SUPER']]]);
        $this->assertTrue($plan['self']);
        $this->assertContains('revoke_admin', $plan['self_lockout']);
        $this->assertCount(1, $plan['statements']);
    }

    public function testRunCollectsErrors(): void
    {
        $r = $this->manager()->run([
            ['sql' => 'GRANT SELECT ON *.* TO `ldb_ghost_user_x`@`%`', 'db' => null],
            ['sql' => 'DO 1', 'db' => null],
        ]);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, $r['executed']);
        $this->assertFalse($r['results'][0]['ok']);
        $this->assertNotEmpty($r['results'][0]['error']);
        $r = $this->manager()->run([
            ['sql' => 'GRANT SELECT ON *.* TO `ldb_ghost_user_x`@`%`', 'db' => null],
            ['sql' => 'DO 1', 'db' => null],
        ], true);
        $this->assertCount(1, $r['results']);
    }

    public function testObjectsListsTablesAndColumns(): void
    {
        $o = $this->manager()->objects(self::$db);
        $this->assertSame(self::$db, $o['db']);
        $this->assertSame('customers', $o['tables'][0]['name']);
        $this->assertSame(['id', 'name', 'email'], $o['tables'][0]['columns']);
    }
}
