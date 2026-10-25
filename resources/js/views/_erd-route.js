/**
 * Orthogonal edge routing for the ER diagram (pure, DOM-free; unit-tested in node).
 *
 * Each relation leaves a card side horizontally (stub), then follows the cheapest orthogonal path
 * (length + bend penalty, A* on a sparse grid built from the obstacle margins of the cards near the
 * pair) around all card boxes. The search region grows when no path is found. routeAll() also
 * spreads edges that share a row anchor and nudges collinear interior segments apart.
 * @module views/_erd-route
 */

const M = 14; // clearance kept around cards
const STUB = 24; // horizontal stub out of a card side (> M, room for crow's foot)
const BEND = 36; // cost of one bend, in px

/** @typedef {{x: number, y: number, w: number, h: number}} Box */
/** @typedef {{A: Box, B: Box, ya: number, yb: number, dya?: number, dyb?: number, sa?: number, sb?: number}} Edge */

/** Side pair [sa, sb] (+1 right, -1 left) for a card pair. */
export function chooseSides(A, B) {
  if (A === B) return [1, 1];
  if (A.x + A.w + STUB * 2 <= B.x) return [1, -1];
  if (B.x + B.w + STUB * 2 <= A.x) return [-1, 1];
  const r = Math.max(A.x + A.w, B.x + B.w) * 2 - (A.x + A.w) - (B.x + B.w);
  const l = (A.x + B.x) - Math.min(A.x, B.x) * 2;
  return r <= l ? [1, 1] : [-1, -1];
}

const lower = (arr, v) => { let lo = 0; let hi = arr.length; while (lo < hi) { const m = (lo + hi) >> 1; if (arr[m] < v) lo = m + 1; else hi = m; } return lo; };
const upper = (arr, v) => { let lo = 0; let hi = arr.length; while (lo < hi) { const m = (lo + hi) >> 1; if (arr[m] <= v) lo = m + 1; else hi = m; } return lo; };

/** Sorted unique coordinates plus channel midpoints. */
function axis(vals) {
  const u = [...new Set(vals)].sort((a, b) => a - b);
  const out = [];
  for (let i = 0; i < u.length; i++) {
    out.push(u[i]);
    if (i + 1 < u.length && u[i + 1] - u[i] > 12) out.push((u[i] + u[i + 1]) / 2);
  }
  return out;
}

/* binary min-heap on parallel arrays */
function heap() {
  const k = []; const v = [];
  return {
    get size() { return k.length; },
    push(key, val) {
      let i = k.length; k.push(key); v.push(val);
      while (i > 0) { const p = (i - 1) >> 1; if (k[p] <= key) break; k[i] = k[p]; v[i] = v[p]; i = p; }
      k[i] = key; v[i] = val;
    },
    top: () => k[0],
    pop() {
      const out = v[0]; const lk = k.pop(); const lv = v.pop();
      if (k.length) {
        let i = 0; const n = k.length;
        for (;;) {
          let c = i * 2 + 1; if (c >= n) break;
          if (c + 1 < n && k[c + 1] < k[c]) c++;
          if (k[c] >= lk) break;
          k[i] = k[c]; v[i] = v[c]; i = c;
        }
        k[i] = lk; v[i] = lv;
      }
      return out;
    },
  };
}

const DX = [1, -1, 0, 0];
const DY = [0, 0, 1, -1];
const OPP = [1, 0, 3, 2];

/**
 * A* between two points on a sparse grid inside region R around the obstacles.
 * @returns {number[][]|null} corner points (including s and t)
 */
