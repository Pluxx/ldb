import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  MAX_SAVED, normalizeEntry, uniqueName, loadSaved, addEntry, updateEntry, duplicateEntry, removeEntry,
  filterEntries, serializeExport, parseImport, mergeImport, SAVED_KEY, LEGACY_SNIPPETS_KEY,
} from './saved-queries.js';

const fakeStorage = (init = {}) => {
  const m = new Map(Object.entries(init));
  return { get: (k, f = null) => (m.has(k) ? m.get(k) : f), set: (k, v) => { m.set(k, v); return true; }, remove: (k) => m.delete(k), m };
};
let n = 0;
const ids = () => `id${++n}`;

test('normalizeEntry validates name and sql', () => {
  assert.equal(normalizeEntry({ name: '', sql: 'SELECT 1' }), null);
  assert.equal(normalizeEntry({ name: 'a', sql: '   ' }), null);
  assert.equal(normalizeEntry(null), null);
  assert.equal(normalizeEntry({ name: 5, sql: 'x' }), null);
  const e = normalizeEntry({ name: ' q ', sql: 'SELECT 1', db: 'shop', conn: '', junk: 1 }, { now: 100, newId: ids });
  assert.deepEqual(e, { id: e.id, name: 'q', sql: 'SELECT 1', created: 100, updated: 100, db: 'shop' });
});

test('uniqueName appends (2), (3)', () => {
  assert.equal(uniqueName('a', ['b']), 'a');
  assert.equal(uniqueName('a', ['a']), 'a (2)');
  assert.equal(uniqueName('a', ['A', 'a (2)']), 'a (3)');
  assert.equal(uniqueName('a (2)', ['a', 'a (2)']), 'a (3)');
});

test('loadSaved migrates legacy snippets once', () => {
  const st = fakeStorage({ [LEGACY_SNIPPETS_KEY]: [{ id: 'sn-1', name: 'old', sql: 'SELECT 2', at: 5000 }, { name: '', sql: 'x' }] });
  const list = loadSaved(st);
  assert.equal(list.length, 1);
  assert.equal(list[0].name, 'old');
  assert.equal(list[0].created, 5000);
  assert.equal(st.m.has(LEGACY_SNIPPETS_KEY), false);
  assert.equal(st.get(SAVED_KEY).length, 1);
  assert.deepEqual(loadSaved(fakeStorage()), []);
  assert.deepEqual(loadSaved(fakeStorage({ [SAVED_KEY]: 'garbage' })), []);
});

test('add / update / duplicate / remove / filter', () => {
  let r = addEntry([], { name: 'users', sql: 'SELECT * FROM users', db: 'app' }, 10);
  assert.equal(r.entry.created, 10);
  r = addEntry(r.list, { name: 'users', sql: 'SELECT 1' }, 20);
  assert.equal(r.entry.name, 'users (2)');
  assert.equal(r.list[0], r.entry);
  const d = duplicateEntry(r.list, r.list[1].id, 30);
  assert.equal(d.entry.name, 'users (3)');
  let list = updateEntry(d.list, d.entry.id, { name: 'users' }, 40);
  assert.notEqual(list.find((e) => e.id === d.entry.id).name, 'users');
  list = updateEntry(list, d.entry.id, { name: 'renamed' }, 50);
  const upd = list.find((e) => e.id === d.entry.id);
  assert.equal(upd.name, 'renamed');
  assert.equal(upd.updated, 50);
  assert.equal(upd.created, 30);
  assert.equal(filterEntries(list, 'APP').length, 2); // db match (original + duplicate)
  assert.equal(filterEntries(list, 'select 1').length, 1);
  assert.equal(removeEntry(list, upd.id).length, list.length - 1);
});

test('cap at MAX_SAVED', () => {
  const list = Array.from({ length: MAX_SAVED }, (_, i) => normalizeEntry({ name: `q${i}`, sql: 'SELECT 1', id: `i${i}` }));
  assert.equal(addEntry(list, { name: 'x', sql: 'SELECT 1' }), null);
  assert.equal(duplicateEntry(list, 'i0'), null);
  const m = mergeImport(list, [{ name: 'new', sql: 'SELECT 2' }]);
  assert.equal(m.list.length, MAX_SAVED);
  assert.equal(m.dropped, 1);
});

test('parseImport accepts envelope and array, rejects bad shapes', () => {
  const list = addEntry([], { name: 'ビュー', sql: "SELECT 'こんにちは'" }, 1).list;
  const round = parseImport(serializeExport(list, 0));
  assert.equal(round.entries[0].name, 'ビュー');
  assert.equal(round.entries[0].sql, "SELECT 'こんにちは'");
  assert.equal(parseImport(JSON.stringify([{ name: 'a', sql: 'SELECT 1' }, { name: 'bad' }, 3])).skipped, 2);
  assert.throws(() => parseImport('not json'), { code: 'bad_json' });
  assert.throws(() => parseImport('{"a":1}'), { code: 'bad_shape' });
  assert.throws(() => parseImport('{"format":"other","queries":[]}'), { code: 'bad_shape' });
  assert.throws(() => parseImport('[]'), { code: 'empty' });
  assert.throws(() => parseImport('[{"name":"","sql":""}]'), { code: 'empty' });
  assert.throws(() => parseImport('x'.repeat(6 * 1024 * 1024)), { code: 'too_large' });
});

test('mergeImport merges by name with (2) suffix and skips identical', () => {
  const base = [normalizeEntry({ name: 'report', sql: 'SELECT 1', id: 'a' }), normalizeEntry({ name: 'report (2)', sql: 'SELECT 9', id: 'b' })];
  const incoming = [
    { name: 'report', sql: 'SELECT 1' },          // identical → skipped
    { name: 'Report', sql: 'SELECT 2' },          // same name (case-insens.) → renamed
    { name: 'fresh', sql: 'SELECT 3' },
    { name: 'fresh', sql: 'SELECT 3' },           // identical to previous import → skipped
  ];
  const r = mergeImport(base, incoming, { newId: ids });
  assert.deepEqual(r.list.map((e) => e.name), ['report', 'report (2)', 'Report (3)', 'fresh']);
  assert.equal(r.added, 2);
  assert.equal(r.renamed, 1);
  assert.equal(r.duplicates, 2);
  assert.equal(new Set(r.list.map((e) => e.id)).size, 4);
});
