/**
 * Server monitor (route #/c/{conn}/server/monitor): live KPI tiles and time-series charts from
 * `server.metrics` (rates computed client-side from counter deltas), top statements
 * (`server.slowLog`), tuning hints (`server.advice`) and long-running queries (kill: WRITE,
 * hidden on read-only connections). Samples live in memory only (ring buffer, ≤ 60 min);
 * polling pauses while the browser tab is hidden and stops when the view is destroyed.
 */
import { h, cls, setChildren, uid } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmDialog, viewerDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { errorState, emptyState, callout, statCard, refreshButton } from '../ui/feedback.js';
import { t, fmtNumber, fmtBytes, fmtDuration } from '../i18n/index.js';
import { api } from '../api.js';
import { storage } from '../store.js';
import { href } from '../router.js';
import { getOverview } from '../data.js';
import { Ring, derive, summary } from './_monitor-math.js';
import { timeChart } from './_monitor-chart.js';

const WINDOWS = [5, 15, 60];
const INTERVALS = [2, 5, 10];
const MAX_MS = 60 * 60 * 1000;
const C = ['var(--mon-1)', 'var(--mon-2)', 'var(--mon-3)', 'var(--mon-4)'];

let stopFn = null;

const num = (v, d = 1) => fmtNumber(v, { maximumFractionDigits: v !== null && Math.abs(v) < 10 ? d : 0 });
const compact = (v) => fmtNumber(v, { notation: 'compact', maximumFractionDigits: 1 });
const pct = (v) => (v === null || v === undefined ? '—' : `${fmtNumber(v, { maximumFractionDigits: v >= 99 ? 2 : 1 })} %`);
const rateBytes = (v) => `${fmtBytes(v)}/s`;

function selectEl(label, values, current, fmtLabel, onChange) {
  const id = uid('mon');
  return h('label', { for: id, class: 'flex items-center gap-1.5 text-[12.5px] text-muted' }, label,
    h('select', { id, class: 'select select-sm h-[1.875rem] w-auto text-[12.5px]', onChange: (e) => onChange(Number(e.target.value) || e.target.value) },
      values.map((v) => h('option', { value: String(v), selected: String(v) === String(current) }, fmtLabel(v)))));
}

