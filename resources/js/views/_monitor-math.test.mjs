import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Ring, computeRates, deltaRatio, derive, niceStep, niceTicks, extent, stack, scale, nearest, linePath, areaPath, summary } from './_monitor-math.js';

const snap = (ts, uptime, counters, gauges = {}) => ({ ts, uptime, counters, gauges });

test('Ring keeps the newest items and prunes by time', () => {
  const r = new Ring(3);
  [1, 2, 3, 4, 5].forEach((ts) => r.push({ ts }));
  assert.deepEqual(r.toArray().map((x) => x.ts), [3, 4, 5]);
  assert.equal(r.last().ts, 5);
  r.prune(4);
  assert.deepEqual(r.toArray().map((x) => x.ts), [4, 5]);
  r.clear();
  assert.equal(r.length, 0);
});

test('computeRates: per-second deltas', () => {
  const a = snap(1000, 100, { queries: 100, bytes_in: 0 });
  const b = snap(3000, 102, { queries: 160, bytes_in: 2048, new_one: 5 });
  const r = computeRates(a, b);
  assert.equal(r.dt, 2);
  assert.equal(r.reset, false);
  assert.equal(r.rates.queries, 30);
  assert.equal(r.rates.bytes_in, 1024);
  assert.equal(r.rates.new_one, null); // not in the previous sample
  assert.equal(r.deltas.queries, 60);
});

test('computeRates: counter reset gives null for that counter only', () => {
  const r = computeRates(snap(0, 100, { queries: 500, select: 10 }), snap(5000, 105, { queries: 20, select: 20 }));
  assert.equal(r.reset, false);
  assert.equal(r.rates.queries, null);
  assert.equal(r.rates.select, 2);
});

test('computeRates: restart (uptime went down) or bad interval resets everything', () => {
  const r = computeRates(snap(0, 1000, { queries: 5 }), snap(2000, 3, { queries: 9 }));
  assert.equal(r.reset, true);
  assert.equal(r.rates.queries, null);
  assert.equal(computeRates(snap(2000, 1, { q: 1 }), snap(2000, 1, { q: 2 })).reset, true);
  assert.equal(computeRates(null, snap(0, 1, { q: 1 })).reset, true);
});

test('deltaRatio and derive', () => {
  assert.equal(deltaRatio(1000, 10), 99);
  assert.equal(deltaRatio(0, 0), null);
  assert.equal(deltaRatio(null, 1), null);
  const a = snap(0, 10, { queries: 0, slow_queries: 0, cache_requests: 100, cache_misses: 10 }, { connections: 4 });
  const b = snap(10000, 20, { queries: 50, slow_queries: 2, cache_requests: 1100, cache_misses: 20 }, { connections: 5, running: 2 });
  const p = derive(a, b);
  assert.equal(p.qps, 5);
  assert.ok(Math.abs(p.slow - 12) < 1e-9); // 2 per 10 s → 12 per minute
  assert.equal(p.hit, 99);
  assert.equal(p.connections, 5);
  assert.equal(p.running, 2);
  assert.equal(p.bytes_in, null);
  // No cache counters (SQL Server): the gauge is used.
  assert.equal(derive(null, snap(0, 1, { queries: 1 }, { cache_hit_ratio: 97.5 })).hit, 97.5);
  assert.equal(derive(null, b).qps, null);
});

test('niceStep / niceTicks', () => {
  assert.equal(niceStep(0.37), 0.5);
  assert.equal(niceStep(7), 10);
  assert.equal(niceStep(2.2), 2.5);
  assert.equal(niceStep(0), 1);
  assert.deepEqual(niceTicks(0, 87, 4), { min: 0, max: 100, step: 25, ticks: [0, 25, 50, 75, 100] });
  assert.deepEqual(niceTicks(0, 0).ticks, [0, 0.25, 0.5, 0.75, 1]);
  const t = niceTicks(99.2, 99.9, 4);
  assert.ok(t.min <= 99.2 && t.max >= 99.9);
  assert.equal(t.ticks[0], t.min);
  const flat = niceTicks(50, 50);
  assert.ok(flat.min < 50 && flat.max > 50);
  assert.deepEqual(niceTicks(NaN, NaN).ticks, [0, 0.25, 0.5, 0.75, 1]);
});

test('extent and stack', () => {
  const pts = [{ ts: 1, a: 1, b: 2 }, { ts: 2, a: null, b: 5 }, { ts: 3, a: 3, b: 1 }];
  assert.deepEqual(extent(pts, ['a', 'b']), [0, 5]);
  assert.deepEqual(extent(pts, ['a', 'b'], { zero: false }), [1, 5]);
  assert.deepEqual(extent(pts, ['a', 'b'], { stacked: true }), [0, 5]);
  assert.equal(extent([{ ts: 1, a: null }], ['a']), null);
  const s = stack(pts, ['a', 'b']);
  assert.deepEqual(s[0].values, [[0, 1], [1, 3]]);
  assert.deepEqual(s[1].values, [[0, 0], [0, 5]]);
  assert.equal(stack([{ ts: 1 }], ['a'])[0].values, null);
});

test('scale, nearest, paths and summary', () => {
  const sx = scale(0, 10, 0, 100);
  assert.equal(sx(5), 50);
  assert.equal(scale(1, 1, 7, 9)(3), 7);
  const pts = [{ ts: 0, v: 1 }, { ts: 10, v: null }, { ts: 20, v: 3 }, { ts: 30, v: 4, reset: true }, { ts: 40, v: 2 }, { ts: 50, v: 5 }];
  assert.equal(nearest(pts, 14), 1);
  assert.equal(nearest(pts, 16), 2);
  assert.equal(nearest(pts, -5), 0);
  assert.equal(nearest(pts, 99), 5);
  assert.equal(nearest([], 1), -1);
  const id = (x) => x;
  assert.equal(linePath(pts, (p) => p.v, id, id), 'M0.0,1.0M20.0,3.0M40.0,2.0L50.0,5.0');
  assert.equal(areaPath(pts, () => 0, (p) => p.v, id, id), 'M40.0,2.0L50.0,5.0L50.0,0.0L40.0,0.0Z');
  assert.deepEqual(summary(pts, 'v'), { min: 1, max: 5, last: 5 });
  assert.equal(summary([{ ts: 1 }], 'v'), null);
});
