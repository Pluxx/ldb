<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Shared MySQL-family integration tests. Subclasses name the env prefix
 * (LDB_TEST_MYSQL_* / LDB_TEST_MARIADB_*):
 *   <PREFIX>_DSN   e.g. "mysql:host=127.0.0.1;port=3306;dbname=ldb_demo;charset=utf8mb4"
 *   <PREFIX>_USER  needs CREATE/DROP DATABASE, CREATE USER (e.g. root)
 *   <PREFIX>_PASS
 * A throwaway database "ldb_it_<random>" is created and dropped.
 */
abstract class MySqlDriverTestCase extends TestCase
{
    protected static ?MySqlDriver $d = null;
    protected static string $db = '';
    protected static array $params = [];

    abstract protected static function envPrefix(): string;

    public static function setUpBeforeClass(): void
    {
        self::$d = null;
        $dsn = getenv(static::envPrefix() . '_DSN');
        if (!$dsn || !extension_loaded('pdo_mysql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        self::$params = [
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => (string) getenv(static::envPrefix() . '_USER'),
            'password' => (string) getenv(static::envPrefix() . '_PASS'),
            'database' => null,
            'options' => [],
        ];
        $d = new MySqlDriver();
        $d->connect(self::$params);
        self::$db = 'ldb_it_' . bin2hex(random_bytes(4));
        $d->createDatabase(self::$db, 'utf8mb4_unicode_ci');
        $d->select(self::$db);
        foreach (array_merge(
            $d->buildCreateTable(self::authorsDefinition()),
            $d->buildCreateTable(self::booksDefinition())
        ) as $sql) {
            $d->query($sql, 1);
        }
        self::$d = $d;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d !== null) {
            self::$d->dropDatabase(self::$db);
            self::$d = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set ' . static::envPrefix() . '_DSN/USER/PASS to run these integration tests');
        }
    }

    private static function authorsDefinition(): array
    {
        return [
            'name' => 'authors',
            'columns' => [
                ['name' => 'id', 'type' => 'INT', 'unsigned' => true, 'nullable' => false, 'default_kind' => 'none', 'auto_increment' => true, 'comment' => 'pk'],
                ['name' => 'name', 'type' => 'VARCHAR', 'length' => '100', 'nullable' => false, 'default_kind' => 'value', 'default' => "O'Neil"],
                ['name' => 'bio', 'type' => 'TEXT', 'nullable' => true, 'default_kind' => 'null'],
                ['name' => 'created', 'type' => 'TIMESTAMP', 'nullable' => true, 'default_kind' => 'expression', 'default' => 'CURRENT_TIMESTAMP'],
                ['name' => 'avatar', 'type' => 'BLOB', 'nullable' => true, 'default_kind' => 'none'],
            ],
            'primary_key' => ['id'],
            'indexes' => [['name' => 'ix_name', 'columns' => ['name'], 'unique' => false]],
            'foreign_keys' => [],
            'options' => ['engine' => 'InnoDB', 'comment' => 'Authors'],
        ];
    }

    private static function booksDefinition(): array
    {
        return [
            'name' => 'books',
            'columns' => [
                ['name' => 'id', 'type' => 'INT', 'nullable' => false, 'default_kind' => 'none', 'auto_increment' => true],
                ['name' => 'author_id', 'type' => 'INT', 'unsigned' => true, 'nullable' => true, 'default_kind' => 'null'],
                ['name' => 'title', 'type' => 'VARCHAR', 'length' => '200', 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'kind', 'type' => 'ENUM', 'length' => "'novel','poem'", 'nullable' => false, 'default_kind' => 'value', 'default' => 'novel'],
                ['name' => 'price', 'type' => 'DECIMAL', 'length' => '8,2', 'nullable' => true, 'default_kind' => 'value', 'default' => '9.50'],
            ],
            'primary_key' => ['id'],
            'indexes' => [['name' => 'ix_title', 'columns' => ['title'], 'unique' => false]],
            'foreign_keys' => [['name' => 'fk_books_author', 'columns' => ['author_id'], 'ref_table' => 'authors', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => null]],
            'options' => [],
        ];
    }