function search(obs, s, t, d0, d1, R) {
  const inR = obs.filter((o) => o.x2 > R[0] && o.x1 < R[2] && o.y2 > R[1] && o.y1 < R[3]);
  const xs = axis([s[0], t[0], R[0], R[2], ...inR.flatMap((o) => [o.x1, o.x2])].filter((x) => x >= R[0] && x <= R[2]));
  const ys = axis([s[1], t[1], R[1], R[3], ...inR.flatMap((o) => [o.y1, o.y2])].filter((y) => y >= R[1] && y <= R[3]));
  const nx = xs.length; const ny = ys.length;
  const N = nx * ny;
  // hB[i*ny+j]: segment (i,j)-(i+1,j) blocked; vB: (i,j)-(i,j+1)
  const hB = new Uint8Array(N); const vB = new Uint8Array(N);
  for (const o of inR) {
    const i0 = lower(xs, o.x1); const i1 = upper(xs, o.x2) - 1;
    const j0 = lower(ys, o.y1); const j1 = upper(ys, o.y2) - 1;
    const is = upper(xs, o.x1); const ie = lower(xs, o.x2) - 1; // strictly inside
    const js = upper(ys, o.y1); const je = lower(ys, o.y2) - 1;
    for (let i = i0; i < i1; i++) for (let j = js; j <= je; j++) hB[i * ny + j] = 1;
    for (let i = is; i <= ie; i++) for (let j = j0; j < j1; j++) vB[i * ny + j] = 1;
  }
  const si = lower(xs, s[0]); const sj = lower(ys, s[1]);
  const ti = lower(xs, t[0]); const tj = lower(ys, t[1]);
  const goal = ti * ny + tj;
  const g = new Float64Array(N * 4).fill(Infinity);
  const prev = new Int32Array(N * 4).fill(-1);
  const H = heap();
  const hh = (i, j) => Math.abs(xs[i] - t[0]) + Math.abs(ys[j] - t[1]);
  const st = (si * ny + sj) * 4 + d0;
  g[st] = 0;
  H.push(hh(si, sj), st);
  let best = Infinity; let bestState = -1;
  while (H.size) {
    if (H.top() >= best) break;
    const cur = H.pop();
    const node = cur >> 2; const dir = cur & 3;
    const gc = g[cur];
    const i = (node / ny) | 0; const j = node - i * ny;
    if (node === goal) {
      const c = gc + (dir === d1 ? 0 : dir === OPP[d1] ? BEND * 4 : BEND);
      if (c < best) { best = c; bestState = cur; }
      continue;
    }
    for (let d = 0; d < 4; d++) {
      if (d === OPP[dir]) continue;
      const ni = i + DX[d]; const nj = j + DY[d];
      if (ni < 0 || nj < 0 || ni >= nx || nj >= ny) continue;
      if (d === 0 ? hB[i * ny + j] : d === 1 ? hB[ni * ny + j] : d === 2 ? vB[i * ny + j] : vB[i * ny + nj]) continue;
      const nn = ni * ny + nj;
      const ns = nn * 4 + d;
      const ng = gc + Math.abs(xs[ni] - xs[i]) + Math.abs(ys[nj] - ys[j]) + (d === dir ? 0 : BEND);
      if (ng < g[ns]) { g[ns] = ng; prev[ns] = cur; H.push(ng + hh(ni, nj), ns); }
    }
  }
  if (bestState < 0) return null;
  const pts = [];
  for (let c = bestState; c >= 0; c = prev[c]) { const n = c >> 2; const i = (n / ny) | 0; pts.push([xs[i], ys[n - i * ny]]); }
  return pts.reverse();
}

/** Drop repeated and collinear points (keeps first and last). */
export function simplify(pts) {
  const out = [];
  for (const p of pts) {
    const a = out[out.length - 1];
    if (a && a[0] === p[0] && a[1] === p[1]) continue;
    const b = out[out.length - 2];
    if (a && b && ((b[0] === a[0] && a[0] === p[0]) || (b[1] === a[1] && a[1] === p[1]))) out[out.length - 1] = p;
    else out.push(p);
  }
  return out;
}

