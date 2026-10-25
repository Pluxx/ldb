/**
 * Pure helpers for the server monitor (no DOM): sample ring buffer, rates from counter deltas
 * (with counter-reset / restart handling) and chart scaling. Unit-tested in _monitor-math.test.mjs.
 * @module views/_monitor-math
 */

/** Fixed-capacity FIFO of samples (oldest dropped first). */
export class Ring {
  constructor(cap) { this.cap = Math.max(1, cap | 0); this.items = []; }
  push(x) { this.items.push(x); if (this.items.length > this.cap) this.items.splice(0, this.items.length - this.cap); return this; }
  get length() { return this.items.length; }
  last() { return this.items[this.items.length - 1]; }
  toArray() { return this.items.slice(); }
  /** Drop samples older than `ts`. */
  prune(ts) { let i = 0; while (i < this.items.length && this.items[i].ts < ts) i++; if (i) this.items.splice(0, i); return this; }
  clear() { this.items = []; }
}

/**
 * Per-second rates of every counter between two snapshots.
 * A counter that went DOWN (stats reset, FLUSH STATUS, pg_stat_reset) yields null for that counter;
 * a lower uptime (server restart) or a non-positive interval marks the whole sample as reset.
 * @param {{ts:number, uptime?:number|null, counters?:object}|null} prev
 * @param {{ts:number, uptime?:number|null, counters?:object}} cur
 * @returns {{dt:number, reset:boolean, rates:Record<string, number|null>, deltas:Record<string, number|null>}}
 */
export function computeRates(prev, cur) {
  const out = { dt: 0, reset: !prev, rates: {}, deltas: {} };
  if (!prev) return out;
  const dt = (cur.ts - prev.ts) / 1000;
  out.dt = dt;
  const restarted = prev.uptime != null && cur.uptime != null && cur.uptime < prev.uptime;
  out.reset = restarted || !(dt > 0);
  const pc = prev.counters || {};
  const cc = cur.counters || {};
  for (const k of Object.keys(cc)) {
    const a = Number(pc[k]);
    const b = Number(cc[k]);
    const d = out.reset || !(k in pc) || !Number.isFinite(a) || !Number.isFinite(b) || b < a ? null : b - a;
    out.deltas[k] = d;
    out.rates[k] = d === null ? null : d / dt;
  }
  return out;
}

/** Hit ratio in % from two counter deltas (null without traffic). */
export function deltaRatio(requests, misses) {
  if (requests == null || misses == null || !(requests > 0)) return null;
  return Math.max(0, Math.min(100, (1 - misses / requests) * 100));
}

/**
 * One chart/KPI point from two consecutive snapshots: rates per second (slow queries per minute),
 * gauges as-is, interval cache hit ratio (falls back to the server's gauge, e.g. SQL Server).
 */
export function derive(prev, cur) {
  const g = cur.gauges || {};
  const r = computeRates(prev, cur);
  const rate = (k) => (k in r.rates ? r.rates[k] : null);
  const hasCacheCounters = cur.counters && 'cache_requests' in cur.counters;
  return {
    ts: cur.ts,
    reset: r.reset,
    qps: rate('queries'),
    select: rate('select'), insert: rate('insert'), update: rate('update'), delete: rate('delete'),
    slow: rate('slow_queries') === null ? null : rate('slow_queries') * 60,
    bytes_in: rate('bytes_in'), bytes_out: rate('bytes_out'),
    connections: g.connections ?? null, running: g.running ?? null,
    idle_in_tx: g.idle_in_tx ?? null, waiting: g.waiting ?? null,
    hit: hasCacheCounters ? deltaRatio(r.deltas.cache_requests, r.deltas.cache_misses) : (g.cache_hit_ratio ?? null),
  };
}

/** Round a step to 1/2/2.5/5 × 10^n. */
export function niceStep(raw) {
  if (!(raw > 0) || !Number.isFinite(raw)) return 1;
  const p = 10 ** Math.floor(Math.log10(raw));
  const f = raw / p;
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
}

/**
 * Clean axis ticks covering [min, max] with about `count` intervals.
 * @returns {{min:number, max:number, step:number, ticks:number[]}}
 */
