<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/** SQLite database settings (PRAGMAs) on a temporary file; always runs with pdo_sqlite. */
final class SqliteDatabaseSettingsTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->file = (string) tempnam(sys_get_temp_dir(), 'oph_dbset_');
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function driver(): SqliteDriver
    {
        $d = new SqliteDriver();
        $d->connect(['driver' => 'sqlite', 'file' => $this->file, 'options' => []]);
        return $d;
    }

    public function testInfoAndPragmas(): void
    {
        $d = $this->driver();
        $d->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $d->pdo()->exec("INSERT INTO t (v) VALUES ('a'), ('b')");
        $info = $d->databaseInfo('x');
        $this->assertSame($this->file, $info['file']);
        $this->assertSame(1, $info['table_count']);
        $this->assertSame('delete', $info['settings']['journal_mode']);
        $this->assertSame('none', $info['settings']['auto_vacuum']);
        $this->assertSame(0, $info['settings']['user_version']);
        $this->assertTrue($info['settings']['foreign_keys'], 'LDB enables foreign_keys on every connection');
        $this->assertSame('UTF-8', $info['settings']['encoding']);

        $plan = $d->alterDatabase('x', ['pragmas' => ['journal_mode' => 'wal', 'user_version' => 12, 'auto_vacuum' => 'full'], 'vacuum' => true]);
        $this->assertContains('VACUUM', $plan['sql']);
        unset($d);

        $s = $this->driver()->databaseInfo('x')['settings'];
        $this->assertSame('wal', $s['journal_mode'], 'persists in the file');
        $this->assertSame(12, $s['user_version']);
        $this->assertSame('full', $s['auto_vacuum'], 'applied by VACUUM');
        $this->assertSame('2', (string) $this->driver()->query('SELECT COUNT(*) FROM t', 1)['rows'][0][0]);

        $this->driver()->alterDatabase('x', ['pragmas' => ['journal_mode' => 'delete']]);
        $this->assertSame('delete', $this->driver()->databaseInfo('x')['settings']['journal_mode']);
    }

    public function testNonWhitelistedPragmaIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->driver()->alterDatabase('x', ['pragmas' => ['writable_schema' => 'on']]);
    }

    public function testCopyIsUnsupported(): void
    {
        try {
            $this->driver()->copyDatabase('a', 'b');
            $this->fail('Expected unsupported');
        } catch (ApiException $e) {
            $this->assertSame('unsupported', $e->getErrorCode());
        }
    }
}
