<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Privileges;

use Ldb\Privileges\MySqlGrantParser;
use PHPUnit\Framework\TestCase;

/** SHOW GRANTS line parsing (MySQL 5.7/8, MariaDB). */
final class MySqlGrantParserTest extends TestCase
{
    private static function names(array $g): array
    {
        return array_column($g['privileges'], 'name');
    }

    public function testGlobalAllPrivilegesWithGrantOption(): void
    {
        $g = MySqlGrantParser::parse("GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION");
        $this->assertSame('privileges', $g['type']);
        $this->assertSame(['level' => 'global'], $g['on']);
        $this->assertSame(['ALL PRIVILEGES'], self::names($g));
        $this->assertNull($g['privileges'][0]['columns']);
        $this->assertTrue($g['grant_option']);
    }

    public function testMySql8DynamicPrivilegeLine(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT, INSERT,BACKUP_ADMIN,SYSTEM_VARIABLES_ADMIN ON *.* TO `u`@`%`');
        $this->assertSame(['SELECT', 'INSERT', 'BACKUP_ADMIN', 'SYSTEM_VARIABLES_ADMIN'], self::names($g));
        $this->assertFalse($g['grant_option']);
        $this->assertSame('global', $g['on']['level']);
    }

    public function testMultiWordPrivilegesAndUsage(): void
    {
        $g = MySqlGrantParser::parse('GRANT CREATE TEMPORARY TABLES, SHOW VIEW, REPLICATION CLIENT ON *.* TO `u`@`%`');
        $this->assertSame(['CREATE TEMPORARY TABLES', 'SHOW VIEW', 'REPLICATION CLIENT'], self::names($g));
        $g = MySqlGrantParser::parse('GRANT USAGE ON *.* TO `u`@`%`');
        $this->assertSame(['USAGE'], self::names($g));
    }

    public function testDatabaseLevelWithEscapedName(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT, INSERT ON `ldb\_demo`.* TO `u`@`%`');
        $this->assertSame('database', $g['on']['level']);
        // the name is returned as written (LIKE-escaped); the planner uses names verbatim
        $this->assertSame('ldb\_demo', $g['on']['db']);
        $this->assertSame(['SELECT', 'INSERT'], self::names($g));
    }

    public function testTableLevel(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT, UPDATE ON `shop`.`orders` TO `u`@`%` WITH GRANT OPTION');
        $this->assertSame(['level' => 'table', 'db' => 'shop', 'table' => 'orders'], $g['on']);
        $this->assertTrue($g['grant_option']);
    }

    public function testColumnPrivileges(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT (`id`, `na,me`), UPDATE (`x`) ON `d`.`t` TO `u`@`%`');
        $this->assertSame(['level' => 'table', 'db' => 'd', 'table' => 't'], $g['on']);
        $this->assertSame([
            ['name' => 'SELECT', 'columns' => ['id', 'na,me']],
            ['name' => 'UPDATE', 'columns' => ['x']],
        ], $g['privileges']);
    }

    public function testColumnPrivilegesMixedWithTablePrivileges(): void
    {
        $g = MySqlGrantParser::parse('GRANT INSERT, SELECT (`a`) ON `d`.`t` TO `u`@`%`');
        $this->assertSame([
            ['name' => 'INSERT', 'columns' => null],
            ['name' => 'SELECT', 'columns' => ['a']],
        ], $g['privileges']);
    }

    public function testMySqlRoleLine(): void
    {
        $g = MySqlGrantParser::parse('GRANT `r1`@`%`,`r2`@`localhost` TO `u`@`%` WITH ADMIN OPTION');
        $this->assertSame('roles', $g['type']);
        $this->assertSame([['name' => 'r1', 'host' => '%'], ['name' => 'r2', 'host' => 'localhost']], $g['roles']);
        $this->assertTrue($g['admin_option']);
    }

    public function testMariaDbRoleLine(): void
    {
        $g = MySqlGrantParser::parse('GRANT `role` TO `u`@`%`');
        $this->assertSame('roles', $g['type']);
        $this->assertSame([['name' => 'role', 'host' => null]], $g['roles']);
        $this->assertFalse($g['admin_option']);
        $g = MySqlGrantParser::parse('GRANT `a`, `b` TO `u`@`%` WITH ADMIN OPTION');
        $this->assertSame([['name' => 'a', 'host' => null], ['name' => 'b', 'host' => null]], $g['roles']);
        $this->assertTrue($g['admin_option']);
    }

    public function testProxyLineIsIgnored(): void
    {
        $this->assertNull(MySqlGrantParser::parse("GRANT PROXY ON ''@'' TO 'root'@'localhost' WITH GRANT OPTION"));
        $this->assertNull(MySqlGrantParser::parse('GRANT PROXY ON `a`@`b` TO `u`@`%`'));
    }

