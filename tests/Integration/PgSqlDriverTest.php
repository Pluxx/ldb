<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\PgSqlDriver;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real PostgreSQL server. Skipped unless these env vars are set:
 *   LDB_TEST_PGSQL_DSN   e.g. "pgsql:host=127.0.0.1;port=5432;dbname=postgres"
 *   LDB_TEST_PGSQL_USER  (needs CREATEDB / CREATEROLE, e.g. postgres)
 *   LDB_TEST_PGSQL_PASS
 * A throwaway database "ldb_it_<random>" is created and dropped.
 */
final class PgSqlDriverTest extends TestCase
{
    private static ?PgSqlDriver $d = null;
    private static string $db = '';
    private static array $params = [];

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('LDB_TEST_PGSQL_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^pgsql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        self::$params = [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => (string) getenv('LDB_TEST_PGSQL_USER'),
            'password' => (string) getenv('LDB_TEST_PGSQL_PASS'),
            'database' => $parts['dbname'] ?? 'postgres',
            'options' => [],
        ];
        $d = new PgSqlDriver();
        $d->connect(self::$params);
        self::$db = 'ldb_it_' . bin2hex(random_bytes(4));
        $d->createDatabase(self::$db);
        $d->select(self::$db, 'public');
        foreach ($d->buildCreateTable(self::authorsDefinition()) as $sql) {
            $d->pdo()->exec($sql);
        }
        foreach ($d->buildCreateTable(self::booksDefinition()) as $sql) {
            $d->pdo()->exec($sql);
        }
        self::$d = $d;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d !== null) {
            $d = new PgSqlDriver();
            $d->connect(self::$params);
            self::$d = null; // close the connection to the test database first
            gc_collect_cycles();
            $d->pdo()->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
                . $d->pdo()->quote(self::$db) . ' AND pid <> pg_backend_pid()');
            $d->dropDatabase(self::$db);
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set LDB_TEST_PGSQL_DSN/USER/PASS to run PostgreSQL integration tests');
        }
    }

    private static function authorsDefinition(): array
    {
        return [
            'name' => 'authors',
            'columns' => [
                ['name' => 'id', 'type' => 'integer', 'auto_increment' => true, 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'name', 'type' => 'character varying', 'length' => '100', 'nullable' => false,
                 'default_kind' => 'value', 'default' => "O'Brien", 'comment' => 'Author name'],
                ['name' => 'tags', 'type' => 'text[]', 'nullable' => true, 'default_kind' => 'none'],
                ['name' => 'meta', 'type' => 'jsonb', 'nullable' => true, 'default_kind' => 'none'],
            ],
            'primary_key' => ['id'],
            'indexes' => [['name' => 'authors_name_uq', 'columns' => ['name'], 'unique' => true]],
            'foreign_keys' => [],
            'options' => ['comment' => 'All authors'],
        ];
    }

    private static function booksDefinition(): array
    {
        return [
            'name' => 'books',
            'columns' => [
                ['name' => 'id', 'type' => 'bigserial', 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'author_id', 'type' => 'integer', 'nullable' => true, 'default_kind' => 'none'],
                ['name' => 'title', 'type' => 'text', 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'price', 'type' => 'numeric', 'length' => '10,2', 'nullable' => true, 'default_kind' => 'value', 'default' => '9.99'],
                ['name' => 'ip', 'type' => 'inet', 'nullable' => true, 'default_kind' => 'none'],
                ['name' => 'active', 'type' => 'boolean', 'nullable' => false, 'default_kind' => 'value', 'default' => 'true'],
            ],
            'primary_key' => ['id'],
            'indexes' => [],
            'foreign_keys' => [['name' => 'books_author_fk', 'columns' => ['author_id'], 'ref_table' => 'authors',
                                'ref_columns' => ['id'], 'on_delete' => 'SET NULL', 'on_update' => null]],
            'options' => [],
        ];
    }

    public function testSelectSetsDatabaseAndSchema(): void
    {
        $d = self::$d;
        $this->assertSame(self::$db, $d->currentDatabase());
        $this->assertSame('public', $d->currentSchema());
        $this->assertContains('public', $d->listSchemas());
        $this->assertContains(self::$db, array_column($d->listDatabases(), 'name'));
    }

    public function testTableInfo(): void
    {
        $info = self::$d->tableInfo('authors');
        $cols = array_column($info['columns'], null, 'name');
        $this->assertTrue($cols['id']['auto_increment']);
        $this->assertTrue($cols['id']['primary']);
        $this->assertSame('character varying', $cols['name']['type']);
        $this->assertSame('100', $cols['name']['length']);
        $this->assertSame('value', $cols['name']['default_kind']);
        $this->assertSame("O'Brien", $cols['name']['default']);
        $this->assertSame('Author name', $cols['name']['comment']);
        $this->assertSame('text[]', $cols['tags']['type']);
        $this->assertSame('All authors', $info['options']['comment']);
        $this->assertSame('books', $info['referenced_by'][0]['table']);

        $books = self::$d->tableInfo('books');
        $this->assertSame('SET NULL', $books['foreign_keys'][0]['on_delete']);
        $this->assertSame(['id'], $books['indexes'][0]['columns']);
        $this->assertTrue(array_column($books['columns'], null, 'name')['id']['auto_increment']);
    }

    public function testRowEditingAndBrowse(): void
    {
        $d = self::$d;
        $r = $d->insertRow('authors', ['name' => 'Alice', 'tags' => '{a,b}']);
        $this->assertSame(1, $r['affected']);
        $this->assertNotNull($r['insert_id']);
        $aid = (int) $r['insert_id'];
        $d->insertRow('books', ['author_id' => $aid, 'title' => 'Zed', 'ip' => '10.1.2.3']);
        $d->insertRow('books', ['author_id' => $aid, 'title' => 'Alpha', 'active' => 'false']);

        $b = $d->browse('books', ['page' => 1, 'per_page' => 1, 'order' => [['column' => 'title', 'dir' => 'asc']]]);
        $this->assertSame(2, $b['total']);
        $this->assertCount(1, $b['rows']);
        $this->assertSame('Alpha', $b['rows'][0][2]);
        $this->assertSame('false', $b['rows'][0][5]);
        $this->assertTrue($b['editable']);

        $this->assertSame(1, $d->browse('books', ['search' => 'alp'])['total']);
        $this->assertSame(1, $d->browse('books', ['search' => '10.1.2'])['total']);
        $this->assertSame(1, $d->browse('books', ['filters' => [['column' => 'active', 'op' => '=', 'value' => 'true']]])['total']);

        $id = $b['rows'][0][0];
        $this->assertSame(1, $d->updateRow('books', ['id' => $id], ['title' => 'Alpha 2']));
        $this->assertSame(1, $d->deleteRows('books', [['id' => $id]]));

        $fk = $d->fkValues('books', 'author_id', 'ali');
        $this->assertSame('Alice', $fk[0]['label']);
    }

    public function testQueryAndExplain(): void
    {
        $d = self::$d;
        $q = $d->query('SELECT generate_series(1, 5) AS n', 3);
        $this->assertTrue($q['is_result_set']);
        $this->assertCount(3, $q['rows']);
        $this->assertTrue($q['truncated']);
        $this->assertArrayNotHasKey('more_results', $q);
        $e = $d->explain('SELECT * FROM books');
        $this->assertTrue($e['is_result_set']);
    }

    public function testCreateStatementReplays(): void
    {
        $d = self::$d;
        $ddl = $d->createStatement('authors');
        $this->assertStringContainsString('GENERATED BY DEFAULT AS IDENTITY', $ddl);
        $this->assertStringContainsString('COMMENT ON COLUMN', $ddl);
        $d->pdo()->exec('CREATE SCHEMA replay');
        $sql = str_replace(['"public".', ' public.', 'authors_name_uq'], ['"replay".', ' replay.', 'authors_name_uq2'], $ddl);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            $d->pdo()->exec($stmt);
        }
        $d->select(self::$db, 'replay');
        $this->assertSame('All authors', $d->tableInfo('authors')['options']['comment']);
        $d->select(self::$db, 'public');
    }

    public function testAlterTable(): void
    {
        $d = self::$d;
        $sqls = $d->buildAlterTable('books', [
            'add' => [['name' => 'pages', 'type' => 'integer', 'nullable' => false, 'default_kind' => 'value', 'default' => '0']],
            'modify' => [['column' => 'title', 'definition' => ['name' => 'book_title', 'type' => 'character varying',
                          'length' => '200', 'nullable' => true, 'default_kind' => 'none', 'comment' => 'Title']]],
            'add_index' => [['name' => 'books_pages_idx', 'columns' => ['pages'], 'unique' => false]],
            'options' => ['comment' => 'Books'],
        ]);
        foreach ($sqls as $s) {
            $d->pdo()->exec($s);
        }
        $info = $d->tableInfo('books');
        $cols = array_column($info['columns'], null, 'name');
        $this->assertArrayHasKey('book_title', $cols);
        $this->assertTrue($cols['book_title']['nullable']);
        $this->assertSame('200', $cols['book_title']['length']);
        $this->assertSame('Title', $cols['book_title']['comment']);
        $this->assertContains('books_pages_idx', array_column($info['indexes'], 'name'));
        $this->assertSame('Books', $info['options']['comment']);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(ApiException::class);
        self::$d->buildAlterTable('books', ['add' => [['name' => 'x', 'type' => 'int; DROP TABLE books', 'default_kind' => 'none']]]);
    }

    public function testCopyRenameTruncateDrop(): void
    {
        $d = self::$d;
        $d->insertRow('authors', ['name' => 'Copy me']);
        $d->copyTable('authors', 'authors_copy', true);
        $this->assertGreaterThanOrEqual(1, (int) $d->pdo()->query('SELECT count(*) FROM authors_copy')->fetchColumn());
        $d->renameTable('authors_copy', 'authors_copy2');
        $d->truncateTable('authors_copy2');
        $this->assertSame('1', $d->insertRow('authors_copy2', ['name' => 'x'])['insert_id']);
        $m = $d->maintenance(['authors_copy2'], 'vacuum');
        $this->assertSame('OK', $m['rows'][0][3]);
        $d->dropTable('authors_copy2');
        $this->assertNotContains('authors_copy2', array_column($d->listTables(), 'name'));
    }

    public function testServerInformation(): void
    {
        $d = self::$d;
        $this->assertSame('PostgreSQL', $d->serverInfo()['name']);
        $this->assertNotEmpty($d->variables('work_mem'));
        $this->assertNotEmpty($d->status());
        $this->assertSame('id', $d->processes()['columns'][0]);
        $this->assertNotEmpty($d->collations());
        $this->assertArrayHasKey('JSON', $d->dataTypes());
        $this->assertArrayHasKey('keywords', $d->completionWords());
    }

    public function testUsers(): void
    {
        $d = self::$d;
        $role = 'ldb_it_' . bin2hex(random_bytes(3));
        $d->saveUser($role, null, 'secret', ['GRANT SELECT ON public.authors TO ' . $d->quoteIdentifier($role)], true);
        try {
            $this->assertContains($role, array_column($d->users(), 'user'));
            $grants = implode("\n", $d->userGrants($role, null));
            $this->assertStringContainsString('LOGIN', $grants);
            $this->assertStringContainsString('GRANT SELECT ON TABLE public.authors', $grants);
        } finally {
            $d->pdo()->exec('REVOKE ALL ON public.authors FROM ' . $d->quoteIdentifier($role));
            $d->dropUser($role, null);
        }
        $this->assertNotContains($role, array_column($d->users(), 'user'));
    }
}
