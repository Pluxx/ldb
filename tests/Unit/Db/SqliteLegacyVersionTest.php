<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Db;

use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * SQLite 3.22 (the oldest supported library) simulated on the bundled, newer library: the
 * version-dependent code paths (capabilities, table rebuild instead of RENAME/DROP COLUMN,
 * table_info instead of table_xinfo, refused STRICT / generated columns) are chosen from
 * simulateServerVersion(). PHP images ship SQLite 3.3x/3.4x, so no real 3.22 binary is used.
 */
final class SqliteLegacyVersionTest extends TestCase
{
    private ?SqliteDriver $d = null;
    private string $file = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->file = (string) tempnam(sys_get_temp_dir(), 'ldb_sqlite_old_');
        $this->d = new SqliteDriver();
        $this->d->connect(['driver' => 'sqlite', 'file' => $this->file, 'options' => []]);
        $this->d->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT)', 0);
        $this->d->query("INSERT INTO t (name, note) VALUES ('a', 'x'), ('b', 'y')", 0);
        $this->d->simulateServerVersion('3.22.0');
    }

    protected function tearDown(): void
    {
        $this->d = null;
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function exec(array $sqls): void
    {
        foreach ($sqls as $s) {
            $this->d->query($s, 0);
        }
    }

    public function testCapabilitiesAndSupport(): void
    {
        $c = $this->d->capabilities();
        foreach (['rename_column', 'drop_column_native', 'window_functions', 'generated_columns', 'returning', 'strict_tables', 'upsert'] as $k) {
            $this->assertFalse($c[$k], $k);
        }
        $this->assertTrue($c['drop_column'], 'via table rebuild');
        $this->assertTrue($c['alter_column']);
        $info = $this->d->serverInfo();
        $this->assertSame('3.22.0', $info['version']);
        $this->assertTrue($info['version_supported']);
        $this->assertSame('3.22.0', $info['min_version']);

        $this->d->simulateServerVersion('3.21.0');
        $this->assertFalse($this->d->serverInfo()['version_supported']);
        $this->d->simulateServerVersion('3.24.0');
        $this->assertTrue($this->d->capabilities()['upsert']);
        $this->assertFalse($this->d->capabilities()['rename_column']);
        $this->d->simulateServerVersion(null);
        $this->assertTrue($this->d->capabilities()['rename_column'], 'bundled library is newer than 3.25');
    }

    public function testTableInfoWithoutTableXinfo(): void
    {
        $cols = array_column($this->d->tableInfo('t')['columns'], 'name');
        $this->assertSame(['id', 'name', 'note'], $cols);
    }

    public function testRenameColumnUsesRebuild(): void
    {
        $col = array_column($this->d->tableInfo('t')['columns'], null, 'name')['note'];
        $col['name'] = 'remark';
        $sqls = $this->d->buildAlterTable('t', ['modify' => [['column' => 'note', 'definition' => $col]]]);
        $this->assertStringNotContainsString('RENAME COLUMN', implode("\n", $sqls));
        $this->assertStringContainsString('ldb:rebuild', implode("\n", $sqls));
        $this->exec($sqls);
        $this->d->simulateServerVersion(null);
        $this->assertSame(['id', 'name', 'remark'], array_column($this->d->tableInfo('t')['columns'], 'name'));
        $this->assertSame('y', $this->d->query('SELECT remark FROM t WHERE id = 2', 1)['rows'][0][0]);
    }

    public function testDropColumnUsesRebuild(): void
    {
        $sqls = $this->d->buildAlterTable('t', ['drop' => ['note']]);
        $this->assertStringNotContainsString('DROP COLUMN', implode("\n", $sqls));
        $this->exec($sqls);
        $this->assertSame(['id', 'name'], array_column($this->d->tableInfo('t')['columns'], 'name'));
        $this->assertSame(2, (int) $this->d->query('SELECT COUNT(*) FROM t', 1)['rows'][0][0]);
    }

    public function testStrictTablesAndGeneratedColumnsAreRefused(): void
    {
        $base = ['name' => 'n', 'columns' => [['name' => 'a', 'type' => 'INTEGER', 'nullable' => true, 'default_kind' => 'null']]];
        foreach ([
            $base + ['options' => ['strict' => true]],
            ['name' => 'g', 'columns' => [$base['columns'][0], ['name' => 'b', 'type' => 'INTEGER', 'nullable' => true, 'generated' => 'a * 2']]],
        ] as $def) {
            try {
                $this->d->buildCreateTable($def);
                $this->fail('Expected unsupported');
            } catch (ApiException $e) {
                $this->assertSame('unsupported', $e->getErrorCode());
                $this->assertStringContainsString('SQLite 3.3', $e->getMessage());
            }
        }
        $this->d->simulateServerVersion('3.37.0');
        $this->assertStringContainsString('STRICT', $this->d->buildCreateTable($base + ['options' => ['strict' => true]])[0]);
    }
}
