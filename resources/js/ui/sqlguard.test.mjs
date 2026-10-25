// Run: node --test resources/js/ui/sqlguard.test.mjs   (not bundled: nothing imports this file)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { analyzeRisk, risksForTx } from './sqlguard.js';

const kinds = (sql, d) => analyzeRisk(sql, d).map((r) => r.kind);

test('DELETE / UPDATE without WHERE', () => {
  assert.deepEqual(kinds('DELETE FROM audit_log'), ['delete-no-where']);
  assert.equal(analyzeRisk('DELETE FROM `audit_log`')[0].target, 'audit_log');
  assert.deepEqual(kinds('UPDATE t SET a = 1'), ['update-no-where']);
  assert.equal(analyzeRisk('UPDATE t SET a = 1')[0].target, 't');
  assert.deepEqual(kinds('DELETE FROM t LIMIT 10'), ['delete-no-where']);
  assert.deepEqual(kinds('DELETE a FROM a JOIN b ON a.id = b.id'), ['delete-no-where']);
  assert.deepEqual(kinds('UPDATE a JOIN b ON a.id=b.id SET a.x=1'), ['update-no-where']);
});

test('with WHERE is safe', () => {
  assert.deepEqual(kinds('DELETE FROM t WHERE id = 1'), []);
  assert.deepEqual(kinds('UPDATE t SET a = 1 WHERE 1=1'), []);
  assert.deepEqual(kinds('delete from t where id in (1,2)'), []);
  assert.deepEqual(kinds('SELECT * FROM t'), []);
  assert.deepEqual(kinds('INSERT INTO t VALUES (1)'), []);
});

test('WHERE only inside a subquery does not count', () => {
  assert.deepEqual(kinds('UPDATE t SET a = (SELECT b FROM u WHERE u.id = 1)'), ['update-no-where']);
  assert.deepEqual(kinds('DELETE FROM t USING (SELECT 1 WHERE true) x'), ['delete-no-where']);
  assert.deepEqual(kinds('UPDATE t SET a = (SELECT 1 WHERE 1) WHERE id = 3'), []);
});

test('keywords in strings, comments, identifiers are ignored', () => {
  assert.deepEqual(kinds("UPDATE t SET note = 'WHERE x'"), ['update-no-where']);
  assert.deepEqual(kinds("UPDATE t SET a = 1 -- WHERE id = 1"), ['update-no-where']);
  assert.deepEqual(kinds('UPDATE t SET a = 1 /* WHERE id = 1 */'), ['update-no-where']);
  assert.deepEqual(kinds('UPDATE t SET `where` = 1'), ['update-no-where']);
  assert.deepEqual(kinds('UPDATE t SET "where" = 1', 'pgsql'), ['update-no-where']);
  assert.deepEqual(kinds('UPDATE t SET [where] = 1'), ['update-no-where']);
  assert.deepEqual(kinds("SELECT 'DROP TABLE x; DELETE FROM y'"), []);
  assert.deepEqual(kinds('-- DROP TABLE x\nSELECT 1'), []);
  assert.deepEqual(kinds('# DELETE FROM x\nSELECT 1', 'mysql'), []);
  assert.deepEqual(kinds('SELECT `drop`, `delete` FROM t'), []);
  assert.deepEqual(kinds("UPDATE t SET a = 'it''s' WHERE id = 1"), []);
  assert.deepEqual(kinds("UPDATE t SET a = 'a\\'b WHERE' "), ['update-no-where']);
});

test('PostgreSQL dollar quotes', () => {
  const fn = 'CREATE FUNCTION f() RETURNS void AS $$ BEGIN DELETE FROM t; END; $$ LANGUAGE plpgsql; SELECT 1';
  assert.deepEqual(kinds(fn, 'pgsql'), []);
  assert.deepEqual(kinds("UPDATE t SET a = $tag$ WHERE $tag$", 'pgsql'), ['update-no-where']);
  assert.deepEqual(kinds('SELECT $1; DELETE FROM t', 'pgsql'), ['delete-no-where']);
});

test('multi-statement scripts', () => {
  const r = analyzeRisk("DELETE FROM a WHERE id=1; DELETE FROM b; SELECT 1;\nUPDATE c SET x=1; DROP TABLE d");
  assert.deepEqual(r.map((x) => x.kind), ['delete-no-where', 'update-no-where', 'drop']);
  assert.equal(r[0].statement, 'DELETE FROM b');
  assert.deepEqual(r.map((x) => x.target), ['b', 'c', 'd']);
});