export function niceTicks(min, max, count = 4) {
  if (!Number.isFinite(min) || !Number.isFinite(max)) { min = 0; max = 1; }
  if (max < min) [min, max] = [max, min];
  if (max === min) { if (max === 0) max = 1; else { const pad = Math.abs(max) * 0.1; min -= pad; max += pad; } }
  const step = niceStep((max - min) / Math.max(1, count));
  const lo = Math.floor(min / step + 1e-9) * step;
  const hi = Math.ceil(max / step - 1e-9) * step;
  const ticks = [];
  for (let v = lo; v <= hi + step / 2; v += step) ticks.push(Math.round(v / step) * step);
  return { min: lo, max: hi, step, ticks };
}

/**
 * Y extent of the given series keys over points (stacked: sum per point). Non-finite values ignored.
 * @param {object[]} points
 * @param {string[]} keys
 * @param {{stacked?: boolean, zero?: boolean}} [o]  zero: include 0 (default true)
 */
export function extent(points, keys, o = {}) {
  let lo = Infinity;
  let hi = -Infinity;
  for (const p of points) {
    if (o.stacked) {
      let s = 0;
      let any = false;
      for (const k of keys) { const v = p[k]; if (Number.isFinite(v)) { s += v; any = true; } }
      if (any) { lo = Math.min(lo, s); hi = Math.max(hi, s); }
    } else {
      for (const k of keys) { const v = p[k]; if (Number.isFinite(v)) { lo = Math.min(lo, v); hi = Math.max(hi, v); } }
    }
  }
  if (lo === Infinity) return null;
  if (o.zero !== false) lo = Math.min(0, lo);
  return [lo, hi];
}

/**
 * Cumulative stack per point: {ts, values:[[y0, y1] | null per key]}. A point with no value for
 * a key keeps the band empty (y0 === y1).
 */
export function stack(points, keys) {
  return points.map((p) => {
    let acc = 0;
    let any = false;
    const values = keys.map((k) => {
      const v = p[k];
      if (!Number.isFinite(v)) return [acc, acc];
      any = true;
      const band = [acc, acc + v];
      acc += v;
      return band;
    });
    return { ts: p.ts, values: any ? values : null };
  });
}

/** Linear scale d0..d1 → r0..r1 (degenerate domains map to the range start). */
export const scale = (d0, d1, r0, r1) => (v) => (d1 === d0 ? r0 : r0 + ((v - d0) / (d1 - d0)) * (r1 - r0));

/** Index of the point whose ts is closest to `ts` (points sorted by ts); -1 when empty. */
export function nearest(points, ts) {
  let lo = 0;
  let hi = points.length - 1;
  if (hi < 0) return -1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (points[mid].ts < ts) lo = mid + 1; else hi = mid;
  }
  if (lo > 0 && Math.abs(points[lo - 1].ts - ts) <= Math.abs(points[lo].ts - ts)) return lo - 1;
  return lo;
}

/**
 * SVG path "d" for a line through points, breaking at null values (gaps) and at resets.
 * @param {object[]} points  sorted by ts
 * @param {(p: object) => number|null} y  value accessor
 * @param {(ts: number) => number} sx
 * @param {(v: number) => number} sy
 */
export function linePath(points, y, sx, sy) {
  let d = '';
  let pen = false;
  for (const p of points) {
    const v = y(p);
    if (!Number.isFinite(v) || p.reset) { pen = false; continue; }
    d += `${pen ? 'L' : 'M'}${sx(p.ts).toFixed(1)},${sy(v).toFixed(1)}`;
    pen = true;
  }
  return d;
}

/** Area path between y0(p) and y1(p) (closed per contiguous run). */
export function areaPath(points, y0, y1, sx, sy) {
  let d = '';
  let run = [];
  const flush = () => {
    if (run.length > 1) {
      d += run.map((p, i) => `${i ? 'L' : 'M'}${sx(p.ts).toFixed(1)},${sy(y1(p)).toFixed(1)}`).join('');
      for (let i = run.length - 1; i >= 0; i--) d += `L${sx(run[i].ts).toFixed(1)},${sy(y0(run[i])).toFixed(1)}`;
      d += 'Z';
    }
    run = [];
  };
  for (const p of points) {
    if (!Number.isFinite(y1(p)) || !Number.isFinite(y0(p)) || p.reset) flush(); else run.push(p);
  }
  flush();
  return d;
}

/** min / max / last of a key over points (null when no values). */
export function summary(points, key) {
  let min = Infinity;
  let max = -Infinity;
  let last = null;
  for (const p of points) {
    const v = p[key];
    if (!Number.isFinite(v)) continue;
    min = Math.min(min, v); max = Math.max(max, v); last = v;
  }
  return last === null ? null : { min, max, last };
}
