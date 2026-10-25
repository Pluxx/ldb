/**
 * Lightweight inline-SVG time-series chart for the server monitor: one y-axis, hairline grid,
 * 2px lines (single series: 10 % area wash; stacked: banded areas), end dots with a surface
 * ring, crosshair + tooltip on pointer / touch / arrow keys, legend with current values for
 * ≥ 2 series, and a visually hidden data summary. The plot is always LTR (time runs left→right),
 * titles and legend follow the page direction. Colours come from --mon-1…4 (app.css).
 * @module views/_monitor-chart
 */
import { h, s, setChildren } from '../ui/dom.js';
import { t, fmtDate } from '../i18n/index.js';
import { niceTicks, extent, stack, scale, nearest, linePath, areaPath, summary } from './_monitor-math.js';

const H = 168;
const MARGIN = { t: 10, r: 12, b: 22 };
const TIME_STEPS = [10, 15, 30, 60, 120, 300, 600, 900, 1800].map((x) => x * 1000);

/**
 * @param {object} o
 * @param {string} o.title
 * @param {{key: string, label: string, color: string}[]} o.series
 * @param {(v: number) => string} o.fmt        value formatter (tooltip, legend, axis)
 * @param {boolean} [o.stacked]
 * @param {boolean} [o.zero]                   include 0 in the y domain (default true)
 * @param {number} [o.max]                     clamp the y domain (e.g. 100 for %)
 * @param {number} [o.minSpan]                 minimum y span (ratio charts)
 */
