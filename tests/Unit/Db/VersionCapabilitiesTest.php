<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Db;

use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqlSrvDriver;
use Ldb\Privileges\MySqlPrivilegePlanner;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Version-gated capabilities of the server drivers, decided from a simulated server version
 * (no connection needed: capabilities() only looks at the version).
 */
final class VersionCapabilitiesTest extends TestCase
{
    private function mysql(string $version): MySqlDriver
    {
        $d = new MySqlDriver();
        $d->simulateServerVersion($version);
        return $d;
    }

    public function testMySql8011(): void
    {
        $d = $this->mysql('8.0.11');
        $this->assertSame('mysql', $d->versionFlavor());
        $this->assertSame('8.0.11', $d->serverVersion());
        $c = $d->capabilities();
        $this->assertFalse($c['check_constraints'], 'CHECK enforced from 8.0.16');
        $this->assertFalse($c['functional_indexes'], '8.0.13');
        $this->assertFalse($c['default_expressions'], '8.0.13');
        $this->assertFalse($c['explain_tree'], '8.0.16');
        $this->assertFalse($c['explain_analyze'], '8.0.18');
        $this->assertFalse($c['invisible_columns'], '8.0.23');
        $this->assertTrue($c['rename_column']);
        $this->assertTrue($c['roles']);
        $this->assertTrue($c['account_lock']);
        $this->assertFalse($c['sequences']);
        $this->assertTrue($d->versionSupport()['version_supported']);
    }

    public function testMySqlCurrent(): void
    {
        $c = $this->mysql('8.0.40')->capabilities();
        foreach (['check_constraints', 'functional_indexes', 'default_expressions', 'explain_tree', 'explain_analyze', 'invisible_columns'] as $k) {
            $this->assertTrue($c[$k], $k);
        }
    }

    public function testMySql57IsUnsupported(): void
    {
        $d = $this->mysql('5.7.44-log');
        $this->assertFalse($d->capabilities()['roles']);
        $this->assertSame(['version_number' => '5.7.44', 'version_supported' => false, 'min_version' => '8.0.11', 'version_flavor' => 'mysql'], $d->versionSupport());
    }

    public function testMariaDb103(): void
    {
        $d = $this->mysql('10.3.39-MariaDB-1:10.3.39+maria~ubu2004');
        $this->assertTrue($d->isMariaDb());
        $this->assertSame('mariadb', $d->versionFlavor());
        $this->assertSame('10.3.39', $d->serverVersion());
        $c = $d->capabilities();
        $this->assertTrue($c['check_constraints']);
        $this->assertTrue($c['sequences']);
        $this->assertTrue($c['roles']);
        $this->assertTrue($c['create_or_replace_trigger']);
        $this->assertTrue($c['invisible_columns']);
        $this->assertFalse($c['account_lock'], 'ACCOUNT LOCK 10.4.2');
        $this->assertFalse($c['password_expire'], 'PASSWORD EXPIRE 10.4.3');
        $this->assertFalse($c['rename_column'], 'RENAME COLUMN 10.5.2 (LDB uses CHANGE COLUMN)');
        $this->assertFalse($c['functional_indexes']);
        $this->assertFalse($c['explain_tree']);
        $this->assertTrue($c['explain_analyze']);
        $this->assertTrue($d->versionSupport()['version_supported']);
        $this->assertSame('10.3', $d->versionSupport()['min_version']);
    }

    public function testMariaDb102IsUnsupported(): void
    {
        $d = $this->mysql('10.2.44-MariaDB');
        $this->assertFalse($d->versionSupport()['version_supported']);
        $this->assertFalse($d->capabilities()['sequences']);
    }

    public function testMariaDbCurrent(): void
    {
        $c = $this->mysql('11.4.2-MariaDB')->capabilities();
        $this->assertTrue($c['account_lock']);
        $this->assertTrue($c['password_expire']);
        $this->assertTrue($c['rename_column']);
    }

    public function testExplainFormatsAreRefusedOnOldMySql(): void
    {
        $d = $this->mysql('8.0.11');
        foreach (['tree', 'analyze'] as $format) {
            try {
                $d->explainFormat('SELECT 1', $format);
                $this->fail('Expected unsupported for ' . $format);
            } catch (ApiException $e) {
                $this->assertSame('unsupported', $e->getErrorCode());
                $this->assertStringContainsString('8.0.1', $e->getMessage());
            }
        }
    }

