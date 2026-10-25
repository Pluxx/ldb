/**
 * Server monitor panels loaded lazily below the charts: top statements (server.slowLog: sortable,
 * open in SQL editor, EXPLAIN) and tuning hints (server.advice).
 * @module views/_monitor-panels
 */
import { h, cls, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { viewerDialog } from '../ui/modal.js';
import { toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, callout, refreshButton } from '../ui/feedback.js';
import { t, fmtNumber, fmtBytes, fmtDuration } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore } from '../store.js';
import { href, navigate } from '../router.js';

const SEV = { critical: ['circle-alert', 'text-danger'], warning: ['warning', 'text-warning'], info: ['info', 'text-info'] };
const EXPLAINABLE = /^\s*(select|with|insert|update|delete|replace|table)\b/i;
const state = { source: 'auto', order: 'total', sort: null };

/** Translate advice params: numbers localised, *bytes keys as sizes. */
function adviceParams(p) {
  const out = {};
  for (const [k, v] of Object.entries(p || {})) out[k] = typeof v === 'number' ? (/bytes$/.test(k) ? fmtBytes(v) : fmtNumber(v)) : String(v);
  return out;
}

// ---------------------------------------------------------------- top statements

const hintText = (code) => h('div', { class: 'space-y-1.5' },
  h('p', {}, t(`monitor.top.hint.${code}`)),
  h('pre', { class: 'overflow-auto rounded-md bg-surface-2 p-2 font-mono text-xs', dir: 'ltr' }, t(`monitor.top.hint.${code}Sql`)));

async function explain(conn, r) {
  try {
    const q = await api('sql.explain', { conn, db: r.db || undefined, sql: r.sql });
    const cols = (q.columns || []).map((c) => (typeof c === 'string' ? c : c.name));
    const text = q.rows?.length && cols.length === 1 ? q.rows.map((x) => String(x[0] ?? '')).join('\n') : (q.rows || []).map((x) => cols.map((c, i) => `${c}: ${x[i] ?? 'NULL'}`).join('\n')).join('\n\n');
    viewerDialog({ title: t('monitor.top.explain'), text: text || '—', language: 'plain' });
  } catch (e) { toastError(e); }
}

function openSql(conn, r) {
  appStore.set({ pendingSql: { sql: r.sql || r.text, db: r.db || undefined } });
  navigate(href.sql(conn, { db: r.db || undefined }));
}

