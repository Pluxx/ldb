import { test } from 'node:test';
import assert from 'node:assert/strict';
import { splitStatements, statementAt } from './split.js';

const texts = (sql, d) => splitStatements(sql, d).map((s) => s.text.trim());

test('plain scripts split at ";" outside quotes and comments (unchanged)', () => {
  assert.deepEqual(texts("SELECT 'a;b', \"c;d\", `e;f`; -- x;\nSELECT 2 /* ; */;SELECT 3"), [
    "SELECT 'a;b', \"c;d\", `e;f`",
    "-- x;\nSELECT 2 /* ; */",
    'SELECT 3',
  ]);
  assert.deepEqual(texts('SELECT CASE WHEN a THEN 1 END; SELECT 2'), ['SELECT CASE WHEN a THEN 1 END', 'SELECT 2']);
  assert.deepEqual(texts('SELECT CASE WHEN a THEN 1 END; SELECT 2', 'pgsql'), ['SELECT CASE WHEN a THEN 1 END', 'SELECT 2']);
  assert.deepEqual(texts('BEGIN; SELECT 1; COMMIT;'), ['BEGIN', 'SELECT 1', 'COMMIT']);
  assert.deepEqual(texts('CREATE TABLE trigger_log (a INT); SELECT 2'), ['CREATE TABLE trigger_log (a INT)', 'SELECT 2']);
  const all = splitStatements('SELECT 1; SELECT 2');
  assert.deepEqual(all.map((s) => [s.start, s.end]), [[0, 8], [9, 18]]);
});

test('MySQL procedure without DELIMITER stays whole', () => {
  const proc = "CREATE DEFINER=`root`@`%` PROCEDURE p(IN n INT)\nBEGIN\n"
    + "  DECLARE i INT DEFAULT 0;\n"
    + "  DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN SET i = -1; END;\n"
    + "  IF n > 0 THEN SELECT 'pos;'; ELSE SELECT IF(n = 0, 'zero', 'neg'); END IF;\n"
    + "  CASE n WHEN 1 THEN SELECT 1; ELSE SELECT 2; END CASE;\n"
    + "  SELECT CASE WHEN n > 1 THEN 'big' END;\n"
    + "  lbl: LOOP SET i = i + 1; IF i > n THEN LEAVE lbl; END IF; END LOOP lbl;\n"
    + "  WHILE i > 0 DO SET i = i - 1; END WHILE;\n"
    + "  REPEAT SET i = i + 1; UNTIL i > 2 END REPEAT;\n"
    + 'END';
  for (const d of [undefined, 'mysql', 'mariadb']) {
    assert.deepEqual(texts(`${proc};\nCALL p(1);\nSELECT 1`, d), [proc, 'CALL p(1)', 'SELECT 1'], String(d));
  }
});

test('MySQL trigger, event, BEGIN NOT ATOMIC', () => {
  const trg = 'CREATE TRIGGER trg BEFORE INSERT ON t FOR EACH ROW BEGIN IF NEW.a IS NULL THEN SET NEW.a = 0; END IF; END';
  assert.deepEqual(texts(`${trg}; INSERT INTO t VALUES (1)`, 'mysql'), [trg, 'INSERT INTO t VALUES (1)']);
  const ev = 'CREATE EVENT ev ON SCHEDULE EVERY 1 DAY DO BEGIN DELETE FROM log; OPTIMIZE TABLE log; END';
  assert.deepEqual(texts(`${ev}; SHOW EVENTS`), [ev, 'SHOW EVENTS']);
  const b = 'BEGIN NOT ATOMIC DECLARE x INT; SET x = 1; END';
  assert.deepEqual(texts(`${b}; SELECT 2`, 'mysql'), [b, 'SELECT 2']);
});

test('keywords in literals/comments do not count', () => {
  const p = "CREATE PROCEDURE p() BEGIN SELECT 'END;', `end`; /* END; */ -- END;\n SELECT t.end; END";
  assert.deepEqual(texts(`${p}; SELECT 1`, 'mysql'), [p, 'SELECT 1']);
});

