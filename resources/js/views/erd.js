/**
 * Database ER diagram (db.erd), rendered as plain SVG without libraries.
 *
 * - table cards (PK / FK / nullable markers, muted types), relations as smooth paths attached
 *   to the exact column rows with crow's-foot ends (many) and bars (one / exactly one)
 * - automatic layout: connected components are layered by FK dependency (referenced tables to
 *   the left), ordered with barycenter sweeps, tall layers wrapped; components are shelf-packed
 * - pan (drag background / one finger), zoom (wheel, pinch, buttons, keyboard), fit to screen
 * - drag tables (positions + viewport persisted per connection/database in localStorage);
 *   only the dragged card and its edges are touched per animation frame
 * - hover / focus highlights connected tables and relations; click / Enter opens a table menu
 * - keyboard: tables are the tab stops (in layout order); R / Shift+R on a table cycles through its
 *   relations (Esc goes back); a visually hidden summary + relation list is the text alternative
 * - find table, "keys only" toggle, export as SVG or PNG (canvas from the serialized SVG)
 * - relations routed orthogonally around the cards (views/_erd-route.js); while dragging only the
 *   moved card's edges are rerouted, everything on drop
 * - editing (writable connections): drag a column handle onto another column / table header (or
 *   "link mode": tap source, tap target) to add a foreign key; relation menu with details, edit,
 *   drop; table menu with add column, rename, drop. All changes go through the SQL review dialog
 *   (views/_table-editor.js, loaded on demand); the diagram reloads keeping card positions.
 * The canvas is always LTR; the toolbar follows the page direction.
 */
import { h, s, downloadText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openMenu, bindMenu } from '../ui/menu.js';
import { emptyState, errorState, spinner } from '../ui/feedback.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { t, plural } from '../i18n/index.js';
import { api } from '../api.js';
import { storage } from '../store.js';
import { invalidate } from '../data.js';
import { href, navigate } from '../router.js';
import { routeAll, routeEdge, pathD, freeSpot } from './_erd-route.js';

const HEAD = 30;
const ROW = 22;
const PAD = 10;
const MIN_W = 168;
const MAX_W = 300;
const GAP_X = 96;
const GAP_Y = 32;
const ZMIN = 0.1;
const ZMAX = 2.5;
const SVGNS = 'http://www.w3.org/2000/svg';

/* Scoped stylesheet; var() tokens are resolved to literal colours for exports. */
const CSS = `
.erd{font-family:var(--o-font-sans)}
.erd-bg{fill:var(--o-bg)}
.erd-card{fill:var(--o-surface);stroke:var(--o-border-strong);stroke-width:1}
.erd-head{fill:var(--o-surface-2)}
.erd-sep{stroke:var(--o-border);stroke-width:1}
.erd-title{font-size:12.5px;font-weight:600;fill:var(--o-fg)}
.erd-col{font-size:12px;fill:var(--o-fg)}
.erd-pkc{font-weight:600}
.erd-type{font-family:var(--o-font-mono);font-size:10.5px;fill:var(--o-muted)}
.erd-null{font-family:var(--o-font-mono);font-size:10.5px;fill:var(--o-warning)}
.erd-more{font-size:11px;fill:var(--o-muted);font-style:italic}
.erd-pk{color:var(--o-warning)}
.erd-fk{color:var(--o-accent)}
.erd-rowbg{fill:transparent}
.erd-r.is-hl .erd-rowbg{fill:var(--o-accent-soft)}
.erd-edge{fill:none;stroke:var(--o-subtle);stroke-width:1.4;stroke-linecap:round}
.erd-hit{fill:none;stroke:transparent;stroke-width:14;pointer-events:stroke}
.erd-e{cursor:pointer}
.erd-e:focus{outline:none}
.erd-e:focus-visible .erd-edge{stroke:var(--o-accent);stroke-width:3.2;stroke-dasharray:6 3}
.erd-hd{opacity:0;cursor:crosshair;transition:opacity .12s}
.erd-hh{fill:transparent}
.erd-h{fill:var(--o-surface);stroke:var(--o-accent);stroke-width:1.5}
.erd-t:hover .erd-hd,.erd-t:focus-within .erd-hd,.erd.is-link .erd-hd{opacity:1}
.erd-r.is-drop .erd-rowbg,.erd-r.is-src .erd-rowbg{fill:var(--o-accent-soft)}
.erd-r.is-src .erd-h{fill:var(--o-accent)}
.erd-t.is-drop .erd-card{stroke:var(--o-accent);stroke-width:2;stroke-dasharray:5 3}
.erd-tmp{fill:none;stroke:var(--o-accent);stroke-width:1.6;stroke-dasharray:5 4;pointer-events:none}
.erd-t,.erd-e{transition:opacity .12s}
.erd-t{cursor:grab}
.erd-t:focus{outline:none}
.erd-t.is-hl .erd-card{stroke:var(--o-accent);stroke-width:2}
.erd-t:focus-visible .erd-card{stroke:var(--o-accent);stroke-width:3.5}
.erd-e.is-hl .erd-edge{stroke:var(--o-accent);stroke-width:2.2}
.erd.is-dim .erd-t:not(.is-hl){opacity:.32}
.erd.is-dim .erd-e:not(.is-hl){opacity:.12}
`;

let cleanup = [];


/** Text measurement through a shared 2D canvas context. */
let mctx = null;
function measure(text, font) {
  if (!mctx) mctx = document.createElement('canvas').getContext('2d');
  mctx.font = font;
  return mctx.measureText(text).width;
}
function fit(text, font, max) {
  if (measure(text, font) <= max) return text;
  let lo = 0;
  let hi = text.length;
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1;
    if (measure(`${text.slice(0, mid)}…`, font) <= max) lo = mid; else hi = mid - 1;
  }
  return `${text.slice(0, lo)}…`;
}

/**
 * Automatic layout.
 * @param {string[]} names
 * @param {{from_table: string, to_table: string}[]} rels
 * @param {Map<string, {w: number, h: number}>} size
 * @returns {Map<string, [number, number]>}
 */