    public function testServerInfoAndCapabilities(): void
    {
        $info = self::$d->serverInfo();
        $this->assertSame('mysql', $info['driver']);
        $this->assertNotEmpty($info['version']);
        $caps = self::$d->capabilities();
        $this->assertTrue($caps['users']);
        $this->assertFalse($caps['schemas']);
        $this->assertContains(self::$db, array_column(self::$d->listDatabases(), 'name'));
    }

    public function testTableInfo(): void
    {
        $i = self::$d->tableInfo('authors');
        $cols = array_column($i['columns'], null, 'name');
        $this->assertTrue($cols['id']['auto_increment']);
        $this->assertTrue($cols['id']['unsigned']);
        $this->assertSame('value', $cols['name']['default_kind']);
        $this->assertSame("O'Neil", $cols['name']['default']);
        $this->assertSame('null', $cols['bio']['default_kind']);
        $this->assertSame('expression', $cols['created']['default_kind']);
        $this->assertSame('Authors', $i['options']['comment']);
        $this->assertCount(1, $i['referenced_by']);
        $b = self::$d->tableInfo('books');
        $this->assertSame('authors', $b['foreign_keys'][0]['ref_table']);
        $this->assertSame('CASCADE', $b['foreign_keys'][0]['on_delete']);
        $this->assertContains('ix_title', array_column($b['indexes'], 'name'));
        $this->expectException(ApiException::class);
        self::$d->tableInfo('does_not_exist');
    }

    public function testRowsBrowseAndFilters(): void
    {
        $r = self::$d->insertRow('authors', ['name' => 'Ann', 'bio' => 'Ünïcødé', 'avatar' => "\x89PNG\x00\xff"]);
        $this->assertSame(1, $r['affected']);
        $annId = (int) $r['insert_id'];
        $bobId = (int) self::$d->insertRow('authors', ['name' => 'Bob'])['insert_id'];
        for ($i = 0; $i < 20; $i++) {
            self::$d->insertRow('books', ['author_id' => $i % 2 ? $bobId : $annId, 'title' => 'Book ' . $i . ($i === 5 ? ' 50%_off' : ''), 'price' => $i]);
        }
        $b = self::$d->browse('books', ['page' => 2, 'per_page' => 5, 'order' => [['column' => 'id', 'dir' => 'asc']]]);
        $this->assertSame(20, $b['total']);
        $this->assertCount(5, $b['rows']);
        $this->assertTrue($b['editable']);
        $this->assertSame(['id'], $b['primary_key']);
        $fk = array_column($b['columns'], 'fk', 'name')['author_id'];
        $this->assertSame(['table' => 'authors', 'column' => 'id'], $fk);

        $cases = [
            ['price', '>=', 15, 5], ['price', 'BETWEEN', [2, 4], 3], ['price', 'IN', [1, 2], 2],
            ['price', 'NOT IN', '1,2', 18], ['title', 'contains', '%_off', 1], ['title', 'starts', 'Book 1', 11],
            ['title', 'ends', '9', 2], ['title', 'LIKE', 'Book 1_', 10], ['title', 'IS NOT NULL', null, 20],
            ['price', '!=', 3, 19],
        ];
        foreach ($cases as [$col, $op, $val, $exp]) {
            $r = self::$d->browse('books', ['per_page' => 100, 'filters' => [['column' => $col, 'op' => $op, 'value' => $val]]]);
            $this->assertSame($exp, $r['total'], "$col $op " . json_encode($val));
        }
        $this->assertSame(1, self::$d->browse('books', ['search' => '50%'])['total']);

        $a = self::$d->browse('authors', ['filters' => [['column' => 'id', 'op' => '=', 'value' => $annId]]]);
        $this->assertSame('Ünïcødé', $a['rows'][0][2]);
        $this->assertSame(6, $a['rows'][0][4]['length']);

        $this->assertSame(1, self::$d->updateRow('authors', ['id' => $bobId], ['bio' => 'x']));
        $fv = self::$d->fkValues('books', 'author_id', 'bo');
        $this->assertSame('Bob', $fv[0]['label']);
        $first = self::$d->query('SELECT id FROM books ORDER BY id LIMIT 2', 10)['rows'];
        $this->assertSame(2, self::$d->deleteRows('books', [['id' => $first[0][0]], ['id' => $first[1][0]]]));
    }