export default {
  title: () => t('monitor.title'),

  async render(el, ctx) {
    stopFn?.();
    const { conn, caps } = ctx;
    const canKill = !ctx.readOnly && !!caps.kill;
    if (!caps.monitor) { el.replaceChildren(emptyState({ icon: 'gauge', title: t('monitor.unsupported') })); return; }
    const overview = await getOverview(conn).catch(() => null);
    const driver = overview?.info?.driver || '';
    const isMy = driver === 'mysql';
    const isPg = driver === 'pgsql';

    let interval = INTERVALS.includes(storage.get('monitor.interval')) ? storage.get('monitor.interval') : 5;
    let windowMin = WINDOWS.includes(storage.get('monitor.window')) ? storage.get('monitor.window') : 5;
    let paused = false;
    let stopped = false;
    let timer = null;
    let ctrl = null;
    let last = null;
    let failures = 0;
    let loadTop = () => {};
    let loadAdvice = () => {};
    const ring = new Ring(Math.ceil(MAX_MS / 2000) + 4);

    // ---------------------------------------------------------------- charts
    const charts = [
      timeChart({ title: t(isPg ? 'monitor.chart.tps' : isMy ? 'monitor.chart.qps' : 'monitor.chart.batches'), series: [{ key: 'qps', label: t(isPg ? 'monitor.kpi.tps' : isMy ? 'monitor.kpi.qps' : 'monitor.kpi.batches'), color: C[0] }], fmt: (v, axis) => (axis ? compact(v) : num(v)) }),
      isMy || isPg ? timeChart({
        title: t(isPg ? 'monitor.chart.mixRows' : 'monitor.chart.mix'), stacked: true, fmt: (v, axis) => (axis ? compact(v) : num(v)),
        series: ['select', 'insert', 'update', 'delete'].map((k, i) => ({ key: k, label: t(`monitor.mix.${isPg ? `${k}Rows` : k}`), color: C[i] })),
      }) : null,
      timeChart({
        title: t('monitor.chart.connections'), fmt: (v, axis) => (axis ? compact(v) : fmtNumber(v)),
        series: [{ key: 'connections', label: t('monitor.series.connected'), color: C[0] }, { key: 'running', label: t(isPg ? 'monitor.series.active' : 'monitor.series.running'), color: C[1] },
          ...(isPg ? [{ key: 'idle_in_tx', label: t('monitor.series.idleInTx'), color: C[2] }] : [])],
      }),
      timeChart({ title: t(isMy ? 'monitor.chart.bufferPool' : 'monitor.chart.cache'), zero: false, max: 100, minSpan: 5, series: [{ key: 'hit', label: t('monitor.kpi.hit'), color: C[2] }], fmt: pct }),
      isMy ? timeChart({ title: t('monitor.chart.traffic'), fmt: rateBytes, series: [{ key: 'bytes_in', label: t('monitor.series.in'), color: C[0] }, { key: 'bytes_out', label: t('monitor.series.out'), color: C[1] }] }) : null,
    ].filter(Boolean);

    // ---------------------------------------------------------------- layout
    const status = h('span', { class: 'badge', 'aria-live': 'polite' });
    const pauseBtn = h('button', { type: 'button', class: 'btn btn-sm', onClick: () => setPaused(!paused) });
    const tiles = h('section', { class: 'grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 xl:grid-cols-6', 'aria-label': t('monitor.kpis') });
    const longBox = h('div', { class: 'card overflow-x-auto' });
    const topBox = h('div', { class: 'space-y-2' });
    const adviceBox = h('div', { class: 'space-y-2' });
    const errBox = h('div');

    const controls = h('div', { class: 'flex flex-wrap items-center gap-2' },
      selectEl(t('monitor.window'), WINDOWS, windowMin, (v) => t('monitor.minutes', { n: fmtNumber(v) }), (v) => { windowMin = v; storage.set('monitor.window', v); redraw(); }),
      selectEl(t('monitor.interval'), INTERVALS, interval, (v) => t('monitor.seconds', { n: fmtNumber(v) }), (v) => { interval = v; storage.set('monitor.interval', v); restart(); }),
      pauseBtn, status);

    const section = (id, title, extra, body) => h('section', { class: 'space-y-2', 'aria-labelledby': id },
      h('div', { class: 'flex flex-wrap items-center gap-2' }, h('h2', { id, class: 'me-auto text-[14px] font-semibold' }, title), extra), body);

    const body = h('div', { class: 'w-full space-y-5 p-4 sm:p-6' },
      controls, errBox, tiles,
      h('div', { class: 'grid gap-3 lg:grid-cols-2' }, charts.map((c) => c.el)),
      section('mon-long', t('monitor.long.title', { n: fmtNumber(10) }), caps.processes ? h('a', { href: href.serverTab(conn, 'processes'), class: 'btn btn-ghost btn-sm' }, icon('activity'), t('monitor.long.all')) : null, longBox),
      section('mon-top', t('monitor.top.title'), null, topBox),
      section('mon-adv', t('monitor.advice.title'), refreshButton(() => loadAdvice()), adviceBox));

    el.replaceChildren(
      h('div', { class: 'border-b border-border bg-surface' },
        h('div', { class: 'flex items-center gap-2 px-4 py-3' },
          icon('chart-line', 'text-muted'),
          h('h1', { class: 'truncate text-[15px] font-semibold' }, t('monitor.title')),
          h('a', { href: href.server(conn), class: 'btn btn-ghost btn-sm ms-auto' }, icon('arrow-left'), h('span', { class: 'max-sm:sr-only' }, t('nav.server'))))),
      h('div', { class: 'min-h-0 flex-1 overflow-auto' }, body));

    // ---------------------------------------------------------------- tiles
    function drawTiles(points, snap) {
      const g = snap?.gauges || {};
      const recent = points.filter((p) => p.ts >= Date.now() - windowMin * 60000);
      const lastOf = (k) => summary(points.slice(-3), k)?.last ?? null;
      const avg = (k) => { const v = recent.map((p) => p[k]).filter(Number.isFinite); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null; };
      const q = lastOf('qps');
      const hit = lastOf('hit');
      const list = [
        { label: t(isPg ? 'monitor.kpi.tps' : isMy ? 'monitor.kpi.qps' : 'monitor.kpi.batches'), value: q === null ? '—' : num(q), hint: avg('qps') === null ? null : t('monitor.kpi.avg', { v: num(avg('qps')) }), icon: 'zap' },
        { label: t('monitor.kpi.connections'), value: g.connections === undefined ? '—' : (g.max_connections ? `${fmtNumber(g.connections)} / ${fmtNumber(g.max_connections)}` : fmtNumber(g.connections)), hint: g.max_connections ? t('monitor.kpi.ofMax', { pct: fmtNumber(g.connections / g.max_connections * 100, { maximumFractionDigits: 0 }) }) : null, icon: 'plug' },
        { label: t(isPg ? 'monitor.kpi.active' : 'monitor.kpi.running'), value: g.running === undefined ? '—' : fmtNumber(g.running), hint: isPg && g.idle_in_tx !== undefined ? t('monitor.kpi.idleInTx', { n: fmtNumber(g.idle_in_tx) }) : null, icon: 'activity' },
        { label: t(isMy ? 'monitor.kpi.bpHit' : 'monitor.kpi.hit'), value: pct(hit ?? g.cache_hit_ratio ?? null), hint: g.cache_hit_ratio !== undefined && hit !== null ? t('monitor.kpi.sinceStart', { v: pct(g.cache_hit_ratio) }) : null, icon: 'gauge' },
      ];
      if (isMy) {
        const sl = lastOf('slow');
        list.push({ label: t('monitor.kpi.slow'), value: sl === null ? '—' : num(sl), hint: snap ? t('monitor.kpi.total', { n: fmtNumber(snap.counters.slow_queries) }) : null, icon: 'timer' });
        const rx = lastOf('bytes_in');
        const tx = lastOf('bytes_out');
        list.push({ label: t('monitor.kpi.traffic'), value: rx === null ? '—' : `↓ ${rateBytes(rx)}`, hint: tx === null ? null : `↑ ${rateBytes(tx)}`, icon: 'arrow-up-down' });
      } else if (isPg) {
        list.push({ label: t('monitor.kpi.waiting'), value: g.waiting === undefined ? '—' : fmtNumber(g.waiting), icon: 'timer' });
        list.push(g.replication_lag !== undefined
          ? { label: t('monitor.kpi.replLag'), value: fmtDuration(g.replication_lag * 1000), hint: g.replicas ? t('monitor.kpi.replicas', { n: fmtNumber(g.replicas) }) : null, icon: 'network' }
          : { label: t('monitor.kpi.deadlocks'), value: snap ? fmtNumber(snap.counters.deadlocks) : '—', hint: t('monitor.kpi.sinceStartShort'), icon: 'warning' });
      } else {
        list.push({ label: t('monitor.kpi.ple'), value: g.page_life_expectancy === undefined ? '—' : fmtDuration(g.page_life_expectancy * 1000), icon: 'timer' });
        list.push({ label: t('monitor.kpi.blocked'), value: g.blocked === undefined ? '—' : fmtNumber(g.blocked), icon: 'warning' });
      }
      setChildren(tiles, list.map(statCard));
    }

    // ---------------------------------------------------------------- long-running
    async function kill(id) {
      const ok = await confirmDialog({
        title: t('admin.proc.killTitle', { id }), message: t('admin.proc.killMessage'), danger: true, confirmLabel: t('admin.proc.kill'),
        sql: isPg ? `SELECT pg_terminate_backend(${Number(id)});` : `KILL ${Number(id)};`,
      });
      if (!ok) return;
      try { await api('server.kill', { conn, id: String(id) }); toast.success(t('admin.proc.killed', { id })); } catch (e) { toastError(e); }
    }

    function drawLong(snap) {
      const rows = snap?.long || [];
      if (!rows.length) { setChildren(longBox, h('p', { class: 'px-4 py-3 text-[13px] text-muted' }, t('monitor.long.none'))); return; }
      setChildren(longBox, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {}, ['id', 'user', 'db', 'time', 'state', 'query'].map((k) => h('th', { class: cls(k === 'time' && 'num') }, t(`monitor.long.${k}`))),
          canKill ? h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))) : null)),
        h('tbody', {}, rows.map((r) => h('tr', { class: cls(r.time >= 60 && '[&>td]:bg-warning/10') },
          h('td', { class: 'num' }, r.id), h('td', { dir: 'auto' }, r.user ?? '—'), h('td', { dir: 'auto' }, r.db ?? '—'),
          h('td', { class: cls('num whitespace-nowrap', r.time >= 60 && 'font-semibold text-warning') }, fmtDuration(r.time * 1000)),
          h('td', { class: 'whitespace-nowrap text-muted', dir: 'auto' }, r.state ?? '—'),
          h('td', { class: 'max-w-[28rem]' }, h('button', { type: 'button', class: 'block max-w-full truncate text-start font-mono text-[12px] hover:text-accent', dir: 'ltr', title: r.query, onClick: () => viewerDialog({ title: t('admin.proc.query', { id: r.id }), text: r.query || '', language: 'sql' }) }, r.query || '—')),
          canKill ? h('td', { class: 'w-10 text-end' }, h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('admin.proc.killNamed', { id: r.id }), title: t('admin.proc.kill'), onClick: () => kill(r.id) }, icon('circle-x'))) : null)))));
    }

    // ---------------------------------------------------------------- polling
    function redraw() {
      const now = last?.ts ? Math.max(Date.now(), last.ts) : Date.now();
      const pts = ring.toArray();
      charts.forEach((c) => c.update(pts, now - windowMin * 60000, now));
      drawTiles(pts, last);
    }

    function setStatus() {
      const live = !paused && document.visibilityState === 'visible';
      setChildren(status, h('span', { class: cls('inline-block size-2 rounded-full', live ? 'bg-success' : 'bg-subtle') }), t(live ? 'monitor.live' : 'monitor.paused'));
      setChildren(pauseBtn, icon(paused ? 'play' : 'pause'), t(paused ? 'monitor.resume' : 'monitor.pause'));
      pauseBtn.setAttribute('aria-pressed', String(paused));
    }

    async function tick() {
      timer = null;
      if (stopped || paused || document.visibilityState !== 'visible') return;
      ctrl = new AbortController();
      try {
        const snap = await api('server.metrics', { conn }, { signal: ctrl.signal });
        if (stopped) return;
        // Samples closer than 1 s (e.g. right after changing the interval) give noisy rates: skip.
        if (last && snap.ts - last.ts < 1000) throw Object.assign(new Error('skip'), { code: 'skip' });
        const p = derive(last, snap);
        // A long gap (tab was hidden, network stall) is drawn as a gap, not as an averaged line.
        if (last && snap.ts - last.ts > interval * 3000) p.reset = true;
        last = snap;
        ring.push(p).prune(snap.ts - MAX_MS);
        failures = 0;
        errBox.replaceChildren();
        redraw();
        drawLong(snap);
      } catch (e) {
        if (stopped || e.code === 'aborted') return;
        if (e.code === 'skip') { timer = setTimeout(tick, interval * 1000); return; }
        failures++;
        errBox.replaceChildren(callout('danger', `${t('monitor.error')} ${e.message}`));
        if (e.code === 'auth_required' || e.code === 'unsupported' || failures >= 5) { setPaused(true); return; }
      } finally {
        ctrl = null;
      }
      if (!stopped && !paused && !timer) timer = setTimeout(tick, interval * 1000);
    }

    function halt() { clearTimeout(timer); timer = null; ctrl?.abort(); ctrl = null; }
    function restart() { halt(); setStatus(); if (!paused) tick(); }
    function setPaused(v) { paused = v; restart(); }
    const onVis = () => { if (document.visibilityState === 'visible') restart(); else { halt(); setStatus(); } };
    document.addEventListener('visibilitychange', onVis);
    stopFn = () => { stopped = true; halt(); document.removeEventListener('visibilitychange', onVis); charts.forEach((c) => c.destroy()); stopFn = null; };

    setStatus();
    drawTiles([], null);
    drawLong(null);
    redraw();
    tick();
    import('./_monitor-panels.js').then((m) => {
      if (stopped) return;
      const pc = { conn, caps, isPg, stopped: () => stopped, selectEl };
      loadTop = () => m.topQueries(topBox, pc);
      loadAdvice = () => m.advicePanel(adviceBox, pc);
      loadTop();
      loadAdvice();
    }, (e) => setChildren(topBox, errorState(e)));
  },

  destroy() {
    stopFn?.();
  },
};
