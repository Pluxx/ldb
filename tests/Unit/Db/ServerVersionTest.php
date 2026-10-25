<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Db;

use Ldb\Db\ServerVersion;
use PHPUnit\Framework\TestCase;

/** Version parsing, comparison and the support policy (2018+ releases). */
final class ServerVersionTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public function normalizeProvider(): array
    {
        return [
            'mysql' => ['8.0.11', '8.0.11'],
            'mysql suffix' => ['8.0.36-0ubuntu0.22.04.1', '8.0.36'],
            'percona' => ['8.0.35-27', '8.0.35'],
            'mariadb' => ['10.3.39-MariaDB-1:10.3.39+maria~ubu2004', '10.3.39'],
            'mariadb handshake prefix' => ['5.5.5-10.3.39-MariaDB', '10.3.39'],
            'mariadb 11' => ['11.4.2-MariaDB-ubu2404', '11.4.2'],
            'pg version()' => ['PostgreSQL 11.22 (Debian 11.22-1.pgdg90+1) on x86_64-pc-linux-gnu', '11.22'],
            'pg server_version' => ['11.22 (Debian 11.22-1.pgdg90+1)', '11.22'],
            'pg 17' => ['17.2', '17.2'],
            'pg beta' => ['17beta1', '17'],
            'pg 9.6' => ['9.6.24', '9.6.24'],
            'sqlsrv' => ['15.0.4415.2', '15.0.4415.2'],
            'sqlite' => ['3.22.0', '3.22.0'],
            'empty' => ['', ''],
            'garbage' => ['unknown', ''],
        ];
    }

    /** @dataProvider normalizeProvider */
    public function testNormalize(string $raw, string $expected): void
    {
        $this->assertSame($expected, ServerVersion::normalize($raw));
    }

    public function testCompare(): void
    {
        $this->assertSame(0, ServerVersion::compare('10.3', '10.3.0'));
        $this->assertSame(-1, ServerVersion::compare('8.0.9', '8.0.11'));
        $this->assertSame(1, ServerVersion::compare('8.0.11', '8.0.9'));
        $this->assertSame(1, ServerVersion::compare('10.10.2', '10.9.8'));
        $this->assertSame(-1, ServerVersion::compare('15.0', '15.0.4415.2'));
        $this->assertSame(1, ServerVersion::compare('11.22', '11'));
        $this->assertTrue(ServerVersion::atLeast('10.3.39-MariaDB', '10.3'));
        $this->assertFalse(ServerVersion::atLeast('10.2.44-MariaDB', '10.3'));
        $this->assertTrue(ServerVersion::atLeast('', '99'), 'unknown version counts as new');
    }

    /** @return array<string,array{string,string,bool}> */
    public function supportProvider(): array
    {
        return [
            'mysql 5.7' => ['mysql', '5.7.44', false],
            'mysql 8.0.4 rc' => ['mysql', '8.0.4', false],
            'mysql 8.0.11' => ['mysql', '8.0.11', true],
            'mysql 8.4' => ['mysql', '8.4.3', true],
            'mysql 9' => ['mysql', '9.1.0', true],
            'mariadb 10.2' => ['mariadb', '10.2.44-MariaDB', false],
            'mariadb 10.3.0' => ['mariadb', '10.3.0-MariaDB', true],
            'mariadb 10.11' => ['mariadb', '10.11.6-MariaDB', true],
            'mariadb 11' => ['mariadb', '11.4.2-MariaDB', true],
            'pg 10' => ['pgsql', '10.23', false],
            'pg 11' => ['pgsql', '11.0', true],
            'pg 17' => ['pgsql', '17.2', true],
            'sqlsrv 2017' => ['sqlsrv', '14.0.3456.2', false],
            'sqlsrv 2019' => ['sqlsrv', '15.0.2000.5', true],
            'sqlsrv 2022' => ['sqlsrv', '16.0.4135.4', true],
            'sqlite 3.21' => ['sqlite', '3.21.0', false],
            'sqlite 3.22' => ['sqlite', '3.22.0', true],
            'sqlite 3.46' => ['sqlite', '3.46.1', true],
            'unknown version' => ['mysql', '', true],
            'unknown flavor' => ['oracle', '1.0', true],
        ];
    }

    /** @dataProvider supportProvider */
    public function testSupportPolicy(string $flavor, string $version, bool $supported): void
    {
        $this->assertSame($supported, ServerVersion::isSupported($flavor, $version));
        $s = ServerVersion::support($flavor, $version);
        $this->assertSame($supported, $s['version_supported']);
        $this->assertSame(ServerVersion::minimum($flavor), $s['min_version']);
        $this->assertSame($flavor, $s['version_flavor']);
    }

    public function testMinimumIsTheFirst2018Release(): void
    {
        $this->assertSame(['mysql' => '8.0.11', 'mariadb' => '10.3', 'pgsql' => '11', 'sqlsrv' => '15.0', 'sqlite' => '3.22.0'], ServerVersion::MINIMUM);
    }
}
