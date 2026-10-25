<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\RateLimiter;
use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqlitePdo;
use Ldb\Export\CsvExporter;
use Ldb\Http\ApiException;
use Ldb\Http\Request;
use Ldb\Http\Response;
use Ldb\Kernel;
use PHPUnit\Framework\TestCase;

/** Security-relevant pure logic: DSN building, SQLite guards, path checks, CSV, kernel gates. */
final class SecurityTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    private function tmpDir(): string
    {
        $this->tmp = sys_get_temp_dir() . '/oph_sec_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/allowed', 0700, true);
        mkdir($this->tmp . '/outside', 0700, true);
        return $this->tmp;
    }

    /** @param array<string,mixed> $params */
    private static function dsn(object $driver, array $params): string
    {
        $m = new \ReflectionMethod($driver, 'buildDsn');
        $m->setAccessible(true);
        return (string) $m->invoke($driver, $params);
    }

    // ------------------------------------------------------------------ DSN injection

    public function testPgDsnValuesAreQuoted(): void
    {
        $dsn = self::dsn(new PgSqlDriver(), ['host' => 'db', 'port' => 5432, 'database' => "x host=10.0.0.1 o'k\\"]);
        $this->assertSame("pgsql:host='db';port=5432;dbname='x host=10.0.0.1 o\\'k\\\\'", $dsn);
    }

    public function testPgDsnRejectsSemicolon(): void
    {
        $this->expectException(ApiException::class);
        self::dsn(new PgSqlDriver(), ['host' => 'db', 'database' => 'a;host=evil']);
    }

    public function testMySqlDsnRejectsSemicolon(): void
    {
        $this->assertSame('mysql:host=db;port=3306;dbname=shop db;charset=utf8mb4', self::dsn(new MySqlDriver(), ['host' => 'db', 'port' => 3306, 'database' => 'shop db']));
        $this->expectException(ApiException::class);
        self::dsn(new MySqlDriver(), ['host' => 'db', 'database' => 'x;host=10.0.0.1']);
    }

    // ------------------------------------------------------------------ SQLite

    /** @return array<string,array{0:string}> */
    public function blockedSqlite(): array
    {
        return [
            'attach' => ["ATTACH '/tmp/x.php' AS x"],
            'attach database lower' => ["attach database '/tmp/x' as x"],
            'after comment' => ["/* hi */ -- there\n ATTACH 'x' AS y"],
            'explain' => ["EXPLAIN QUERY PLAN ATTACH 'x' AS y"],
            'second statement' => ["SELECT 1; ATTACH 'x' AS y"],
            'vacuum into' => ["VACUUM INTO '/var/www/html/public/x.php'"],
            'vacuum schema into' => ['VACUUM main INTO "f"'],
        ];
    }

    /** @dataProvider blockedSqlite */
    public function testSqliteBlocksFileEscapes(string $sql): void
    {
        $this->expectException(\PDOException::class);
        SqlitePdo::assertAllowed($sql);
    }

    public function testSqliteAllowsHarmlessUsesOfTheWords(): void
    {
        foreach ([
            "SELECT 'ATTACH x' AS \"attach\", [attach], `attach` FROM t",
            'SELECT attach FROM t',
            'VACUUM',
            "INSERT INTO t VALUES ('VACUUM INTO x')",
            "CREATE TABLE t (a); INSERT INTO t VALUES (1)",
        ] as $sql) {
            SqlitePdo::assertAllowed($sql);
        }
        $this->addToAssertionCount(1);
    }

    public function testSqlitePdoGuardsAllEntryPoints(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $pdo = new SqlitePdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
        foreach (['exec', 'query', 'prepare'] as $method) {
            try {
                $pdo->$method("ATTACH ':memory:' AS x");
                $this->fail($method . ' allowed ATTACH');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('ATTACH', $e->getMessage());
            }
        }
    }

    public function testSqlitePathAllowList(): void
    {
        $dir = $this->tmpDir();
        touch($dir . '/allowed/a.db');
        touch($dir . '/outside/b.db');
        symlink($dir . '/outside/b.db', $dir . '/allowed/link.db');
        $f = new DriverFactory(new Config(['sqlite_paths' => [$dir . '/allowed']]));

        $this->assertSame(realpath($dir . '/allowed/a.db'), $f->checkSqlitePath($dir . '/allowed/a.db'));
        foreach (['/allowed/../outside/b.db', '/allowed/link.db', '/outside/b.db', '/allowed'] as $bad) {
            try {
                $f->checkSqlitePath($dir . $bad);
                $this->fail('accepted ' . $bad);
            } catch (ApiException $e) {
                $this->assertContains($e->getHttpStatus(), [400, 403]);
            }
        }
    }

    public function testSqliteLoginNeedsPassword(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $dir = $this->tmpDir();
        touch($dir . '/allowed/a.db');
        $input = ['driver' => 'sqlite', 'file' => $dir . '/allowed/a.db'];

        $noHash = new DriverFactory(new Config(['sqlite_paths' => [$dir . '/allowed']]));
        try {
            $noHash->resolveLogin($input);
            $this->fail('SQLite login without configured password accepted');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getHttpStatus());
        }

        $f = new DriverFactory(new Config(['sqlite_paths' => [$dir . '/allowed'], 'sqlite_password_hash' => password_hash('s3cret', PASSWORD_DEFAULT)]));
        try {
            $f->resolveLogin($input + ['password' => 'wrong']);
            $this->fail('wrong SQLite password accepted');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }
        $this->assertSame('a.db', $f->resolveLogin($input + ['password' => 's3cret'])['server']);

        $open = new DriverFactory(new Config(['sqlite_paths' => [$dir . '/allowed'], 'sqlite_allow_no_password' => true]));
        $this->assertSame('a.db', $open->resolveLogin($input)['server']);
    }

    public function testSqliteWithoutPresetPolicy(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $dir = $this->tmpDir();
        touch($dir . '/allowed/a.db');
        $input = ['driver' => 'sqlite', 'file' => $dir . '/allowed/a.db'];
        $base = ['sqlite_paths' => [$dir . '/allowed'], 'sqlite_allow_no_password' => true];

        // Default (allow_arbitrary_server false): SQLite works without a preset.
        $f = new DriverFactory(new Config($base + ['allow_arbitrary_server' => false]));
        $this->assertTrue($f->isAvailable('sqlite'));
        $this->assertSame('a.db', $f->resolveLogin($input)['server']);

        // only_presets without a SQLite preset: refused.
        $strict = new DriverFactory(new Config($base + ['only_presets' => true]));
        $this->assertFalse($strict->isAvailable('sqlite'));
        try {
            $strict->resolveLogin($input);
            $this->fail('SQLite accepted under only_presets without a SQLite preset');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getHttpStatus());
        }

        // ... but a SQLite preset enables it.
        $withPreset = new DriverFactory(new Config($base + ['only_presets' => true, 'servers' => [['id' => 'lite', 'label' => 'Files', 'driver' => 'sqlite']]]));
        $this->assertSame('a.db', $withPreset->resolveLogin($input + ['preset' => 'lite'])['server']);
    }

    // ------------------------------------------------------------------ misc

    public function testCsvFormulaNeutralisation(): void
    {
        $this->assertSame("'=1+1", CsvExporter::neutraliseFormula('=1+1'));
        $this->assertSame("'@SUM(A1)", CsvExporter::neutraliseFormula('@SUM(A1)'));
        $this->assertSame("'+cmd|' /C calc'!A0", CsvExporter::neutraliseFormula("+cmd|' /C calc'!A0"));
        $this->assertSame("'\t=x", CsvExporter::neutraliseFormula("\t=x"));
        $this->assertSame('-5', CsvExporter::neutraliseFormula('-5'));
        $this->assertSame('1.5e3', CsvExporter::neutraliseFormula('1.5e3'));
        $this->assertSame('a=b', CsvExporter::neutraliseFormula('a=b'));
        $this->assertSame('', CsvExporter::neutraliseFormula(''));
    }

    public function testContentDispositionCannotInjectHeaders(): void
    {
        $h = Response::contentDisposition("a\"b\r\nSet-Cookie: x=1;.csv");
        $this->assertStringNotContainsString("\r", $h);
        $this->assertStringNotContainsString("\n", $h);
        $this->assertSame(1, substr_count($h, 'filename="'));
    }

    public function testRateLimiterIpv6BucketAndUnsafeDir(): void
    {
        $this->assertSame(RateLimiter::ipBucket('2001:db8:1:2:aaaa::1'), RateLimiter::ipBucket('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(RateLimiter::ipBucket('2001:db8:1:2::1'), RateLimiter::ipBucket('2001:db8:1:3::1'));
        $this->assertSame('203.0.113.7', RateLimiter::ipBucket('203.0.113.7'));

        $dir = $this->tmpDir();
        $safe = new RateLimiter(2, 60, $dir . '/rl');
        $safe->hit('k');
        $safe->hit('k');
        $this->assertTrue($safe->tooManyAttempts('k'));

        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid')) {
            mkdir($dir . '/shared', 0777);
            chmod($dir . '/shared', 0777);
            $unsafe = new RateLimiter(1, 60, $dir . '/shared');
            $prev = ini_set('error_log', '/dev/null');
            $unsafe->hit('k');
            ini_set('error_log', (string) $prev);
            $this->assertSame([], glob($dir . '/shared/*'), 'must not write into a world-writable directory');
        }
    }

    public function testKernelRejectsCrossSiteAndFormLogin(): void
    {
        $kernel = new Kernel(new Config());
        $r = $kernel->handle(new Request('POST', ['action' => 'db.list'], [], ['Sec-Fetch-Site' => 'cross-site']));
        $this->assertSame(403, $r->status());
        $r = $kernel->handle(new Request('POST', ['action' => 'auth.login'], ['user' => 'root'], ['Content-Type' => 'application/x-www-form-urlencoded']));
        $this->assertSame(415, $r->status());
    }
}
