<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\AbstractPdoDriver;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Batched grid edits (table.applyChanges → AbstractPdoDriver::applyRowChanges) on every engine:
 * SQLite always; MySQL / MariaDB / PostgreSQL when LDB_TEST_{MYSQL,MARIADB,PGSQL}_{DSN,USER,PASS}
 * are set. A scratch table "oph_it_batch_<random>" is created in the DSN's database and dropped.
 */
final class ApplyChangesTest extends TestCase
{
    private ?AbstractPdoDriver $d = null;
    private string $table = '';
    private string $file = '';

    /** @return array<string,array{0:string}> */
    public function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['MYSQL'], 'mariadb' => ['MARIADB'], 'pgsql' => ['PGSQL']];
    }

    private function boot(string $engine): AbstractPdoDriver
    {
        if ($engine === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->markTestSkipped('pdo_sqlite not available');
            }
            $this->file = (string) tempnam(sys_get_temp_dir(), 'oph_batch_');
            $d = new SqliteDriver();
            $d->connect(['driver' => 'sqlite', 'file' => $this->file, 'options' => []]);
        } else {
            $dsn = getenv('LDB_TEST_' . $engine . '_DSN');
            $isPg = $engine === 'PGSQL';
            if (!$dsn || !extension_loaded($isPg ? 'pdo_pgsql' : 'pdo_mysql')) {
                $this->markTestSkipped('LDB_TEST_' . $engine . '_DSN not set');
            }
            $parts = [];
            foreach (explode(';', (string) preg_replace('/^\w+:/', '', (string) $dsn)) as $kv) {
                if (strpos($kv, '=') !== false) {
                    [$k, $v] = explode('=', $kv, 2);
                    $parts[strtolower(trim($k))] = trim($v);
                }
            }
            $params = [
                'driver' => $isPg ? 'pgsql' : 'mysql',
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => (int) ($parts['port'] ?? ($isPg ? 5432 : 3306)),
                'user' => (string) getenv('LDB_TEST_' . $engine . '_USER'),
                'password' => (string) getenv('LDB_TEST_' . $engine . '_PASS'),
                'database' => $parts['dbname'] ?? null,
                'options' => [],
            ];
            $d = $isPg ? new PgSqlDriver() : new MySqlDriver();
            $d->connect($params);
            $d->select($parts['dbname'] ?? null, $isPg ? 'public' : null);
        }
        $this->table = 'oph_it_batch_' . bin2hex(random_bytes(3));
        $def = [
            'name' => $this->table,
            'columns' => [
                ['name' => 'id', 'type' => 'INTEGER', 'nullable' => false, 'default_kind' => 'none', 'auto_increment' => true],
                ['name' => 'name', 'type' => 'VARCHAR', 'length' => '100', 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'note', 'type' => 'TEXT', 'nullable' => true, 'default_kind' => 'null'],
                ['name' => 'code', 'type' => 'VARCHAR', 'length' => '20', 'nullable' => true, 'default_kind' => 'null'],
            ],
            'primary_key' => ['id'],
            'indexes' => [['name' => 'uq_' . $this->table . '_code', 'columns' => ['code'], 'unique' => true]],
        ];
        foreach ($d->buildCreateTable($def) as $sql) {
            $d->query($sql, 0);
        }
        foreach (['Ann', 'Bob', 'Cid'] as $i => $name) {
            $d->insertRow($this->table, ['name' => $name, 'code' => 'c' . ($i + 1)]);
        }
        return $this->d = $d;
    }

    protected function tearDown(): void
    {
        if ($this->d !== null && $this->table !== '') {
            try {
                $this->d->dropTable($this->table);
            } catch (\Throwable $e) {
                // best effort
            }
        }
        $this->d = null;
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /** @return array<int,string> id => name */
    private function names(): array
    {
        $res = $this->d->browse($this->table, ['per_page' => 100, 'order' => [['column' => 'id', 'dir' => 'ASC']]]);
        $out = [];
        foreach ($res['rows'] as $r) {
            $out[(int) $r[0]] = (string) $r[1];
        }
        return $out;
    }

    /** @dataProvider engines */
    public function testMixedBatchCommits(string $engine): void
    {
        $d = $this->boot($engine);
        $res = $d->applyRowChanges($this->table, [
            ['op' => 'update', 'key' => ['id' => 1], 'values' => ['name' => 'Anna', 'note' => 'n1']],
            ['op' => 'update', 'key' => ['id' => 2], 'values' => ['name' => 'Bob']], // unchanged value still matches
            ['op' => 'delete', 'key' => ['id' => 3]],
            ['op' => 'insert', 'values' => ['name' => 'Dee']],
            ['op' => 'update', 'key' => ['code' => 'c2'], 'values' => ['note' => 'by unique key']],
        ]);
        $this->assertTrue($res['executed']);
        $this->assertSame([1, 1, 1, 1, 1], $res['affected']);
        $this->assertCount(5, $res['sql']);
        $this->assertNotNull($res['insert_ids'][3]);
        $names = $this->names();
        $this->assertSame('Anna', $names[1]);
        $this->assertArrayNotHasKey(3, $names);
        $this->assertContains('Dee', $names);
        $this->assertCount(3, $names);
    }

    /** @dataProvider engines */
    public function testFailingThirdChangeRollsBackTheFirstTwo(string $engine): void
    {
        $d = $this->boot($engine);
        try {
            $d->applyRowChanges($this->table, [
                ['op' => 'update', 'key' => ['id' => 1], 'values' => ['name' => 'changed']],
                ['op' => 'delete', 'key' => ['id' => 2]],
                ['op' => 'update', 'key' => ['id' => 3], 'values' => ['code' => 'c1']], // duplicate unique value
                ['op' => 'insert', 'values' => ['name' => 'never']],
            ]);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('db_error', $e->getErrorCode());
            $this->assertSame(2, $e->getDetails()['index']);
            $this->assertStringContainsString('Change 3 of 4', $e->getMessage());
            $this->assertNotNull($e->getSql());
        }
        $this->assertSame([1 => 'Ann', 2 => 'Bob', 3 => 'Cid'], $this->names());
        // the connection is still usable after the rollback
        $this->assertSame(1, $d->applyRowChanges($this->table, [['op' => 'delete', 'key' => ['id' => 3]]])['affected'][0]);
    }

    /** @dataProvider engines */
    public function testStaleKeyRollsBack(string $engine): void
    {
        $d = $this->boot($engine);
        $d->deleteRows($this->table, [['id' => 2]]); // deleted "meanwhile"
        foreach (['update', 'delete'] as $op) {
            try {
                $change = ['op' => $op, 'key' => ['id' => 2]];
                if ($op === 'update') {
                    $change['values'] = ['name' => 'x'];
                }
                $d->applyRowChanges($this->table, [
                    ['op' => 'update', 'key' => ['id' => 1], 'values' => ['name' => 'changed']],
                    $change,
                ]);
                $this->fail('expected ApiException');
            } catch (ApiException $e) {
                $this->assertSame('stale_row', $e->getErrorCode());
                $this->assertSame(1, $e->getDetails()['index']);
            }
            $this->assertSame([1 => 'Ann', 3 => 'Cid'], $this->names());
        }
    }

    /** @dataProvider engines */
    public function testPreviewRendersQuotedLiteralsAndExecutesNothing(string $engine): void
    {
        $d = $this->boot($engine);
        $text = "日本語の \"テキスト\" it's";
        $res = $d->applyRowChanges($this->table, [
            ['op' => 'update', 'key' => ['id' => 1], 'values' => ['name' => $text, 'note' => null]],
            ['op' => 'insert', 'values' => ['name' => 'Ō', 'code' => 'z']],
            ['op' => 'delete', 'key' => ['id' => 3]],
        ], true);
        $this->assertFalse($res['executed']);
        $this->assertCount(3, $res['sql']);
        $this->assertStringContainsString("'日本語の \"テキスト\" it''s'", $res['sql'][0]);
        $this->assertStringContainsString('NULL', $res['sql'][0]);
        $this->assertStringStartsWith('UPDATE ', $res['sql'][0]);
        $this->assertStringContainsString("'Ō'", $res['sql'][1]);
        $this->assertStringStartsWith('DELETE FROM ', $res['sql'][2]);
        $this->assertStringNotContainsString('?', implode("\n", $res['sql']));
        $this->assertSame([1 => 'Ann', 2 => 'Bob', 3 => 'Cid'], $this->names());
    }

    /** @dataProvider engines */
    public function testValidation(string $engine): void
    {
        $d = $this->boot($engine);
        $cases = [
            [['op' => 'update', 'key' => ['id' => 1], 'values' => ['nope' => 1]], 'Unknown column'],
            [['op' => 'update', 'key' => ['name' => 'Ann'], 'values' => ['note' => 'x']], 'primary key'],
            [['op' => 'delete', 'key' => ['code' => null]], 'primary key'],
            [['op' => 'delete'], 'row key'],
            [['op' => 'merge', 'key' => ['id' => 1]], 'Unknown operation'],
            [['op' => 'update', 'key' => ['id' => 1], 'values' => ['name' => ['$binary' => 'AA==']]], 'Invalid value'],
        ];
        foreach ($cases as [$change, $needle]) {
            try {
                $d->applyRowChanges($this->table, [['op' => 'insert', 'values' => ['name' => 'ok']], $change]);
                $this->fail('expected ApiException for ' . json_encode($change));
            } catch (ApiException $e) {
                $this->assertSame('bad_request', $e->getErrorCode());
                $this->assertStringContainsString($needle, $e->getMessage());
                $this->assertSame(1, $e->getDetails()['index']);
            }
        }
        $this->assertCount(3, $this->names(), 'validation errors execute nothing');
    }
}