test('DELIMITER scripts', () => {
  const sql = 'DELIMITER $$\nCREATE PROCEDURE p() BEGIN SELECT 1; END$$\nDELIMITER ;\nSELECT 1; SELECT 2;';
  assert.deepEqual(texts(sql), ['CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 1', 'SELECT 2']);
  assert.deepEqual(texts(sql, 'mysql'), ['CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 1', 'SELECT 2']);
});

test('PostgreSQL BEGIN ATOMIC and dollar quotes', () => {
  const f = 'CREATE OR REPLACE FUNCTION f(a int) RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT a + 1; SELECT CASE WHEN a > 0 THEN 1 END; END';
  assert.deepEqual(texts(`${f}; SELECT f(1)`, 'pgsql'), [f, 'SELECT f(1)']);
  const g = 'CREATE FUNCTION g() RETURNS int AS $b$ BEGIN RETURN 1; END; $b$ LANGUAGE plpgsql';
  assert.deepEqual(texts(`${g}; SELECT 1`, 'pgsql'), [g, 'SELECT 1']);
  assert.deepEqual(texts(`${g}; SELECT 1`), [g, 'SELECT 1']);
  assert.deepEqual(texts('BEGIN; SELECT 1; END;', 'pgsql'), ['BEGIN', 'SELECT 1', 'END']);
});

test('SQLite trigger', () => {
  const t = "CREATE TEMP TRIGGER IF NOT EXISTS t AFTER INSERT ON [x] BEGIN UPDATE y SET n = CASE WHEN n > 0 THEN n END; DELETE FROM z; END";
  assert.deepEqual(texts(`${t}; SELECT 1`, 'sqlite'), [t, 'SELECT 1']);
  assert.deepEqual(texts('BEGIN TRANSACTION; SELECT 1; END;', 'sqlite'), ['BEGIN TRANSACTION', 'SELECT 1', 'END']);
});

test('SQL Server procedures, blocks and GO batches', () => {
  const p = "CREATE OR ALTER PROCEDURE dbo.p @x INT AS BEGIN SET NOCOUNT ON; BEGIN TRY BEGIN TRAN; UPDATE t SET a = @x; COMMIT; END TRY "
    + 'BEGIN CATCH IF @@TRANCOUNT > 0 ROLLBACK; THROW; END CATCH; END';
  assert.deepEqual(texts(`${p}; EXEC dbo.p 1`, 'sqlsrv'), [p, 'EXEC dbo.p 1']);
  assert.deepEqual(texts(`${p}; EXEC dbo.p 1`), [p, 'EXEC dbo.p 1']);
  assert.deepEqual(texts('IF 1 = 1 BEGIN SELECT 1; SELECT 2; END; BEGIN TRANSACTION; COMMIT', 'sqlsrv'),
    ['IF 1 = 1 BEGIN SELECT 1; SELECT 2; END', 'BEGIN TRANSACTION', 'COMMIT']);
  // with GO: one entry per batch
  const go = 'SELECT 1; SELECT 2\nGO\nCREATE PROCEDURE q AS SELECT 1; SELECT 2;\ngo 2\nSELECT 3';
  assert.deepEqual(texts(go, 'sqlsrv'), ['SELECT 1; SELECT 2', 'CREATE PROCEDURE q AS SELECT 1; SELECT 2;', 'SELECT 3']);
  assert.deepEqual(texts(go), ['SELECT 1', 'SELECT 2', 'CREATE PROCEDURE q AS SELECT 1', 'SELECT 2', 'SELECT 3']);
});

test('unbalanced input keeps all text', () => {
  const s = 'CREATE PROCEDURE p() BEGIN SELECT 1; IF x THEN SELECT 2;';
  assert.deepEqual(texts(s, 'mysql'), [s.trim()]);
});

test('statementAt finds the whole routine under the cursor', () => {
  const sql = 'SELECT 0;\nCREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END;\nSELECT 3;';
  const at = statementAt(sql, sql.indexOf('SELECT 2'));
  assert.equal(at.text.trim(), 'CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END');
  assert.equal(statementAt(sql, sql.indexOf('SELECT 3')).text.trim(), 'SELECT 3');
  assert.equal(statementAt(sql, 3).text.trim(), 'SELECT 0');
});
