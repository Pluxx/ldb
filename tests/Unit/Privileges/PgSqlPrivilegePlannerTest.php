<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Privileges;

use Ldb\Http\ApiException;
use Ldb\Privileges\PgSqlPrivilegePlanner;
use PHPUnit\Framework\TestCase;

/** Exact SQL generation of the PostgreSQL privilege planner (no database needed). */
final class PgSqlPrivilegePlannerTest extends TestCase
{
    private function pg(int $v = 170000): PgSqlPrivilegePlanner
    {
        return new PgSqlPrivilegePlanner($v);
    }

    private function sql(PgSqlPrivilegePlanner $p, string $user, array $changes, ?array $current = null): array
    {
        return array_column($p->plan($user, null, $changes, $current)['statements'], 'sql');
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

    public function testAlterRoleAttributes(): void
    {
        $sql = $this->sql($this->pg(), 'x', ['attributes' => [
            'superuser' => false, 'createdb' => true, 'connection_limit' => 5, 'valid_until' => '2030-01-01',
        ]]);
        $this->assertSame(["ALTER ROLE \"x\" WITH NOSUPERUSER CREATEDB CONNECTION LIMIT 5 VALID UNTIL '2030-01-01'"], $sql);
    }

    public function testValidUntilNullIsInfinity(): void
    {
        $this->assertSame(
            ["ALTER ROLE \"x\" WITH VALID UNTIL 'infinity'"],
            $this->sql($this->pg(), 'x', ['attributes' => ['valid_until' => null]])
        );
        $this->assertSame(
            ["ALTER ROLE \"x\" WITH VALID UNTIL '2030-05-06 07:08:09+02'"],
            $this->sql($this->pg(), 'x', ['attributes' => ['valid_until' => '2030-05-06 07:08:09+02']])
        );
    }

    public function testInvalidValidUntilRejected(): void
    {
        foreach (["2030'; DROP", 'tomorrow', '2030-01-01; --', "2030-01-01'"] as $bad) {
            $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['attributes' => ['valid_until' => $bad]]));
        }
    }

    public function testAttributeValidation(): void
    {
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['attributes' => ['superuser' => 'yes']]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['attributes' => ['password' => 'x']]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['attributes' => ['connection_limit' => -5]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['nope' => 1]));
        $this->assertSame(
            ['ALTER ROLE "x" WITH CONNECTION LIMIT -1'],
            $this->sql($this->pg(), 'x', ['attributes' => ['connection_limit' => '-1']])
        );
    }

    public function testAttributesNoOpAgainstCurrent(): void
    {
        $cur = ['account' => ['attributes' => ['superuser' => false, 'createdb' => false, 'connection_limit' => -1]]];
        $this->assertSame(
            ['ALTER ROLE "x" WITH CREATEDB'],
            $this->sql($this->pg(), 'x', ['attributes' => ['superuser' => false, 'createdb' => true, 'connection_limit' => -1]], $cur)
        );
    }

    public function testLockUnlockMapToLogin(): void
    {
        $this->assertSame(['ALTER ROLE "x" WITH NOLOGIN'], $this->sql($this->pg(), 'x', ['account' => ['lock' => true]]));
        $this->assertSame(['ALTER ROLE "x" WITH LOGIN'], $this->sql($this->pg(), 'x', ['account' => ['unlock' => true]]));
    }

    public function testMemberships(): void
    {
        $this->assertSame(
            ['REVOKE "old" FROM "x"', 'GRANT "a", "b" TO "x"'],
            $this->sql($this->pg(), 'x', ['roles' => ['add' => ['a', 'b'], 'remove' => ['old']]])
        );
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['roles' => ['add' => ['x']]]));
        $cur = ['roles' => ['available' => [['name' => 'a']], 'granted' => []]];
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['roles' => ['add' => ['ghost']]], $cur));
        $this->assertSame(['GRANT "a" TO "x"'], $this->sql($this->pg(), 'x', ['roles' => ['add' => ['a']]], $cur));
    }

    public function testDatabasePrivilegesAndTempAlias(): void
    {
        $this->assertSame(
            ['GRANT CONNECT, TEMPORARY ON DATABASE "d" TO "x"'],
            $this->sql($this->pg(), 'x', ['databases' => [['db' => 'd', 'add' => ['CONNECT', 'TEMP']]]])
        );
        $this->assertSame(
            ['REVOKE CREATE ON DATABASE "d" FROM "x"'],
            $this->sql($this->pg(), 'x', ['databases' => [['db' => 'd', 'remove' => ['create']]]])
        );
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['databases' => [['db' => 'd', 'add' => ['SELECT']]]]));
    }

    public function testDatabaseStatementsHaveNoDbContext(): void
    {
        $plan = $this->pg()->plan('x', null, ['databases' => [['db' => 'd', 'add' => ['CONNECT']]]]);
        $this->assertNull($plan['statements'][0]['db']);
    }

    public function testSchemaTableSequenceColumnWithDbContext(): void
    {
        $plan = $this->pg()->plan('x', null, [
            'schemas' => [['db' => 'd1', 'schema' => 's', 'add' => ['USAGE', 'CREATE']]],
            'tables' => [
                ['db' => 'd1', 'schema' => 's', 'table' => 't', 'add' => ['SELECT', 'INSERT'], 'remove' => ['DELETE']],
                ['db' => 'd2', 'table' => 'u', 'add' => ['TRUNCATE']],
                ['db' => 'd1', 'schema' => 's', 'table' => 'seq', 'kind' => 'sequence', 'add' => ['USAGE', 'SELECT']],
            ],
            'columns' => [
                ['db' => 'd1', 'schema' => 's', 'table' => 't', 'column' => 'a', 'add' => ['SELECT']],
                ['db' => 'd1', 'schema' => 's', 'table' => 't', 'column' => 'b', 'add' => ['SELECT', 'UPDATE']],
                ['db' => 'd1', 'schema' => 's', 'table' => 't', 'column' => 'c', 'remove' => ['INSERT']],
            ],
        ]);
        $this->assertSame([
            ['sql' => 'GRANT USAGE, CREATE ON SCHEMA "s" TO "x"', 'db' => 'd1'],
            ['sql' => 'REVOKE DELETE ON TABLE "s"."t" FROM "x"', 'db' => 'd1'],
            ['sql' => 'GRANT SELECT, INSERT ON TABLE "s"."t" TO "x"', 'db' => 'd1'],
            ['sql' => 'GRANT TRUNCATE ON TABLE "public"."u" TO "x"', 'db' => 'd2'],
            ['sql' => 'GRANT USAGE, SELECT ON SEQUENCE "s"."seq" TO "x"', 'db' => 'd1'],
            ['sql' => 'REVOKE INSERT ("c") ON TABLE "s"."t" FROM "x"', 'db' => 'd1'],
            ['sql' => 'GRANT SELECT ("a", "b"), UPDATE ("b") ON TABLE "s"."t" TO "x"', 'db' => 'd1'],
        ], $plan['statements']);
    }

    public function testSequenceRejectsTablePrivileges(): void
    {
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['tables' => [['db' => 'd', 'table' => 's', 'kind' => 'sequence', 'add' => ['DELETE']]]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['tables' => [['db' => 'd', 'table' => 't', 'add' => ['USAGE']]]]));
    }

    public function testMaintainOnlyOnPg17(): void
    {
        $changes = ['tables' => [['db' => 'd', 'table' => 't', 'add' => ['MAINTAIN']]]];
        $this->assertSame(['GRANT MAINTAIN ON TABLE "public"."t" TO "x"'], $this->sql($this->pg(170000), 'x', $changes));
        $this->assertBadRequest(fn () => $this->sql($this->pg(160004), 'x', $changes));
    }

    public function testIdentifierQuotingAndLimits(): void
    {
        $this->assertSame(
            ['GRANT "a" TO "we""ird"'],
            $this->sql($this->pg(), 'we"ird', ['roles' => ['add' => ['a']]])
        );
        $this->assertSame(
            ['GRANT SELECT ON TABLE "public"."t""q" TO "x"'],
            $this->sql($this->pg(), 'x', ['tables' => [['db' => 'd', 'table' => 't"q', 'add' => ['SELECT']]]])
        );
        // exactly 63 bytes is fine, 64 is not; the limit counts bytes, not characters
        $this->assertCount(1, $this->sql($this->pg(), str_repeat('a', 63), ['account' => ['lock' => true]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), str_repeat('a', 64), ['account' => ['lock' => true]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), str_repeat('é', 32), ['account' => ['lock' => true]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), "a\0b", ['account' => ['lock' => true]]));
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['databases' => [['db' => str_repeat('d', 64), 'add' => ['CONNECT']]]]));
    }

    public function testUnicodeRoleName(): void
    {
        $this->assertSame(['ALTER ROLE "ユーザー" WITH LOGIN'], $this->sql($this->pg(), 'ユーザー', ['account' => ['unlock' => true]]));
    }

    public function testSamePrivInAddAndRemoveRejected(): void
    {
        $this->assertBadRequest(fn () => $this->sql($this->pg(), 'x', ['tables' => [['db' => 'd', 'table' => 't', 'add' => ['SELECT'], 'remove' => ['SELECT']]]]));
    }

    public function testNoOpFiltering(): void
    {
        $cur = [
            'tables' => [['db' => 'd', 'schema' => 'public', 'table' => 't', 'privileges' => ['SELECT']]],
            'databases' => [['db' => 'd', 'privileges' => ['CONNECT']]],
        ];
        $changes = [
            'databases' => [['db' => 'd', 'add' => ['CONNECT']]],
            'tables' => [['db' => 'd', 'table' => 't', 'add' => ['SELECT'], 'remove' => ['INSERT']]],
        ];
        $this->assertSame([], $this->sql($this->pg(), 'x', $changes, $cur));
        $this->assertCount(3, $this->sql($this->pg(), 'x', $changes));
    }

    public function testSelfGuardAttributes(): void
    {
        $cur = ['self' => true, 'account' => ['attributes' => ['superuser' => true, 'createrole' => true, 'login' => true]]];
        $p = $this->pg();
        $this->assertSame(['revoke_superuser'], $p->plan('me', null, ['attributes' => ['superuser' => false]], $cur)['self_lockout']);
        $this->assertSame(['revoke_createrole'], $p->plan('me', null, ['attributes' => ['createrole' => false]], $cur)['self_lockout']);
        $this->assertSame(['revoke_login'], $p->plan('me', null, ['attributes' => ['login' => false]], $cur)['self_lockout']);
        $this->assertSame(['revoke_login'], $p->plan('me', null, ['account' => ['lock' => true]], $cur)['self_lockout']);
        $this->assertSame([], $p->plan('me', null, ['attributes' => ['createdb' => false]], $cur)['self_lockout']);
        // not self: no guard
        $cur['self'] = false;
        $this->assertSame([], $p->plan('me', null, ['attributes' => ['superuser' => false]], $cur)['self_lockout']);
    }

    public function testSelfGuardConnectAndRename(): void
    {
        $p = $this->pg();
        $cur = ['self' => true];
        $this->assertSame(
            ['revoke_connect'],
            $p->plan('me', null, ['databases' => [['db' => 'd', 'remove' => ['CONNECT']]]], $cur)['self_lockout']
        );
        $plan = $p->plan('me', null, ['rename' => ['user' => 'you']], $cur);
        $this->assertSame(['rename_self'], $plan['self_lockout']);
        $this->assertSame(['ALTER ROLE "me" RENAME TO "you"'], array_column($plan['statements'], 'sql'));
        $this->assertContains('pg_rename_md5', $plan['warnings']);
        $this->assertSame([], $p->plan('me', null, ['rename' => ['user' => 'me']], $cur)['statements']);
    }

    public function testCreateAndDropRole(): void
    {
        $this->assertSame(['CREATE ROLE "r"'], array_column($this->pg()->createRole('r'), 'sql'));
        $this->assertSame(['CREATE ROLE "r" WITH LOGIN'], array_column($this->pg()->createRole('r', true), 'sql'));
        $this->assertSame(['DROP ROLE "r"'], array_column($this->pg()->dropRole('r'), 'sql'));
    }
}
