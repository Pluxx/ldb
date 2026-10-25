<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\SqlSrvDriver;
use Ldb\Export\SqlExporter;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real SQL Server (2017+). Skipped unless these env vars are set (and pdo_sqlsrv is loaded):
 *   LDB_TEST_SQLSRV_DSN   e.g. "sqlsrv:Server=127.0.0.1,1433;TrustServerCertificate=yes"
 *   LDB_TEST_SQLSRV_USER  (needs CREATE DATABASE, e.g. sa)
 *   LDB_TEST_SQLSRV_PASS
 * A throwaway database "ldb_it_<random>" is created and dropped.
 */
final class SqlSrvDriverTest extends TestCase
{
    private static ?SqlSrvDriver $d = null;
    private static string $db = '';
    private static array $params = [];

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('LDB_TEST_SQLSRV_DSN');
        if (!$dsn || !(extension_loaded('pdo_sqlsrv') || extension_loaded('pdo_dblib'))) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^(sqlsrv|dblib):/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $server = $parts['server'] ?? ($parts['host'] ?? '127.0.0.1,1433');
        $server = str_replace(':', ',', $server);
        $host = $server;
        $port = 1433;
        if (preg_match('/^(.*?)[,:](\d+)$/', $server, $m)) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }
        self::$params = [
            'driver' => 'sqlsrv',
            'host' => $host,
            'port' => $port,
            'user' => (string) getenv('LDB_TEST_SQLSRV_USER'),
            'password' => (string) getenv('LDB_TEST_SQLSRV_PASS'),
            'database' => null,
            // a "dblib:" DSN exercises the FreeTDS fallback instead of pdo_sqlsrv
            'options' => ['trust_server_certificate' => true] + (strpos($dsn, 'dblib:') === 0 ? ['pdo_driver' => 'dblib'] : []),
        ];
        $d = new SqlSrvDriver();
        $d->connect(self::$params);
        self::$db = 'ldb_it_' . bin2hex(random_bytes(4));
        $d->createDatabase(self::$db, 'Latin1_General_100_CI_AS_SC_UTF8');
        $d->select(self::$db, 'dbo');
        foreach ([self::authorsDefinition(), self::booksDefinition()] as $def) {
            foreach ($d->buildCreateTable($def) as $sql) {
                $d->pdo()->exec($sql);
            }
        }
        self::$d = $d;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d !== null) {
            $d = new SqlSrvDriver();
            $d->connect(self::$params);
            self::$d = null; // close the connection to the test database
            gc_collect_cycles();
            $d->pdo()->exec('ALTER DATABASE [' . self::$db . '] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            $d->dropDatabase(self::$db);
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set LDB_TEST_SQLSRV_DSN/USER/PASS (and load pdo_sqlsrv) to run SQL Server integration tests');
        }
    }

    /** serverInfo() carries the support policy (Ldb\Db\ServerVersion) consistent with serverVersion(). */
    public function testServerVersionSupport(): void
    {
        $d = self::$d;
        $info = $d->serverInfo();
        $flavor = 'sqlsrv';
        $this->assertSame($flavor, $info['version_flavor']);
        $this->assertSame(\Ldb\Db\ServerVersion::minimum($flavor), $info['min_version']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)*$/', $d->serverVersion());
        $this->assertSame(\Ldb\Db\ServerVersion::isSupported($flavor, $d->serverVersion()), $info['version_supported']);
    }

    private static function authorsDefinition(): array
    {
        return [
            'name' => 'authors',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'auto_increment' => true, 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'name', 'type' => 'nvarchar', 'length' => '100', 'nullable' => false,
                 'default_kind' => 'value', 'default' => "O'Brien", 'comment' => 'Author name'],
                ['name' => 'bio', 'type' => 'nvarchar', 'length' => 'max', 'nullable' => true, 'default_kind' => 'none'],
                ['name' => 'born', 'type' => 'date', 'nullable' => true, 'default_kind' => 'none'],
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
                ['name' => 'id', 'type' => 'bigint', 'auto_increment' => true, 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'author_id', 'type' => 'int', 'nullable' => true, 'default_kind' => 'none'],
                ['name' => 'title', 'type' => 'nvarchar', 'length' => '200', 'nullable' => false, 'default_kind' => 'none'],
                ['name' => 'price', 'type' => 'decimal', 'length' => '10,2', 'nullable' => true, 'default_kind' => 'value', 'default' => '9.99'],
                ['name' => 'active', 'type' => 'bit', 'nullable' => false, 'default_kind' => 'value', 'default' => '1'],
                ['name' => 'created', 'type' => 'datetime2', 'length' => '0', 'nullable' => false, 'default_kind' => 'expression', 'default' => 'SYSDATETIME()'],
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
        $this->assertSame('dbo', $d->currentSchema());
        $this->assertContains('dbo', $d->listSchemas());
        $this->assertContains(self::$db, array_column($d->listDatabases(), 'name'));
        $this->assertContains('authors', array_column($d->listTables(), 'name'));
    }

    public function testTableInfo(): void
    {
        $info = self::$d->tableInfo('authors');
        $cols = array_column($info['columns'], null, 'name');
        $this->assertTrue($cols['id']['auto_increment']);
        $this->assertTrue($cols['id']['primary']);
        $this->assertSame('nvarchar', $cols['name']['type']);
        $this->assertSame('100', $cols['name']['length']);
        $this->assertSame('value', $cols['name']['default_kind']);
        $this->assertSame("O'Brien", $cols['name']['default']);
        $this->assertSame('Author name', $cols['name']['comment']);
        $this->assertSame('max', $cols['bio']['length']);
        $this->assertSame('All authors', $info['options']['comment']);
        $this->assertSame('books', $info['referenced_by'][0]['table']);

        $books = self::$d->tableInfo('books');
        $this->assertSame('SET NULL', $books['foreign_keys'][0]['on_delete']);
        $this->assertSame(['id'], $books['indexes'][0]['columns']);
        $bc = array_column($books['columns'], null, 'name');
        $this->assertSame('bigint', $bc['id']['type']);
        $this->assertTrue($bc['id']['auto_increment']);
        $this->assertSame('10,2', $bc['price']['length']);
    }

    public function testRowEditingAndBrowse(): void
    {
        $d = self::$d;
        $r = $d->insertRow('authors', ['name' => '山田 太郎', 'bio' => 'こんにちは、世界 🎉']);
        $this->assertSame(1, $r['affected']);
        $this->assertNotNull($r['insert_id']);
        $aid = (int) $r['insert_id'];
        $d->insertRow('authors', ['name' => 'محمد الأحمد', 'bio' => 'مرحبا بالعالم']);
        $d->insertRow('books', ['author_id' => $aid, 'title' => 'Zed', 'price' => '5.50']);
        $d->insertRow('books', ['author_id' => $aid, 'title' => '日本語の本', 'active' => '0']);
        $d->insertRow('books', ['author_id' => $aid, 'title' => 'Alpha']);

        $b = $d->browse('books', ['page' => 1, 'per_page' => 2, 'order' => [['column' => 'title', 'dir' => 'asc']]]);
        $this->assertSame(3, $b['total']);
        $this->assertCount(2, $b['rows']);
        $this->assertSame('Alpha', $b['rows'][0][2]);
        $this->assertTrue($b['editable']);
        $p2 = $d->browse('books', ['page' => 2, 'per_page' => 2, 'order' => [['column' => 'title', 'dir' => 'asc']]]);
        $this->assertCount(1, $p2['rows']);
        $this->assertSame('日本語の本', $p2['rows'][0][2]);

        // default order (no ORDER BY given): must not fail for OFFSET/FETCH
        $this->assertCount(3, $d->browse('books', ['page' => 1, 'per_page' => 10])['rows']);

        $this->assertSame(1, $d->browse('books', ['search' => 'alp'])['total']);
        $this->assertSame(1, $d->browse('books', ['search' => '日本'])['total']);
        $this->assertSame(1, $d->browse('authors', ['search' => 'الأحمد'])['total']);
        $this->assertSame(1, $d->browse('books', ['filters' => [['column' => 'active', 'op' => '=', 'value' => '0']]])['total']);
        $this->assertSame(2, $d->browse('books', ['filters' => [['column' => 'price', 'op' => '>', 'value' => '6']]])['total']);
        $this->assertSame(1, $d->browse('books', ['filters' => [['column' => 'title', 'op' => 'LIKE', 'value' => '%ed']]])['total']);

        $id = $b['rows'][0][0];
        $this->assertSame(1, $d->updateRow('books', ['id' => $id], ['title' => 'Alpha 2 ✓']));
        $row = $d->browse('books', ['filters' => [['column' => 'id', 'op' => '=', 'value' => (string) $id]]]);
        $this->assertSame('Alpha 2 ✓', $row['rows'][0][2]);
        $this->assertSame(1, $d->deleteRows('books', [['id' => $id]]));

        $fk = $d->fkValues('books', 'author_id', '山田');
        $this->assertSame('山田 太郎', $fk[0]['label']);
    }

    public function testQueryAndExplain(): void
    {
        $d = self::$d;
        $q = $d->query('SELECT n FROM (VALUES (1),(2),(3),(4),(5)) AS t(n)', 3);
        $this->assertTrue($q['is_result_set']);
        $this->assertCount(3, $q['rows']);
        $this->assertTrue($q['truncated']);

        $u = $d->query("UPDATE authors SET bio = N'x' WHERE 1 = 1", 100);
        $this->assertFalse($u['is_result_set']);
        $this->assertGreaterThanOrEqual(2, $u['affected']);

        $u = $d->query("SELECT N'日本語' AS j, CAST(1 AS bit) AS b, CAST(1.50 AS decimal(5,2)) AS d", 10);
        $this->assertSame('日本語', $u['rows'][0][0]);

        $e = $d->explain('SELECT * FROM books');
        $this->assertTrue($e['is_result_set']);
        $this->assertNotEmpty($e['rows']);
        // the session must be usable (SHOWPLAN off) afterwards
        $this->assertTrue($d->query('SELECT 1 AS one', 10)['is_result_set']);
    }

    public function testCreateStatementReplays(): void
    {
        $d = self::$d;
        $ddl = $d->createStatement('authors');
        $this->assertStringContainsString('IDENTITY(1,1)', $ddl);
        $this->assertStringContainsString('MS_Description', $ddl);
        $d->pdo()->exec('CREATE SCHEMA replay');
        $d->select(self::$db, 'replay');
        $sql = str_replace('[dbo]', '[replay]', $ddl);
        $sql = str_replace(['authors_name_uq', "N'dbo'"], ['authors_name_uq2', "N'replay'"], $sql);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            $d->pdo()->exec(rtrim($stmt, ';'));
        }
        $this->assertSame('All authors', $d->tableInfo('authors')['options']['comment']);
        $d->select(self::$db, 'dbo');
    }

    public function testAlterTable(): void
    {
        $d = self::$d;
        $sqls = $d->buildAlterTable('books', [
            'add' => [['name' => 'pages', 'type' => 'int', 'nullable' => false, 'default_kind' => 'value', 'default' => '0']],
            'modify' => [['column' => 'title', 'definition' => ['name' => 'book_title', 'type' => 'nvarchar',
                          'length' => '300', 'nullable' => true, 'default_kind' => 'none', 'comment' => 'Title']]],
            'add_index' => [['name' => 'books_pages_idx', 'columns' => ['pages'], 'unique' => false]],
            'options' => ['comment' => 'Books'],
        ]);
        foreach ($sqls as $s) {
            $d->pdo()->exec($s);
        }
        $info = $d->tableInfo('books');
        $cols = array_column($info['columns'], null, 'name');
        $this->assertArrayHasKey('book_title', $cols);
        $this->assertArrayNotHasKey('title', $cols);
        $this->assertTrue($cols['book_title']['nullable']);
        $this->assertSame('300', $cols['book_title']['length']);
        $this->assertSame('Title', $cols['book_title']['comment']);
        $this->assertSame('0', $cols['pages']['default']);
        $this->assertContains('books_pages_idx', array_column($info['indexes'], 'name'));
        $this->assertSame('Books', $info['options']['comment']);

        // drop column with default + index, drop and re-add FK
        foreach ($d->buildAlterTable('books', [
            'drop_index' => ['books_pages_idx'],
            'drop' => ['pages'],
            'drop_fk' => ['books_author_fk'],
        ]) as $s) {
            $d->pdo()->exec($s);
        }
        $info = $d->tableInfo('books');
        $this->assertArrayNotHasKey('pages', array_column($info['columns'], null, 'name'));
        $this->assertSame([], $info['foreign_keys']);
        foreach ($d->buildAlterTable('books', [
            'add_fk' => [['name' => 'books_author_fk2', 'columns' => ['author_id'], 'ref_table' => 'authors',
                          'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => null]],
        ]) as $s) {
            $d->pdo()->exec($s);
        }
        $this->assertSame('CASCADE', $d->tableInfo('books')['foreign_keys'][0]['on_delete']);
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
        $this->assertGreaterThanOrEqual(1, (int) $d->pdo()->query('SELECT COUNT(*) FROM authors_copy')->fetchColumn());
        $this->assertTrue(array_column($d->tableInfo('authors_copy')['columns'], null, 'name')['id']['primary']);
        $d->renameTable('authors_copy', 'authors_copy2');
        $this->assertContains('authors_copy2', array_column($d->listTables(), 'name'));
        $d->truncateTable('authors_copy2');
        $this->assertSame(0, (int) $d->pdo()->query('SELECT COUNT(*) FROM authors_copy2')->fetchColumn());
        $m = $d->maintenance(['authors_copy2'], 'analyze');
        $this->assertSame('OK', $m['rows'][0][3]);
        $d->dropTable('authors_copy2');
        $this->assertNotContains('authors_copy2', array_column($d->listTables(), 'name'));
    }

    public function testViewsRoutinesTriggers(): void
    {
        $d = self::$d;
        $d->pdo()->exec('CREATE VIEW v_books AS SELECT id, book_title FROM dbo.books');
        $d->pdo()->exec('CREATE FUNCTION dbo.fn_one() RETURNS int AS BEGIN RETURN 1; END');
        $d->pdo()->exec('CREATE TRIGGER dbo.trg_authors ON dbo.authors AFTER INSERT AS BEGIN SET NOCOUNT ON; END');
        $tables = array_column($d->listTables(), 'type', 'name');
        $this->assertSame('view', $tables['v_books']);
        $this->assertContains('fn_one', array_column($d->listRoutines(), 'name'));
        $this->assertContains('trg_authors', array_column($d->listTriggers(), 'name'));
        $this->assertStringContainsString('CREATE VIEW', $d->createStatement('v_books', 'view'));
        $d->pdo()->exec('DROP TRIGGER dbo.trg_authors');
    }

    public function testServerInformation(): void
    {
        $d = self::$d;
        $this->assertSame('SQL Server', $d->serverInfo()['name']);
        $this->assertSame('sqlsrv', $d->serverInfo()['driver']); // frontend profile key
        $this->assertNotEmpty($d->variables('version'));
        $this->assertNotEmpty($d->status());
        $this->assertSame('id', $d->processes()['columns'][0]);
        $this->assertNotEmpty($d->processes()['rows']);
        $this->assertNotEmpty($d->collations());
        $this->assertNotEmpty($d->dataTypes());
        $this->assertArrayHasKey('keywords', $d->completionWords());
    }

    public function testUsers(): void
    {
        $d = self::$d;
        $login = 'ldb_it_' . bin2hex(random_bytes(3));
        $d->saveUser($login, null, 'Secr3t!Passw0rd', [], true);
        try {
            $this->assertContains($login, array_column($d->users(), 'user'));
            $this->assertIsArray($d->userGrants($login, null));
        } finally {
            $d->dropUser($login, null);
        }
        $this->assertNotContains($login, array_column($d->users(), 'user'));
    }

    public function testSqlExportOrdersByForeignKeysAndUsesNationalLiterals(): void
    {
        $d = self::$d;
        $d->insertRow('authors', ['name' => 'Export 山田']);
        $out = fopen('php://temp', 'w+');
        (new SqlExporter($d, ['structure' => true, 'data' => true]))->export(['books', 'authors'], $out);
        rewind($out);
        $sql = (string) stream_get_contents($out);
        $this->assertLessThan(strpos($sql, 'CREATE TABLE [dbo].[books]'), strpos($sql, 'CREATE TABLE [dbo].[authors]'));
        $this->assertStringContainsString("N'Export 山田'", $sql);
        $this->assertStringNotContainsString('GO;', $sql);
    }

    public function testBatchWithSeveralResultSetsAndLaterErrors(): void
    {
        $d = self::$d;
        $r = $d->query('SELECT 1 AS a; SELECT 2 AS b, 3 AS c', 10);
        $this->assertTrue($r['is_result_set']);
        $this->assertCount(1, $r['more_results']);
        $this->assertSame('b', $r['more_results'][0]['columns'][0]['name']);

        // row-count sets between SELECTs are not reported
        $r = $d->query('SET NOCOUNT OFF; CREATE TABLE #t (x int); INSERT INTO #t VALUES (1),(2); SELECT x FROM #t; SELECT 9 AS z', 10);
        $this->assertSame('x', $r['columns'][0]['name']);
        $this->assertCount(1, $r['more_results']);
        $this->assertTrue($r['more_results'][0]['is_result_set']);

        // row counts only: summed
        $r = $d->query('CREATE TABLE #u (x int); INSERT INTO #u VALUES (1),(2); INSERT INTO #u VALUES (3)', 10);
        $this->assertFalse($r['is_result_set']);
        $this->assertSame(3, $r['affected']);

        // error in a later statement must surface
        $this->expectException(\PDOException::class);
        try {
            $d->query('SELECT 1 AS a; SELECT 1/0 AS b', 10);
        } finally {
            $this->assertSame(1, (int) $d->query('SELECT 1 AS one', 10)['rows'][0][0]);
        }
    }
}