    public function testQuery(): void
    {
        $q = self::$d->query('SELECT * FROM books', 3);
        $this->assertTrue($q['is_result_set']);
        $this->assertTrue($q['truncated']);
        $this->assertCount(3, $q['rows']);
        $this->assertSame(['table' => 'books', 'primary_key' => ['id']], $q['editable']);
        $q = self::$d->query("INSERT INTO authors (name) VALUES ('Cy')", 10);
        $this->assertSame(1, $q['affected']);
        $this->assertNotNull($q['insert_id']);
        $this->assertFalse(self::$d->query('USE ' . self::$d->quoteIdentifier(self::$db), 1)['is_result_set']);
        $this->assertTrue(self::$d->explain('SELECT * FROM books WHERE id = 1')['is_result_set']);
        $j = self::$d->explainFormat('SELECT * FROM books', 'json');
        $this->assertNotNull(json_decode((string) $j['rows'][0][0], true));
    }

    public function testAlterCopyRenameDrop(): void
    {
        $sqls = self::$d->buildAlterTable('books', [
            'add' => [['name' => 'pages', 'type' => 'INT', 'nullable' => true, 'default_kind' => 'null', 'after' => 'title']],
            'modify' => [['column' => 'price', 'definition' => ['name' => 'cost', 'type' => 'DECIMAL', 'length' => '10,2', 'nullable' => true, 'default_kind' => 'null']]],
            'drop' => ['kind'],
            'add_index' => [['name' => 'ix_pages', 'columns' => ['pages'], 'unique' => false]],
            'drop_index' => ['ix_title'],
            'drop_fk' => ['fk_books_author'],
            'add_fk' => [['name' => 'fk2', 'columns' => ['author_id'], 'ref_table' => 'authors', 'ref_columns' => ['id'], 'on_delete' => 'SET NULL']],
            'options' => ['comment' => 'Books'],
        ]);
        foreach ($sqls as $s) {
            self::$d->query($s, 1);
        }
        $i = self::$d->tableInfo('books');
        $this->assertSame(['id', 'author_id', 'title', 'pages', 'cost'], array_column($i['columns'], 'name'));
        $this->assertSame('fk2', $i['foreign_keys'][0]['name']);
        $this->assertContains('ix_pages', array_column($i['indexes'], 'name'));

        self::$d->copyTable('books', 'books_copy', true);
        $n = (int) self::$d->query('SELECT COUNT(*) FROM books', 1)['rows'][0][0];
        $this->assertSame($n, (int) self::$d->query('SELECT COUNT(*) FROM books_copy', 1)['rows'][0][0]);
        self::$d->renameTable('books_copy', 'books_old');
        self::$d->truncateTable('books_old');
        $this->assertSame(0, (int) self::$d->query('SELECT COUNT(*) FROM books_old', 1)['rows'][0][0]);
        $m = self::$d->maintenance(['books', 'books_old'], 'analyze');
        $this->assertCount(2, $m['rows']);
        self::$d->dropTable('books_old');
        $this->assertNotContains('books_old', array_column(self::$d->listTables(), 'name'));
    }

