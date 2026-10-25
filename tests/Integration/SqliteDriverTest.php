<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/** SQLite driver tests against a temporary database file (always run when pdo_sqlite exists). */
final class SqliteDriverTest extends TestCase
{
    private ?SqliteDriver $d = null;
    private string $file = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->file = tempnam(sys_get_temp_dir(), 'oph_sqlite_');
        $this->d = new SqliteDriver();
        $this->d->connect(['driver' => 'sqlite', 'file' => $this->file, 'options' => []]);
        $defs = [
            [
                'name' => 'authors',
                'columns' => [
                    ['name' => 'id', 'type' => 'INTEGER', 'nullable' => false, 'default_kind' => 'none', 'auto_increment' => true],
                    ['name' => 'name', 'type' => 'TEXT', 'nullable' => false, 'default_kind' => 'value', 'default' => "O'Neil", 'collation' => 'NOCASE'],
                    ['name' => 'created', 'type' => 'DATETIME', 'nullable' => true, 'default_kind' => 'expression', 'default' => 'CURRENT_TIMESTAMP'],
                    ['name' => 'avatar', 'type' => 'BLOB', 'nullable' => true, 'default_kind' => 'null'],
                ],
                'primary_key' => ['id'],
            ],
            [
                'name' => 'books',
                'columns' => [
                    ['name' => 'id', 'type' => 'INTEGER', 'nullable' => false, 'default_kind' => 'none', 'auto_increment' => true],
                    ['name' => 'author_id', 'type' => 'INTEGER', 'nullable' => true, 'default_kind' => 'null'],
                    ['name' => 'title', 'type' => 'VARCHAR', 'length' => '200', 'nullable' => false, 'default_kind' => 'none'],
                    ['name' => 'price', 'type' => 'REAL', 'nullable' => true, 'default_kind' => 'value', 'default' => '1.5'],
                ],
                'primary_key' => ['id'],
                'indexes' => [['name' => 'ix_title', 'columns' => ['title'], 'unique' => false], ['name' => 'uq_title_price', 'columns' => ['title', 'price'], 'unique' => true]],
                'foreign_keys' => [['columns' => ['author_id'], 'ref_table' => 'authors', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE']],
            ],
        ];
        foreach ($defs as $def) {
            foreach ($this->d->buildCreateTable($def) as $sql) {
                $this->d->query($sql, 1);
            }
        }
        $this->d->insertRow('authors', ['name' => 'Ann', 'avatar' => "\x00\xff\xfe"]);
        $this->d->insertRow('authors', ['name' => 'Bob']);
        for ($i = 0; $i < 12; $i++) {
            $this->d->insertRow('books', ['author_id' => $i % 2 + 1, 'title' => 'Book ' . $i, 'price' => $i]);
        }
        $this->d->query('CREATE TRIGGER trg_books AFTER UPDATE ON books BEGIN SELECT 1; END', 1);
        $this->d->query('CREATE VIEW v_books AS SELECT id, title FROM books', 1);
    }

    protected function tearDown(): void
    {
        $this->d = null;
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }
    }

    public function testConnectRequiresExistingFile(): void
    {
        $this->expectException(\PDOException::class);
        (new SqliteDriver())->connect(['file' => $this->file . '.missing']);
    }

    public function testMetadata(): void
    {
        $this->assertSame([basename($this->file)], array_column($this->d->listDatabases(), 'name'));
        $caps = $this->d->capabilities();
        $this->assertFalse($caps['users']);
        $this->assertFalse($caps['processes']);
        $this->assertTrue($caps['triggers']);
        $types = array_column($this->d->listTables(), 'type', 'name');
        $this->assertSame(['authors' => 'table', 'books' => 'table', 'v_books' => 'view'], $types);

        $a = $this->d->tableInfo('authors');
        $cols = array_column($a['columns'], null, 'name');
        $this->assertTrue($cols['id']['auto_increment']);
        $this->assertSame("O'Neil", $cols['name']['default']);
        $this->assertSame('NOCASE', $cols['name']['collation']);
        $this->assertSame('expression', $cols['created']['default_kind']);
        $this->assertSame('books', $a['referenced_by'][0]['table']);

        $b = $this->d->tableInfo('books');
        $this->assertSame('authors', $b['foreign_keys'][0]['ref_table']);
        $this->assertSame(['id'], $b['foreign_keys'][0]['ref_columns']);
        $ix = array_column($b['indexes'], null, 'name');
        $this->assertTrue($ix['PRIMARY']['primary']);
        $this->assertTrue($ix['uq_title_price']['unique']);
        $this->assertSame('AFTER', $b['triggers'][0]['timing']);
        $this->assertSame('UPDATE', $b['triggers'][0]['event']);
        $this->assertStringContainsString('CREATE TABLE', $this->d->createStatement('books'));
        $this->assertStringContainsString('VIEW', $this->d->createStatement('v_books', 'view'));
        $this->assertSame([], $this->d->listRoutines());

        $this->expectException(ApiException::class);
        $this->d->users();
    }

