import { test } from 'node:test';
import assert from 'node:assert/strict';
import { splitLines, diffLines, diffStats, sideBySide, withContext } from './linediff.js';
import { groupChanges, buildScript, scriptFilename, actionOf } from './plan.js';

/** Rebuild both inputs from the ops (every line accounted for, in order). */
const replay = (ops) => ({
  a: ops.filter((o) => o.op !== 'add').map((o) => o.text),
  b: ops.filter((o) => o.op !== 'del').map((o) => o.text),
});

/** Length of the longest common subsequence (reference DP). */
function lcsLength(a, b) {
  const dp = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));
  for (let i = a.length - 1; i >= 0; i--) for (let j = b.length - 1; j >= 0; j--) dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  return dp[0][0];
}

test('splitLines normalises line endings and drops the trailing newline', () => {
  assert.deepEqual(splitLines('a\r\nb\rc\n'), ['a', 'b', 'c']);
  assert.deepEqual(splitLines(''), []);
  assert.deepEqual(splitLines(null), []);
  assert.deepEqual(splitLines('\n'), ['']);
});

test('identical, empty and disjoint inputs', () => {
  assert.deepEqual(diffLines(['a', 'b'], ['a', 'b']).map((o) => o.op), ['eq', 'eq']);
  assert.deepEqual(diffLines([], ['x']), [{ op: 'add', text: 'x', a: null, b: 0 }]);
  assert.deepEqual(diffLines(['x'], []), [{ op: 'del', text: 'x', a: 0, b: null }]);
  assert.deepEqual(diffLines(['a'], ['b']).map((o) => o.op), ['del', 'add']);
});

test('a changed column line in a CREATE TABLE', () => {
  const a = splitLines('CREATE TABLE t (\n  id INT,\n  name VARCHAR(100),\n  x INT\n)');
  const b = splitLines('CREATE TABLE t (\n  id INT,\n  name VARCHAR(200),\n  x INT,\n  y INT\n)');
  const ops = diffLines(a, b);
  assert.deepEqual(replay(ops), { a, b });
  assert.deepEqual(diffStats(ops), { added: 3, removed: 2 });
  assert.deepEqual(ops.filter((o) => o.op !== 'eq').map((o) => `${o.op}:${o.text.trim()}`), [
    'del:name VARCHAR(100),', 'del:x INT', 'add:name VARCHAR(200),', 'add:x INT,', 'add:y INT',
  ]);
  // line numbers refer to the original arrays
  for (const o of ops) {
    if (o.a !== null) assert.equal(a[o.a], o.text);
    if (o.b !== null) assert.equal(b[o.b], o.text);
  }
});

test('random inputs: ops replay both sides and the edit script is minimal', () => {
  let seed = 7;
  const rnd = (n) => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed % n; };
  for (let round = 0; round < 300; round++) {
    const a = Array.from({ length: rnd(12) }, () => 'abcde'[rnd(5)]);
    const b = Array.from({ length: rnd(12) }, () => 'abcde'[rnd(5)]);
    const ops = diffLines(a, b);
    assert.deepEqual(replay(ops), { a, b }, JSON.stringify({ a, b }));
    const eq = ops.filter((o) => o.op === 'eq').length;
    assert.equal(eq, lcsLength(a, b), `not minimal for ${JSON.stringify({ a, b })}`);
  }
});

test('maxEdits fallback still replays correctly', () => {
  const a = Array.from({ length: 50 }, (_, i) => `a${i}`);
  const b = Array.from({ length: 50 }, (_, i) => `b${i}`);
  const ops = diffLines(a, b, { maxEdits: 10 });
  assert.deepEqual(replay(ops), { a, b });
});

test('sideBySide pairs deletions with insertions', () => {
  const rows = sideBySide(diffLines(['x', 'old1', 'old2', 'y'], ['x', 'new1', 'y']));
  assert.deepEqual(rows.map((r) => [r.left?.text ?? null, r.right?.text ?? null]), [
    ['x', 'x'], ['old1', 'new1'], ['old2', null], ['y', 'y'],
  ]);
});

