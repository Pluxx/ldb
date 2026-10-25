<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Privileges;

use Ldb\Http\ApiException;
use Ldb\Privileges\MySqlPrivilegePlanner;
use PHPUnit\Framework\TestCase;

/** Exact SQL generation of the MySQL / MariaDB privilege planner (no database needed). */
final class MySqlPrivilegePlannerTest extends TestCase
{
    private function my(): MySqlPrivilegePlanner
    {
        return new MySqlPrivilegePlanner(false, '8.0.36');
    }

    private function maria(): MySqlPrivilegePlanner
    {
        return new MySqlPrivilegePlanner(true, '11.4.2-MariaDB');
    }

    /** @return string[] */
    private function sql(MySqlPrivilegePlanner $p, string $user, ?string $host, array $changes, ?array $current = null): array
    {
        return array_column($p->plan($user, $host, $changes, $current)['statements'], 'sql');
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

    public function testGlobalGrantAndRevokeOrder(): void
    {
        $sql = $this->sql($this->my(), 'bob', 'localhost', ['global' => ['add' => ['select', 'Insert'], 'remove' => ['DELETE']]]);
        $this->assertSame([
            'REVOKE DELETE ON *.* FROM `bob`@`localhost`',
            'GRANT SELECT, INSERT ON *.* TO `bob`@`localhost`',
        ], $sql);
    }

    public function testNullHostDefaultsToPercent(): void
    {
        $this->assertSame(
            ['GRANT SELECT ON *.* TO `bob`@`%`'],
            $this->sql($this->my(), 'bob', null, ['global' => ['add' => ['SELECT']]])
        );
    }

    public function testGrantOptionBecomesWithGrantOption(): void
    {
        $p = $this->my();
        $this->assertSame(
            ['GRANT SELECT ON *.* TO `u`@`%` WITH GRANT OPTION'],
            $this->sql($p, 'u', '%', ['global' => ['add' => ['SELECT', 'GRANT OPTION']]])
        );
        $this->assertSame(
            ['GRANT USAGE ON *.* TO `u`@`%` WITH GRANT OPTION'],
            $this->sql($p, 'u', '%', ['global' => ['add' => ['GRANT OPTION']]])
        );
        $this->assertSame(
            ['REVOKE GRANT OPTION ON *.* FROM `u`@`%`'],
            $this->sql($p, 'u', '%', ['global' => ['remove' => ['GRANT OPTION']]])
        );
    }

    public function testDatabaseTableAndColumnLevels(): void
    {
        $sql = $this->sql($this->my(), 'u', 'h', [
            'databases' => [['db' => 'shop', 'add' => ['SELECT', 'INSERT']]],
            'tables' => [['db' => 'shop', 'table' => 'orders', 'add' => ['UPDATE'], 'remove' => ['DELETE']]],
        ]);
        $this->assertSame([
            'GRANT SELECT, INSERT ON `shop`.* TO `u`@`h`',
            'REVOKE DELETE ON `shop`.`orders` FROM `u`@`h`',
            'GRANT UPDATE ON `shop`.`orders` TO `u`@`h`',
        ], $sql);
    }

    public function testColumnGrouping(): void
    {
        $sql = $this->sql($this->my(), 'u', 'h', ['columns' => [
            ['db' => 'd', 'table' => 't', 'column' => 'a', 'add' => ['SELECT']],
            ['db' => 'd', 'table' => 't', 'column' => 'b', 'add' => ['SELECT']],
            ['db' => 'd', 'table' => 't', 'column' => 'c', 'add' => ['UPDATE']],
            ['db' => 'd', 'table' => 't', 'column' => 'e', 'remove' => ['INSERT']],
            ['db' => 'd', 'table' => 'other', 'column' => 'x', 'add' => ['REFERENCES']],
        ]]);
        $this->assertSame([
            'REVOKE INSERT (`e`) ON `d`.`t` FROM `u`@`h`',
            'GRANT SELECT (`a`, `b`), UPDATE (`c`) ON `d`.`t` TO `u`@`h`',
            'GRANT REFERENCES (`x`) ON `d`.`other` TO `u`@`h`',
        ], $sql);
    }

    public function testMySqlRolesAreGrantedInOneStatement(): void
    {
        $sql = $this->sql($this->my(), 'u', '%', ['roles' => ['add' => ['r1', ['name' => 'r2', 'host' => 'localhost']], 'remove' => ['old']]]);
        $this->assertSame([
            'REVOKE `old`@`%` FROM `u`@`%`',
            'GRANT `r1`@`%`, `r2`@`localhost` TO `u`@`%`',
        ], $sql);
    }

    public function testMySqlDefaultRole(): void
    {
        $p = $this->my();
        $this->assertSame(
            ['SET DEFAULT ROLE `r1`@`%`, `r2`@`%` TO `u`@`%`'],
            $this->sql($p, 'u', '%', ['roles' => ['default' => ['r1', 'r2']]])
        );
        $this->assertSame(
            ['SET DEFAULT ROLE NONE TO `u`@`%`'],
            $this->sql($p, 'u', '%', ['roles' => ['default' => []]])
        );
    }

    public function testMariaDbRolesOneStatementPerRole(): void
    {
        $p = $this->maria();
        $sql = $this->sql($p, 'u', '%', ['roles' => ['add' => ['r1', 'r2'], 'remove' => ['old'], 'default' => ['r1']]]);
        $this->assertSame([
            'REVOKE `old` FROM `u`@`%`',
            'GRANT `r1` TO `u`@`%`',
            'GRANT `r2` TO `u`@`%`',
            'SET DEFAULT ROLE `r1` FOR `u`@`%`',
        ], $sql);
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['roles' => ['default' => ['a', 'b']]]));
    }

    public function testRolesAfterGrantsDefaultRoleLast(): void
    {
        $sql = $this->sql($this->my(), 'u', '%', [
            'roles' => ['add' => ['r'], 'default' => ['r']],
            'global' => ['add' => ['SELECT']],
        ]);
        $this->assertSame([
            'GRANT `r`@`%` TO `u`@`%`',
            'GRANT SELECT ON *.* TO `u`@`%`',
            'SET DEFAULT ROLE `r`@`%` TO `u`@`%`',
        ], $sql);
    }

    public function testLimitsAndAccountInOneAlterUser(): void
    {
        $sql = $this->sql($this->my(), 'u', '%', [
            'account' => ['lock' => true, 'password_expire' => 'never', 'require_ssl' => 'X509'],
            'limits' => ['max_queries_per_hour' => 100, 'max_user_connections' => '5'],
        ]);
        $this->assertSame([
            'ALTER USER `u`@`%` REQUIRE X509 WITH MAX_QUERIES_PER_HOUR 100 MAX_USER_CONNECTIONS 5 ACCOUNT LOCK PASSWORD EXPIRE NEVER',
        ], $sql);
    }

    public function testPasswordExpireVariants(): void
    {
        $p = $this->my();
        $this->assertSame(['ALTER USER `u`@`%` PASSWORD EXPIRE'], $this->sql($p, 'u', '%', ['account' => ['password_expire' => true]]));
        $this->assertSame(['ALTER USER `u`@`%` PASSWORD EXPIRE DEFAULT'], $this->sql($p, 'u', '%', ['account' => ['password_expire' => 'default']]));
        $this->assertSame(['ALTER USER `u`@`%` PASSWORD EXPIRE INTERVAL 90 DAY'], $this->sql($p, 'u', '%', ['account' => ['password_expire' => 90]]));
        $this->assertSame(['ALTER USER `u`@`%` ACCOUNT UNLOCK'], $this->sql($p, 'u', '%', ['account' => ['unlock' => true]]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['account' => ['password_expire' => 'soon']]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['account' => ['require_ssl' => 'MAYBE']]));
    }

    public function testMariaDbMaxStatementTime(): void
    {
        $this->assertSame(
            ['ALTER USER `u`@`%` WITH MAX_STATEMENT_TIME 2.5'],
            $this->sql($this->maria(), 'u', '%', ['limits' => ['max_statement_time' => 2.5]])
        );
        $this->assertBadRequest(fn () => $this->sql($this->my(), 'u', '%', ['limits' => ['max_statement_time' => 2]]));
    }

    public function testRename(): void
    {
        $p = $this->my();
        $this->assertSame(['RENAME USER `a`@`h` TO `b`@`h`'], $this->sql($p, 'a', 'h', ['rename' => ['user' => 'b']]));
        $this->assertSame(['RENAME USER `a`@`h` TO `a`@`10.0.%`'], $this->sql($p, 'a', 'h', ['rename' => ['host' => '10.0.%']]));
        $this->assertSame([], $this->sql($p, 'a', 'h', ['rename' => ['user' => 'a']]));
    }

    public function testRenameIsLastStatement(): void
    {
        $sql = $this->sql($this->my(), 'a', 'h', ['rename' => ['user' => 'b'], 'global' => ['add' => ['SELECT']]]);
        $this->assertSame(['GRANT SELECT ON *.* TO `a`@`h`', 'RENAME USER `a`@`h` TO `b`@`h`'], $sql);
    }

    public function testIdentifierQuoting(): void
    {
        $p = $this->my();
        $g = ['global' => ['add' => ['SELECT']]];
        $this->assertSame(["GRANT SELECT ON *.* TO `o'brien`@`%`"], $this->sql($p, "o'brien", '%', $g));
        $this->assertSame(['GRANT SELECT ON *.* TO `a``b`@`%`'], $this->sql($p, 'a`b', '%', $g));
        $this->assertSame(['GRANT SELECT ON *.* TO `u`@`10.0.%`'], $this->sql($p, 'u', '10.0.%', $g));
        $this->assertSame(['GRANT SELECT ON *.* TO `ユーザー`@`%`'], $this->sql($p, 'ユーザー', '%', $g));
        $this->assertSame(
            ['GRANT SELECT ON `my``db`.* TO `u`@`%`'],
            $this->sql($p, 'u', '%', ['databases' => [['db' => 'my`db', 'add' => ['SELECT']]]])
        );
        $this->assertSame(
            ['GRANT SELECT ON *.* TO \'\'@`localhost`'],
            $this->sql($p, '', 'localhost', $g)
        );
        $this->assertSame(
            ['GRANT SELECT (`c``x`) ON `d`.`t``q` TO `u`@`%`'],
            $this->sql($p, 'u', '%', ['columns' => [['db' => 'd', 'table' => 't`q', 'column' => 'c`x', 'add' => ['SELECT']]]])
        );
    }

    public function testWhitelistRejection(): void
    {
        $p = $this->my();
        foreach (['DROP DATABASE', 'SELECT; DROP', 'ALL', 'ALL PRIVILEGES', 'SELECT, INSERT'] as $bad) {
            $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['global' => ['add' => [$bad]]]));
        }
    }

    public function testGlobalOnlyPrivilegeRejectedAtDbLevel(): void
    {
        $p = $this->my();
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['databases' => [['db' => 'd', 'add' => ['SUPER']]]]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['tables' => [['db' => 'd', 'table' => 't', 'add' => ['CREATE ROUTINE']]]]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['columns' => [['db' => 'd', 'table' => 't', 'column' => 'c', 'add' => ['DELETE']]]]));
        // the same privilege is fine globally
        $this->assertSame(['GRANT SUPER ON *.* TO `u`@`%`'], $this->sql($p, 'u', '%', ['global' => ['add' => ['SUPER']]]));
    }

    public function testMySql8DynamicPrivilegesOnlyOnMySql8(): void
    {
        $this->assertSame(
            ['GRANT BACKUP_ADMIN ON *.* TO `u`@`%`'],
            $this->sql($this->my(), 'u', '%', ['global' => ['add' => ['BACKUP_ADMIN']]])
        );
        $this->assertBadRequest(fn () => $this->sql(new MySqlPrivilegePlanner(false, '5.7.44'), 'u', '%', ['global' => ['add' => ['BACKUP_ADMIN']]]));
        $extra = new MySqlPrivilegePlanner(false, '8.0.36', ['MY_PLUGIN_ADMIN']);
        $this->assertSame(['GRANT MY_PLUGIN_ADMIN ON *.* TO `u`@`%`'], $this->sql($extra, 'u', '%', ['global' => ['add' => ['my_plugin_admin']]]));
    }

    public function testUnknownKeysAndOptionsRejected(): void
    {
        $p = $this->my();
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['bogus' => []]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['account' => ['drop' => true]]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['limits' => ['max_foo' => 1]]));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['roles' => ['bogus' => []]]));
    }

    public function testNulByteAndBadEncodingRejected(): void
    {
        $p = $this->my();
        $g = ['global' => ['add' => ['SELECT']]];
        $this->assertBadRequest(fn () => $this->sql($p, "a\0b", '%', $g));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', "h\0", $g));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['databases' => [['db' => "d\0", 'add' => ['SELECT']]]]));
        $this->assertBadRequest(fn () => $this->sql($p, "\xC3\x28", '%', $g));
        $this->assertBadRequest(fn () => $this->sql($p, str_repeat('a', 129), '%', $g));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['databases' => [['db' => str_repeat('d', 65), 'add' => ['SELECT']]]]));
    }

    public function testSamePrivilegeInAddAndRemoveRejected(): void
    {
        $this->assertBadRequest(fn () => $this->sql($this->my(), 'u', '%', ['global' => ['add' => ['SELECT'], 'remove' => ['select']]]));
        $this->assertBadRequest(fn () => $this->sql($this->my(), 'u', '%', ['databases' => [['db' => 'd', 'add' => ['SELECT'], 'remove' => ['SELECT']]]]));
    }

    public function testNoOpFilteringAgainstCurrentState(): void
    {
        $p = $this->my();
        $current = [
            'global' => ['SELECT'],
            'databases' => [['db' => 'd', 'privileges' => ['SELECT', 'INSERT']]],
            'tables' => [['db' => 'd', 'table' => 't', 'privileges' => ['UPDATE']]],
            'columns' => [['db' => 'd', 'table' => 't', 'column' => 'a', 'privileges' => ['SELECT']]],
        ];
        $changes = [
            'global' => ['add' => ['SELECT', 'INSERT'], 'remove' => ['DELETE']],
            'databases' => [['db' => 'd', 'add' => ['SELECT', 'DELETE'], 'remove' => ['DROP']]],
            'tables' => [['db' => 'd', 'table' => 't', 'add' => ['UPDATE']]],
            'columns' => [['db' => 'd', 'table' => 't', 'column' => 'a', 'add' => ['SELECT']]],
        ];
        $this->assertSame([
            'GRANT INSERT ON *.* TO `u`@`%`',
            'GRANT DELETE ON `d`.* TO `u`@`%`',
        ], $this->sql($p, 'u', '%', $changes, $current));
        // without state everything is kept
        $this->assertCount(6, $this->sql($p, 'u', '%', $changes));
    }

    public function testFullyNoOpPlanIsEmpty(): void
    {
        $current = ['global' => ['SELECT'], 'account' => ['locked' => true]];
        $plan = $this->my()->plan('u', '%', ['global' => ['add' => ['SELECT']], 'account' => ['lock' => true]], $current);
        $this->assertSame([], $plan['statements']);
    }

    public function testSelfLockoutRevokeAdmin(): void
    {
        $p = $this->my();
        $cur = ['self' => true, 'global' => ['SELECT', 'SUPER', 'INSERT']];
        $plan = $p->plan('root', 'localhost', ['global' => ['remove' => ['SUPER']]], $cur);
        $this->assertSame(['revoke_admin'], $plan['self_lockout']);
        $this->assertCount(1, $plan['statements']);
    }

    public function testSelfLockoutRevokeAll(): void
    {
        $cur = ['self' => true, 'global' => ['SELECT', 'INSERT']];
        $plan = $this->my()->plan('root', 'localhost', ['global' => ['remove' => ['SELECT', 'INSERT']]], $cur);
        $this->assertSame(['revoke_all'], $plan['self_lockout']);
        $plan = $this->my()->plan('root', 'localhost', ['global' => ['remove' => ['SELECT']]], $cur);
        $this->assertSame([], $plan['self_lockout']);
    }

    public function testSelfLockoutLockAndRename(): void
    {
        $p = $this->my();
        $cur = ['self' => true, 'global' => ['SELECT'], 'account' => ['locked' => false]];
        $this->assertSame(['lock_self'], $p->plan('root', 'localhost', ['account' => ['lock' => true]], $cur)['self_lockout']);
        $this->assertSame(['rename_self'], $p->plan('root', 'localhost', ['rename' => ['user' => 'other']], $cur)['self_lockout']);
        $this->assertSame([], $p->plan('root', 'localhost', ['account' => ['unlock' => true]], $cur)['self_lockout']);
    }

    public function testNotSelfNeverLocksOut(): void
    {
        $cur = ['self' => false, 'global' => ['SUPER'], 'account' => ['locked' => false]];
        $plan = $this->my()->plan('bob', '%', [
            'global' => ['remove' => ['SUPER']],
            'account' => ['lock' => true],
            'rename' => ['user' => 'x'],
        ], $cur);
        $this->assertSame([], $plan['self_lockout']);
        $this->assertCount(3, $plan['statements']);
    }

    public function testUnknownRoleRejectedWhenStateKnown(): void
    {
        $cur = ['roles' => ['available' => [['name' => 'r1', 'host' => '%']], 'granted' => [], 'default' => []]];
        $p = $this->my();
        $this->assertSame(['GRANT `r1`@`%` TO `u`@`%`'], $this->sql($p, 'u', '%', ['roles' => ['add' => ['r1']]], $cur));
        $this->assertBadRequest(fn () => $this->sql($p, 'u', '%', ['roles' => ['add' => ['ghost']]], $cur));
    }

    public function testGrantedRoleIsNoOp(): void
    {
        $cur = ['roles' => ['available' => [['name' => 'r1', 'host' => '%']], 'granted' => [['name' => 'r1', 'host' => '%']], 'default' => []]];
        $this->assertSame([], $this->sql($this->my(), 'u', '%', ['roles' => ['add' => ['r1']]], $cur));
        $this->assertSame(
            ['REVOKE `r1`@`%` FROM `u`@`%`'],
            $this->sql($this->my(), 'u', '%', ['roles' => ['remove' => ['r1']]], $cur)
        );
    }

    public function testMariaDbRoleTargetHasNoHost(): void
    {
        $cur = ['account' => ['is_role' => true]];
        $this->assertSame(
            ['GRANT SELECT ON `d`.* TO `analyst`'],
            $this->sql($this->maria(), 'analyst', null, ['databases' => [['db' => 'd', 'add' => ['SELECT']]]], $cur)
        );
        // MySQL ignores the flag and keeps the host
        $this->assertSame(
            ['GRANT SELECT ON `d`.* TO `analyst`@`%`'],
            $this->sql($this->my(), 'analyst', null, ['databases' => [['db' => 'd', 'add' => ['SELECT']]]], $cur)
        );
    }

    public function testRolesUnsupportedOnOldServers(): void
    {
        try {
            $this->sql(new MySqlPrivilegePlanner(false, '5.7.44'), 'u', '%', ['roles' => ['add' => ['r']]]);
            $this->fail('Expected unsupported');
        } catch (ApiException $e) {
            $this->assertSame('unsupported', $e->getErrorCode());
        }
    }

    public function testCreateAndDropRole(): void
    {
        $this->assertSame(['CREATE ROLE `r`@`%`'], array_column($this->my()->createRole('r', null), 'sql'));
        $this->assertSame(['CREATE ROLE `r`'], array_column($this->maria()->createRole('r', 'ignored'), 'sql'));
        $this->assertSame(['DROP ROLE `r`@`h`'], array_column($this->my()->dropRole('r', 'h'), 'sql'));
        $this->assertSame(['DROP ROLE `r`'], array_column($this->maria()->dropRole('r', null), 'sql'));
    }

    public function testStatementsCarryNoDatabase(): void
    {
        $plan = $this->my()->plan('u', '%', ['global' => ['add' => ['SELECT']]]);
        $this->assertNull($plan['statements'][0]['db']);
    }

    public function testDatabaseLevelEscapesWildcardsButTableLevelDoesNot(): void
    {
        $sql = $this->sql($this->my(), 'u', 'h', [
            'databases' => [['db' => 'ldb_demo', 'add' => ['SELECT']]],
            'tables' => [['db' => 'ldb_demo', 'table' => 't_1', 'add' => ['SELECT']]],
            'columns' => [['db' => 'ldb_demo', 'table' => 't_1', 'column' => 'c_1', 'add' => ['UPDATE']]],
        ]);
        $this->assertSame([
            'GRANT SELECT ON `ldb\\_demo`.* TO `u`@`h`',
            'GRANT SELECT ON `ldb_demo`.`t_1` TO `u`@`h`',
            'GRANT UPDATE (`c_1`) ON `ldb_demo`.`t_1` TO `u`@`h`',
        ], $sql);
        $this->assertSame(
            ['REVOKE SELECT ON `a\\%b\\\\c`.* FROM `u`@`h`'],
            $this->sql($this->maria(), 'u', 'h', ['databases' => [['db' => 'a%b\\c', 'remove' => ['SELECT']]]])
        );
    }

    public function testExistingPatternIsUsedAsStored(): void
    {
        $cur = ['databases' => [
            ['db' => 'app_%', 'pattern' => true, 'privileges' => ['SELECT', 'INSERT']],
            ['db' => 'app_%', 'pattern' => false, 'privileges' => ['DELETE']],
        ]];
        $this->assertSame(
            ['REVOKE INSERT ON `app_%`.* FROM `u`@`h`'],
            $this->sql($this->my(), 'u', 'h', ['databases' => [['db' => 'app_%', 'pattern' => true, 'remove' => ['INSERT']]]], $cur)
        );
        // the literal database of the same text is a different grant target
        $this->assertSame(
            ['REVOKE DELETE ON `app\\_\\%`.* FROM `u`@`h`'],
            $this->sql($this->my(), 'u', 'h', ['databases' => [['db' => 'app_%', 'remove' => ['DELETE']]]], $cur)
        );
    }
}