    public function testBrowseEditQuery(): void
    {
        $b = $this->d->browse('books', ['page' => 2, 'per_page' => 5, 'order' => [['column' => 'price', 'dir' => 'desc']], 'filters' => [['column' => 'price', 'op' => '>=', 'value' => 2]]]);
        $this->assertSame(10, $b['total']);
        $this->assertCount(5, $b['rows']);
        $this->assertTrue($b['editable']);
        $this->assertSame(1, $this->d->browse('books', ['search' => 'ook 11'])['total']);
        $this->assertSame(3, $this->d->browse('books', ['filters' => [['column' => 'id', 'op' => 'IN', 'value' => [1, 2, 3]]]])['total']);
        $a = $this->d->browse('authors', []);
        $this->assertSame(3, $a['rows'][0][3]['length']);

        $this->assertSame(1, $this->d->updateRow('books', ['id' => 1], ['title' => 'Changed']));
        $this->assertSame(2, $this->d->deleteRows('books', [['id' => 1], ['id' => 2]]));
        $q = $this->d->query('SELECT * FROM books', 4);
        $this->assertTrue($q['truncated']);
        $this->assertSame(['table' => 'books', 'primary_key' => ['id']], $q['editable']);
        $this->assertSame([['value' => '1', 'label' => 'Ann'], ['value' => '2', 'label' => 'Bob']], array_map(function ($r) {
            return ['value' => (string) $r['value'], 'label' => $r['label']];
        }, $this->d->fkValues('books', 'author_id', null)));
        $this->assertTrue($this->d->explain('SELECT * FROM books WHERE id = 3')['is_result_set']);

        // ON DELETE CASCADE works -> foreign keys are on
        $this->d->deleteRows('authors', [['id' => 1]]);
        $this->assertSame(0, (int) $this->d->query('SELECT COUNT(*) FROM books WHERE author_id = 1', 1)['rows'][0][0]);
    }

    public function testAlterRebuildKeepsDataIndexesTriggers(): void
    {
        $sqls = $this->d->buildAlterTable('books', [
            'add' => [['name' => 'isbn', 'type' => 'TEXT', 'nullable' => true, 'default_kind' => 'null', 'first' => true]],
            'modify' => [['column' => 'price', 'definition' => ['name' => 'cost', 'type' => 'NUMERIC', 'length' => '10,2', 'nullable' => false, 'default_kind' => 'value', 'default' => '0']]],
            'drop_index' => ['ix_title'],
            'add_index' => [['name' => 'ix_cost', 'columns' => ['cost'], 'unique' => false]],
        ]);
        $this->assertContains('PRAGMA foreign_keys = OFF', $sqls);
        foreach ($sqls as $s) {
            $this->d->query($s, 1);
        }
        $i = $this->d->tableInfo('books');
        $this->assertSame(['isbn', 'id', 'author_id', 'title', 'cost'], array_column($i['columns'], 'name'));
        $ix = array_column($i['indexes'], 'name');
        $this->assertContains('ix_cost', $ix);
        $this->assertContains('uq_title_price', $ix);
        $this->assertNotContains('ix_title', $ix);
        $this->assertCount(1, $i['triggers']);
        $this->assertCount(1, $i['foreign_keys']);
        $this->assertSame(12, (int) $this->d->query('SELECT COUNT(*) FROM books', 1)['rows'][0][0]);
        $this->assertSame(1, (int) $this->d->query('PRAGMA foreign_keys', 1)['rows'][0][0]);
        $this->assertSame([], $this->d->query('PRAGMA foreign_key_check', 10)['rows']);
        $this->assertCount(12, $this->d->query('SELECT * FROM v_books', 100)['rows']);

        // rename only -> native RENAME COLUMN
        $col = array_column($i['columns'], null, 'name')['isbn'];
        $col['name'] = 'code';
        $sqls = $this->d->buildAlterTable('books', ['modify' => [['column' => 'isbn', 'definition' => $col]]]);
        $this->assertCount(1, $sqls);
        $this->assertStringContainsString('RENAME COLUMN', $sqls[0]);
        $this->d->query($sqls[0], 1);

        // failing rebuild rolls back and leaves the connection usable
        $sqls = $this->d->buildAlterTable('books', ['add_index' => [['name' => 'PRIMARY', 'columns' => ['author_id'], 'primary' => true]]]);
        try {
            foreach ($sqls as $s) {
                $this->d->query($s, 1);
            }
            $this->fail('expected duplicate primary key failure');
        } catch (\PDOException $e) {
            $this->assertSame(12, (int) $this->d->query('SELECT COUNT(*) FROM books', 1)['rows'][0][0]);
            $this->assertSame(1, (int) $this->d->query('PRAGMA foreign_keys', 1)['rows'][0][0]);
        }
    }

    public function testTableOperations(): void
    {
        $this->d->copyTable('books', 'books2', true);
        $this->assertSame(12, (int) $this->d->query('SELECT COUNT(*) FROM books2', 1)['rows'][0][0]);
        $this->d->renameTable('books2', 'books3');
        $this->d->truncateTable('books3');
        $this->assertSame('1', $this->d->insertRow('books3', ['title' => 'x'])['insert_id']);
        $this->d->dropTable('books3');
        $this->d->dropTable('v_books', true);
        $this->assertSame(['authors', 'books'], array_column($this->d->listTables(), 'name'));
        foreach (['vacuum', 'analyze', 'check', 'optimize'] as $op) {
            $this->assertNotEmpty($this->d->maintenance(['books'], $op)['rows'], $op);
        }
        $this->assertNotEmpty($this->d->variables('page_size'));
        $this->assertNotEmpty($this->d->collations());
        $this->assertContains('PRAGMA', $this->d->completionWords()['keywords']);
    }

    public function testQueryHasNoMoreResults(): void
    {
        $r = $this->d->query('SELECT 1 AS a UNION ALL SELECT 2', 1);
        $this->assertTrue($r['is_result_set']);
        $this->assertTrue($r['truncated']);
        $this->assertArrayNotHasKey('more_results', $r);
        $w = $this->d->query("UPDATE authors SET name = name", 10);
        $this->assertFalse($w['is_result_set']);
        $this->assertSame(2, $w['affected']);
    }
}
