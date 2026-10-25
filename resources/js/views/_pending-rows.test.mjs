// Run: node --test resources/js/views/_pending-rows.test.mjs   (not bundled: nothing imports this file)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPendingRows, sameValue } from './_pending-rows.js';

const setup = () => {
  const rows = [[1, 'Ann', 10], [2, 'Bob', 20], [3, 'Cid', null]];
  const p = createPendingRows({ columns: ['id', 'name', 'qty'], primaryKey: ['id'] });
  p.reset(rows);
  // what the grid does: snapshot, write the cell, then call onEdit → editCell
  const edit = (di, ci, v) => { const before = [...p.rows()[di]]; p.rows()[di][ci] = v; p.editCell(di, ci, before); };
  return { rows, p, edit };
};

test('sameValue is loose for numbers/strings but strict for null/undefined', () => {
  assert.ok(sameValue(1, '1'));
  assert.ok(!sameValue(null, ''));
  assert.ok(!sameValue(undefined, null));
  assert.ok(sameValue({ a: 1 }, { a: 1 }));
});

test('cell edits, auto-revert and per-cell revert', () => {
  const { rows, p, edit } = setup();
  edit(0, 1, 'Anna');
  edit(0, 2, 11);
  assert.equal(p.cellState(0, 1), 'edited');
  assert.equal(p.rowState(0), 'edited');
  assert.equal(p.count(), 1);
  edit(0, 2, '10'); // back to the original value
  assert.equal(p.cellState(0, 2), null);
  assert.ok(p.revertCell(0, 1));
  assert.equal(rows[0][1], 'Ann');
  assert.equal(p.count(), 0);
  assert.equal(p.revertCell(0, 1), false);
});

test('key uses the original values even when the key column was edited', () => {
  const { p, edit } = setup();
  edit(1, 0, 99);
  edit(1, 1, 'Robert');
  const { changes } = p.toChanges();
  assert.deepEqual(changes, [{ op: 'update', key: { id: 2 }, values: { id: 99, name: 'Robert' } }]);
});

test('deletes toggle, new rows, ordering of the batch', () => {
  const { p, edit } = setup();
  edit(0, 1, 'Anna');
  p.toggleDelete([2]);
  const di = p.addRow({ name: 'Dee', qty: null });
  assert.equal(di, 3);
  assert.equal(p.rowState(3), 'new');
  edit(3, 2, 5);
  edit(2, 1, 'ignored because deleted');
  assert.equal(p.cellState(2, 1), null);
  const { changes, rows } = p.toChanges();
  assert.deepEqual(changes.map((c) => c.op), ['delete', 'update', 'insert']);
  assert.deepEqual(rows, [2, 0, 3]);
  assert.deepEqual(changes[0].key, { id: 3 });
  assert.deepEqual(changes[2].values, { name: 'Dee', qty: 5 });
  assert.equal(p.count(), 3);
  assert.deepEqual(p.counts(), { update: 1, delete: 1, insert: 1 });
  // toggle back
  assert.equal(p.toggleDelete([2]), false);
  assert.equal(p.rowState(2), 'edited');
  // deleting a new row drops it
  p.toggleDelete([3]);
  assert.equal(p.rows().length, 3);
});

test('revertRow restores all cells and removes new rows', () => {
  const { rows, p, edit } = setup();
  edit(1, 1, 'B');
  edit(1, 2, 0);
  p.toggleDelete([1]);
  assert.ok(p.revertRow(1));
  assert.deepEqual(rows[1], [2, 'Bob', 20]);
  assert.equal(p.rowState(1), null);
  const di = p.addRow({ name: 'X' });
  assert.ok(p.revertRow(di));
  assert.equal(p.count(), 0);
});

test('new row cell revert goes back to DEFAULT and is left out of the insert', () => {
  const { p, edit } = setup();
  const di = p.addRow({ name: 'X' });
  edit(di, 2, 7);
  assert.ok(p.revertCell(di, 2));
  assert.equal(p.rows()[di][2], undefined);
  assert.deepEqual(p.toChanges().changes[0], { op: 'insert', values: { name: 'X' } });
});

test('setValues stages a row-form edit', () => {
  const { p } = setup();
  p.setValues(0, { name: 'Z', qty: 10 });
  assert.equal(p.cellState(0, 1), 'edited');
  assert.equal(p.cellState(0, 2), null);
  assert.deepEqual(p.toChanges().changes, [{ op: 'update', key: { id: 1 }, values: { name: 'Z' } }]);
});

test('discardAll restores the original values', () => {
  const { rows, p, edit } = setup();
  edit(0, 1, 'X');
  edit(2, 2, 3);
  p.toggleDelete([1]);
  p.addRow({ name: 'N' });
  p.discardAll();
  assert.deepEqual(rows, [[1, 'Ann', 10], [2, 'Bob', 20], [3, 'Cid', null]]);
  assert.equal(p.count(), 0);
  assert.equal(p.rows().length, 3);
});