export async function topQueries(topBox, pc) {
  const { conn, caps, isPg, selectEl } = pc;
  const loadTop = () => topQueries(topBox, pc);
  setChildren(topBox, skeleton({ rows: 3 }));
  let data;
  try { data = await api('server.slowLog', { conn, limit: 25, source: state.source, order: state.order }); } catch (e) { setChildren(topBox, errorState(e, { onRetry: loadTop })); return; }
  if (pc.stopped()) return;
  const bar = h('div', { class: 'flex flex-wrap items-center gap-2' },
    data.sources.length > 1 ? selectEl(t('monitor.top.source'), data.sources, data.source, (v) => t(`monitor.top.src.${v}`), (v) => { state.source = v; loadTop(); }) : (data.source !== 'none' ? h('span', { class: 'badge' }, t(`monitor.top.src.${data.source}`)) : null),
    data.source !== 'slow_log' && data.source !== 'none' ? selectEl(t('monitor.top.order'), ['total', 'avg', 'count'], state.order, (v) => t(`monitor.top.by.${v}`), (v) => { state.order = v; state.sort = null; loadTop(); }) : null,
    h('div', { class: 'flex-1' }), refreshButton(loadTop));
  if (data.source === 'none') { setChildren(topBox, bar, callout('info', hintText(data.hint || 'mysql_enable'))); return; }
  if (!data.rows.length) { setChildren(topBox, bar, h('div', { class: 'card' }, emptyState({ icon: 'search', title: t('monitor.top.none') }))); return; }

  const colsDef = [
    ['text', 'query'], ['db', 'db'], ['count', 'count', true], ['total_ms', 'total', true], ['avg_ms', 'avg', true], ['max_ms', 'max', true],
    ['rows_examined', isPg ? 'blocks' : 'examined', true], ['rows_sent', 'sent', true], ['no_index', 'noIndex', true],
  ].filter(([k]) => data.rows.some((r) => r[k] !== null && r[k] !== undefined));
  const table = h('table', { class: 'table-grid' });
  const drawTable = () => {
    const rows = data.rows.slice();
    if (state.sort) rows.sort((a, b) => { const x = a[state.sort.key]; const y = b[state.sort.key]; const c = typeof x === 'number' && typeof y === 'number' ? x - y : String(x ?? '').localeCompare(String(y ?? '')); return state.sort.dir === 'asc' ? c : -c; });
    setChildren(table,
      h('thead', {}, h('tr', {}, colsDef.map(([k, label, isNum]) => h('th', { class: cls(isNum && 'num', 'whitespace-nowrap'), 'aria-sort': state.sort?.key === k ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : null },
        h('button', { type: 'button', class: 'inline-flex items-center gap-1 hover:text-fg', onClick: () => { state.sort = { key: k, dir: state.sort?.key === k && state.sort.dir === 'desc' ? 'asc' : 'desc' }; drawTable(); } },
          t(`monitor.top.col.${label}`), state.sort?.key === k ? icon(state.sort.dir === 'asc' ? 'arrow-up' : 'arrow-down', 'size-3') : null))),
      h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
      h('tbody', {}, rows.map((r) => h('tr', {}, colsDef.map(([k, , isNum]) => {
        if (k === 'text') {
          return h('td', { class: 'max-w-[26rem]' }, h('button', { type: 'button', class: 'block max-w-full truncate text-start font-mono text-[12px] hover:text-accent', dir: 'ltr', title: r.text, onClick: () => viewerDialog({ title: t('monitor.top.col.query'), text: r.text || '', language: 'sql' }) }, r.text || '—'));
        }
        const v = r[k];
        const shown = v === null || v === undefined ? '—' : /_ms$/.test(k) ? fmtDuration(v) : isNum ? fmtNumber(v) : v;
        return h('td', { class: cls(isNum ? 'num whitespace-nowrap' : 'whitespace-nowrap', k === 'no_index' && v > 0 && 'text-warning'), dir: isNum ? null : 'auto' }, shown);
      }),
      h('td', { class: 'whitespace-nowrap text-end' },
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('monitor.top.openSql'), 'aria-label': t('monitor.top.openSql'), onClick: () => openSql(conn, r) }, icon('terminal')),
        caps.explain !== false ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('monitor.top.explain'), 'aria-label': t('monitor.top.explain'), disabled: !(r.sql && EXPLAINABLE.test(r.sql)), onClick: () => explain(conn, r) }, icon('workflow')) : null)))));
  };
  drawTable();
  setChildren(topBox, bar, h('div', { class: 'card overflow-x-auto' }, table));
}

// ---------------------------------------------------------------- advice
export async function advicePanel(adviceBox, pc) {
  const { conn } = pc;
  const loadAdvice = () => advicePanel(adviceBox, pc);
  setChildren(adviceBox, skeleton({ rows: 2 }));
  let data;
  try { data = await api('server.advice', { conn }); } catch (e) { setChildren(adviceBox, errorState(e, { onRetry: loadAdvice })); return; }
  if (pc.stopped()) return;
  if (!data.hints.length) { setChildren(adviceBox, callout('success', t('monitor.advice.none'))); return; }
  setChildren(adviceBox, h('ul', { class: 'card divide-y divide-border' }, data.hints.map((hint) => {
    const [ic, color] = SEV[hint.severity] || SEV.info;
    const p = adviceParams(hint.params);
    const k = `monitor.advice.${hint.id}`;
    return h('li', { class: 'flex items-start gap-3 p-3 sm:p-4' },
      h('span', { class: cls('mt-0.5 shrink-0', color) }, icon(ic)),
      h('div', { class: 'min-w-0 flex-1 space-y-1', dir: 'auto' },
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          h('span', { class: 'text-[13.5px] font-medium' }, t(`${k}.title`, p)),
          h('span', { class: cls('badge', hint.severity === 'critical' && 'badge-danger', hint.severity === 'warning' && 'badge-warning') }, t(`monitor.severity.${hint.severity}`)),
          h('code', { class: 'badge font-mono', dir: 'ltr' }, hint.metric)),
        h('p', { class: 'text-[13px] text-muted' }, t(`${k}.detail`, p)),
        h('p', { class: 'text-[13px]' }, t(`${k}.suggestion`, p))));
  })), h('p', { class: 'text-xs text-subtle' }, t('monitor.advice.disclaimer')));
}
