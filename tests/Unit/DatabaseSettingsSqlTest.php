<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqliteDriver;
use Ldb\Db\SqlSrvDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/** SQL generation for db.alter (database settings page); no database connection needed. */
final class DatabaseSettingsSqlTest extends TestCase
{
    private const COLLATIONS = [
        ['name' => 'utf8mb4_general_ci', 'charset' => 'utf8mb4', 'default' => false],
        ['name' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4', 'default' => false],
        ['name' => 'utf8mb4_0900_ai_ci', 'charset' => 'utf8mb4', 'default' => true],
        ['name' => 'latin1_swedish_ci', 'charset' => 'latin1', 'default' => true],
        ['name' => 'latin1_german2_ci', 'charset' => 'latin1', 'default' => false],
        // MariaDB UCA-14 collation shared by two charsets
        ['name' => 'uca1400_ai_ci', 'charset' => 'utf8mb3', 'default' => false],
        ['name' => 'uca1400_ai_ci', 'charset' => 'utf8mb4', 'default' => false],
    ];
    private const CHARSETS = [
        ['name' => 'utf8mb4', 'default_collation' => 'utf8mb4_0900_ai_ci'],
        ['name' => 'utf8mb3', 'default_collation' => 'utf8mb3_general_ci'],
        ['name' => 'latin1', 'default_collation' => 'latin1_swedish_ci'],
    ];
    private const CURRENT = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];

    private function mysqlPlan(array $changes, array $tables = ['a', 'b`c']): array
    {
        return (new MySqlDriver())->planAlterDatabase('shop`db', $changes, self::CURRENT, self::COLLATIONS, self::CHARSETS, $tables);
    }