export function timeChart(o) {
  const M = { ...MARGIN, l: 40 };
  const single = o.series.length === 1;
  const current = h('span', { class: 'ms-auto text-[13px] font-semibold tabular-nums' });
  const legend = single ? null : h('ul', { class: 'flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted' });
  const svg = s('svg', { class: 'block w-full touch-pan-y outline-none focus-visible:ring-2 focus-visible:ring-focus rounded', height: H, tabindex: '0', role: 'img', 'aria-label': o.title });
  const tip = h('div', { class: 'pointer-events-none absolute top-1 z-10 hidden min-w-32 rounded-md border border-border bg-surface px-2.5 py-1.5 text-xs shadow', role: 'presentation' });
  const sr = h('p', { class: 'sr-only' });
  const wrap = h('div', { class: 'relative', dir: 'ltr' }, svg, tip);
  const el = h('figure', { class: 'card min-w-0 space-y-2 p-3 sm:p-4' },
    h('figcaption', { class: 'flex items-baseline gap-2' }, h('h3', { class: 'truncate text-[13px] font-medium text-muted' }, o.title), single ? current : null),
    legend, wrap, sr);

  let pts = [];
  let t0 = 0;
  let t1 = 1;
  let width = 600;
  let hover = -1;
  let hoverTs = null;
  let geo = null;

  const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(() => { const w = wrap.clientWidth; if (w && Math.abs(w - width) > 1) { width = w; draw(); } }) : null;
  ro?.observe(wrap);

  const valueOf = (p, k) => (Number.isFinite(p[k]) ? p[k] : null);

  function draw() {
    width = wrap.clientWidth || width;
    const W = width;
    const visible = pts.filter((p) => p.ts >= t0 - 30000);
    const keys = o.series.map((x) => x.key);
    const ext = extent(visible, keys, { stacked: o.stacked, zero: o.zero !== false });
    const nodes = [];
    if (!ext) {
      geo = null;
      nodes.push(s('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'fill-subtle text-[12px]' }, t(pts.length > 1 ? 'monitor.chart.noData' : 'monitor.chart.collecting')));
      setChildren(svg, nodes);
      svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
      return;
    }
    let [lo, hi] = ext;
    if (o.max !== undefined) hi = Math.min(o.max, hi);
    // Keep tiny fluctuations (e.g. 99.97 → 99.99 %) from filling the whole plot.
    if (o.minSpan && hi - lo < o.minSpan) lo = hi - o.minSpan;
    const yt = niceTicks(lo, hi, 4);
    if (o.max !== undefined) yt.max = Math.min(yt.max, o.max);
    const ticks = yt.ticks.filter((v) => v <= yt.max + 1e-9);
    const labels = ticks.map((v) => o.fmt(v, true));
    // Left margin fits the longest tick label (≈ 6 px per glyph at 10.5 px).
    M.l = Math.max(30, Math.max(...labels.map((x) => x.length)) * 6 + 10);
    const sx = scale(t0, t1, M.l, W - M.r);
    const sy = scale(yt.min, yt.max, H - M.b, M.t);
    geo = { sx, sy, visible };
    hover = hoverTs === null ? -1 : nearest(visible, hoverTs);

    for (const [i, v] of ticks.entries()) {
      const y = sy(v).toFixed(1);
      nodes.push(s('line', { x1: M.l, x2: W - M.r, y1: y, y2: y, class: v === yt.min ? 'stroke-border-strong' : 'stroke-border', 'stroke-width': 1, 'shape-rendering': 'crispEdges' }));
      nodes.push(s('text', { x: M.l - 6, y, dy: '0.32em', 'text-anchor': 'end', class: 'fill-subtle text-[10.5px] tabular-nums' }, labels[i]));
    }
    const span = t1 - t0;
    const step = TIME_STEPS.find((x) => span / x <= Math.max(2, Math.floor(W / 90))) || 3600000;
    const withSec = step < 60000;
    for (let ts = Math.ceil(t0 / step) * step; ts <= t1; ts += step) {
      const x = sx(ts);
      if (x < M.l + 12 || x > W - M.r - 12) continue;
      nodes.push(s('text', { x: x.toFixed(1), y: H - 6, 'text-anchor': 'middle', class: 'fill-subtle text-[10.5px] tabular-nums' },
        fmtDate(ts, withSec ? { hour: '2-digit', minute: '2-digit', second: '2-digit' } : { hour: '2-digit', minute: '2-digit' })));
    }

    if (o.stacked) {
      const st = stack(visible, keys);
      const bands = visible.map((p, i) => ({ ts: p.ts, reset: p.reset, b: st[i].values }));
      o.series.forEach((ser, i) => {
        // Zero-height bands are skipped so an empty series never paints over the one below.
        const on = (p) => p.b && p.b[i][1] > p.b[i][0];
        const y0 = (p) => (on(p) ? p.b[i][0] : null);
        const y1 = (p) => (on(p) ? p.b[i][1] : null);
        nodes.push(s('path', { d: areaPath(bands, y0, y1, sx, sy), fill: ser.color, 'fill-opacity': 0.28 }));
        nodes.push(s('path', { d: linePath(bands, y1, sx, sy), fill: 'none', stroke: ser.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
      });
    } else {
      o.series.forEach((ser) => {
        if (single) nodes.push(s('path', { d: areaPath(visible, () => Math.max(yt.min, 0), (p) => valueOf(p, ser.key), sx, sy), fill: ser.color, 'fill-opacity': 0.1 }));
        nodes.push(s('path', { d: linePath(visible, (p) => valueOf(p, ser.key), sx, sy), fill: 'none', stroke: ser.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
      });
    }
    // End dots (surface ring keeps them legible where lines cross).
    const last = [...visible].reverse().find((p) => keys.some((k) => valueOf(p, k) !== null));
    if (last && !o.stacked) {
      o.series.forEach((ser) => {
        const v = valueOf(last, ser.key);
        if (v !== null) nodes.push(s('circle', { cx: sx(last.ts).toFixed(1), cy: sy(Math.min(v, yt.max)).toFixed(1), r: 4, fill: ser.color, class: 'stroke-surface', 'stroke-width': 2 }));
      });
    }
    if (hover >= 0 && visible[hover]) {
      const p = visible[hover];
      const x = sx(p.ts).toFixed(1);
      nodes.push(s('line', { x1: x, x2: x, y1: M.t, y2: H - M.b, class: 'stroke-muted', 'stroke-width': 1 }));
      const st = o.stacked ? stack([p], keys)[0].values : null;
      o.series.forEach((ser, i) => {
        const v = o.stacked ? (st ? st[i][1] : null) : valueOf(p, ser.key);
        if (v !== null && valueOf(p, ser.key) !== null) nodes.push(s('circle', { cx: x, cy: sy(Math.min(v, yt.max)).toFixed(1), r: 4, fill: ser.color, class: 'stroke-surface', 'stroke-width': 2 }));
      });
    }
    setChildren(svg, nodes);
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    drawTip();
  }

  function drawTip() {
    const p = geo && hover >= 0 ? geo.visible[hover] : null;
    if (!p) { tip.classList.add('hidden'); return; }
    setChildren(tip,
      h('div', { class: 'mb-1 text-subtle tabular-nums' }, fmtDate(p.ts, { hour: '2-digit', minute: '2-digit', second: '2-digit' })),
      o.series.map((ser) => h('div', { class: 'flex items-center gap-2', dir: 'auto' },
        h('span', { class: 'inline-block h-0.5 w-3 shrink-0 rounded', style: { background: ser.color } }),
        h('span', { class: 'font-semibold tabular-nums' }, valueOf(p, ser.key) === null ? '—' : o.fmt(p[ser.key])),
        h('span', { class: 'text-muted' }, ser.label))));
    tip.classList.remove('hidden');
    const x = geo.sx(p.ts);
    const w = tip.offsetWidth || 140;
    tip.style.left = `${Math.max(0, Math.min(width - w, x > width / 2 ? x - w - 10 : x + 10))}px`;
  }

  function setHover(i) {
    if (i === hover) return;
    hoverTs = i < 0 || !geo || !geo.visible[i] ? null : geo.visible[i].ts;
    draw();
  }

  const onPointer = (e) => {
    if (!geo || !geo.visible.length) return;
    const r = svg.getBoundingClientRect();
    const x = ((e.clientX - r.left) / r.width) * width;
    const ts = t0 + ((x - M.l) / (width - M.l - M.r)) * (t1 - t0);
    setHover(nearest(geo.visible, ts));
  };
  svg.addEventListener('pointermove', onPointer);
  svg.addEventListener('pointerdown', onPointer);
  svg.addEventListener('pointerleave', () => setHover(-1));
  svg.addEventListener('blur', () => setHover(-1));
  svg.addEventListener('keydown', (e) => {
    const n = geo?.visible.length || 0;
    if (!n) return;
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      const base = hover < 0 ? n - 1 : hover + (e.key === 'ArrowLeft' ? -1 : 1);
      setHover(Math.max(0, Math.min(n - 1, base)));
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault();
      setHover(e.key === 'Home' ? 0 : n - 1);
    } else if (e.key === 'Escape') setHover(-1);
  });

  if (legend) setChildren(legend, o.series.map((ser) => {
      ser.valueEl = h('span', { class: 'font-semibold tabular-nums text-fg' }, '—');
      return h('li', { class: 'flex items-center gap-1.5' },
        o.stacked
          ? h('span', { class: 'inline-block size-2.5 shrink-0 rounded-sm', style: { background: ser.color } })
          : h('span', { class: 'inline-block h-0.5 w-3.5 shrink-0 rounded', style: { background: ser.color } }),
        h('span', { dir: 'auto' }, ser.label), ser.valueEl);
    }));

  return {
    el,
    /** Redraw with the given points (sorted by ts) for the window [from, to] (ms). */
    update(points, from, to) {
      pts = points;
      t0 = from;
      t1 = to;
      draw();
      const parts = [];
      for (const ser of o.series) {
        const sm = summary(pts.filter((p) => p.ts >= from), ser.key);
        if (legend) ser.valueEl.textContent = sm ? o.fmt(sm.last) : '—';
        if (sm) parts.push(t('monitor.chart.summary', { series: ser.label, last: o.fmt(sm.last), min: o.fmt(sm.min), max: o.fmt(sm.max) }));
      }
      if (single) { const sm = summary(pts, o.series[0].key); current.textContent = sm ? o.fmt(sm.last) : ''; }
      sr.textContent = parts.length ? `${o.title}. ${parts.join(' ')}` : t('monitor.chart.collecting');
    },
    destroy() { ro?.disconnect(); },
  };
}