/**
 * Route one edge. Obstacles are all boxes (including both endpoint cards).
 * @param {Box[]} boxes
 * @param {Edge} e  sa/sb fixed when given, else chosen by chooseSides()
 * @returns {{pts: number[][], sa: number, sb: number}}
 */
export function routeEdge(boxes, e) {
  const { A, B } = e;
  let [sa, sb] = chooseSides(A, B);
  if (e.sa) { sa = e.sa; sb = e.sb; }
  const ya = e.ya + (e.dya || 0);
  let yb = e.yb + (e.dyb || 0);
  const xa = sa > 0 ? A.x + A.w : A.x;
  const xb = sb > 0 ? B.x + B.w : B.x;
  if (A === B) {
    if (Math.abs(ya - yb) < 6) yb = ya + 8;
    const lx = xa + sa * STUB;
    return { pts: [[xa, ya], [lx, ya], [lx, yb], [xb, yb]], sa, sb };
  }
  const s = [xa + sa * STUB, ya];
  const t = [xb + sb * STUB, yb];
  const inside = (o, p) => p[0] > o.x1 && p[0] < o.x2 && p[1] > o.y1 && p[1] < o.y2;
  const obs = boxes.map((b) => ({ x1: b.x - M, y1: b.y - M, x2: b.x + b.w + M, y2: b.y + b.h + M })).filter((o) => !inside(o, s) && !inside(o, t));
  const d0 = sa > 0 ? 0 : 1;
  const d1 = sb > 0 ? 1 : 0; // arrival heading towards the card
  let path = null;
  for (const pad of [90, 320, Infinity]) {
    let R;
    if (pad === Infinity) {
      R = [Math.min(s[0], t[0]), Math.min(s[1], t[1]), Math.max(s[0], t[0]), Math.max(s[1], t[1])];
      for (const o of obs) { R[0] = Math.min(R[0], o.x1); R[1] = Math.min(R[1], o.y1); R[2] = Math.max(R[2], o.x2); R[3] = Math.max(R[3], o.y2); }
      R = [R[0] - 40, R[1] - 40, R[2] + 40, R[3] + 40];
    } else {
      R = [Math.min(s[0], t[0]) - pad, Math.min(s[1], t[1]) - pad, Math.max(s[0], t[0]) + pad, Math.max(s[1], t[1]) + pad];
    }
    path = search(obs, s, t, d0, d1, R);
    if (path) break;
  }
  if (!path) { const mx = (s[0] + t[0]) / 2; path = [s, [mx, s[1]], [mx, t[1]], t]; }
  return { pts: simplify([[xa, ya], ...path, [xb, yb]]), sa, sb };
}

/**
 * Route all edges: side choice, anchor spreading, routing, segment nudging.
 * Writes e.sa/e.sb/e.dya/e.dyb back so single-edge reroutes (dragging) stay consistent.
 * @param {Box[]} boxes
 * @param {Edge[]} edges
 * @returns {{pts: number[][], sa: number, sb: number}[]}
 */
export function routeAll(boxes, edges) {
  const groups = new Map();
  const add = (key, item) => { if (!groups.has(key)) groups.set(key, []); groups.get(key).push(item); };
  const ids = new Map(boxes.map((b, i) => [b, i]));
  edges.forEach((e) => {
    [e.sa, e.sb] = chooseSides(e.A, e.B);
    e.dya = 0; e.dyb = 0;
    if (e.A === e.B) return;
    add(`${ids.get(e.A)}|${e.sa}|${Math.round(e.ya)}`, { e, end: 'a', other: e.B.y + e.B.h / 2 });
    add(`${ids.get(e.B)}|${e.sb}|${Math.round(e.yb)}`, { e, end: 'b', other: e.A.y + e.A.h / 2 });
  });
  groups.forEach((list) => {
    if (list.length < 2) return;
    list.sort((p, q) => p.other - q.other);
    const sp = Math.min(5, 16 / (list.length - 1));
    list.forEach((it, i) => { it.e[it.end === 'a' ? 'dya' : 'dyb'] = (i - (list.length - 1) / 2) * sp; });
  });
  const out = edges.map((e) => routeEdge(boxes, e));
  nudge(out, 0);
  nudge(out, 1);
  return out;
}