    private function assertBadRequest(callable $fn, string $contains = ''): void
    {
        try {
            $fn();
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
            if ($contains !== '') {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    public function testMysqlCollationChangeDerivesCharset(): void
    {
        $plan = $this->mysqlPlan(['collation' => 'utf8mb4_general_ci']);
        $this->assertSame(['ALTER DATABASE `shop``db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'], $plan['sql']);
        $this->assertSame([], $plan['warnings']);
    }

    public function testMysqlCharsetOnlyUsesItsDefaultCollation(): void
    {
        $plan = $this->mysqlPlan(['charset' => 'latin1']);
        $this->assertSame(['ALTER DATABASE `shop``db` CHARACTER SET latin1 COLLATE latin1_swedish_ci'], $plan['sql']);
        // unchanged charset keeps the current collation → nothing to do
        $this->assertSame([], $this->mysqlPlan(['charset' => 'utf8mb4'])['sql']);
    }

    public function testMysqlSharedMariaDbCollationPrefersCurrentCharset(): void
    {
        $plan = $this->mysqlPlan(['collation' => 'uca1400_ai_ci']);
        $this->assertSame(['ALTER DATABASE `shop``db` CHARACTER SET utf8mb4 COLLATE uca1400_ai_ci'], $plan['sql']);
        $plan = $this->mysqlPlan(['charset' => 'utf8mb3', 'collation' => 'uca1400_ai_ci']);
        $this->assertSame(['ALTER DATABASE `shop``db` CHARACTER SET utf8mb3 COLLATE uca1400_ai_ci'], $plan['sql']);
    }

    public function testMysqlRejectsUnknownOrMismatchedCharsetCollation(): void
    {
        $this->assertBadRequest(fn () => $this->mysqlPlan(['collation' => 'utf8mb4_general_ci; DROP DATABASE x']), 'Unknown collation');
        $this->assertBadRequest(fn () => $this->mysqlPlan(['charset' => 'klingon']), 'Unknown character set');
        $this->assertBadRequest(fn () => $this->mysqlPlan(['charset' => 'latin1', 'collation' => 'utf8mb4_general_ci']), 'does not belong');
        $this->assertBadRequest(fn () => $this->mysqlPlan(['engine' => 'InnoDB']), 'Unknown database change');
        $this->assertBadRequest(fn () => $this->mysqlPlan(['collation' => ['x']]), 'Invalid value');
    }

    public function testMysqlConvertTablesAllAndSelected(): void
    {
        $plan = $this->mysqlPlan(['collation' => 'utf8mb4_general_ci', 'convert_tables' => true]);
        $this->assertSame([
            'ALTER DATABASE `shop``db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            'ALTER TABLE `shop``db`.`a` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            'ALTER TABLE `shop``db`.`b``c` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
        ], $plan['sql']);
        $this->assertSame(['convert_rewrites_tables'], $plan['warnings']);

        $plan = $this->mysqlPlan(['collation' => 'utf8mb4_general_ci', 'convert_tables' => true, 'tables' => ['b`c']]);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringContainsString('`b``c`', $plan['sql'][1]);

        // convert only (defaults unchanged): no ALTER DATABASE, tables converted to the current default
        $plan = $this->mysqlPlan(['convert_tables' => true, 'tables' => ['a']]);
        $this->assertSame(['ALTER TABLE `shop``db`.`a` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'], $plan['sql']);

        $this->assertBadRequest(fn () => $this->mysqlPlan(['convert_tables' => true, 'tables' => ['v_view']]), 'Not a base table');
        $this->assertSame([], $this->mysqlPlan(['convert_tables' => false, 'tables' => ['a']])['sql']);
    }

    public function testMysqlCopyRetargetsObjectHeadOnly(): void
    {
        $t = "CREATE DEFINER=`root`@`%` TRIGGER `src`.trg BEFORE INSERT ON src.books FOR EACH ROW SET NEW.x = (SELECT 1 FROM `src`.other)";
        $this->assertSame(
            "CREATE DEFINER=`root`@`%` TRIGGER `dst`.trg BEFORE INSERT ON `dst`.books FOR EACH ROW SET NEW.x = (SELECT 1 FROM `src`.other)",
            MySqlDriver::retargetHead($t, 'trigger', '`src`', '`dst`')
        );
        $f = 'CREATE FUNCTION `src`.`f`(x INT) RETURNS INT RETURN (SELECT x FROM `src`.t)';
        $this->assertSame('CREATE FUNCTION `dst`.`f`(x INT) RETURNS INT RETURN (SELECT x FROM `src`.t)', MySqlDriver::retargetHead($f, 'function', '`src`', '`dst`'));
        $e = 'CREATE EVENT `src`.`e` ON SCHEDULE EVERY 1 DAY DO DELETE FROM `src`.log';
        $this->assertSame('CREATE EVENT `dst`.`e` ON SCHEDULE EVERY 1 DAY DO DELETE FROM `src`.log', MySqlDriver::retargetHead($e, 'event', '`src`', '`dst`'));
        $this->assertSame('CREATE TRIGGER t BEFORE INSERT ON mysrc.b FOR EACH ROW SET @a = 1', MySqlDriver::retargetHead('CREATE TRIGGER t BEFORE INSERT ON mysrc.b FOR EACH ROW SET @a = 1', 'trigger', '`src`', '`dst`'));
    }

    public function testPgOwnerCommentLimitRenameQuoting(): void
    {
        $plan = (new PgSqlDriver())->planAlterDatabase('my"db', [
            'owner' => 'app"user',
            'comment' => "It's a \"db\"",
            'connection_limit' => '25',
            'rename' => 'new"name',
        ]);
        $this->assertSame([
            'ALTER DATABASE "my""db" OWNER TO "app""user"',
            "COMMENT ON DATABASE \"my\"\"db\" IS 'It''s a \"db\"'",
            'ALTER DATABASE "my""db" WITH CONNECTION LIMIT 25',
            'ALTER DATABASE "my""db" RENAME TO "new""name"',
        ], $plan['sql']);
        $this->assertSame(['pg_rename_exclusive'], $plan['warnings']);
    }

    public function testPgEmptyCommentAndValidation(): void
    {
        $d = new PgSqlDriver();
        $this->assertSame(['COMMENT ON DATABASE "d" IS NULL'], $d->planAlterDatabase('d', ['comment' => ''])['sql']);
        $this->assertSame(['ALTER DATABASE "d" WITH CONNECTION LIMIT -1'], $d->planAlterDatabase('d', ['connection_limit' => -1])['sql']);
        $this->assertSame([], $d->planAlterDatabase('d', ['rename' => 'd', 'owner' => ''])['sql']);
        $this->assertBadRequest(fn () => $d->planAlterDatabase('d', ['connection_limit' => '-2']));
        $this->assertBadRequest(fn () => $d->planAlterDatabase('d', ['connection_limit' => '5; DROP']));
        $this->assertBadRequest(fn () => $d->planAlterDatabase('d', ['rename' => str_repeat('x', 64)]), '63 bytes');
        $this->assertBadRequest(fn () => $d->planAlterDatabase('d', ['tablespace' => 'x']), 'Unknown database change');
    }

    public function testSqlitePragmaWhitelist(): void
    {
        $d = new SqliteDriver();
        $plan = $d->planAlterDatabase(['pragmas' => ['journal_mode' => 'WAL', 'user_version' => '42', 'foreign_keys' => false, 'auto_vacuum' => 'incremental'], 'vacuum' => true], ['auto_vacuum' => 'none']);
        $this->assertSame([
            'PRAGMA journal_mode = WAL',
            'PRAGMA user_version = 42',
            'PRAGMA foreign_keys = OFF',
            'PRAGMA auto_vacuum = INCREMENTAL',
            'VACUUM',
        ], $plan['sql']);
        $this->assertContains('sqlite_vacuum_rewrites', $plan['warnings']);
        $this->assertContains('sqlite_wal_files', $plan['warnings']);

        $plan = $d->planAlterDatabase(['pragmas' => ['auto_vacuum' => 'full']], ['auto_vacuum' => 'none']);
        $this->assertSame(['PRAGMA auto_vacuum = FULL'], $plan['sql']);
        $this->assertSame(['sqlite_auto_vacuum_needs_vacuum'], $plan['warnings']);

        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['journal_mode' => 'off']]), 'allowed');
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['journal_mode' => 'wal; ATTACH']]));
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['writable_schema' => 'on']]), 'not allowed');
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['user_version' => '1.5']]));
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['user_version' => '99999999999']]));
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => ['foreign_keys' => 'maybe']]));
        $this->assertBadRequest(fn () => $d->planAlterDatabase(['pragmas' => 'journal_mode=wal']));
    }

    public function testSqlSrvCollationAndRename(): void
    {
        $d = new SqlSrvDriver();
        $plan = $d->planAlterDatabase('sales]db', ['collation' => 'Latin1_General_100_CI_AS_SC_UTF8', 'rename' => 'sales]2'], ['Latin1_General_100_CI_AS_SC_UTF8', 'SQL_Latin1_General_CP1_CI_AS']);
        $this->assertSame([
            'ALTER DATABASE [sales]]db] COLLATE Latin1_General_100_CI_AS_SC_UTF8',
            'ALTER DATABASE [sales]]db] MODIFY NAME = [sales]]2]',
        ], $plan['sql']);
        $this->assertSame(['sqlsrv_exclusive'], $plan['warnings']);
        $this->assertBadRequest(fn () => $d->planAlterDatabase('x', ['collation' => 'Nope_CI'], ['SQL_Latin1_General_CP1_CI_AS']), 'Unknown collation');
        $this->assertBadRequest(fn () => $d->planAlterDatabase('x', ['collation' => 'a b'], ['a b']));
    }

    public function testCapabilitiesFlags(): void
    {
        $m = (new MySqlDriver())->capabilities();
        $this->assertTrue($m['db_alter'] && $m['db_copy'] && $m['db_rename']);
        $p = (new PgSqlDriver())->capabilities();
        $this->assertTrue($p['db_alter'] && $p['db_copy'] && $p['db_rename']);
        $s = (new SqliteDriver())->capabilities();
        $this->assertTrue($s['db_alter']);
        $this->assertFalse($s['db_copy'] || $s['db_rename']);
        $x = (new SqlSrvDriver())->capabilities();
        $this->assertTrue($x['db_alter'] && $x['db_rename']);
        $this->assertFalse($x['db_copy']);
    }
}