export function autoLayout(names, rels, size) {
  const parents = new Map(names.map((n) => [n, new Set()]));
  const adj = new Map(names.map((n) => [n, new Set()]));
  for (const r of rels) {
    if (r.from_table === r.to_table || !adj.has(r.from_table) || !adj.has(r.to_table)) continue;
    parents.get(r.from_table).add(r.to_table);
    adj.get(r.from_table).add(r.to_table);
    adj.get(r.to_table).add(r.from_table);
  }
  const comps = [];
  const seen = new Set();
  for (const n of names) {
    if (seen.has(n)) continue;
    const stack = [n];
    const c = [];
    seen.add(n);
    while (stack.length) {
      const x = stack.pop();
      c.push(x);
      for (const y of adj.get(x)) if (!seen.has(y)) { seen.add(y); stack.push(y); }
    }
    comps.push(c);
  }

  const blocks = comps.map((c) => {
    // layer = longest path to a referenced root (cycle-safe)
    const layer = new Map();
    const visiting = new Set();
    const depth = (n) => {
      if (layer.has(n)) return layer.get(n);
      if (visiting.has(n)) return 0;
      visiting.add(n);
      let d = 0;
      for (const p of parents.get(n)) d = Math.max(d, depth(p) + 1);
      visiting.delete(n);
      layer.set(n, d);
      return d;
    };
    c.forEach(depth);
    const L = [];
    for (const n of [...c].sort()) (L[layer.get(n)] ||= []).push(n);
    for (let i = 0; i < L.length; i++) L[i] ||= [];
    // barycenter ordering sweeps
    const bary = (layerArr, ref) => {
      const idx = new Map(ref.map((n, i) => [n, i]));
      const cur = new Map(layerArr.map((n, i) => [n, i]));
      const val = (n) => {
        const ns = [...adj.get(n)].filter((x) => idx.has(x));
        return ns.length ? ns.reduce((a, x) => a + idx.get(x), 0) / ns.length : cur.get(n);
      };
      const v = new Map(layerArr.map((n) => [n, val(n)]));
      layerArr.sort((a, b) => v.get(a) - v.get(b) || cur.get(a) - cur.get(b));
    };
    for (let it = 0; it < 4; it++) {
      for (let d = 1; d < L.length; d++) bary(L[d], L[d - 1]);
      for (let d = L.length - 2; d >= 0; d--) bary(L[d], L[d + 1]);
    }
    // wrap tall layers into several columns
    const area = c.reduce((a, n) => a + (size.get(n).w + GAP_X) * (size.get(n).h + GAP_Y), 0);
    const maxH = Math.max(640, Math.sqrt(area) * 1.1);
    const cols = [];
    for (const layerArr of L) {
      let col = [];
      let hgt = 0;
      for (const n of layerArr) {
        const hh = size.get(n).h;
        if (col.length && hgt + hh > maxH) { cols.push(col); col = []; hgt = 0; }
        col.push(n);
        hgt += hh + GAP_Y;
      }
      if (col.length) cols.push(col);
    }
    // vertical placement: align each card with its already placed neighbours, then push
    // down to resolve overlaps (keeps relation lines short and mostly horizontal)
    const pos = new Map();
    let x = 0;
    for (const col of cols) {
      let w = 0;
      let bottom = -Infinity;
      for (const n of col) {
        const { h: hh } = size.get(n);
        const ns = [...adj.get(n)].filter((m) => pos.has(m));
        const want = ns.length ? ns.reduce((a, m) => a + pos.get(m)[1] + size.get(m).h / 2, 0) / ns.length - hh / 2 : 0;
        const y = Math.max(want, bottom + GAP_Y);
        pos.set(n, [x, y]);
        bottom = y + hh;
        w = Math.max(w, size.get(n).w);
      }
      x += w + GAP_X;
    }
    const minY = Math.min(...[...pos.values()].map((p) => p[1]));
    let H = 0;
    pos.forEach((p, n) => { p[1] -= minY; H = Math.max(H, p[1] + size.get(n).h); });
    return { n: c.length, w: x - GAP_X, h: H, pos };
  });

  // shelf-pack components: big ones first, single tables last (by height)
  blocks.sort((a, b) => (b.n > 1) - (a.n > 1) || b.n - a.n || b.h - a.h);
  const total = blocks.reduce((a, b) => a + (b.w + GAP_X) * (b.h + GAP_Y * 2), 0);
  const maxW = Math.max(Math.sqrt(total * 1.9), ...blocks.map((b) => b.w));
  const out = new Map();
  let x = 0;
  let y = 0;
  let shelf = 0;
  for (const b of blocks) {
    if (x > 0 && x + b.w > maxW) { x = 0; y += shelf + GAP_Y * 2; shelf = 0; }
    for (const [n, [px, py]] of b.pos) out.set(n, [Math.round(x + px), Math.round(y + py)]);
    x += b.w + GAP_X;
    shelf = Math.max(shelf, b.h);
  }
  return out;
}

