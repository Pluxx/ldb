/**
 * Line diff (Myers O(ND) shortest edit script) for the schema diff DDL panes. Pure, no DOM.
 * @module diff/linediff
 */

/**
 * Split text into lines (\r\n and \r normalised; a trailing newline adds no empty line).
 * @param {string|null|undefined} text
 * @returns {string[]}
 */
export function splitLines(text) {
  if (text === null || text === undefined || text === '') return [];
  const lines = String(text).replace(/\r\n?/g, '\n').split('\n');
  if (lines.length && lines[lines.length - 1] === '') lines.pop();
  return lines;
}

/**
 * @typedef {{op: 'eq'|'del'|'add', text: string, a: number|null, b: number|null}} DiffOp
 *   a / b: 0-based line index in the old (a) / new (b) text
 */

/**
 * Shortest edit script turning `a` into `b` (Myers 1986, greedy forward search with a trace).
 * Common prefix/suffix are trimmed first. Beyond `maxEdits` differences the middle part is
 * reported as a plain delete + insert block (keeps huge unrelated inputs fast).
 * @param {string[]} a  old lines
 * @param {string[]} b  new lines
 * @param {{maxEdits?: number}} [opts]
 * @returns {DiffOp[]}
 */
export function diffLines(a, b, opts = {}) {
  const maxEdits = opts.maxEdits ?? 4000;
  const out = [];
  let start = 0;
  while (start < a.length && start < b.length && a[start] === b[start]) {
    out.push({ op: 'eq', text: a[start], a: start, b: start });
    start++;
  }
  let endA = a.length;
  let endB = b.length;
  const tail = [];
  while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
    endA--;
    endB--;
    tail.unshift({ op: 'eq', text: a[endA], a: endA, b: endB });
  }
  const mid = myers(a, b, start, endA, start, endB, maxEdits);
  return out.concat(mid, tail);
}

function myers(a, b, a0, a1, b0, b1, maxEdits) {
  const n = a1 - a0;
  const m = b1 - b0;
  const plain = () => {
    const ops = [];
    for (let i = a0; i < a1; i++) ops.push({ op: 'del', text: a[i], a: i, b: null });
    for (let j = b0; j < b1; j++) ops.push({ op: 'add', text: b[j], a: null, b: j });
    return ops;
  };
  if (n === 0 || m === 0) return plain();
  const max = Math.min(n + m, maxEdits);
  const offset = max + 1;
  const v = new Int32Array(2 * max + 3);
  const trace = [];
  let found = -1;
  for (let d = 0; d <= max; d++) {
    trace.push(v.slice());
    for (let k = -d; k <= d; k += 2) {
      let x;
      if (k === -d || (k !== d && v[offset + k - 1] < v[offset + k + 1])) x = v[offset + k + 1]; // down: insertion
      else x = v[offset + k - 1] + 1; // right: deletion
      let y = x - k;
      while (x < n && y < m && a[a0 + x] === b[b0 + y]) { x++; y++; }
      v[offset + k] = x;
      if (x >= n && y >= m) { found = d; break; }
    }
    if (found >= 0) break;
  }
  if (found < 0) return plain();
  // backtrack
  const ops = [];
  let x = n;
  let y = m;
  for (let d = found; d > 0; d--) {
    const pv = trace[d];
    const k = x - y;
    const down = k === -d || (k !== d && pv[offset + k - 1] < pv[offset + k + 1]);
    const prevK = down ? k + 1 : k - 1;
    const prevX = pv[offset + prevK];
    const prevY = prevX - prevK;
    while (x > prevX && y > prevY) {
      x--; y--;
      ops.push({ op: 'eq', text: a[a0 + x], a: a0 + x, b: b0 + y });
    }
    if (down) { y--; ops.push({ op: 'add', text: b[b0 + y], a: null, b: b0 + y }); }
    else { x--; ops.push({ op: 'del', text: a[a0 + x], a: a0 + x, b: null }); }
  }
  while (x > 0 && y > 0) {
    x--; y--;
    ops.push({ op: 'eq', text: a[a0 + x], a: a0 + x, b: b0 + y });
  }
  return ops.reverse();
}

/**
 * Counts of added / removed lines.
 * @param {DiffOp[]} ops
 */
export function diffStats(ops) {
  let added = 0;
  let removed = 0;
  for (const o of ops) {
    if (o.op === 'add') added++;
    else if (o.op === 'del') removed++;
  }
  return { added, removed };
}

/**
 * Side-by-side rows: unchanged lines on both sides, a run of deletions paired line by line with
 * the following run of insertions (the shorter side padded with null).
 * @param {DiffOp[]} ops
 * @returns {{left: DiffOp|null, right: DiffOp|null}[]}
 */
export function sideBySide(ops) {
  const rows = [];
  let i = 0;
  while (i < ops.length) {
    const o = ops[i];
    if (o.op === 'eq') { rows.push({ left: o, right: o }); i++; continue; }
    const dels = [];
    const adds = [];
    while (i < ops.length && ops[i].op === 'del') dels.push(ops[i++]);
    while (i < ops.length && ops[i].op === 'add') adds.push(ops[i++]);
    const n = Math.max(dels.length, adds.length);
    for (let k = 0; k < n; k++) rows.push({ left: dels[k] || null, right: adds[k] || null });
  }
  return rows;
}

/**
 * Group ops into hunks with `context` unchanged lines around changes; long unchanged stretches
 * become {skip: count} markers.
 * @param {DiffOp[]} ops
 * @param {number} [context=3]
 * @returns {Array<DiffOp|{skip: number}>}
 */
export function withContext(ops, context = 3) {
  const keep = new Uint8Array(ops.length);
  ops.forEach((o, i) => {
    if (o.op === 'eq') return;
    for (let k = Math.max(0, i - context); k <= Math.min(ops.length - 1, i + context); k++) keep[k] = 1;
  });
  const out = [];
  let skipped = 0;
  ops.forEach((o, i) => {
    if (keep[i]) {
      if (skipped) { out.push({ skip: skipped }); skipped = 0; }
      out.push(o);
    } else {
      skipped++;
    }
  });
  if (skipped) out.push({ skip: skipped });
  return out;
}