    public function testRoutineLevels(): void
    {
        $g = MySqlGrantParser::parse('GRANT EXECUTE ON FUNCTION `shop`.`calc` TO `u`@`%`');
        $this->assertSame(['level' => 'routine', 'routine_type' => 'FUNCTION', 'db' => 'shop', 'name' => 'calc'], $g['on']);
        $g = MySqlGrantParser::parse('GRANT EXECUTE, ALTER ROUTINE ON PROCEDURE `shop`.`do_it` TO `u`@`%` WITH GRANT OPTION');
        $this->assertSame(['level' => 'routine', 'routine_type' => 'PROCEDURE', 'db' => 'shop', 'name' => 'do_it'], $g['on']);
        $this->assertSame(['EXECUTE', 'ALTER ROUTINE'], self::names($g));
        $this->assertTrue($g['grant_option']);
    }

    public function testTableKeywordAndBareTable(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT ON TABLE `d`.`t` TO `u`@`%`');
        $this->assertSame(['level' => 'table', 'db' => 'd', 'table' => 't'], $g['on']);
    }

    public function testIdentifiedByPasswordIsIgnored(): void
    {
        $g = MySqlGrantParser::parse("GRANT SELECT ON *.* TO 'u'@'%' IDENTIFIED BY PASSWORD '*ABC123' WITH GRANT OPTION");
        $this->assertSame(['SELECT'], self::names($g));
        $this->assertTrue($g['grant_option']);
        $g = MySqlGrantParser::parse("GRANT USAGE ON *.* TO `u`@`%` IDENTIFIED VIA mysql_native_password USING 'PASSWORD(\\'x\\')'");
        $this->assertSame(['USAGE'], self::names($g));
        $this->assertFalse($g['grant_option']);
    }

    public function testBacktickDoubling(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT (`c``x`) ON `my``db`.`t``q` TO `a``b`@`%`');
        $this->assertSame(['level' => 'table', 'db' => 'my`db', 'table' => 't`q'], $g['on']);
        $this->assertSame(['c`x'], $g['privileges'][0]['columns']);
    }

    public function testUnicodeNames(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT ON `データ`.`テーブル` TO `ユーザー`@`%`');
        $this->assertSame(['level' => 'table', 'db' => 'データ', 'table' => 'テーブル'], $g['on']);
    }

    public function testNonGrantLinesReturnNull(): void
    {
        $this->assertNull(MySqlGrantParser::parse(''));
        $this->assertNull(MySqlGrantParser::parse('REVOKE SELECT ON *.* FROM u'));
        $this->assertNull(MySqlGrantParser::parse('SET DEFAULT ROLE ALL TO u'));
    }

    public function testMariaDbGlobalPrivilegesWithSpaces(): void
    {
        $g = MySqlGrantParser::parse('GRANT BINLOG ADMIN, DELETE HISTORY ON *.* TO `u`@`%`');
        $this->assertSame(['BINLOG ADMIN', 'DELETE HISTORY'], self::names($g));
    }

    public function testPatternEscapingRoundTrip(): void
    {
        $this->assertSame('ldb\\_demo', MySqlGrantParser::escapePattern('ldb_demo'));
        $this->assertSame('a\\%b\\\\c', MySqlGrantParser::escapePattern('a%b\\c'));
        foreach (['plain', 'ldb_demo', '50%', 'a\\b', '\\_', 'x`y'] as $name) {
            $this->assertSame($name, MySqlGrantParser::unescapePattern(MySqlGrantParser::escapePattern($name)));
            $this->assertFalse(MySqlGrantParser::hasWildcard(MySqlGrantParser::escapePattern($name)));
        }
        $this->assertSame('a_b%', MySqlGrantParser::unescapePattern('a\\_b\\%'));
    }

    public function testHasWildcard(): void
    {
        $this->assertTrue(MySqlGrantParser::hasWildcard('ldb_demo'));
        $this->assertTrue(MySqlGrantParser::hasWildcard('app%'));
        $this->assertTrue(MySqlGrantParser::hasWildcard('a\\\\_b'));
        $this->assertFalse(MySqlGrantParser::hasWildcard('ldb\\_demo'));
        $this->assertFalse(MySqlGrantParser::hasWildcard('plain'));
        $this->assertFalse(MySqlGrantParser::hasWildcard('a\\%'));
    }

    public function testDatabaseLevelKeepsStoredPattern(): void
    {
        $g = MySqlGrantParser::parse('GRANT SELECT ON `ldb\\_demo`.* TO `u`@`%`');
        $this->assertSame(['level' => 'database', 'db' => 'ldb\\_demo'], $g['on']);
    }
}