    public function testFunctionalIndexIsRefusedBefore8013(): void
    {
        try {
            $this->mysql('8.0.11')->buildCreateTable([
                'name' => 't',
                'columns' => [['name' => 'a', 'type' => 'VARCHAR', 'length' => '10', 'nullable' => true, 'default_kind' => 'null']],
                'indexes' => [['name' => 'ix', 'columns' => ['(lower(a))']]],
            ]);
            $this->fail('Expected unsupported');
        } catch (ApiException $e) {
            $this->assertSame('unsupported', $e->getErrorCode());
        }
    }

    public function testPostgreSql(): void
    {
        $d = new PgSqlDriver();
        $d->simulateServerVersion('11.22 (Debian 11.22-1.pgdg90+1)');
        $c = $d->capabilities();
        $this->assertFalse($c['generated_columns'], '12+');
        $this->assertFalse($c['reindex_concurrently'], '12+');
        $this->assertFalse($c['create_or_replace_trigger'], '14+');
        $this->assertFalse($c['maintain_privilege'], '17+');
        $this->assertTrue($c['procedures']);
        $this->assertSame('11.22', $d->serverVersion());
        $this->assertTrue($d->versionSupport()['version_supported']);

        $d->simulateServerVersion('10.23');
        $this->assertFalse($d->capabilities()['procedures']);
        $this->assertFalse($d->versionSupport()['version_supported']);

        $d->simulateServerVersion('17.2');
        $c = $d->capabilities();
        foreach (['generated_columns', 'reindex_concurrently', 'create_or_replace_trigger', 'maintain_privilege', 'procedures'] as $k) {
            $this->assertTrue($c[$k], $k);
        }
    }

    public function testSqlServer(): void
    {
        $d = new SqlSrvDriver();
        $d->simulateServerVersion('15.0.4415.2');
        $c = $d->capabilities();
        $this->assertTrue($c['create_or_alter']);
        $this->assertTrue($c['string_agg']);
        $this->assertTrue($c['utf8_collations']);
        $this->assertTrue($d->versionSupport()['version_supported']);

        $d->simulateServerVersion('13.0.1601.5'); // 2016 RTM
        $c = $d->capabilities();
        $this->assertFalse($c['create_or_alter']);
        $this->assertFalse($c['string_agg']);
        $this->assertFalse($d->versionSupport()['version_supported']);
    }

    public function testMariaDb103PrivilegePlannerRefusesLockAndExpire(): void
    {
        $p = new MySqlPrivilegePlanner(true, '10.3.39-MariaDB');
        $this->assertFalse($p->supportsAccountLock());
        $this->assertFalse($p->supportsPasswordExpire());
        foreach ([['lock' => true], ['unlock' => true], ['password_expire' => 'never']] as $account) {
            try {
                $p->plan('u', '%', ['account' => $account]);
                $this->fail('Expected unsupported');
            } catch (ApiException $e) {
                $this->assertSame('unsupported', $e->getErrorCode());
            }
        }
        // require_ssl and limits work on 10.3
        $sql = array_column($p->plan('u', '%', ['account' => ['require_ssl' => 'SSL']])['statements'], 'sql');
        $this->assertSame(['ALTER USER `u`@`%` REQUIRE SSL'], $sql);
        $this->assertTrue((new MySqlPrivilegePlanner(true, '10.4.2-MariaDB'))->supportsAccountLock());
        $this->assertTrue((new MySqlPrivilegePlanner(false, '8.0.11'))->supportsPasswordExpire());
    }

    /** @return array<string,array{string,string,string}> */
    public function intervalProvider(): array
    {
        return [
            'quoted (current servers)' => ["'1:30'", 'HOUR_MINUTE', '1:30'],
            'plain unit' => ['5', 'MINUTE', '5'],
            'hour_minute in minutes (8.0.11)' => ['90', 'HOUR_MINUTE', '1:30'],
            'minute_second' => ['125', 'MINUTE_SECOND', '2:5'],
            'hour_second' => ['3725', 'HOUR_SECOND', '1:2:5'],
            'day_hour' => ['50', 'DAY_HOUR', '2 2'],
            'day_minute' => ['1530', 'DAY_MINUTE', '1 1:30'],
            'day_second' => ['90061', 'DAY_SECOND', '1 1:1:1'],
            'year_month' => ['14', 'YEAR_MONTH', '1-2'],
            'microsecond kept' => ['15', 'SECOND_MICROSECOND', '15'],
        ];
    }

    /** @dataProvider intervalProvider */
    public function testEventIntervalValue(string $raw, string $field, string $expected): void
    {
        $this->assertSame($expected, MySqlDriver::eventIntervalValue($raw, $field));
    }
}