test('withContext collapses long unchanged stretches', () => {
  const a = Array.from({ length: 20 }, (_, i) => `l${i}`);
  const b = [...a];
  b[10] = 'changed';
  const out = withContext(diffLines(a, b), 2);
  assert.deepEqual(out[0], { skip: 8 });
  assert.equal(out.filter((o) => !o.skip).length, 6);
  assert.deepEqual(out[out.length - 1], { skip: 7 });
});

test('groupChanges: tables first (own change as header), then object types', () => {
  const groups = groupChanges([
    { id: 'v', kind: 'view_added', object_type: 'view', table: null, object: 'v1' },
    { id: 'c2', kind: 'column_removed', object_type: 'table', table: 'b', object: 'x' },
    { id: 'c1', kind: 'column_added', object_type: 'table', table: 'b', object: 'y' },
    { id: 't', kind: 'table_added', object_type: 'table', table: 'a', object: 'a' },
    { id: 'r', kind: 'routine_removed', object_type: 'routine', table: null, object: 'p' },
  ]);
  assert.deepEqual(groups.map((g) => [g.key, g.action, g.changes.map((c) => c.id)]), [
    ['table:a', 'added', ['t']],
    ['table:b', 'changed', ['c1', 'c2']],
    ['objects:view', 'changed', ['v']],
    ['objects:routine', 'changed', ['r']],
  ]);
  assert.equal(actionOf('column_renamed'), 'renamed');
});

test('buildScript: header, phase comments, MySQL DELIMITER for compound bodies', () => {
  const plan = { statements: [
    { sql: 'DROP VIEW `v`', phase: 'drop_view' },
    { sql: 'CREATE PROCEDURE `p`()\nBEGIN SELECT 1; END', phase: 'create_routine' },
    { sql: 'CREATE TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET NEW.a = 1', phase: 'create_trigger' },
  ] };
  const text = buildScript(plan, { driver: 'mysql', header: ['Target: prod'], phaseLabel: (p) => `[${p}]` });
  assert.equal(text, [
    '-- Target: prod', '',
    '-- [drop_view]', 'DROP VIEW `v`;', '',
    '-- [create_routine]', 'DELIMITER $$', 'CREATE PROCEDURE `p`()\nBEGIN SELECT 1; END$$', 'DELIMITER ;', '',
    '-- [create_trigger]', 'CREATE TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET NEW.a = 1;', '',
  ].join('\n'));
  assert.ok(buildScript({ statements: [] }, { driver: 'mysql', use: 'a`b' }).startsWith('USE `a``b`;\n'));
  const pg = buildScript({ statements: [{ sql: 'CREATE FUNCTION f() RETURNS int AS $$ SELECT 1; $$ LANGUAGE sql;', phase: 'x' }] }, { driver: 'pgsql' });
  assert.equal(pg, 'CREATE FUNCTION f() RETURNS int AS $$ SELECT 1; $$ LANGUAGE sql;\n');
  const lite = buildScript({ statements: [{ sql: 'PRAGMA foreign_keys = OFF', phase: 'setup', tx_control: true }, { sql: 'DROP TABLE "a"', phase: 'drop_table' }] }, { driver: 'sqlite', includeTxControl: false });
  assert.equal(lite, 'DROP TABLE "a";\n');
});

test('scriptFilename keeps Unicode letters, replaces the rest', () => {
  assert.equal(scriptFilename('stg/app', 'prod db', new Date(2026, 0, 2, 3, 4)), 'diff-stg_app-to-prod_db-20260102-0304.sql');
  assert.equal(scriptFilename('データ', 'x', new Date(2026, 0, 2, 3, 4)), 'diff-データ-to-x-20260102-0304.sql');
});
