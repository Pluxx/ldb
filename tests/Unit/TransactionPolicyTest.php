<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Http\ApiException;
use Ldb\Sql\TransactionPolicy;
use PHPUnit\Framework\TestCase;

final class TransactionPolicyTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string}> dialect, sql, expected kind */
    public function kindProvider(): array
    {
        $ok = TransactionPolicy::OK;
        $tx = TransactionPolicy::TX_CONTROL;
        $no = TransactionPolicy::NOT_IN_TX;
        $ic = TransactionPolicy::IMPLICIT_COMMIT;
        $mc = TransactionPolicy::MAY_COMMIT;
        return [
            // DML everywhere
            'mysql insert' => ['mysql', 'INSERT INTO t VALUES (1)', $ok],
            'mysql update' => ['mysql', 'update t set a = 1 where id = 2', $ok],
            'pg select' => ['pgsql', 'SELECT * FROM t', $ok],
            'pg with' => ['pgsql', 'WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x', $ok],
            'sqlite delete' => ['sqlite', 'DELETE FROM t', $ok],
            'sqlsrv insert' => ['sqlsrv', 'INSERT INTO t VALUES (1)', $ok],
            // transaction control
            'mysql begin' => ['mysql', 'BEGIN', $tx],
            'mysql start transaction' => ['mysql', 'start transaction read only', $tx],
            'mysql commit' => ['mysql', 'COMMIT', $tx],
            'mysql rollback to' => ['mysql', 'ROLLBACK TO SAVEPOINT a', $tx],
            'mysql savepoint' => ['mysql', 'SAVEPOINT a', $tx],
            'mysql release' => ['mysql', 'RELEASE SAVEPOINT a', $tx],
            'mysql xa' => ['mysql', "XA START 'x'", $tx],
            'mysql set autocommit' => ['mysql', 'SET autocommit = 1', $tx],
            'mysql set @@autocommit' => ['mysql', 'SET @@session.autocommit = 0', $tx],
            'mysql exec comment commit' => ['mysql', '/*!40000 COMMIT */', $tx],
            'pg begin' => ['pgsql', 'begin;', $tx],
            'pg end' => ['pgsql', 'END', $tx],
            'pg abort' => ['pgsql', 'ABORT', $tx],
            'pg prepare transaction' => ['pgsql', "PREPARE TRANSACTION 'x'", $tx],
            'sqlite begin immediate' => ['sqlite', 'BEGIN IMMEDIATE', $tx],
            'sqlite end' => ['sqlite', 'END TRANSACTION', $tx],
            'sqlsrv begin tran' => ['sqlsrv', 'BEGIN TRAN', $tx],
            'sqlsrv commit inside batch' => ['sqlsrv', "INSERT INTO t VALUES (1)\nCOMMIT", $tx],
            'sqlsrv save tran' => ['sqlsrv', 'SAVE TRANSACTION s1', $tx],
            'sqlsrv implicit transactions' => ['sqlsrv', 'SET IMPLICIT_TRANSACTIONS ON', $tx],
            // not inside a transaction
            'pg vacuum' => ['pgsql', 'VACUUM ANALYZE t', $no],
            'pg create database' => ['pgsql', 'CREATE DATABASE x', $no],
            'pg drop database' => ['pgsql', 'DROP DATABASE x', $no],
            'pg create index concurrently' => ['pgsql', 'CREATE UNIQUE INDEX CONCURRENTLY i ON t (a)', $no],
            'pg drop index concurrently' => ['pgsql', 'DROP INDEX CONCURRENTLY i', $no],
            'pg reindex concurrently' => ['pgsql', 'REINDEX TABLE CONCURRENTLY t', $no],
            'pg reindex database' => ['pgsql', 'REINDEX DATABASE d', $no],
            'pg alter system' => ['pgsql', "ALTER SYSTEM SET work_mem = '64MB'", $no],
            'sqlite vacuum' => ['sqlite', 'VACUUM', $no],
            'sqlsrv create database' => ['sqlsrv', 'CREATE DATABASE x', $no],
            'sqlsrv alter database' => ['sqlsrv', 'ALTER DATABASE x SET RECOVERY SIMPLE', $no],
            'sqlsrv backup' => ['sqlsrv', "BACKUP DATABASE x TO DISK = 'x.bak'", $no],
            // transactional DDL is fine
            'pg create table' => ['pgsql', 'CREATE TABLE t (id int)', $ok],
            'pg create index' => ['pgsql', 'CREATE INDEX i ON t (a)', $ok],
            'pg alter table' => ['pgsql', 'ALTER TABLE t ADD COLUMN b int', $ok],
            'pg truncate' => ['pgsql', 'TRUNCATE t', $ok],
            'pg refresh concurrently' => ['pgsql', 'REFRESH MATERIALIZED VIEW CONCURRENTLY v', $ok],
            'pg reindex table' => ['pgsql', 'REINDEX TABLE t', $ok],
            'sqlite create table' => ['sqlite', 'CREATE TABLE t (id INTEGER)', $ok],
            'sqlite drop table' => ['sqlite', 'DROP TABLE t', $ok],
            'sqlsrv create table' => ['sqlsrv', 'CREATE TABLE t (id int)', $ok],
            'sqlsrv procedure body' => ['sqlsrv', "CREATE PROCEDURE p AS BEGIN BEGIN TRAN; COMMIT; END", $ok],
            // MySQL implicit commits
            'mysql create table' => ['mysql', 'CREATE TABLE t (id int)', $ic],
            'mysql alter table' => ['mysql', 'ALTER TABLE t ADD c INT', $ic],
            'mysql drop table' => ['mysql', 'DROP TABLE t', $ic],
            'mysql truncate' => ['mysql', 'TRUNCATE TABLE t', $ic],
            'mysql rename' => ['mysql', 'RENAME TABLE a TO b', $ic],
            'mysql grant' => ['mysql', "GRANT SELECT ON *.* TO 'u'@'%'", $ic],
            'mysql lock tables' => ['mysql', 'LOCK TABLES t WRITE', $ic],
            'mysql unlock tables' => ['mysql', 'UNLOCK TABLES', $ic],
            'mysql optimize' => ['mysql', 'OPTIMIZE TABLE t', $ic],
            'mysql set password' => ['mysql', "SET PASSWORD = 'x'", $ic],
            'mysql start replica' => ['mysql', 'START REPLICA', $ic],
            'mysql load index' => ['mysql', 'LOAD INDEX INTO CACHE t', $ic],
            'mysql exec comment ddl' => ['mysql', '/*!50000 CREATE TABLE t (a int) */', $ic],
            'mysql parenthesised' => ['mysql', '(SELECT 1)', $ok],
            'mysql create temporary' => ['mysql', 'CREATE TEMPORARY TABLE t (a int)', $ok],
            'mariadb create or replace temporary' => ['mysql', 'CREATE OR REPLACE TEMPORARY TABLE t (a int)', $ok],
            'mysql drop temporary' => ['mysql', 'DROP TEMPORARY TABLE IF EXISTS t', $ok],
            'mysql load data' => ['mysql', "LOAD DATA INFILE 'x' INTO TABLE t", $ok],
            'mysql set var' => ['mysql', 'SET @a = 1', $ok],
            'mysql set names' => ['mysql', 'SET NAMES utf8mb4', $ok],
            // procedures
            'mysql call' => ['mysql', 'CALL p()', $mc],
            'sqlsrv exec' => ['sqlsrv', 'EXEC dbo.p 1', $mc],
            // literals, comments and identifiers do not count
            'keyword in string' => ['mysql', "INSERT INTO t VALUES ('COMMIT')", $ok],
            'keyword in comment' => ['mysql', "-- COMMIT\nSELECT 1", $ok],
            'keyword in hash comment' => ['mysql', "# DROP TABLE x\nSELECT 1", $ok],
            'keyword in backticks' => ['mysql', 'SELECT `commit` FROM t', $ok],
            'pg dollar quote' => ['pgsql', 'SELECT $$VACUUM$$', $ok],
            'sqlsrv bracket' => ['sqlsrv', 'SELECT [commit] FROM t', $ok],
            'sqlsrv qualified' => ['sqlsrv', 'SELECT t.rollback FROM t', $ok],
            // routine definitions are classified by their head (the body does not run now)
            'mysql procedure body' => ['mysql', 'CREATE PROCEDURE p() BEGIN START TRANSACTION; UPDATE t SET a = 1; COMMIT; END', $ic],
            'mysql trigger body' => ['mysql', 'CREATE DEFINER=`r`@`%` TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; END', $ic],
            'pg begin atomic body' => ['pgsql', 'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; END', $ok],
            'sqlite trigger body' => ['sqlite', 'CREATE TRIGGER t AFTER INSERT ON x BEGIN DELETE FROM y; END', $ok],
            'sqlsrv go batch' => ['sqlsrv', "SET NOCOUNT ON;\nBEGIN TRAN;\nUPDATE t SET a = 1;\nCOMMIT;", $tx],
            'sqlsrv batch with ddl' => ['sqlsrv', "UPDATE t SET a = 1;\nCREATE DATABASE x;", $no],
            // MariaDB BEGIN NOT ATOMIC runs its statements now
            'mariadb anonymous block' => ['mysql', 'BEGIN NOT ATOMIC DECLARE x INT; SET x = 1; INSERT INTO t VALUES (x); END', $ok],
            'mariadb anonymous block commit' => ['mysql', 'BEGIN NOT ATOMIC INSERT INTO t VALUES (1); IF 1 THEN COMMIT; END IF; END', $tx],
            'mariadb anonymous block ddl' => ['mysql', 'BEGIN NOT ATOMIC lbl: BEGIN CREATE TABLE t (a INT); END; END', $ic],
        ];
    }

    /** @dataProvider kindProvider */
    public function testClassify(string $dialect, string $sql, string $kind): void
    {
        $this->assertSame($kind, (new TransactionPolicy($dialect))->classify($sql)['kind'], $sql);
    }

    public function testHeadForMessages(): void
    {
        $this->assertSame('CREATE TABLE', (new TransactionPolicy('mysql'))->classify('create table t (a int)')['head']);
        $this->assertSame('VACUUM', (new TransactionPolicy('pgsql'))->classify('VACUUM')['head']);
        $this->assertSame('COMMIT', (new TransactionPolicy('sqlsrv'))->classify("INSERT INTO t VALUES (1)\nCOMMIT")['head']);
    }

    public function testNoneModeNeverChecks(): void
    {
        $p = new TransactionPolicy('mysql');
        $this->assertSame([], $p->check(['BEGIN', 'CREATE TABLE t (a int)', 'COMMIT'], TransactionPolicy::NONE));
    }

    public function testDryRunRefusesMysqlDdl(): void
    {
        $p = new TransactionPolicy('mysql');
        try {
            $p->check(['INSERT INTO t VALUES (1)', 'CREATE TABLE x (a int)'], TransactionPolicy::DRYRUN);
            $this->fail('expected refusal');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
            $this->assertStringContainsString('implicit commit', $e->getMessage());
            $this->assertSame('CREATE TABLE x (a int)', $e->getSql());
        }
    }

    public function testAtomicWarnsOnMysqlDdl(): void
    {
        $w = (new TransactionPolicy('mysql'))->check(['INSERT INTO t VALUES (1)', 'ALTER TABLE t ADD b INT', 'CALL p()'], TransactionPolicy::ATOMIC);
        $this->assertCount(2, $w);
        $this->assertStringContainsString('Statement 2 (ALTER TABLE)', $w[0]);
        $this->assertStringContainsString('Statement 3 (CALL P)', $w[1]);
    }

    public function testDryRunAllowsTransactionalDdl(): void
    {
        $this->assertSame([], (new TransactionPolicy('pgsql'))->check(['CREATE TABLE t (a int)', 'DROP TABLE t'], TransactionPolicy::DRYRUN));
        $this->assertSame([], (new TransactionPolicy('sqlite'))->check(['CREATE TABLE t (a int)'], TransactionPolicy::DRYRUN));
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public function refusedProvider(): array
    {
        return [
            'mysql atomic commit' => ['mysql', 'COMMIT', TransactionPolicy::ATOMIC],
            'mysql dryrun begin' => ['mysql', 'START TRANSACTION', TransactionPolicy::DRYRUN],
            'pg atomic vacuum' => ['pgsql', 'VACUUM', TransactionPolicy::ATOMIC],
            'pg dryrun concurrently' => ['pgsql', 'CREATE INDEX CONCURRENTLY i ON t (a)', TransactionPolicy::DRYRUN],
            'sqlite atomic savepoint' => ['sqlite', 'SAVEPOINT s', TransactionPolicy::ATOMIC],
            'sqlite dryrun vacuum' => ['sqlite', 'VACUUM', TransactionPolicy::DRYRUN],
            'sqlsrv atomic backup' => ['sqlsrv', "BACKUP DATABASE d TO DISK = 'x'", TransactionPolicy::ATOMIC],
        ];
    }

    /** @dataProvider refusedProvider */
    public function testRefused(string $dialect, string $sql, string $mode): void
    {
        $this->expectException(ApiException::class);
        (new TransactionPolicy($dialect))->check(['SELECT 1', $sql], $mode);
    }

    public function testModes(): void
    {
        $this->assertTrue(TransactionPolicy::isMode('dryrun'));
        $this->assertFalse(TransactionPolicy::isMode('DRYRUN'));
        $this->assertFalse(TransactionPolicy::isMode(''));
    }
}
