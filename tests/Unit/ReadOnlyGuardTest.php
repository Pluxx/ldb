<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Http\ApiException;
use Ldb\Sql\ReadOnlyGuard;
use PHPUnit\Framework\TestCase;

final class ReadOnlyGuardTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public function allowedProvider(): array
    {
        return [
            'select' => ['mysql', 'SELECT 1'],
            'select lower' => ['pgsql', "select * from users where id = 1"],
            'trailing semicolon' => ['mysql', 'SELECT 1;'],
            'two selects' => ['mysql', 'SELECT 1; SELECT 2;'],
            'leading comments' => ['mysql', "-- note\n/* block */ # hash\nSELECT 1"],
            'parenthesised union' => ['mysql', '(SELECT 1) UNION (SELECT 2)'],
            'keyword in string' => ['mysql', "SELECT 'DROP TABLE x'"],
            'delete in string' => ['pgsql', "SELECT 'DELETE'"],
            'keyword in double-quoted identifier' => ['pgsql', 'select * from "delete"'],
            'keyword in backticks' => ['mysql', 'SELECT `update`, `set` FROM `insert`'],
            'keyword in brackets' => ['sqlsrv', 'SELECT [delete] FROM [update]'],
            'keyword in comment' => ['mysql', "SELECT 1 -- DELETE FROM t\n"],
            'keyword in block comment' => ['pgsql', 'SELECT /* drop table x */ 1'],
            'qualified keyword column' => ['mysql', 'SELECT t.update FROM t'],
            'backslash in mysql string' => ['mysql', "SELECT 'C:\\\\temp', 'a\\'b'"],
            'doubled quote' => ['sqlite', "SELECT 'it''s; DROP TABLE x'"],
            'dollar quoted' => ['pgsql', 'SELECT $$ DELETE FROM t $$, $tag$ drop $tag$'],
            'e string' => ['pgsql', "SELECT E'a\\'; DELETE'"],
            'with cte select' => ['pgsql', 'WITH x AS (SELECT 1 AS a) SELECT * FROM x'],
            'with recursive' => ['mysql', 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c WHERE n<5) SELECT * FROM c'],
            'show tables' => ['mysql', 'SHOW TABLES'],
            'show create table' => ['mysql', 'SHOW CREATE TABLE t'],
            'show character set' => ['mysql', 'SHOW CHARACTER SET'],
            'show pg' => ['pgsql', 'SHOW search_path'],
            'explain' => ['mysql', 'EXPLAIN SELECT * FROM t'],
            'explain format' => ['mysql', 'EXPLAIN FORMAT=JSON SELECT * FROM t'],
            'explain query plan sqlite' => ['sqlite', 'EXPLAIN QUERY PLAN SELECT 1'],
            'describe' => ['mysql', 'DESCRIBE t'],
            'desc' => ['mysql', 'DESC t'],
            'values' => ['pgsql', 'VALUES (1, 2), (3, 4)'],
            'table' => ['pgsql', 'TABLE t'],
            'use mysql' => ['mysql', 'USE ldb_demo'],
            'replace function' => ['mysql', "SELECT REPLACE(name, 'a', 'b') FROM t"],
            'insert function' => ['mysql', "SELECT INSERT('abc', 1, 1, 'x')"],
            'truncate function' => ['mysql', 'SELECT TRUNCATE(1.234, 1)'],
            'cast charset' => ['mysql', 'SELECT CAST(x AS CHAR CHARACTER SET utf8mb4) FROM t'],
            'substring for' => ['pgsql', 'SELECT substring(name FROM 1 FOR 3) FROM t'],
            'for json sqlsrv' => ['sqlsrv', 'SELECT * FROM t FOR JSON AUTO'],
            'mysql dash without space is minus' => ['mysql', 'SELECT 1--1'],
            'empty / comment only' => ['mysql', '-- nothing'],
            'unicode identifiers' => ['pgsql', 'SELECT größe FROM tabelle'],
            'pg param' => ['pgsql', 'SELECT $1'],
        ];
    }

    /** @dataProvider allowedProvider */
    public function testAllowed(string $dialect, string $sql): void
    {
        $guard = new ReadOnlyGuard($dialect);
        $this->assertNull($guard->violation($sql), $sql);
        $this->assertTrue($guard->isAllowed($sql));
    }

    /** @return array<string,array{0:string,1:string}> */
    public function blockedProvider(): array
    {
        return [
            'insert' => ['mysql', 'INSERT INTO t VALUES (1)'],
            'update' => ['pgsql', 'UPDATE t SET a = 1'],
            'delete' => ['sqlite', 'DELETE FROM t'],
            'delete lowercase' => ['mysql', 'delete from t'],
            'replace into' => ['mysql', 'REPLACE INTO t VALUES (1)'],
            'merge' => ['sqlsrv', 'MERGE INTO t USING s ON 1=1 WHEN MATCHED THEN DELETE;'],
            'create' => ['mysql', 'CREATE TABLE x (a int)'],
            'alter' => ['pgsql', 'ALTER TABLE t ADD c int'],
            'drop' => ['mysql', 'DROP TABLE t'],
            'truncate' => ['pgsql', 'TRUNCATE t'],
            'grant' => ['mysql', 'GRANT ALL ON *.* TO x'],
            'revoke' => ['pgsql', 'REVOKE ALL ON t FROM x'],
            'call' => ['mysql', 'CALL p()'],
            'exec' => ['sqlsrv', "EXEC sp_who"],
            'exec dynamic after select' => ['sqlsrv', "SELECT 1 EXEC('delete from t')"],
            'sqlsrv batch without semicolon' => ['sqlsrv', 'SELECT 1 DELETE FROM t'],
            'do' => ['pgsql', "DO \$\$ BEGIN DELETE FROM t; END \$\$"],
            'lock' => ['pgsql', 'LOCK TABLE t'],
            'copy' => ['pgsql', "COPY t TO '/tmp/x'"],
            'load data' => ['mysql', "LOAD DATA INFILE '/etc/passwd' INTO TABLE t"],
            'handler' => ['mysql', 'HANDLER t OPEN'],
            'set session rw' => ['mysql', 'SET SESSION TRANSACTION READ WRITE'],
            'set pg' => ['pgsql', 'SET default_transaction_read_only = off'],
            'set characteristics' => ['pgsql', 'SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE'],
            'start transaction' => ['mysql', 'START TRANSACTION READ WRITE'],
            'begin' => ['pgsql', 'BEGIN READ WRITE'],
            'pragma' => ['sqlite', 'PRAGMA query_only = OFF'],
            'pragma readonly kind' => ['sqlite', 'PRAGMA table_info(t)'],
            'attach' => ['sqlite', "ATTACH DATABASE '/tmp/x' AS y"],
            'vacuum' => ['sqlite', 'VACUUM'],
            'rename' => ['mysql', 'RENAME TABLE a TO b'],
            'prepare' => ['pgsql', 'PREPARE p AS DELETE FROM t'],
            'notify' => ['pgsql', "NOTIFY ch, 'x'"],
            'refresh' => ['pgsql', 'REFRESH MATERIALIZED VIEW v'],
            'kill' => ['mysql', 'KILL 12'],
            'select into outfile' => ['mysql', "SELECT * FROM t INTO OUTFILE '/tmp/x'"],
            'select into dumpfile' => ['mysql', "SELECT 'x' INTO DUMPFILE '/tmp/x'"],
            'select into var' => ['mysql', 'SELECT 1 INTO @a'],
            'pg select into table' => ['pgsql', 'SELECT * INTO new_t FROM t'],
            'for update' => ['mysql', 'SELECT * FROM t FOR UPDATE'],
            'for share' => ['pgsql', 'SELECT * FROM t FOR SHARE'],
            'for no key update' => ['pgsql', 'SELECT * FROM t FOR NO KEY UPDATE'],
            'for key share' => ['pgsql', 'SELECT * FROM t FOR KEY SHARE'],
            'lock in share mode' => ['mysql', 'SELECT * FROM t LOCK IN SHARE MODE'],
            'cte delete' => ['pgsql', 'WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x'],
            'cte insert' => ['pgsql', 'WITH x AS (INSERT INTO t VALUES (1) RETURNING id) SELECT * FROM x'],
            'cte then update' => ['mysql', 'WITH x AS (SELECT 1) UPDATE t SET a = 1'],
            'second statement' => ['mysql', 'SELECT 1; DELETE FROM t'],
            'second statement no space' => ['pgsql', 'SELECT 1;DROP TABLE t'],
            'explain analyze' => ['pgsql', 'EXPLAIN ANALYZE SELECT 1'],
            'explain analyse' => ['pgsql', 'EXPLAIN ANALYSE SELECT 1'],
            'explain options analyze' => ['pgsql', 'EXPLAIN (ANALYZE, BUFFERS) SELECT 1'],
            'explain delete' => ['mysql', 'EXPLAIN DELETE FROM t'],
            'nextval' => ['pgsql', "SELECT nextval('s')"],
            'setval' => ['pgsql', "SELECT setval('s', 1)"],
            'set_config qualified' => ['pgsql', "SELECT pg_catalog.set_config('default_transaction_read_only', 'off', false)"],
            'set_config quoted' => ['pgsql', "SELECT \"set_config\"('default_transaction_read_only', 'off', false)"],
            'pg_terminate_backend' => ['pgsql', 'SELECT pg_terminate_backend(123)'],
            'lo_import' => ['pgsql', "SELECT lo_import('/etc/passwd')"],
            'dblink_exec' => ['pgsql', "SELECT dblink_exec('dbname=x', 'DELETE FROM t')"],
            'query_to_xml' => ['pgsql', "SELECT query_to_xml('delete from t returning 1', true, false, '')"],
            'get_lock' => ['mysql', "SELECT GET_LOCK('x', 10)"],
            'load_file' => ['mysql', "SELECT LOAD_FILE('/etc/passwd')"],
            'sleep' => ['mysql', 'SELECT SLEEP(10)'],
            'openrowset' => ['sqlsrv', "SELECT * FROM OPENROWSET('SQLNCLI', 'x', 'y')"],
            'updlock hint' => ['sqlsrv', 'SELECT * FROM t WITH (UPDLOCK)'],
            'use on pgsql' => ['pgsql', 'USE x'],
            'use with trailing' => ['mysql', 'USE x DELETE'],
            'unknown statement' => ['mysql', 'OPTIMIZE TABLE t'],
            'starts with number' => ['mysql', '1; DELETE FROM t'],
            'analyze' => ['mysql', 'ANALYZE TABLE t'],
            // ---- lexer ambiguity tricks
            'backslash hides delete (pg standard strings)' => ['pgsql', "SELECT 'a\\'; DELETE FROM t; --'"],
            'backslash hides delete (mysql NO_BACKSLASH_ESCAPES)' => ['mysql', "SELECT 'a\\'; DELETE FROM t; -- '"],
            'dollar quote opening a fake string' => ['pgsql', "SELECT \$\$'\$\$; DELETE FROM t; SELECT '\$\$'"],
            'mysql dash comment hides quote' => ['mysql', "SELECT 1 -- '\nDELETE FROM t -- '"],
            'mysql executable comment' => ['mysql', 'SELECT 1 /*! ; DELETE FROM t */'],
            'mysql versioned executable comment' => ['mysql', 'SELECT 1 /*!50000 , (DELETE) */'],
            'mariadb executable comment' => ['mysql', 'SELECT 1 /*M! ; DROP TABLE t */'],
            'version-gated comment hides quote' => ['mysql', "SELECT 1 /*!99999 ' */ ; DELETE FROM t; -- '"],
            'pg nested comment ambiguity' => ['pgsql', "SELECT 1 /* /* */ ; DELETE FROM t; -- */"],
            'hash comment' => ['mysql', "SELECT 1 # comment\n; DROP TABLE t"],
            'parenthesised delete' => ['pgsql', '(DELETE FROM t)'],
            'only parens' => ['mysql', '(('],
        ];
    }

    /** @dataProvider blockedProvider */
    public function testBlocked(string $dialect, string $sql): void
    {
        $this->assertNotNull((new ReadOnlyGuard($dialect))->violation($sql), $sql);
    }

    public function testAssertThrowsReadOnlyCode(): void
    {
        try {
            (new ReadOnlyGuard('mysql'))->assertReadOnly('DELETE FROM t');
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame('read_only', $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatus());
            $this->assertStringContainsString('DELETE', $e->getMessage());
        }
        (new ReadOnlyGuard('mysql'))->assertReadOnly('SELECT 1');
        $this->addToAssertionCount(1);
    }

    public function testUnknownDialectIsConservative(): void
    {
        $g = new ReadOnlyGuard('other');
        $this->assertTrue($g->isAllowed('SELECT 1'));
        $this->assertFalse($g->isAllowed("SELECT 'a\\'; DELETE FROM t; --'"));
        $this->assertFalse($g->isAllowed('SELECT $$ x $$; UPDATE t SET a=1'));
    }
}
