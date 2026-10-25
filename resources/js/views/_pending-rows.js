/**
 * Pending-changes model for batched grid edits (table browse). Pure data, no DOM: the grid owns
 * the row arrays and mutates cells in place; this model remembers original values, deleted rows
 * and staged new rows, and turns them into a table.applyChanges batch.
 *
 * Row indexes ("di") address the combined row list `[...baseRows, ...addedRows]` that is handed
 * to the grid (see rows()). Base rows keep their index while changes are pending (paging/sorting
 * is blocked until they are applied or discarded).
 * @module views/_pending-rows
 */

/** Loose cell equality (the grid turns numeric input back into numbers). */
export const sameValue = (a, b) => {
  if (a === undefined || b === undefined) return a === b;
  if (a === null || b === null) return a === b;
  const s = (v) => (typeof v === 'object' ? JSON.stringify(v) : String(v));
  return s(a) === s(b);
};

/**
 * @param {object} o
 * @param {string[]} o.columns      column names (index = ci)
 * @param {string[]} o.primaryKey   key column names
 */
export function createPendingRows(o) {
  let columns = o.columns || [];
  let primaryKey = o.primaryKey || [];
  let base = [];
  /** @type {Map<number, {orig: any[], cols: Set<number>}>} */
  const edits = new Map();
  const deleted = new Set();
  /** @type {{row: any[], set: Set<number>}[]} */
  let added = [];
  let failed = null; // di of the change that failed last

  const isNew = (di) => di >= base.length;
  const addedAt = (di) => added[di - base.length];
  const colIndex = (name) => columns.indexOf(name);

  const api = {
    /** New page of data: forget everything. */
    reset(rows, meta = {}) {
      base = rows || [];
      if (meta.columns) columns = meta.columns;
      if (meta.primaryKey) primaryKey = meta.primaryKey;
      edits.clear();
      deleted.clear();
      added = [];
      failed = null;
    },
    /** Drop every pending change and put the original cell values back into the rows. */
    discardAll() {
      edits.forEach((e, di) => { e.orig.forEach((v, ci) => { base[di][ci] = v; }); });
      edits.clear();
      deleted.clear();
      added = [];
      failed = null;
    },
    /** Rows for the grid: base rows followed by staged new rows. */
    rows: () => (added.length ? [...base, ...added.map((a) => a.row)] : base),
    isNew,
    /** Number of row operations (one per edited/deleted/new row). */
    count() {
      let n = deleted.size + added.length;
      edits.forEach((_, di) => { if (!deleted.has(di)) n++; });
      return n;
    },
    counts() {
      let upd = 0;
      edits.forEach((_, di) => { if (!deleted.has(di)) upd++; });
      return { update: upd, delete: deleted.size, insert: added.length };
    },

    /**
     * Record a cell edit after the grid stored the new value in the row.
     * @param {number} di @param {number} ci
     * @param {any[]} before  the row values before this edit (grid snapshot)
     */
    editCell(di, ci, before) {
      failed = null;
      if (isNew(di)) {
        const a = addedAt(di);
        if (a) a.set.add(ci);
        return;
      }
      let e = edits.get(di);
      if (!e) { e = { orig: [...before], cols: new Set() }; edits.set(di, e); }
      if (sameValue(base[di][ci], e.orig[ci])) e.cols.delete(ci); else e.cols.add(ci);
      if (!e.cols.size) edits.delete(di);
    },

    /** Undo one cell. @returns {boolean} whether anything was reverted */
    revertCell(di, ci) {
      if (isNew(di)) {
        const a = addedAt(di);
        if (!a || !a.set.has(ci)) return false;
        a.set.delete(ci);
        a.row[ci] = undefined; // back to the column DEFAULT
        return true;
      }
      const e = edits.get(di);
      if (!e || !e.cols.has(ci)) return false;
      base[di][ci] = e.orig[ci];
      e.cols.delete(ci);
      if (!e.cols.size) edits.delete(di);
      return true;
    },

    /** Undo all edits / the delete mark of a row; a new row is removed. @returns {boolean} */
    revertRow(di) {
      if (isNew(di)) {
        const k = di - base.length;
        if (k < 0 || k >= added.length) return false;
        added.splice(k, 1);
        if (failed !== null && failed >= di) failed = null;
        return true;
      }
      let changed = deleted.delete(di);
      const e = edits.get(di);
      if (e) {
        e.orig.forEach((v, ci) => { base[di][ci] = v; });
        edits.delete(di);
        changed = true;
      }
      if (failed === di) failed = null;
      return changed;
    },

    /**
     * Mark rows deleted; new rows are dropped instead. When every given base row is already
     * marked, the marks are removed (toggle).
     */
    toggleDelete(indexes) {
      failed = null;
      const baseIdx = indexes.filter((di) => !isNew(di));
      const newIdx = indexes.filter((di) => isNew(di)).sort((a, b) => b - a);
      const allMarked = baseIdx.length > 0 && baseIdx.every((di) => deleted.has(di));
      baseIdx.forEach((di) => { if (allMarked) deleted.delete(di); else deleted.add(di); });
      newIdx.forEach((di) => added.splice(di - base.length, 1));
      return !allMarked;
    },

    /**
     * Stage a new row.
     * @param {Record<string, any>} values  explicitly set columns; others use the DEFAULT
     * @returns {number} di of the new row
     */
    addRow(values) {
      failed = null;
      const row = columns.map(() => undefined);
      const set = new Set();
      Object.entries(values || {}).forEach(([name, v]) => {
        const ci = colIndex(name);
        if (ci >= 0) { row[ci] = v; set.add(ci); }
      });
      added.push({ row, set });
      return base.length + added.length - 1;
    },

    /** Stage changed values for an existing row (row form in batch mode). */
    setValues(di, values) {
      const before = [...api.rows()[di]];
      Object.entries(values || {}).forEach(([name, v]) => {
        const ci = colIndex(name);
        if (ci < 0) return;
        api.rows()[di][ci] = v;
        api.editCell(di, ci, before);
      });
    },

    /** @returns {'edited'|null} */
    cellState(di, ci) {
      if (isNew(di)) return addedAt(di)?.set.has(ci) ? 'edited' : null;
      return edits.get(di)?.cols.has(ci) && !deleted.has(di) ? 'edited' : null;
    },
    /** @returns {'deleted'|'new'|'edited'|null} */
    rowState(di) {
      if (isNew(di)) return 'new';
      if (deleted.has(di)) return 'deleted';
      return edits.has(di) ? 'edited' : null;
    },
    isFailed: (di) => failed === di,
    setFailed(di) { failed = di ?? null; },

    /** Key of a base row from its ORIGINAL values. */
    keyOf(di) {
      const src = edits.get(di)?.orig || base[di];
      return Object.fromEntries(primaryKey.map((k) => [k, src[colIndex(k)]]));
    },

    /**
     * The batch for table.applyChanges: deletes first (frees unique values), then updates, then
     * inserts. `rows[i]` is the di behind `changes[i]`.
     * @returns {{changes: object[], rows: number[]}}
     */
    toChanges() {
      const changes = [];
      const rows = [];
      [...deleted].sort((a, b) => a - b).forEach((di) => { changes.push({ op: 'delete', key: api.keyOf(di) }); rows.push(di); });
      [...edits.keys()].sort((a, b) => a - b).forEach((di) => {
        if (deleted.has(di)) return;
        const values = {};
        edits.get(di).cols.forEach((ci) => { values[columns[ci]] = base[di][ci] === undefined ? null : base[di][ci]; });
        changes.push({ op: 'update', key: api.keyOf(di), values });
        rows.push(di);
      });
      added.forEach((a, k) => {
        const values = {};
        [...a.set].sort((x, y) => x - y).forEach((ci) => { if (a.row[ci] !== undefined) values[columns[ci]] = a.row[ci]; });
        changes.push({ op: 'insert', values });
        rows.push(base.length + k);
      });
      return { changes, rows };
    },
  };
  return api;
}
