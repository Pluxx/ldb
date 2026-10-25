/**
 * Database-wide search (db.search): term, mode (contains / exact / regex where the driver
 * supports it), optional table subset. Results are grouped per table with per-column match
 * counts; a column opens the table browse tab filtered on that column (?where=…), a table
 * header opens browse with the same filter OR-ed through the browse search box (contains) or
 * the first matching column. Regex matches open the SQL editor with a ready SELECT instead,
 * since table.browse has no regex operator. The last search lives in the URL (?term=&mode=
 * &tables=) so "back" from a result restores it.
 */
import { h, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { emptyState, errorState, spinner, callout } from '../ui/feedback.js';
import { t, plural, fmtNumber } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore } from '../store.js';
import { href, navigate } from '../router.js';
import { getObjects, getOverview } from '../data.js';

let abort = null;

export default {
  title: (ctx) => `${t('nav.dbSearch')} · ${ctx.db}`,

  async render(el, ctx) {
    const { conn, db, schema, params } = ctx;
    const [objects, overview] = await Promise.all([getObjects(conn, db, schema), getOverview(conn).catch(() => null)]);
    const driver = overview?.info?.driver || '';
    const regexOk = driver === 'mysql' || driver === 'pgsql';
    const quote = driver && driver !== 'mysql' ? '"' : '`';
    const qi = (n) => quote + String(n).split(quote).join(quote + quote) + quote;
    const allTables = (objects.tables || []).filter((x) => x.type !== 'view').map((x) => x.name);

    let mode = ['contains', 'exact', 'regex'].includes(params.mode) && (params.mode !== 'regex' || regexOk) ? params.mode : 'contains';
    const picked = new Set(params.tables ? params.tables.split(',').filter((n) => allTables.includes(n)) : allTables);

    // ----- form
    const termIn = h('input', {
      type: 'search', name: 'term', class: 'input', required: true, dir: 'auto', value: params.term || '', 'data-autofocus': true, enterkeyhint: 'search',
      placeholder: t('dbSearch.termPlaceholder'), 'aria-label': t('dbSearch.term'),
    });
    const modeGroup = h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': t('dbSearch.mode') });
    const drawModes = () => setChildren(modeGroup, ['contains', 'exact', ...(regexOk ? ['regex'] : [])].map((m) => h('button', {
      type: 'button', role: 'radio', 'aria-checked': String(m === mode), title: t(`dbSearch.modeHint.${m}`),
      onClick: () => { mode = m; drawModes(); },
    }, t(`dbSearch.modes.${m}`))));
    drawModes();

    const tablesSummary = h('span');
    const tablesBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-expanded': 'false', 'aria-controls': 'dbsearch-tables', onClick: () => toggleTables() }, icon('table'), tablesSummary, icon('chevron-down', 'size-3.5 text-muted'));
    const tableFilter = h('input', { type: 'search', class: 'input input-sm w-full sm:w-56', placeholder: t('db.filter'), 'aria-label': t('db.filter'), dir: 'auto', onInput: () => drawTableList() });
    const tableList = h('div', { class: 'grid max-h-64 grid-cols-1 gap-x-4 overflow-auto py-1 sm:grid-cols-2 lg:grid-cols-3' });
    const tablesPanel = h('div', { id: 'dbsearch-tables', class: 'space-y-2 border-t border-border pt-3', hidden: true },
      h('div', { class: 'flex flex-wrap items-center gap-2' }, tableFilter, h('div', { class: 'flex-1' }),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { allTables.forEach((n) => picked.add(n)); drawTableList(); } }, t('dbSearch.selectAll')),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { picked.clear(); drawTableList(); } }, t('dbSearch.selectNone'))),
      tableList);
    function toggleTables(force) {
      const show = force ?? tablesPanel.hidden;
      tablesPanel.hidden = !show;
      tablesBtn.setAttribute('aria-expanded', String(show));
      if (show) drawTableList();
    }
    function drawSummary() {
      tablesSummary.textContent = picked.size === allTables.length ? t('dbSearch.allTables', { n: fmtNumber(allTables.length) }) : t('dbSearch.someTables', { n: fmtNumber(picked.size), total: fmtNumber(allTables.length) });
    }
    function drawTableList() {
      const q = tableFilter.value.trim().toLowerCase();
      setChildren(tableList, allTables.filter((n) => !q || n.toLowerCase().includes(q)).map((n) => h('label', { class: 'flex min-h-8 cursor-pointer items-center gap-2 rounded px-1 text-[13px] hover:bg-surface-2' },
        h('input', { type: 'checkbox', class: 'checkbox', checked: picked.has(n), onChange: (e) => { if (e.target.checked) picked.add(n); else picked.delete(n); drawSummary(); } }),
        h('span', { class: 'truncate', dir: 'auto' }, n))));
      drawSummary();
    }
    drawSummary();

    const submitBtn = h('button', { type: 'submit', class: 'btn btn-primary' }, icon('search'), t('dbSearch.submit'));
    const form = h('form', { class: 'card space-y-3 p-3 sm:p-4', role: 'search', onSubmit: (e) => { e.preventDefault(); run(); } },
      h('div', { class: 'flex flex-col gap-2 sm:flex-row' }, h('div', { class: 'min-w-0 flex-1' }, termIn), submitBtn),
      h('div', { class: 'flex flex-wrap items-center gap-2' }, modeGroup, h('div', { class: 'flex-1' }), tablesBtn),
      tablesPanel);

    const results = h('div', { class: 'space-y-3', 'aria-live': 'polite' });
    el.replaceChildren(
      h('div', { class: 'flex items-center gap-2 border-b border-border bg-surface px-4 py-3' },
        icon('text-search', 'text-muted'), h('h1', { class: 'text-[15px] font-semibold' }, t('dbSearch.title')),
        h('a', { href: href.db(conn, db, schema), class: 'truncate text-[13px] text-muted hover:text-fg', dir: 'auto' }, schema ? `${db}.${schema}` : db)),
      h('div', { class: 'min-h-0 flex-1 overflow-auto' }, h('div', { class: 'mx-auto w-full max-w-5xl space-y-4 p-3 sm:p-6' }, form, results)));

    // ----- result links
    const filterFor = (column, term, m) => [{ column, op: m === 'exact' ? '=' : 'contains', value: term }];
    function regexSql(table, columns, term) {
      const op = driver === 'pgsql' ? '~*' : 'REGEXP';
      const lit = `'${(driver === 'mysql' ? term.replace(/\\/g, '\\\\') : term).replace(/'/g, "''")}'`;
      return `SELECT *\nFROM ${qi(table)}\nWHERE ${columns.map((c) => `${qi(c)} ${op} ${lit}`).join('\n   OR ')}\nLIMIT 100;`;
    }
    function openResult(table, columns, term, m) {
      if (m === 'regex') {
        appStore.set({ pendingSql: { sql: regexSql(table, columns, term), db, schema } });
        navigate(href.sql(conn, { db, schema }));
      } else if (columns.length === 1) {
        navigate(href.table(conn, db, schema, table, 'browse', { where: JSON.stringify(filterFor(columns[0], term, m)) }));
      } else {
        // several columns: browse search box (contains over all columns) or the first column for exact
        navigate(href.table(conn, db, schema, table, 'browse', m === 'contains' ? { search: term } : { where: JSON.stringify(filterFor(columns[0], term, m)) }));
      }
    }

    function drawResults(list, term, m) {
      if (!list.length) {
        setChildren(results, emptyState({ icon: 'text-search', title: t('dbSearch.noResults'), message: t('dbSearch.noResultsHint', { term }) }));
        return;
      }
      const sorted = [...list].sort((a, b) => b.count - a.count || String(a.table).localeCompare(String(b.table)));
      const total = sorted.reduce((s, r) => s + r.count, 0);
      setChildren(results,
        h('p', { class: 'text-[13px] text-muted', dir: 'auto' }, t('dbSearch.summary', { rows: plural('dbSearch.rows', total), tables: plural('dbSearch.tables', sorted.length) })),
        sorted.map((r) => {
          const cols = Object.entries(r.column_matches || {}).sort((a, b) => b[1] - a[1]);
          const max = Math.max(1, ...cols.map((c) => c[1]));
          return h('section', { class: 'card overflow-hidden' },
            h('div', { class: 'flex items-center gap-2 border-b border-border bg-surface-2/50 px-3 py-2' },
              icon('table', 'text-muted'),
              h('button', { type: 'button', class: 'min-w-0 truncate text-start text-[13.5px] font-semibold hover:text-accent', dir: 'auto', title: t('dbSearch.openTable'), onClick: () => openResult(r.table, cols.map((c) => c[0]), term, m) }, r.table),
              h('span', { class: 'badge badge-accent ms-auto shrink-0', dir: 'auto' }, plural('dbSearch.rows', r.count))),
            h('ul', { class: 'divide-y divide-border' }, cols.map(([col, n]) => h('li', {},
              h('button', {
                type: 'button', class: 'flex min-h-10 w-full items-center gap-3 px-3 py-1.5 text-start text-[13px] hover:bg-surface-2',
                title: t('dbSearch.openColumn', { column: col }), onClick: () => openResult(r.table, [col], term, m),
              },
              h('span', { class: 'min-w-0 flex-1 truncate font-mono text-[12.5px]', dir: 'auto' }, col),
              h('span', { class: 'hidden h-1.5 w-24 overflow-hidden rounded-full bg-surface-3 sm:block', 'aria-hidden': 'true' },
                h('span', { class: 'block h-full rounded-full bg-accent', style: { width: `${Math.max(4, Math.round((n / max) * 100))}%` } })),
              h('span', { class: 'w-16 shrink-0 text-end tabular-nums text-muted' }, fmtNumber(n)),
              icon('chevron-right', 'size-3.5 text-subtle'))))));
        }));
    }

    async function run() {
      const term = termIn.value;
      if (!term.trim()) { termIn.focus(); return; }
      if (!picked.size) { setChildren(results, callout('warning', t('dbSearch.noTablesSelected'))); return; }
      const all = picked.size === allTables.length;
      const m = mode;
      // keep the search in the URL without re-rendering the view
      try { history.replaceState(history.state, '', href.dbSearch(conn, db, schema, { term, mode: m, tables: all ? undefined : [...picked].join(',') })); } catch { /* ignore */ }
      abort?.abort();
      abort = new AbortController();
      const my = abort;
      submitBtn.disabled = true;
      setChildren(results, h('div', { class: 'flex items-center justify-center gap-2 py-10 text-[13px] text-muted' }, spinner(), t('dbSearch.searching')));
      try {
        const list = await api('db.search', { conn, db, schema, term, mode: m, tables: all ? undefined : [...picked] }, { signal: my.signal });
        if (my !== abort) return;
        drawResults(list || [], term, m);
      } catch (e) {
        if (e.code === 'aborted') return;
        setChildren(results, errorState(e, { onRetry: () => run() }));
      } finally {
        if (my === abort) submitBtn.disabled = false;
      }
    }

    if (!allTables.length) {
      setChildren(results, emptyState({ icon: 'table', title: t('db.noTables') }));
      submitBtn.disabled = true;
    } else if (params.term) run();
  },

  destroy() {
    abort?.abort();
    abort = null;
  },
};
