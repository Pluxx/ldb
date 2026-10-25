import { test } from 'node:test';
import assert from 'node:assert/strict';
import { routeAll, routeEdge, pathD, freeSpot, simplify } from './_erd-route.js';

/** deterministic PRNG */
function rng(seed) {
  let s = seed >>> 0;
  return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 2 ** 32; };
}

/** Does the axis-aligned segment a-b cross the open interior of box? */
function hits(a, b, box) {
  const x1 = Math.min(a[0], b[0]); const x2 = Math.max(a[0], b[0]);
  const y1 = Math.min(a[1], b[1]); const y2 = Math.max(a[1], b[1]);
  return x2 > box.x && x1 < box.x + box.w && y2 > box.y && y1 < box.y + box.h;
}

function check(boxes, edges, routes) {
  routes.forEach((r, i) => {
    const e = edges[i];
    const p = r.pts;
    assert.ok(p.length >= 2);
    for (let k = 0; k + 1 < p.length; k++) {
      const [a, b] = [p[k], p[k + 1]];
      assert.ok(a[0] === b[0] || a[1] === b[1], `edge ${i} segment ${k} not orthogonal`);
      for (const box of boxes) assert.ok(!hits(a, b, box), `edge ${i} segment ${k} crosses card at ${box.x},${box.y}`);
    }
    // ends sit on the card sides
    const A = e.A; const B = e.B;
    assert.ok(p[0][0] === A.x || p[0][0] === A.x + A.w);
    assert.ok(p[p.length - 1][0] === B.x || p[p.length - 1][0] === B.x + B.w);
    assert.ok(p[0][1] >= A.y && p[0][1] <= A.y + A.h);
  });
}

const box = (x, y, w = 180, h = 120) => ({ x, y, w, h });
const edge = (A, B, ra = 1, rb = 0) => ({ A, B, ya: A.y + 30 + ra * 22 + 11, yb: B.y + 30 + rb * 22 + 11 });

test('grid layout: no segment crosses a card', () => {
  const boxes = [];
  for (let r = 0; r < 4; r++) for (let c = 0; c < 4; c++) boxes.push(box(c * 280, r * 200, 180, 100 + ((r * 4 + c) % 3) * 20));
  const R = rng(7);
  const edges = [];
  for (let i = 0; i < 30; i++) {
    const a = boxes[Math.floor(R() * boxes.length)];
    const b = boxes[Math.floor(R() * boxes.length)];
    edges.push(edge(a, b, Math.floor(R() * 2), Math.floor(R() * 2)));
  }
  check(boxes, edges, routeAll(boxes, edges));
});

test('far pair with a wall of cards in between routes around it', () => {
  const A = box(0, 200);
  const B = box(800, 200);
  const wall = [box(400, 0, 160, 180), box(400, 220, 160, 180), box(400, 440, 160, 180)];
  const boxes = [A, B, ...wall];
  const edges = [edge(A, B), edge(B, A, 2, 1)];
  const routes = routeAll(boxes, edges);
  check(boxes, edges, routes);
});

test('overlapping columns (same-side), self references and shared anchors', () => {
  const A = box(0, 0);
  const B = box(40, 300, 220, 160);
  const C = box(500, 100);
  const boxes = [A, B, C];
  const edges = [edge(A, B), edge(B, A, 2, 0), edge(C, C, 1, 0), edge(C, A, 0, 0), edge(B, A, 0, 0), edge(C, B, 2, 0)];
  const routes = routeAll(boxes, edges);
  check(boxes, edges, routes);
  // shared target anchor (A row 0) is spread
  const ends = routes.filter((_, i) => edges[i].B === A && edges[i].yb === A.y + 41).map((r) => r.pts[r.pts.length - 1][1]);
  assert.equal(new Set(ends).size, ends.length);
});

test('random layouts (60 cards) are routed quickly and cleanly', () => {
  for (const seed of [1, 2, 3]) {
    const R = rng(seed);
    const boxes = [];
    let guard = 0;
    while (boxes.length < 60 && guard++ < 5000) {
      const b = box(Math.round(R() * 3200), Math.round(R() * 2200), 160 + Math.round(R() * 80), 60 + Math.round(R() * 200));
      if (boxes.some((o) => b.x < o.x + o.w + 60 && b.x + b.w + 60 > o.x && b.y < o.y + o.h + 60 && b.y + b.h + 60 > o.y)) continue;
      boxes.push(b);
    }
    const edges = [];
    for (let i = 0; i < 80; i++) {
      const a = boxes[Math.floor(R() * boxes.length)];
      const b = boxes[Math.floor(R() * boxes.length)];
      edges.push(edge(a, b, 0, 0));
    }
    const t0 = performance.now();
    const routes = routeAll(boxes, edges);
    const ms = performance.now() - t0;
    check(boxes, edges, routes);
    assert.ok(ms < 3000, `routing took ${ms}ms`);
  }
});

test('single reroute keeps fixed sides', () => {
  const A = box(0, 0); const B = box(400, 0);
  const r = routeEdge([A, B], { ...edge(A, B), sa: 1, sb: -1 });
  assert.equal(r.sa, 1);
  assert.equal(r.pts[0][0], 180);
  assert.equal(r.pts[r.pts.length - 1][0], 400);
});

test('helpers', () => {
  assert.deepEqual(simplify([[0, 0], [5, 0], [10, 0], [10, 0], [10, 5]]), [[0, 0], [10, 0], [10, 5]]);
  assert.match(pathD([[0, 0], [20, 0], [20, 20]]), /^M0 0L13 0Q20 0 20 7L20 20$/);
  const boxes = [box(0, 0), box(300, 0)];
  const [x, y] = freeSpot(boxes, 180, 120, [100, 300]);
  assert.ok(!boxes.some((b) => x < b.x + b.w && x + 180 > b.x && y < b.y + b.h && y + 120 > b.y));
  assert.deepEqual(freeSpot([], 10, 10), [0, 0]);
});