export default {
  title: (ctx) => `${t('nav.erd')} · ${ctx.db}`,

  async render(el, ctx, opts = {}) {
    cleanup = [];
    const self = this;
    const { conn, db, schema, caps = {} } = ctx;
    const canEdit = !ctx.readOnly;
    const canFk = canEdit && caps.foreign_keys !== false;
    const base = { conn, db, schema };
    const storeKey = `erd.${conn}.${db}${schema ? `@${schema}` : ''}`;
    const saved = storage.get(storeKey, {}) || {};
    let keysOnly = !!storage.get('erd.keysOnly', false);

    const body = h('div', { class: 'relative min-h-0 flex-1 overflow-hidden bg-bg', dir: 'ltr' }, h('div', { class: 'flex h-full items-center justify-center' }, spinner()));
    const titleBar = h('div', { class: 'flex min-w-0 items-center gap-2 max-sm:hidden' },
      icon('network', 'text-muted'), h('h1', { class: 'truncate text-[15px] font-semibold', dir: 'auto' }, t('nav.erd')),
      h('a', { href: href.db(conn, db, schema), class: 'truncate text-[13px] text-muted hover:text-fg', dir: 'auto' }, schema ? `${db}.${schema}` : db));
    const stats = h('span', { class: 'hidden whitespace-nowrap text-xs text-muted lg:inline' });
    const toolbar = h('div', { class: 'flex flex-wrap items-center gap-1.5 border-b border-border bg-surface px-3 py-2', role: 'toolbar', 'aria-label': t('nav.erd') }, titleBar, stats);
    el.replaceChildren(toolbar, body);

    let data;
    try {
      data = opts.data || await api('db.erd', { conn, db, schema });
    } catch (e) {
      body.replaceChildren(errorState(e, { onRetry: () => this.render(el, ctx) }));
      return;
    }
    const tables = data.tables || [];
    const rels = (data.relations || []).filter((r) => tables.some((x) => x.name === r.from_table) && tables.some((x) => x.name === r.to_table));
    stats.textContent = `${plural('erd.tables', tables.length)} · ${plural('erd.relations', rels.length)}`;
    if (!tables.length) {
      body.replaceChildren(emptyState({ icon: 'network', title: t('erd.empty'), actions: !canEdit ? [] : [h('a', { href: href.createTable(conn, db, schema), class: 'btn btn-primary btn-sm' }, icon('plus'), t('db.createTable'))] }));
      return;
    }

    // ----- fonts & sizes
    const rootCs = getComputedStyle(document.documentElement);
    const sans = rootCs.getPropertyValue('--o-font-sans').trim() || 'sans-serif';
    const mono = rootCs.getPropertyValue('--o-font-mono').trim() || 'monospace';
    const F_HEAD = `600 12.5px ${sans}`;
    const F_COL = `12px ${sans}`;
    const F_PK = `600 12px ${sans}`;
    const F_TYPE = `10.5px ${mono}`;

    const fkCols = new Map(tables.map((x) => [x.name, new Set()]));
    rels.forEach((r) => r.from_columns.forEach((c) => fkCols.get(r.from_table).add(c)));
    const refCols = new Map(tables.map((x) => [x.name, new Set()]));
    rels.forEach((r) => r.to_columns.forEach((c) => refCols.get(r.to_table).add(c)));

    /** @type {Map<string, any>} */
    const nodes = new Map();
    for (const tb of tables) {
      const fk = fkCols.get(tb.name);
      let w = measure(tb.name, F_HEAD) + PAD * 2 + 8;
      for (const c of tb.columns) w = Math.max(w, measure(c.name, c.primary ? F_PK : F_COL) + measure(c.type + (c.nullable ? ' ?' : ''), F_TYPE) + PAD * 2 + 28);
      nodes.set(tb.name, { tb, fk, w: Math.round(Math.min(MAX_W, Math.max(MIN_W, w))), x: 0, y: 0, h: 0, rows: new Map(), edges: [], g: null });
    }
    const shown = (n) => (keysOnly ? n.tb.columns.filter((c) => c.primary || n.fk.has(c.name) || refCols.get(n.tb.name).has(c.name)) : n.tb.columns);
    const heightOf = (n) => {
      const vis = shown(n).length;
      return HEAD + vis * ROW + (vis < n.tb.columns.length ? ROW : 0) + 6;
    };

    // ----- svg skeleton
    const edgeLayer = s('g', { class: 'erd-edges' });
    const tableLayer = s('g', { class: 'erd-tables' });
    const viewport = s('g', {}, edgeLayer, tableLayer);
    const sumId = `erd-sum-${Date.now().toString(36)}`;
    const svg = s('svg', {
      class: 'erd absolute inset-0 block size-full touch-none select-none outline-none', tabindex: '0', role: 'application',
      'aria-label': t('erd.canvasLabel', { db }), 'aria-describedby': sumId,
    }, s('style', {}, CSS), viewport);
    // text alternative: counts + key help, and every relation as a list (also read in browse mode)
    const relList = h('ul', {}, rels.map((r) => h('li', {}, `${r.from_table}.${r.from_columns.join(', ')} → ${r.to_table}.${r.to_columns.join(', ')}${r.name ? ` (${r.name})` : ''}`)));
    const summary = h('div', { class: 'sr-only' },
      h('p', { id: sumId }, `${t('a11y.erdSummary', { tables: plural('erd.tables', tables.length), relations: plural('erd.relations', rels.length) })} ${t('a11y.erdKeys')}`),
      rels.length ? h('h2', {}, t('a11y.erdRelations')) : null, rels.length ? relList : null);
    const zoomLabel = h('span', { class: 'w-11 text-center text-xs tabular-nums text-muted max-sm:hidden', 'aria-live': 'polite', title: t('erd.zoomLevel') });
    const legend = h('div', { class: 'pointer-events-none absolute bottom-3 start-3 hidden items-center gap-3 rounded-md border border-border bg-surface/90 px-2.5 py-1.5 text-[11px] text-muted shadow-pop sm:flex', dir: 'auto' },
      h('span', { class: 'inline-flex items-center gap-1' }, icon('key-round', 'size-3 text-warning'), t('erd.legend.pk')),
      h('span', { class: 'inline-flex items-center gap-1' }, icon('link', 'size-3 text-accent'), t('erd.legend.fk')),
      h('span', { class: 'inline-flex items-center gap-1' }, h('span', { class: 'font-mono text-warning' }, '?'), t('erd.legend.nullable')));
    body.replaceChildren(svg, legend, summary);

    const view = { tx: 0, ty: 0, k: 1 };
    const applyView = () => {
      viewport.setAttribute('transform', `translate(${view.tx} ${view.ty}) scale(${view.k})`);
      zoomLabel.textContent = `${Math.round(view.k * 100)}%`;
    };
    let saveTimer = 0;
    let userMoved = !!(saved.pos && Object.keys(saved.pos).length);
    const persist = () => {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        const pos = {};
        if (userMoved) nodes.forEach((n, name) => { pos[name] = [Math.round(n.x), Math.round(n.y)]; });
        storage.set(storeKey, { pos: userMoved ? pos : undefined, view: { ...view } });
      }, 250);
    };
    cleanup.push(() => clearTimeout(saveTimer));
    /** Store all current positions now (renamed: [old, new] table name). */
    const savePos = (renamed) => {
      clearTimeout(saveTimer);
      userMoved = true;
      const pos = {};
      nodes.forEach((n, name) => { pos[renamed && renamed[0] === name ? renamed[1] : name] = [Math.round(n.x), Math.round(n.y)]; });
      storage.set(storeKey, { pos, view: { ...view } });
    };

    // ----- card rendering
    function markerIcon(name, cls, x, y) {
      const ic = icon(name);
      ic.removeAttribute('class');
      for (const [k, v] of Object.entries({ class: cls, x, y, width: 12, height: 12, 'stroke-width': 2.25 })) ic.setAttribute(k, String(v));
      return ic;
    }
    function buildCard(n) {
      const cols = shown(n);
      n.h = heightOf(n);
      n.rows = new Map();
      const avail = n.w - PAD * 2 - 16;
      const rowEls = cols.map((c, i) => {
        const y = HEAD + i * ROW;
        const isFk = n.fk.has(c.name);
        const typeText = c.type || '';
        const nameFont = c.primary ? F_PK : F_COL;
        const nullW = c.nullable ? measure(' ?', F_TYPE) : 0;
        let tW = measure(typeText, F_TYPE);
        const nW = measure(c.name, nameFont);
        let type = typeText;
        if (nW + 10 + tW + nullW > avail) { type = fit(typeText, F_TYPE, Math.max(avail - nW - 10 - nullW, avail * 0.38)); tW = measure(type, F_TYPE); }
        const name = fit(c.name, nameFont, avail - tW - nullW - 10);
        const r = s('g', { class: 'erd-r', dataset: { col: c.name } },
          s('title', {}, `${c.name} · ${c.type}${c.nullable ? ' · NULL' : ''}${c.primary ? ` · ${t('erd.legend.pk')}` : ''}${isFk ? ` · ${t('erd.legend.fk')}` : ''}`),
          s('rect', { class: 'erd-rowbg', x: 1, y, width: n.w - 2, height: ROW }),
          c.primary ? markerIcon('key-round', 'erd-pk', PAD - 2, y + 5) : isFk ? markerIcon('link', 'erd-fk', PAD - 2, y + 5) : null,
          s('text', { class: c.primary ? 'erd-col erd-pkc' : 'erd-col', x: PAD + 14, y: y + 15 }, name),
          s('text', { class: 'erd-type', x: n.w - PAD - nullW, y: y + 15, 'text-anchor': 'end' }, type),
          c.nullable ? s('text', { class: 'erd-null', x: n.w - PAD, y: y + 15, 'text-anchor': 'end' }, ' ?') : null,
          canFk ? s('g', { class: 'erd-hd' }, s('title', {}, t('erd.linkHandle')), s('circle', { class: 'erd-hh', cx: n.w, cy: y + ROW / 2, r: 11 }), s('circle', { class: 'erd-h', cx: n.w, cy: y + ROW / 2, r: 4.5 })) : null);
        n.rows.set(c.name, { i, el: r });
        return r;
      });
      const hidden = n.tb.columns.length - cols.length;
      const g = s('g', { class: 'erd-t', tabindex: '0', role: 'button', 'aria-haspopup': 'menu', 'aria-label': t('erd.tableLabel', { name: n.tb.name }), dataset: { name: n.tb.name } },
        s('rect', { class: 'erd-card', x: 0, y: 0, width: n.w, height: n.h, rx: 8 }),
        s('path', { class: 'erd-head', d: `M0 8a8 8 0 0 1 8-8h${n.w - 16}a8 8 0 0 1 8 8v${HEAD - 8}h-${n.w}z` }),
        s('line', { class: 'erd-sep', x1: 0, y1: HEAD, x2: n.w, y2: HEAD }),
        s('text', { class: 'erd-title', x: PAD, y: 19 }, fit(n.tb.name, F_HEAD, n.w - PAD * 2)),
        rowEls,
        hidden > 0 ? s('text', { class: 'erd-more', x: PAD + 14, y: HEAD + cols.length * ROW + 15 }, plural('erd.hiddenColumns', hidden)) : null);
      g.setAttribute('transform', `translate(${n.x} ${n.y})`);
      if (n.g) n.g.replaceWith(g);
      n.g = g;
      return g;
    }

    // ----- edges
    const edges = rels.map((r, i) => {
      const from = nodes.get(r.from_table);
      const to = nodes.get(r.to_table);
      const mandatory = r.from_columns.every((c) => from.tb.columns.find((x) => x.name === c)?.nullable === false);
      const hit = s('path', { class: 'erd-hit' });
      const line = s('path', { class: 'erd-edge' });
      const g = s('g', { class: 'erd-e', dataset: { i }, tabindex: '-1', role: 'button', 'aria-haspopup': 'menu', 'aria-label': t('erd.relationLabel', { from: `${r.from_table}.${r.from_columns.join(', ')}`, to: `${r.to_table}.${r.to_columns.join(', ')}` }) },
        s('title', {}, `${r.from_table}.${r.from_columns.join(', ')} → ${r.to_table}.${r.to_columns.join(', ')}${r.name ? ` (${r.name})` : ''}`), hit, line);
      const e = { r, from, to, mandatory, g, hit, line };
      from.edges.push(e);
      if (to !== from) to.edges.push(e);
      return e;
    });
    const anchorY = (n, cols) => {
      const row = n.rows.get(cols[0]);
      return n.y + (row ? HEAD + row.i * ROW + ROW / 2 : HEAD / 2);
    };
    const setAnchors = (e) => { e.A = e.from; e.B = e.to; e.ya = anchorY(e.from, e.r.from_columns); e.yb = anchorY(e.to, e.r.to_columns); };
    function paint(e, { pts, sa, sb }) {
      const d = pathD(pts);
      const [xa, ya] = pts[0];
      const [xb, yb] = pts[pts.length - 1];
      e.hit.setAttribute('d', d);
      // crow's foot (many) at the referencing side, bar(s) (one) at the referenced side
      let m = `M${xa + sa * 13} ${ya}L${xa} ${ya - 5}M${xa + sa * 13} ${ya}L${xa} ${ya + 5}M${xa + sa * 15} ${ya - 5}v10`;
      m += `M${xb + sb * 8} ${yb - 5}v10`;
      if (e.mandatory) m += `M${xb + sb * 12} ${yb - 5}v10`;
      e.line.setAttribute('d', d + m);
    }
    /** Full reroute (side choice, spreading, nudging). */
    function routeEdges() {
      edges.forEach(setAnchors);
      routeAll([...nodes.values()], edges).forEach((r, i) => paint(edges[i], r));
    }
    let fullTimer = 0;
    const routeLater = () => { clearTimeout(fullTimer); fullTimer = setTimeout(routeEdges, 160); };
    cleanup.push(() => clearTimeout(fullTimer));

    // ----- layout
    function placeAll(forceAuto) {
      const size = new Map([...nodes].map(([name, n]) => [name, { w: n.w, h: heightOf(n) }]));
      const auto = autoLayout([...nodes.keys()], rels, size);
      const pos = !forceAuto && saved.pos ? saved.pos : {};
      const known = [...nodes.keys()].filter((n) => Array.isArray(pos[n]));
      const placed = [];
      nodes.forEach((n, name) => {
        if (known.length && !Array.isArray(pos[name])) return;
        const p = known.length ? pos[name] : auto.get(name);
        n.x = Number(p[0]) || 0;
        n.y = Number(p[1]) || 0;
        placed.push({ x: n.x, y: n.y, ...size.get(name) });
      });
      if (!known.length) return;
      // tables without a stored position (e.g. just created): free spot near their relations
      nodes.forEach((n, name) => {
        if (Array.isArray(pos[name])) return;
        const rel = n.edges.map((e) => (e.from === n ? e.to : e.from)).filter((m) => m !== n && Array.isArray(pos[m.tb.name]));
        const target = rel.length ? [rel.reduce((a, m) => a + m.x + m.w / 2, 0) / rel.length, rel.reduce((a, m) => a + m.y + size.get(m.tb.name).h / 2, 0) / rel.length] : null;
        const sz = size.get(name);
        [n.x, n.y] = freeSpot(placed, sz.w, sz.h, target, GAP_Y * 1.5);
        placed.push({ x: n.x, y: n.y, ...sz });
      });
    }
    function drawAll() {
      tableLayer.replaceChildren(...[...nodes.values()].map(buildCard));
      edgeLayer.replaceChildren(...edges.map((e) => e.g));
      sortTabOrder();
      routeEdges();
      reapplyHighlight();
    }
    /** DOM (= tab) order of the cards follows the layout: left to right, then top to bottom. */
    function sortTabOrder() {
      const f = document.activeElement;
      [...nodes.values()].sort((a, b) => a.x - b.x || a.y - b.y).forEach((n) => tableLayer.appendChild(n.g));
      if (f && tableLayer.contains(f) && document.activeElement !== f) f.focus({ preventScroll: true });
    }

    // ----- view helpers
    function bounds() {
      let x1 = Infinity; let y1 = Infinity; let x2 = -Infinity; let y2 = -Infinity;
      nodes.forEach((n) => { x1 = Math.min(x1, n.x); y1 = Math.min(y1, n.y); x2 = Math.max(x2, n.x + n.w + 60); y2 = Math.max(y2, n.y + n.h); });
      return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }
    const clampK = (k) => Math.min(ZMAX, Math.max(ZMIN, k));
    function zoomAt(px, py, k) {
      k = clampK(k);
      const wx = (px - view.tx) / view.k;
      const wy = (py - view.ty) / view.k;
      view.k = k;
      view.tx = px - wx * k;
      view.ty = py - wy * k;
      applyView();
      persist();
    }
    const center = () => { const r = svg.getBoundingClientRect(); return [r.width / 2, r.height / 2]; };
    /** Fit the diagram into the viewport. `readable`: on phones never zoom out below a legible
     *  scale on first paint — start at the top-start corner and let the user pan instead. */
    function fitView(readable = false) {
      const r = svg.getBoundingClientRect();
      if (!r.width || !r.height) return;
      const b = bounds();
      const m = r.width < 640 ? 16 : 40;
      const k = clampK(Math.min((r.width - m * 2) / b.w, (r.height - m * 2) / b.h, 1.1));
      const minReadable = 0.55;
      if (readable && r.width < 640 && k < minReadable) {
        view.k = minReadable;
        view.tx = document.documentElement.dir === 'rtl' ? r.width - m - (b.x + b.w) * view.k : m - b.x * view.k;
        view.ty = m - b.y * view.k;
      } else {
        view.k = k;
        view.tx = (r.width - b.w * view.k) / 2 - b.x * view.k;
        view.ty = (r.height - b.h * view.k) / 2 - b.y * view.k;
      }
      applyView();
      persist();
    }
    function focusTable(name) {
      const n = nodes.get(name);
      if (!n) return;
      const r = svg.getBoundingClientRect();
      view.k = Math.max(view.k, Math.min(1, r.width / (n.w + 80)));
      view.tx = r.width / 2 - (n.x + n.w / 2) * view.k;
      view.ty = r.height / 2 - (n.y + n.h / 2) * view.k;
      applyView();
      persist();
      pinned = name;
      reapplyHighlight();
      n.g.focus({ preventScroll: true });
    }

    // ----- highlight
    let pinned = null;
    let hover = null; // {table} | {edge}
    let lit = [];
    function highlight(tableNames, edgeList) {
      lit.forEach((x) => x.classList.remove('is-hl'));
      lit = [];
      svg.classList.toggle('is-dim', !!tableNames);
      if (!tableNames) return;
      const add = (x) => { x.classList.add('is-hl'); lit.push(x); };
      tableNames.forEach((n) => nodes.get(n) && add(nodes.get(n).g));
      for (const e of edgeList) {
        add(e.g);
        e.r.from_columns.forEach((c) => { const row = e.from.rows.get(c); if (row) add(row.el); });
        e.r.to_columns.forEach((c) => { const row = e.to.rows.get(c); if (row) add(row.el); });
      }
    }
    function tableHighlight(name) {
      const n = nodes.get(name);
      const set = new Set([name]);
      n.edges.forEach((e) => { set.add(e.from.tb.name); set.add(e.to.tb.name); });
      highlight(set, n.edges);
    }
    function reapplyHighlight() {
      if (hover?.edge) highlight([hover.edge.from.tb.name, hover.edge.to.tb.name], [hover.edge]);
      else if (hover?.table && nodes.has(hover.table)) tableHighlight(hover.table);
      else if (pinned && nodes.has(pinned)) tableHighlight(pinned);
      else highlight(null);
    }

    // ----- editing (writable connections; actions + table editor are loaded on demand)
    /** Reload db.erd and redraw, keeping card positions (renamed: [old, new]) and the viewport. */
    async function refresh(focus, renamed) {
      const next = await api('db.erd', base);
      if (!svg.isConnected) return;
      savePos(renamed);
      self.destroy();
      await self.render(el, ctx, { data: next, focus, link: linkMode });
    }
    const X = { base, caps, nodes, rels, done: async (msg, focus, renamed) => { toast.success(msg); invalidate(conn, db); await refresh(focus, renamed); } };
    const act = (name) => (...a) => import('./_erd-edit.js').then((m) => m[name](X, ...a)).catch((e) => toastError(e));
    const [relate, editRel, dropRel, relDetails, addColumn, renameTable, dropTable] = ['relate', 'editRel', 'dropRel', 'relDetails', 'addColumn', 'renameTable', 'dropTable'].map(act);
    const relText = (r) => [`${r.from_table}.${r.from_columns.join(', ')}`, `${r.to_table}.${r.to_columns.join(', ')}`];
    function relMenu(e, point) {
      const { r } = e;
      const [a, b] = relText(r);
      const open = (name) => ({ label: t('erd.openTable', { name }), icon: 'columns', dir: 'auto', onSelect: () => navigate(href.table(conn, db, schema, name, 'structure')) });
      openMenu(point, [
        { header: r.name || `${a} → ${b}` },
        { label: t('erd.relationDetails'), icon: 'info', onSelect: () => relDetails(e) },
        open(r.from_table),
        r.to_table !== r.from_table ? open(r.to_table) : null,
        ...(canFk ? [{ separator: true },
          { label: t('erd.relationEdit'), icon: 'pencil', disabled: !r.name, onSelect: () => editRel(e) },
          { label: t('erd.relationDrop'), icon: 'trash', danger: true, disabled: !r.name, onSelect: () => dropRel(e) }] : []),
      ].filter(Boolean), { minWidth: 220, label: t('erd.relationLabel', { from: a, to: b }) });
    }

    // ----- table menu
    function tableMenu(name, point) {
      const n = nodes.get(name);
      const go = (tab) => () => navigate(href.table(conn, db, schema, name, tab));
      openMenu(point, [
        { header: name },
        { label: t('table.tab.browse'), icon: 'table-2', onSelect: go('browse') },
        { label: t('table.tab.structure'), icon: 'columns', onSelect: go('structure') },
        { label: t('table.tab.search'), icon: 'text-search', onSelect: go('search') },
        { label: t('table.tab.ddl'), icon: 'file-code', onSelect: go('ddl') },
        { separator: true },
        { label: t('erd.highlight'), icon: 'network', onSelect: () => { pinned = name; reapplyHighlight(); } },
        ...(canEdit ? [{ separator: true },
          canFk ? { label: t('erd.addRelation'), icon: 'link', onSelect: () => relate(n) } : null,
          { label: t('erd.addColumn'), icon: 'plus', onSelect: () => addColumn(n) },
          caps.rename_table !== false ? { label: t('erd.renameTable'), icon: 'pencil', onSelect: () => renameTable(n) } : null,
          { label: t('erd.dropTable'), icon: 'trash', danger: true, onSelect: () => dropTable(n) }] : []),
      ].filter(Boolean), { minWidth: 200, label: name });
    }

    // ----- link mode (touch / click alternative to dragging a handle)
    let linkMode = false;
    let linkSrc = null; // {n, col}
    let linkBtn = null;
    const linkHint = h('div', { class: 'pointer-events-none absolute inset-x-0 top-3 mx-auto w-fit max-w-[calc(100%-2rem)] rounded-md border border-border bg-surface px-3 py-1.5 text-[12.5px] shadow-pop', role: 'status', hidden: true, dir: 'auto' });
    body.appendChild(linkHint);
    function setLinkSrc(v) {
      linkSrc?.n.rows.get(linkSrc.col)?.el.classList.remove('is-src');
      linkSrc = v;
      v?.n.rows.get(v.col)?.el.classList.add('is-src');
      linkHint.hidden = !linkMode && !v;
      linkHint.textContent = t(v ? 'erd.linkPickTarget' : 'erd.linkPickSource');
    }
    function setLinkMode(v) {
      linkMode = v;
      svg.classList.toggle('is-link', v);
      linkBtn?.setAttribute('aria-pressed', String(v));
      linkBtn?.classList.toggle('btn-primary', v);
      setLinkSrc(null);
    }
    function linkTap(n, col) {
      if (!linkSrc) { if (col) setLinkSrc({ n, col }); return; }
      const src = linkSrc;
      setLinkSrc(null);
      if (src.n !== n || src.col !== col) relate(src.n, src.col, n, col || null);
    }
    /** Card / column under a viewport point (pointer capture retargets events to the svg). */
    function dropTarget(e) {
      const at = document.elementFromPoint(e.clientX, e.clientY);
      const tg = at?.closest?.('.erd-t');
      if (!tg || !svg.contains(tg)) return null;
      const row = at.closest('.erd-r');
      return { n: nodes.get(tg.dataset.name), col: row?.dataset.col || null, el: row || tg };
    }

    // ----- dragging (rAF-batched: only the moved card and its edges are rerouted; full reroute on drop)
    const dirty = new Set();
    let raf = 0;
    const flush = () => {
      raf = 0;
      const boxes = [...nodes.values()];
      dirty.forEach((n) => {
        n.g.setAttribute('transform', `translate(${n.x} ${n.y})`);
        n.edges.forEach((e) => { setAnchors(e); paint(e, routeEdge(boxes, { ...e, sa: 0 })); });
      });
      dirty.clear();
    };
    const moveNode = (n) => { dirty.add(n); if (!raf) raf = requestAnimationFrame(flush); };
    cleanup.push(() => cancelAnimationFrame(raf));

    // ----- pointer gestures: pan, pinch, drag, link, click
    const pointers = new Map();
    let gesture = null;
    const local = (e) => { const r = svg.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    function startPinch() {
      const [a, b] = [...pointers.values()];
      gesture = { type: 'pinch', d0: Math.hypot(a.x - b.x, a.y - b.y) || 1, k0: view.k, mx: (a.x + b.x) / 2, my: (a.y + b.y) / 2, tx: view.tx, ty: view.ty };
    }
    const endLink = (g) => { g.tmp?.remove(); g.over?.el.classList.remove('is-drop'); svg.classList.toggle('is-link', linkMode); };
    svg.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      try { svg.setPointerCapture(e.pointerId); } catch { /* ignore */ }
      if (pointers.size === 2) {
        if (gesture?.type === 'drag' && gesture.moved) persist();
        if (gesture?.type === 'link') endLink(gesture);
        startPinch();
        return;
      }
      if (pointers.size > 2) return;
      const tg = e.target.closest?.('.erd-t');
      const hd = tg && e.target.closest('.erd-hd');
      const col = tg && e.target.closest('.erd-r')?.dataset.col;
      if (hd) {
        gesture = { type: 'link', n: nodes.get(tg.dataset.name), col, sx: e.clientX, sy: e.clientY, moved: false };
      } else if (tg) {
        const n = nodes.get(tg.dataset.name);
        gesture = { type: 'drag', n, col, sx: e.clientX, sy: e.clientY, ox: n.x, oy: n.y, moved: false };
        tableLayer.appendChild(n.g); // bring to front
        n.g.focus({ preventScroll: true });
      } else {
        const eg = e.target.closest?.('.erd-e');
        gesture = { type: 'pan', edge: eg ? edges[Number(eg.dataset.i)] : null, sx: e.clientX, sy: e.clientY, tx: view.tx, ty: view.ty, moved: false };
        svg.focus({ preventScroll: true });
      }
    });
    svg.addEventListener('pointermove', (e) => {
      if (!pointers.has(e.pointerId)) {
        if (!gesture) {
          const tg = e.target.closest?.('.erd-t');
          const eg = !tg && e.target.closest?.('.erd-e');
          const next = tg ? { table: tg.dataset.name } : eg ? { edge: edges[Number(eg.dataset.i)] } : null;
          if (next?.table !== hover?.table || next?.edge !== hover?.edge) { hover = next; reapplyHighlight(); }
        }
        return;
      }
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (!gesture) return;
      if (gesture.type === 'pinch') {
        if (pointers.size < 2) return;
        const [a, b] = [...pointers.values()];
        const r = svg.getBoundingClientRect();
        const k = clampK(gesture.k0 * (Math.hypot(a.x - b.x, a.y - b.y) / gesture.d0));
        const mx = (a.x + b.x) / 2;
        const my = (a.y + b.y) / 2;
        const wx = (gesture.mx - r.left - gesture.tx) / gesture.k0;
        const wy = (gesture.my - r.top - gesture.ty) / gesture.k0;
        view.k = k;
        view.tx = mx - r.left - wx * k;
        view.ty = my - r.top - wy * k;
        applyView();
        return;
      }
      const dx = e.clientX - gesture.sx;
      const dy = e.clientY - gesture.sy;
      if (!gesture.moved && Math.hypot(dx, dy) < 4) return;
      gesture.moved = true;
      if (gesture.type === 'link') {
        const g = gesture;
        if (!g.tmp) { g.tmp = s('path', { class: 'erd-tmp' }); viewport.appendChild(g.tmp); svg.classList.add('is-link'); }
        const [px, py] = local(e);
        g.tmp.setAttribute('d', `M${g.n.x + g.n.w} ${anchorY(g.n, [g.col])}L${(px - view.tx) / view.k} ${(py - view.ty) / view.k}`);
        const over = dropTarget(e);
        if (over?.el !== g.over?.el) { g.over?.el.classList.remove('is-drop'); over?.el.classList.add('is-drop'); g.over = over; }
      } else if (gesture.type === 'drag') {
        gesture.n.x = Math.round(gesture.ox + dx / view.k);
        gesture.n.y = Math.round(gesture.oy + dy / view.k);
        moveNode(gesture.n);
      } else {
        view.tx = gesture.tx + dx;
        view.ty = gesture.ty + dy;
        applyView();
      }
    });
    const endPointer = (e) => {
      if (!pointers.delete(e.pointerId)) return;
      const g = gesture;
      if (!g) return;
      if (g.type === 'pinch') { if (pointers.size < 2) { gesture = null; persist(); } return; }
      gesture = null;
      if (g.type === 'link') endLink(g);
      if (e.type === 'pointercancel') return;
      const pt = { x: e.clientX, y: e.clientY };
      if (g.type === 'link') {
        const over = g.moved ? dropTarget(e) : null;
        if (!g.moved) linkTap(g.n, g.col);
        else if (over && (over.n !== g.n || over.col !== g.col)) relate(g.n, g.col, over.n, over.col);
      } else if (g.type === 'drag') {
        if (g.moved) { userMoved = true; persist(); routeEdges(); sortTabOrder(); } else if (linkMode || linkSrc) linkTap(g.n, g.col); else tableMenu(g.n.tb.name, pt);
      } else if (g.moved) persist();
      else if (g.edge) relMenu(g.edge, pt);
      else if (linkSrc) setLinkSrc(null);
      else if (pinned) { pinned = null; reapplyHighlight(); }
    };
    svg.addEventListener('pointerup', endPointer);
    svg.addEventListener('pointercancel', endPointer);
    svg.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse' && hover) { hover = null; reapplyHighlight(); } });
    svg.addEventListener('wheel', (e) => {
      e.preventDefault();
      const [px, py] = local(e);
      const delta = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
      zoomAt(px, py, view.k * Math.exp(-delta * (e.ctrlKey ? 0.01 : 0.0018)));
    }, { passive: false });
    svg.addEventListener('focusin', (e) => {
      const tg = e.target.closest?.('.erd-t');
      const eg = e.target.closest?.('.erd-e');
      if (gesture) return;
      if (tg) hover = { table: tg.dataset.name }; else if (eg) hover = { edge: edges[Number(eg.dataset.i)] }; else return;
      reapplyHighlight();
    });
    svg.addEventListener('focusout', () => { if (hover) { hover = null; reapplyHighlight(); } });
    let relOrigin = null;
    svg.addEventListener('keydown', (e) => {
      const tg = e.target.closest?.('.erd-t');
      const eg = e.target.closest?.('.erd-e');
      const n = tg && nodes.get(tg.dataset.name);
      const step = e.shiftKey ? 48 : 12;
      const arrows = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
      const enter = e.key === 'Enter' || e.key === ' ';
      if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey && !e.altKey && (n || eg)) {
        // cycle through the relations of the focused table (or of the table we came from)
        e.preventDefault();
        const origin = n || relOrigin;
        const list = origin?.edges || [];
        if (!list.length) { announce(t('a11y.erdNoRelations', { name: origin?.tb.name || '' })); return; }
        const cur = eg ? list.indexOf(edges[Number(eg.dataset.i)]) : -1;
        const next = list[(cur + (e.shiftKey ? -1 : 1) + list.length) % list.length];
        relOrigin = origin;
        next.g.focus({ preventScroll: true });
      } else if (eg && e.key === 'Escape' && relOrigin) {
        e.preventDefault();
        relOrigin.g.focus({ preventScroll: true });
      } else if (n && enter && linkMode) {
        // keyboard link mode: Enter on the source table, then on the target table
        e.preventDefault();
        if (!linkSrc) setLinkSrc({ n, col: null });
        else { const src = linkSrc; setLinkSrc(null); relate(src.n, src.col, n, null); }
      } else if (n && enter) {
        e.preventDefault();
        const r = n.g.getBoundingClientRect();
        tableMenu(n.tb.name, { x: r.left + 12, y: r.top + HEAD * view.k });
      } else if (eg && enter) {
        e.preventDefault();
        const r = eg.getBoundingClientRect();
        relMenu(edges[Number(eg.dataset.i)], { x: r.left + r.width / 2, y: r.top + r.height / 2 });
      } else if (arrows[e.key]) {
        e.preventDefault();
        const [ax, ay] = arrows[e.key];
        if (n) { n.x += ax * step; n.y += ay * step; userMoved = true; moveNode(n); persist(); routeLater(); } else { view.tx -= ax * step * 3; view.ty -= ay * step * 3; applyView(); persist(); }
      } else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomAt(...center(), view.k * 1.2); } else if (e.key === '-') { e.preventDefault(); zoomAt(...center(), view.k / 1.2); } else if (e.key === '0') { e.preventDefault(); fitView(); } else if (e.key === 'Escape' && (linkMode || linkSrc)) { setLinkMode(false); } else if (e.key === 'Escape' && pinned) { pinned = null; reapplyHighlight(); }
    });

    // ----- export
    function exportSvg() {
      const b = bounds();
      const m = 24;
      const W = Math.ceil(b.w + m * 2);
      const H = Math.ceil(b.h + m * 2);
      const cs = getComputedStyle(document.documentElement);
      const css = CSS.replace(/var\((--[\w-]+)\)/g, (_, v) => cs.getPropertyValue(v).trim() || '#888');
      const out = document.createElementNS(SVGNS, 'svg');
      for (const [k, v] of Object.entries({ class: 'erd', width: W, height: H, viewBox: `${b.x - m} ${b.y - m} ${W} ${H}` })) out.setAttribute(k, String(v));
      out.append(s('style', {}, css), s('rect', { class: 'erd-bg', x: b.x - m, y: b.y - m, width: W, height: H }), edgeLayer.cloneNode(true), tableLayer.cloneNode(true));
      out.querySelectorAll('.is-hl,.is-src,.is-drop').forEach((x) => x.classList.remove('is-hl', 'is-src', 'is-drop'));
      out.querySelectorAll('.erd-hit,.erd-hd,.erd-tmp').forEach((x) => x.remove());
      out.querySelectorAll('[tabindex]').forEach((x) => { x.removeAttribute('tabindex'); x.removeAttribute('role'); x.removeAttribute('aria-haspopup'); });
      return { text: `<?xml version="1.0" encoding="UTF-8"?>\n${new XMLSerializer().serializeToString(out)}`, W, H };
    }
    const fileBase = `${db}${schema ? `.${schema}` : ''}-erd`;
    function downloadSvg() {
      downloadText(`${fileBase}.svg`, new Blob([exportSvg().text], { type: 'image/svg+xml;charset=utf-8' }));
    }
    function downloadPng() {
      const { text, W, H } = exportSvg();
      const scale = Math.max(0.25, Math.min(2, 8000 / Math.max(W, H)));
      const url = URL.createObjectURL(new Blob([text], { type: 'image/svg+xml;charset=utf-8' }));
      const img = new Image();
      const fail = () => { URL.revokeObjectURL(url); toast.error(t('erd.exportFailed')); };
      img.onerror = fail;
      img.onload = () => {
        try {
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(W * scale);
          canvas.height = Math.round(H * scale);
          const c2 = canvas.getContext('2d');
          c2.scale(scale, scale);
          c2.drawImage(img, 0, 0, W, H);
          URL.revokeObjectURL(url);
          canvas.toBlob((blob) => (blob ? downloadText(`${fileBase}.png`, blob) : toast.error(t('erd.exportFailed'))), 'image/png');
        } catch { fail(); }
      };
      img.src = url;
    }

    // ----- toolbar controls
    const tbBtn = (ic, label, onClick, extra = '') => h('button', { type: 'button', class: `btn btn-sm btn-icon ${extra}`, 'aria-label': label, title: label, onClick }, icon(ic));
    const listId = `erd-tables-${Math.random().toString(36).slice(2, 8)}`;
    const findIn = h('input', {
      type: 'search', class: 'input input-sm w-full', placeholder: t('erd.find'), 'aria-label': t('erd.find'), list: listId, dir: 'auto', enterkeyhint: 'search',
      onChange: () => find(), onKeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); find(); } },
    });
    const datalist = h('datalist', { id: listId }, tables.map((x) => h('option', { value: x.name })));
    function find() {
      const q = findIn.value.trim().toLowerCase();
      if (!q) { pinned = null; reapplyHighlight(); return; }
      const names = [...nodes.keys()];
      const hit = names.find((n) => n.toLowerCase() === q) || names.find((n) => n.toLowerCase().startsWith(q)) || names.find((n) => n.toLowerCase().includes(q));
      if (hit) focusTable(hit); else toast.warning(t('erd.notFound', { name: findIn.value.trim() }));
    }
    const keysBtn = h('button', { type: 'button', class: 'btn btn-sm max-sm:hidden', 'aria-pressed': String(keysOnly), title: t('erd.keysOnlyHint'), onClick: () => setKeysOnly(!keysOnly) }, icon('key-round'), h('span', { class: 'hidden md:inline' }, t('erd.keysOnly')));
    function setKeysOnly(v) {
      keysOnly = v;
      storage.set('erd.keysOnly', v);
      keysBtn.setAttribute('aria-pressed', String(v));
      keysBtn.classList.toggle('btn-primary', v);
      if (!userMoved) placeAll(true);
      drawAll();
    }
    keysBtn.classList.toggle('btn-primary', keysOnly);
    const zoomOut = () => zoomAt(...center(), view.k / 1.25);
    const zoomIn = () => zoomAt(...center(), view.k * 1.25);
    const arrange = () => { userMoved = false; saved.pos = null; placeAll(true); drawAll(); fitView(); };
    const exportBtn = h('button', { type: 'button', class: 'btn btn-sm max-sm:hidden', title: t('erd.export') }, icon('download'), h('span', { class: 'hidden md:inline' }, t('erd.export')));
    const exportItems = () => [
      { label: t('erd.exportSvg'), icon: 'file-code', onSelect: downloadSvg },
      { label: t('erd.exportPng'), icon: 'file-down', onSelect: downloadPng },
    ];
    bindMenu(exportBtn, exportItems, { align: 'end' });
    if (canFk) linkBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', title: t('erd.linkModeHint'), onClick: () => setLinkMode(!linkMode) }, icon('link'), h('span', { class: 'hidden md:inline' }, t('erd.linkMode')));
    // keep current card positions so they survive the round trip through the create-table view
    const keepLayout = () => savePos();
    const newBtn = canEdit ? h('a', { href: href.createTable(conn, db, schema), class: 'btn btn-sm max-sm:hidden', title: t('db.createTable'), onClick: keepLayout }, icon('plus'), h('span', { class: 'hidden lg:inline' }, t('db.createTable'))) : null;
    const moreBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon sm:hidden', 'aria-label': t('common.more') }, icon('ellipsis'));
    bindMenu(moreBtn, () => [
      ...(canEdit ? [{ label: t('db.createTable'), icon: 'plus', onSelect: () => { keepLayout(); navigate(href.createTable(conn, db, schema)); } }, { separator: true }] : []),
      { label: t('erd.keysOnly'), icon: 'key-round', checked: keysOnly, onSelect: () => setKeysOnly(!keysOnly) },
      { label: t('erd.zoomIn'), icon: 'zoom-in', checked: false, onSelect: zoomIn },
      { label: t('erd.zoomOut'), icon: 'zoom-out', checked: false, onSelect: zoomOut },
      { label: t('erd.arrange'), icon: 'wand', checked: false, onSelect: arrange },
      { separator: true },
      ...exportItems(),
    ], { align: 'end' });

    toolbar.append(
      h('div', { class: 'flex-1 max-sm:hidden' }),
      h('div', { class: 'min-w-[7rem] flex-1 sm:max-w-[14rem]' }, findIn, datalist),
      ...[linkBtn, newBtn].filter(Boolean), keysBtn,
      h('div', { class: 'flex items-center gap-0.5', role: 'group', 'aria-label': t('erd.zoomLevel') },
        tbBtn('zoom-out', t('erd.zoomOut'), zoomOut, 'max-sm:hidden'), zoomLabel, tbBtn('zoom-in', t('erd.zoomIn'), zoomIn, 'max-sm:hidden'),
        tbBtn('maximize', t('erd.fit'), () => fitView())),
      tbBtn('wand', t('erd.arrange'), arrange, 'max-sm:hidden'),
      exportBtn, moreBtn);

    // ----- first paint
    placeAll(false);
    drawAll();
    const restore = saved.view && Number.isFinite(saved.view.k);
    if (restore) {
      Object.assign(view, { tx: Number(saved.view.tx) || 0, ty: Number(saved.view.ty) || 0, k: clampK(Number(saved.view.k)) });
      applyView();
    }
    let fitted = restore;
    const ro = new ResizeObserver(() => {
      if (!fitted && svg.clientWidth && svg.clientHeight) { fitted = true; fitView(true); }
    });
    ro.observe(svg);
    cleanup.push(() => ro.disconnect());
    if (!fitted) requestAnimationFrame(() => { if (!fitted && svg.clientWidth) { fitted = true; fitView(true); } });
    applyView();
    if (opts.link) setLinkMode(true);
    if (opts.focus && nodes.has(opts.focus)) nodes.get(opts.focus).g.focus({ preventScroll: true });
  },

  destroy() {
    cleanup.forEach((fn) => { try { fn(); } catch { /* ignore */ } });
    cleanup = [];
  },
};