test('DROP / TRUNCATE / ALTER TABLE ... DROP', () => {
  assert.deepEqual(kinds('DROP TABLE t'), ['drop']);
  assert.deepEqual(kinds('drop view if exists v'), ['drop']);
  assert.deepEqual(kinds('DROP INDEX i ON t'), ['drop']);
  const db = analyzeRisk('DROP DATABASE IF EXISTS `shop`')[0];
  assert.equal(db.target, 'shop');
  assert.equal(db.database, true);
  assert.equal(analyzeRisk('DROP SCHEMA s')[0].database, true);
  assert.equal(analyzeRisk('DROP TABLE t')[0].database, undefined);
  assert.deepEqual(kinds('TRUNCATE TABLE t'), ['truncate']);
  assert.equal(analyzeRisk('TRUNCATE t')[0].target, 't');
  assert.deepEqual(kinds('ALTER TABLE t DROP COLUMN c'), ['alter-drop']);
  assert.deepEqual(kinds('ALTER TABLE t ADD COLUMN c INT'), []);
  assert.deepEqual(kinds('ALTER TABLE t ADD COLUMN c INT DEFAULT (0)'), []);
});

test('CTE + UPDATE/DELETE', () => {
  assert.deepEqual(kinds('WITH x AS (SELECT 1 WHERE true) UPDATE t SET a = 1', 'pgsql'), ['update-no-where']);
  assert.deepEqual(kinds('WITH x AS (SELECT 1) UPDATE t SET a = 1 WHERE id IN (SELECT * FROM x)', 'pgsql'), []);
  assert.deepEqual(kinds('WITH RECURSIVE x AS (SELECT 1) DELETE FROM t', 'pgsql'), ['delete-no-where']);
  assert.deepEqual(kinds('WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x', 'pgsql'), []);
});

test('case-insensitive and whitespace tolerant', () => {
  assert.deepEqual(kinds('  dElEtE\n\tfRoM t  '), ['delete-no-where']);
  assert.deepEqual(kinds('uPdAtE t sEt a=1 wHeRe b=2'), []);
  assert.deepEqual(kinds('tRuNcAtE t'), ['truncate']);
});

test('Japanese identifiers', () => {
  assert.deepEqual(kinds('DELETE FROM 顧客'), ['delete-no-where']);
  assert.equal(analyzeRisk('DELETE FROM 顧客')[0].target, '顧客');
  assert.equal(analyzeRisk('UPDATE `顧客テーブル` SET 名前 = 1')[0].target, '顧客テーブル');
  assert.deepEqual(kinds('UPDATE 顧客 SET 名前 = 1 WHERE 番号 = 2'), []);
  assert.deepEqual(kinds("UPDATE 顧客 SET 備考 = 'WHERE'"), ['update-no-where']);
  assert.equal(analyzeRisk('DROP DATABASE データベース')[0].target, 'データベース');
});

test('empty input', () => {
  assert.deepEqual(analyzeRisk(''), []);
  assert.deepEqual(analyzeRisk('  ;; '), []);
});

test('dry run needs no confirmation, other modes keep every risk', () => {
  const risks = analyzeRisk('DELETE FROM t; DROP TABLE u');
  assert.equal(risks.length, 2);
  assert.deepEqual(risksForTx(risks, 'dryrun'), []);
  assert.equal(risksForTx(risks, 'atomic').length, 2);
  assert.equal(risksForTx(risks, 'none').length, 2);
  assert.equal(risksForTx(risks).length, 2);
});

test('dialect-aware splitting: DELIMITER (mysql), $$ (pgsql), GO (sqlsrv)', () => {
  assert.deepEqual(kinds('DELIMITER $$\nCREATE PROCEDURE p() BEGIN DELETE FROM t; END$$\nDELIMITER ;\nDROP TABLE x', 'mysql'), ['drop']);
  assert.deepEqual(kinds('CREATE FUNCTION f() RETURNS void AS $$ BEGIN DELETE FROM t; END; $$ LANGUAGE plpgsql; DROP TABLE x', 'pgsql'), ['drop']);
  assert.deepEqual(kinds('SELECT 1\nGO\nDELETE FROM t\nGO', 'sqlsrv'), ['delete-no-where']);
});
