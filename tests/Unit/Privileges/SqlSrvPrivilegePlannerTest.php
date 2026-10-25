<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Privileges;

use Ldb\Http\ApiException;
use Ldb\Privileges\SqlSrvPrivilegePlanner;
use PHPUnit\Framework\TestCase;

/** Exact SQL generation of the SQL Server privilege planner (no database needed). */
final class SqlSrvPrivilegePlannerTest extends TestCase
{
    private function sql(string $user, array $changes, ?array $current = null): array
    {
        return array_column((new SqlSrvPrivilegePlanner())->plan($user, null, $changes, $current)['statements'], 'sql');
    }

    private function assertBadRequest(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected ApiException bad_request');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
        }
    }

    public function testServerRoleMembership(): void
    {
        $this->assertSame(
            ['ALTER SERVER ROLE [sysadmin] DROP MEMBER [x]', 'ALTER SERVER ROLE [dbcreator] ADD MEMBER [x]'],
            $this->sql('x', ['server_roles' => ['add' => ['dbcreator'], 'remove' => ['sysadmin']]])
        );
        $this->assertSame(
            ['ALTER SERVER ROLE [sysadmin] ADD MEMBER [x]'],
            $this->sql('x', ['server_roles' => ['add' => ['sysadmin']]])
        );
    }

    public function testServerRoleStateChecks(): void
    {
        $cur = ['roles' => ['available' => [['name' => 'sysadmin'], ['name' => 'dbcreator']], 'granted' => [['name' => 'dbcreator']]]];
        $this->assertSame([], $this->sql('x', ['server_roles' => ['add' => ['dbcreator']]], $cur));
        $this->assertSame(['ALTER SERVER ROLE [dbcreator] DROP MEMBER [x]'], $this->sql('x', ['server_roles' => ['remove' => ['dbcreator']]], $cur));
        $this->assertSame([], $this->sql('x', ['server_roles' => ['remove' => ['sysadmin']]], $cur));
        $this->assertBadRequest(fn () => $this->sql('x', ['server_roles' => ['add' => ['ghost']]], $cur));
    }

    public function testLoginDisableEnable(): void
    {
        $this->assertSame(['ALTER LOGIN [x] DISABLE'], $this->sql('x', ['account' => ['lock' => true]]));
        $this->assertSame(['ALTER LOGIN [x] ENABLE'], $this->sql('x', ['account' => ['unlock' => true]]));
        $this->assertBadRequest(fn () => $this->sql('x', ['account' => ['expire' => true]]));
    }

    public function testCreateDbUser(): void
    {
        $plan = (new SqlSrvPrivilegePlanner())->plan('x', null, ['create_db_user' => ['db' => 'shop']]);
        $this->assertSame([['sql' => 'CREATE USER [x] FOR LOGIN [x]', 'db' => 'shop']], $plan['statements']);
        // list form, skipped when the user already exists
        $cur = ['db_users' => ['shop' => 'x']];
        $this->assertSame([], $this->sql('x', ['create_db_user' => [['db' => 'shop']]], $cur));
    }

    public function testCreateDbUserThenGrantInSameChange(): void
    {
        $cur = ['db_users' => ['shop' => null]];
        $plan = (new SqlSrvPrivilegePlanner())->plan('x', null, [
            'create_db_user' => ['db' => 'shop'],
            'databases' => [['db' => 'shop', 'add' => ['SELECT']]],
        ], $cur);
        $this->assertSame([
            ['sql' => 'CREATE USER [x] FOR LOGIN [x]', 'db' => 'shop'],
            ['sql' => 'GRANT SELECT TO [x]', 'db' => 'shop'],
        ], $plan['statements']);
    }

    public function testDbRolesUseMappedUserName(): void
    {
        $cur = ['db_users' => ['shop' => 'shop_user']];
        $plan = (new SqlSrvPrivilegePlanner())->plan('x', null, ['db_roles' => [
            ['db' => 'shop', 'add' => ['db_datareader'], 'remove' => ['db_datawriter']],
        ]], $cur);
        $this->assertSame([
            ['sql' => 'ALTER ROLE [db_datawriter] DROP MEMBER [shop_user]', 'db' => 'shop'],
            ['sql' => 'ALTER ROLE [db_datareader] ADD MEMBER [shop_user]', 'db' => 'shop'],
        ], $plan['statements']);
        // without state the login name is used
        $this->assertSame(
            ['ALTER ROLE [db_datareader] ADD MEMBER [x]'],
            $this->sql('x', ['db_roles' => [['db' => 'shop', 'add' => ['db_datareader']]]])
        );
    }

    public function testGrantDenyRevokeLevels(): void
    {
        $plan = (new SqlSrvPrivilegePlanner())->plan('u', null, [
            'databases' => [['db' => 'd', 'add' => ['SELECT', 'INSERT'], 'remove' => ['DELETE'], 'deny' => ['ALTER']]],
            'schemas' => [['db' => 'd', 'schema' => 's', 'add' => ['EXECUTE']]],
            'tables' => [
                ['db' => 'd', 'schema' => 's', 'table' => 't', 'add' => ['SELECT']],
                ['db' => 'd', 'table' => 'u', 'deny' => ['DELETE']],
            ],
        ]);
        $this->assertSame([
            ['sql' => 'REVOKE DELETE FROM [u]', 'db' => 'd'],
            ['sql' => 'DENY ALTER TO [u]', 'db' => 'd'],
            ['sql' => 'GRANT SELECT, INSERT TO [u]', 'db' => 'd'],
            ['sql' => 'GRANT EXECUTE ON SCHEMA::[s] TO [u]', 'db' => 'd'],
            ['sql' => 'GRANT SELECT ON OBJECT::[s].[t] TO [u]', 'db' => 'd'],
            ['sql' => 'DENY DELETE ON OBJECT::[dbo].[u] TO [u]', 'db' => 'd'],
        ], $plan['statements']);
    }

    public function testColumnStatements(): void
    {
        $plan = (new SqlSrvPrivilegePlanner())->plan('u', null, ['columns' => [
            ['db' => 'd', 'table' => 't', 'column' => 'a', 'add' => ['SELECT']],
            ['db' => 'd', 'table' => 't', 'column' => 'b', 'add' => ['SELECT']],
            ['db' => 'd', 'table' => 't', 'column' => 'c', 'deny' => ['UPDATE']],
            ['db' => 'd', 'table' => 't', 'column' => 'e', 'remove' => ['SELECT']],
        ]]);
        $this->assertSame([
            ['sql' => 'REVOKE SELECT ([e]) ON OBJECT::[dbo].[t] FROM [u]', 'db' => 'd'],
            ['sql' => 'DENY UPDATE ([c]) ON OBJECT::[dbo].[t] TO [u]', 'db' => 'd'],
            ['sql' => 'GRANT SELECT ([a], [b]) ON OBJECT::[dbo].[t] TO [u]', 'db' => 'd'],
        ], $plan['statements']);
    }

    public function testBracketQuoting(): void
    {
        $this->assertSame(['ALTER LOGIN [a]]b] DISABLE'], $this->sql('a]b', ['account' => ['lock' => true]]));
        $this->assertSame(
            ['GRANT SELECT ON OBJECT::[s]]x].[t]]y] TO [u]'],
            $this->sql('u', ['tables' => [['db' => 'd', 'schema' => 's]x', 'table' => 't]y', 'add' => ['SELECT']]]])
        );
    }

    public function testWhitelistAndKeys(): void
    {
        $this->assertBadRequest(fn () => $this->sql('u', ['databases' => [['db' => 'd', 'add' => ['DROP DATABASE']]]]));
        $this->assertBadRequest(fn () => $this->sql('u', ['databases' => [['db' => 'd', 'add' => ['ALL']]]]));
        $this->assertBadRequest(fn () => $this->sql('u', ['columns' => [['db' => 'd', 'table' => 't', 'column' => 'c', 'add' => ['DELETE']]]]));
        $this->assertBadRequest(fn () => $this->sql('u', ['bogus' => []]));
        $this->assertBadRequest(fn () => $this->sql('u', ['databases' => [['db' => 'd', 'add' => ['SELECT'], 'deny' => ['SELECT']]]]));
        $this->assertBadRequest(fn () => $this->sql("a\0b", ['account' => ['lock' => true]]));
        $this->assertBadRequest(fn () => $this->sql(str_repeat('a', 129), ['account' => ['lock' => true]]));
    }

    public function testNoDbUserInStateIsRejected(): void
    {
        $cur = ['db_users' => ['other' => 'x', 'shop' => null]];
        $this->assertBadRequest(fn () => $this->sql('x', ['databases' => [['db' => 'shop', 'add' => ['SELECT']]]], $cur));
        $this->assertBadRequest(fn () => $this->sql('x', ['db_roles' => [['db' => 'shop', 'add' => ['db_datareader']]]], $cur));
        $this->assertBadRequest(fn () => $this->sql('x', ['tables' => [['db' => 'shop', 'table' => 't', 'add' => ['SELECT']]]], $cur));
        $this->assertBadRequest(fn () => $this->sql('x', ['columns' => [['db' => 'shop', 'table' => 't', 'column' => 'c', 'add' => ['SELECT']]]], $cur));
        // a database the state does not know about falls back to the login name
        $this->assertSame(['GRANT SELECT TO [x]'], $this->sql('x', ['databases' => [['db' => 'unknown', 'add' => ['SELECT']]]], $cur));
    }

    public function testNoOpFilteringIncludingDenied(): void
    {
        $cur = ['db_users' => ['d' => 'u'], 'tables' => [['db' => 'd', 'schema' => 'dbo', 'table' => 't', 'privileges' => ['SELECT'], 'denied' => ['DELETE']]]];
        $changes = ['tables' => [['db' => 'd', 'table' => 't', 'add' => ['SELECT'], 'deny' => ['DELETE'], 'remove' => ['UPDATE']]]];
        $this->assertSame([], $this->sql('u', $changes, $cur));
        // revoking a denied privilege is not a no-op
        $this->assertSame(
            ['REVOKE DELETE ON OBJECT::[dbo].[t] FROM [u]'],
            $this->sql('u', ['tables' => [['db' => 'd', 'table' => 't', 'remove' => ['DELETE']]]], $cur)
        );
    }

    public function testSelfGuard(): void
    {
        $p = new SqlSrvPrivilegePlanner();
        $cur = ['self' => true, 'db_users' => ['d' => 'sa']];
        $this->assertSame(
            ['revoke_admin'],
            $p->plan('sa', null, ['server_roles' => ['remove' => ['sysadmin']]], $cur)['self_lockout']
        );
        $this->assertSame(
            ['revoke_connect'],
            $p->plan('sa', null, ['databases' => [['db' => 'd', 'deny' => ['CONNECT']]]], $cur)['self_lockout']
        );
        $this->assertSame(
            ['revoke_connect'],
            $p->plan('sa', null, ['databases' => [['db' => 'd', 'remove' => ['CONNECT']]]], $cur)['self_lockout']
        );
        $this->assertSame(['lock_self'], $p->plan('sa', null, ['account' => ['lock' => true]], $cur)['self_lockout']);
        $this->assertSame(['rename_self'], $p->plan('sa', null, ['rename' => ['user' => 'sb']], $cur)['self_lockout']);
        $cur['self'] = false;
        $this->assertSame([], $p->plan('sa', null, ['server_roles' => ['remove' => ['sysadmin']]], $cur)['self_lockout']);
    }

    public function testRename(): void
    {
        $this->assertSame(['ALTER LOGIN [a] WITH NAME = [b]'], $this->sql('a', ['rename' => ['user' => 'b']]));
        $this->assertSame([], $this->sql('a', ['rename' => ['user' => 'a']]));
    }

    public function testCreateAndDropRole(): void
    {
        $p = new SqlSrvPrivilegePlanner();
        $this->assertSame([['sql' => 'CREATE ROLE [r]', 'db' => 'd']], $p->createRole('r', 'd'));
        $this->assertSame([['sql' => 'DROP ROLE [r]', 'db' => 'd']], $p->dropRole('r', 'd'));
    }
}