    public function testObjects(): void
    {
        self::$d->query('CREATE VIEW v_books AS SELECT id, title FROM books', 1);
        self::$d->query('CREATE TRIGGER trg_bi BEFORE INSERT ON books FOR EACH ROW SET NEW.title = TRIM(NEW.title)', 1);
        self::$d->query('CREATE FUNCTION f_dbl(x INT) RETURNS INT DETERMINISTIC RETURN x * 2', 1);
        $types = array_column(self::$d->listTables(), 'type', 'name');
        $this->assertSame('view', $types['v_books']);
        $this->assertSame('trg_bi', self::$d->listTriggers()[0]['name']);
        $this->assertSame('FUNCTION', self::$d->listRoutines()[0]['type']);
        $this->assertStringContainsString('VIEW', self::$d->createStatement('v_books', 'view'));
        $this->assertStringContainsString('TRIGGER', self::$d->createStatement('trg_bi', 'trigger'));
        $this->assertStringContainsString('FUNCTION', self::$d->createStatement('f_dbl', 'routine'));
        $this->assertStringStartsWith('CREATE TABLE', self::$d->createStatement('books'));
    }

    public function testServerFeatures(): void
    {
        $this->assertNotEmpty(self::$d->variables('version'));
        $this->assertNotEmpty(self::$d->status());
        $this->assertNotEmpty(self::$d->collations());
        $this->assertNotEmpty(self::$d->charsets());
        $this->assertContains('InnoDB', array_column(self::$d->engines(), 'name'));
        $p = self::$d->processes();
        $this->assertSame('id', $p['columns'][0]);
        $w = self::$d->completionWords();
        $this->assertContains('SELECT', $w['keywords']);
        $this->assertArrayHasKey('Numeric', self::$d->dataTypes());

        $user = 'oph_it_' . bin2hex(random_bytes(3));
        self::$d->saveUser($user, '%', 'Pw-' . bin2hex(random_bytes(6)), ['GRANT SELECT ON ' . self::$d->quoteIdentifier(self::$db) . ".* TO '$user'@'%'"], true);
        try {
            $this->assertContains($user, array_column(self::$d->users(), 'user'));
            $this->assertStringContainsString('GRANT SELECT', implode("\n", self::$d->userGrants($user, '%')));
            self::$d->saveUser($user, '%', null, ["GRANT USAGE ON *.* TO '$user'@'%'"], false);
            $this->assertStringNotContainsString('GRANT SELECT', implode("\n", self::$d->userGrants($user, '%')));
        } finally {
            self::$d->dropUser($user, '%');
        }
        $this->assertNotContains($user, array_column(self::$d->users(), 'user'));
    }

    public function testNormalizeValue(): void
    {
        $this->assertSame(['$binary' => base64_encode("\xff"), 'length' => 1], self::$d->normalizeValue("\xff"));
        $this->assertSame((string) PHP_INT_MAX, self::$d->normalizeValue(PHP_INT_MAX));
        $this->assertSame(7, self::$d->normalizeValue(7));
    }

    public function testCallReturningSeveralResultSets(): void
    {
        $d = self::$d;
        $d->query('DROP PROCEDURE IF EXISTS p_two', 1);
        $d->query('CREATE PROCEDURE p_two() BEGIN SELECT 1 AS a; SELECT 2 AS b, 3 AS c; END', 1);
        $r = $d->query('CALL p_two()', 10);
        $this->assertTrue($r['is_result_set']);
        $this->assertSame('a', $r['columns'][0]['name']);
        $this->assertCount(1, $r['more_results']);
        $this->assertSame('b', $r['more_results'][0]['columns'][0]['name']);
        $this->assertSame([[2, 3]], array_map(fn ($row) => array_map('intval', $row), $r['more_results'][0]['rows']));
        // connection must not be "out of sync"
        $this->assertSame(1, (int) $d->query('SELECT 1 AS one', 10)['rows'][0][0]);
        // plain statements have no more_results
        $this->assertArrayNotHasKey('more_results', $d->query('SELECT 1', 10));
        // procedure without result set: OK packet only
        $d->query('DROP PROCEDURE IF EXISTS p_none', 1);
        $d->query('CREATE PROCEDURE p_none() BEGIN DO 1; END', 1);
        $n = $d->query('CALL p_none()', 10);
        $this->assertFalse($n['is_result_set']);
        $this->assertSame(1, (int) $d->query('SELECT 1', 10)['rows'][0][0]);
        $d->query('DROP PROCEDURE p_two', 1);
        $d->query('DROP PROCEDURE p_none', 1);
    }
}