/** Spread overlapping collinear interior segments (axis 0: vertical segments, 1: horizontal). */
function nudge(routes, ax) {
  const segs = [];
  routes.forEach((r) => {
    const p = r.pts;
    for (let k = 1; k <= p.length - 3; k++) {
      const a = p[k]; const b = p[k + 1];
      if (a[ax] !== b[ax] || (ax === 1 && (k < 2 || k + 1 > p.length - 3))) continue;
      const o = 1 - ax;
      segs.push({ r, k, c: a[ax], lo: Math.min(a[o], b[o]), hi: Math.max(a[o], b[o]) });
    }
  });
  segs.sort((p, q) => p.c - q.c || p.lo - q.lo);
  let i = 0;
  while (i < segs.length) {
    let j = i + 1;
    let hi = segs[i].hi;
    while (j < segs.length && segs[j].c === segs[i].c && segs[j].lo < hi) { hi = Math.max(hi, segs[j].hi); j++; }
    const n = j - i;
    if (n > 1) {
      const sp = Math.min(6, 16 / (n - 1));
      for (let m = i; m < j; m++) {
        const off = (m - i - (n - 1) / 2) * sp;
        const sg = segs[m];
        sg.r.pts[sg.k] = sg.r.pts[sg.k].slice();
        sg.r.pts[sg.k + 1] = sg.r.pts[sg.k + 1].slice();
        sg.r.pts[sg.k][ax] += off;
        sg.r.pts[sg.k + 1][ax] += off;
      }
    }
    i = j;
  }
}

/** SVG path data for an orthogonal polyline with rounded corners. */
export function pathD(pts, radius = 7) {
  const f = (v) => Math.round(v * 10) / 10;
  let d = `M${f(pts[0][0])} ${f(pts[0][1])}`;
  for (let i = 1; i < pts.length - 1; i++) {
    const [px, py] = pts[i - 1]; const [x, y] = pts[i]; const [nx, ny] = pts[i + 1];
    const r = Math.min(radius, Math.hypot(x - px, y - py) / 2, Math.hypot(nx - x, ny - y) / 2);
    const ux = Math.sign(x - px); const uy = Math.sign(y - py);
    const vx = Math.sign(nx - x); const vy = Math.sign(ny - y);
    d += `L${f(x - ux * r)} ${f(y - uy * r)}Q${f(x)} ${f(y)} ${f(x + vx * r)} ${f(y + vy * r)}`;
  }
  const l = pts[pts.length - 1];
  return `${d}L${f(l[0])} ${f(l[1])}`;
}

/**
 * Free spot for a new w×h card: no overlap with existing boxes (plus gap), closest to `target`.
 * @param {Box[]} boxes
 * @returns {[number, number]}
 */
export function freeSpot(boxes, w, h, target, gap = 48) {
  if (!boxes.length) return [0, 0];
  let x1 = Infinity; let y1 = Infinity; let x2 = -Infinity; let y2 = -Infinity;
  boxes.forEach((b) => { x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y); x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h); });
  const tx = target ? target[0] : x2; const ty = target ? target[1] : y1;
  const step = 24;
  let best = null; let bd = Infinity;
  for (let y = y1; y <= y2 + gap; y += step) {
    for (let x = x1; x <= x2 + gap; x += step) {
      const d = Math.hypot(x + w / 2 - tx, y + h / 2 - ty);
      if (d >= bd) continue;
      if (boxes.some((b) => x < b.x + b.w + gap && x + w + gap > b.x && y < b.y + b.h + gap && y + h + gap > b.y)) continue;
      bd = d; best = [x, y];
    }
  }
  return best || [Math.round(x2 + gap * 2), Math.round(y1)];
}
