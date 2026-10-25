/**
 * SQL editor view: multi-tab editors persisted in localStorage, run (selection / statement
 * under cursor / all), explain, format, results in tabs per statement (time, affected rows,
 * editable grids when the server returns `editable`), query history with search, saved
 * queries (browser-local, Ctrl/Cmd+Shift+S, export/import JSON; migrated from the old
 * "snippets"), shareable links (?q=<base64url SQL>, never auto-run), resizable editor/results split.
 *
 * Also used embedded as the table "SQL" tab (ctx.embedded): single editor prefilled with a
 * SELECT for that table, no editor tabs.
 * Route params: ?db=&schema= (context), ?new=1 (open a fresh tab), ?q= (shared SQL → opens a NEW
 * tab, not run; the param is removed from the URL afterwards). appStore.pendingSql
 * ({sql, db, schema}) hands over SQL from other views ("open in SQL editor").
 */
import { h, uid, dragPointer, copyText, downloadText, isCompact, setChildren, on, isMac, modKey } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { createSqlEditor } from '../ui/sqleditor.js';
import { createResultView } from '../ui/resultview.js';
import { createTabs } from '../ui/tabs.js';
import { openModal, promptDialog, confirmDialog } from '../ui/modal.js';
import { bindMenu } from '../ui/menu.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { callout, emptyState, spinner } from '../ui/feedback.js';
import { t, plural, fmtNumber, fmtDuration, fmtDate } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore, storage } from '../store.js';
import { connections } from '../session.js';
import { encodeSqlParam, decodeSqlParam, serverHint, absoluteUrl, MAX_LINK_SQL_BYTES } from '../share.js';
import {
  loadSaved, persistSaved, addEntry, updateEntry, duplicateEntry, removeEntry, filterEntries, serializeExport,
  parseImport, mergeImport, MAX_SAVED, MAX_IMPORT_BYTES,
} from '../sql/saved-queries.js';
import { href, navigate } from '../router.js';
import { getDatabases, getCompletions, getOverview, invalidate } from '../data.js';
import { highlightSql } from '../sql/highlight.js';
import { splitStatements } from '../sql/split.js';
import { analyzeRisk, confirmRisks, risksForTx } from '../ui/sqlguard.js';

const HISTORY_MAX = 200;
const MUTATING = /^\s*(create|drop|alter|rename|truncate)\b/i;
const TX_MODES = ['none', 'atomic', 'dryrun'];
const TX_ICONS = { none: 'zap', atomic: 'layers', dryrun: 'eye' };

/** Random token for sql.execute/sql.cancel (server accepts [A-Za-z0-9_-]{8,64}). */
function newRunId() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}

/** "3.2 s" — one decimal, for the running ticker and cancel messages. */
const secs = (ms) => fmtNumber(Math.max(0, ms) / 1000, { style: 'unit', unit: 'second', unitDisplay: 'short', minimumFractionDigits: 1, maximumFractionDigits: 1 });

let cleanup = [];

export default {
  title: (ctx) => (ctx.embedded ? `${ctx.table} · ${t('table.tab.sql')}` : t('nav.sql')),

  async render(el, ctx) {
    cleanup = [];
    const { conn } = ctx;
    const embedded = !!ctx.embedded;
    const overview = await getOverview(conn).catch(() => null);
    const quote = overview?.info?.driver && overview.info.driver !== 'mysql' ? '"' : '`';
    // Stop needs DriverInterface::cancelBackend (not SQLite: in-process engine).
    const canCancel = !!overview?.capabilities?.cancel;
    const qi = (n) => quote + String(n).split(quote).join(quote + quote) + quote;

    // ----- persisted editor tabs
    const tabsKey = embedded ? `sql.table.${conn}.${ctx.db}.${ctx.table}` : 'sql.tabs';
    let store = storage.get(tabsKey, null);
    if (!store || !Array.isArray(store.tabs) || !store.tabs.length) {
      const initial = embedded ? `SELECT *\nFROM ${qi(ctx.table)}\nLIMIT 100;` : '';
      store = { tabs: [{ id: uid('q'), title: t('sql.untitled', { n: 1 }), sql: initial, db: ctx.db || null, schema: ctx.schema || null }], active: null };
    }
    store.active = store.tabs.some((x) => x.id === store.active) ? store.active : store.tabs[0].id;
    const save = () => storage.set(tabsKey, store);
    const activeTab = () => store.tabs.find((x) => x.id === store.active);
    let counter = store.tabs.length;
    const newTab = (sql = '', db = ctx.db || activeTab()?.db || null, schema = ctx.schema || null) => {
      counter++;
      const tab = { id: uid('q'), title: t('sql.untitled', { n: counter }), sql, db, schema };
      store.tabs.push(tab);
      store.active = tab.id;
      save();
      return tab;
    };

    const pending = appStore.get().pendingSql;
    if (ctx.params.q && !embedded) {
      // Shared link: always a NEW tab, never run. Strip the params so a re-render doesn't add another.
      const shared = decodeSqlParam(ctx.params.q);
      if (shared) {
        newTab(shared, ctx.params.db || activeTab()?.db || null, ctx.params.schema || null);
        toast.info(t('share.sqlOpened'));
      } else toast.error(t('share.sqlInvalid'));
      history.replaceState(history.state, '', href.sql(conn, { db: ctx.params.db, schema: ctx.params.schema }));
    } else if (pending && !embedded) {
      appStore.set({ pendingSql: null });
      const cur = activeTab();
      if (cur && !cur.sql.trim()) { cur.sql = pending.sql; cur.db = pending.db ?? cur.db; cur.schema = pending.schema ?? null; save(); }
      else newTab(pending.sql, pending.db ?? null, pending.schema ?? null);
    } else if (ctx.params.new && !embedded && activeTab().sql.trim()) {
      newTab('', ctx.params.db || activeTab().db, ctx.params.schema || null);
    } else if (!embedded && ctx.params.db && activeTab()) {
      activeTab().db = ctx.params.db;
      activeTab().schema = ctx.params.schema || null;
    }
    const results = new Map(); // tab id → {items: QueryResult[], active: number}
    let shownTabId = store.active; // tab whose text the editor currently holds

    // Running query (one at a time per view)
    /** @type {null|{tabId: string, runId: string, started: number, stopping: boolean}} */
    let runState = null;
    let tickerEl = null;
    let resultStopBtn = null;
    let tickTimer = null;

    // ----- toolbar
    const dbSel = h('select', { class: 'select input-sm w-auto max-w-[8.5rem] sm:max-w-[11rem]', 'aria-label': t('sql.database'), disabled: embedded, onChange: () => { const tb = activeTab(); tb.db = dbSel.value || null; tb.schema = null; save(); loadCompletions(); } },
      h('option', { value: '' }, t('sql.noDatabase')));
    getDatabases(conn).then((dbs) => {
      const cur = activeTab()?.db;
      dbs.forEach((d) => dbSel.appendChild(h('option', { value: d.name, selected: d.name === cur }, d.name)));
      if (cur && !dbs.some((d) => d.name === cur)) dbSel.appendChild(h('option', { value: cur, selected: true }, cur));
    }).catch(() => {});

    const mod = isMac() ? '⌘' : 'Ctrl';
    const runIdle = [icon('play'), h('span', {}, t('sql.run')), h('kbd', { class: 'kbd hidden border-white/30 bg-white/15 text-current lg:inline-flex' }, `${mod}↵`)];
    const runBtn = h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: () => (runState ? stop() : runSelectionOrAll()) }, ...runIdle);
    const explainBtn = h('button', { type: 'button', class: 'btn btn-sm', title: t('sql.explain'), onClick: () => explain() }, icon('workflow'), h('span', { class: 'hidden md:inline' }, t('sql.explain')));
    const formatBtn = h('button', { type: 'button', class: 'btn btn-sm', title: `${t('sql.format')} (${mod}+Shift+F)`, onClick: () => editor.format().catch((e) => toastError(e)) }, icon('wand'), h('span', { class: 'hidden md:inline' }, t('sql.format')));
    const saveBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon max-sm:hidden', title: `${t('savedQueries.saveHint')} (${mod}+Shift+S)`, 'aria-label': t('savedQueries.saveHint'), 'aria-keyshortcuts': isMac() ? 'Meta+Shift+S' : 'Control+Shift+S', onClick: () => saveCurrent() }, icon('bookmark-plus'));
    const shareBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon max-2xl:hidden', title: t('share.queryLink'), 'aria-label': t('share.queryLink'), onClick: () => copyCurrentLink() }, icon('link'));
    const historyBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-pressed': 'false', onClick: () => togglePanel('history') }, icon('history'), h('span', { class: 'hidden md:inline' }, t('sql.history')));
    const savedBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-pressed': 'false', title: t('savedQueries.title'), onClick: () => togglePanel('saved') }, icon('bookmark'), h('span', { class: 'hidden md:inline' }, t('savedQueries.title')));
    const limitSel = h('select', { class: 'select input-sm w-auto max-sm:hidden', 'aria-label': t('sql.limit') },
      [100, 1000, 5000, 10000].map((n) => h('option', { value: String(n), selected: n === Number(storage.get('sql.limit', 1000)) }, t('sql.limitN', { n: fmtNumber(n) }))));
    limitSel.addEventListener('change', () => storage.set('sql.limit', Number(limitSel.value)));

    // Transaction mode (persisted). Each run is one request on its own connection, so a
    // transaction never stays open between runs (explained in the tooltip).
    let txMode = storage.get('sql.tx', 'none');
    if (!TX_MODES.includes(txMode)) txMode = 'none';
    const txGroup = h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': t('sql.tx.label') });
    const drawTx = () => setChildren(txGroup, TX_MODES.map((m) => h('button', {
      type: 'button', role: 'radio', 'aria-checked': String(m === txMode), 'aria-label': t(`sql.tx.${m}`),
      title: `${t(`sql.tx.${m}`)}: ${t(`sql.tx.hint.${m}`)}\n\n${t('sql.tx.note')}`,
      onClick: () => { txMode = m; storage.set('sql.tx', m); drawTx(); },
    }, icon(TX_ICONS[m]), h('span', { class: m === txMode ? 'max-sm:hidden' : 'hidden 2xl:inline' }, t(`sql.tx.short.${m}`)))));
    drawTx();

    const guardEnabled = () => storage.get('sql.guard', true) !== false;
    const settingsBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon', title: t('sqlGuard.settingsLabel'), 'aria-label': t('sqlGuard.settingsLabel') }, icon('settings'));
    bindMenu(settingsBtn, () => [
      { label: t('savedQueries.save'), icon: 'bookmark-plus', hint: `${mod}+Shift+S`, onSelect: () => saveCurrent() },
      { label: t('share.queryLink'), icon: 'link', onSelect: () => copyCurrentLink() },
      { separator: true },
      { label: t('sqlGuard.setting'), checked: guardEnabled(), onSelect: () => storage.set('sql.guard', !guardEnabled()) },
    ], { align: 'end' });

    const toolbar = h('div', { class: 'flex flex-wrap items-center gap-1.5 border-b border-border bg-surface px-3 py-2', role: 'toolbar', 'aria-label': t('sql.toolbar') },
      embedded ? null : dbSel, runBtn, txGroup, explainBtn, formatBtn, saveBtn, shareBtn, limitSel, h('div', { class: 'flex-1' }), historyBtn, savedBtn, settingsBtn);

    // ----- editor
    const editor = createSqlEditor({
      value: activeTab().sql,
      quote,
      dialect: overview?.info?.driver,
      ariaLabel: t('editor.label'),
      placeholder: t('sql.placeholder'),
      className: 'min-h-0 flex-1',
      onChange: (v) => { const tb = activeTab(); if (tb) { tb.sql = v; saveDebounced(); } },
      onRun: (sql) => run(sql),
      onFormat: async (sql) => (await api('sql.format', { sql })).sql,
    });
    let saveTimer = null;
    const saveDebounced = () => { clearTimeout(saveTimer); saveTimer = setTimeout(save, 400); };
    cleanup.push(() => { clearTimeout(saveTimer); save(); editor.destroy(); });

    async function loadCompletions() {
      const tb = activeTab();
      if (!tb?.db) { editor.setCompletions({ tables: {}, keywords: undefined, functions: [] }); return; }
      try { editor.setCompletions(await getCompletions(conn, tb.db, tb.schema || undefined)); } catch { /* optional */ }
    }

    const editorTabs = embedded ? null : createTabs({
      ariaLabel: t('sql.tabs'),
      variant: 'underline',
      className: 'bg-surface px-2',
      items: tabItems(),
      active: store.active,
      onSelect: (id) => switchTab(id),
      onAdd: () => { newTab(''); refreshTabs(); switchTab(store.active, true); },
      onClose: (id) => closeTab(id),
      onDblClick: async (id) => {
        const tb = store.tabs.find((x) => x.id === id);
        const name = await promptDialog({ title: t('sql.renameTab'), label: t('sql.tabName'), value: tb.title });
        if (name) { tb.title = name; save(); refreshTabs(); }
      },
    });
    function tabItems() {
      return store.tabs.map((x) => ({ id: x.id, label: x.title, closable: store.tabs.length > 1, dir: 'auto', title: x.db ? `${x.title} · ${x.db}` : x.title }));
    }
    function refreshTabs() { editorTabs?.setItems(tabItems(), store.active); }
    function switchTab(id, force) {
      if (!force && id === store.active && editor.getValue() === activeTab().sql) return;
      const cur = store.tabs.find((x) => x.id === shownTabId); // not activeTab(): newTab() already switched store.active
      if (cur) cur.sql = editor.getValue();
      store.active = id;
      shownTabId = id;
      save();
      editor.setValue(activeTab().sql);
      dbSel.value = activeTab().db || '';
      refreshTabs();
      renderResults();
      loadCompletions();
      editor.focus();
    }
    function closeTab(id) {
      const i = store.tabs.findIndex((x) => x.id === id);
      if (i < 0 || store.tabs.length === 1) return;
      store.tabs.splice(i, 1);
      results.delete(id);
      if (store.active === id) store.active = store.tabs[Math.max(0, i - 1)].id;
      save();
      refreshTabs();
      switchTab(store.active, true);
    }

    // ----- results
    const resultsBox = h('section', { class: 'flex min-h-0 flex-1 flex-col bg-surface', 'aria-label': t('sql.results') });
    let currentGrid = null;
    let extraGrids = [];

    function renderResults() {
      const r = results.get(store.active);
      currentGrid?.destroy();
      currentGrid = null;
      extraGrids.forEach((g) => g.destroy());
      extraGrids = [];
      if (!r) {
        setChildren(resultsBox, h('div', { class: 'flex flex-1 items-center justify-center overflow-auto' }, emptyState({
          icon: 'play', title: t('sql.noResults'),
          message: t('sql.runHint', { keys: `${mod}+Enter` }),
        })));
        return;
      }
      if (r.running) {
        tickerEl = h('span', { class: 'text-[13px] tabular-nums text-muted', 'aria-live': 'off' }, runState ? secs(performance.now() - runState.started) : '');
        resultStopBtn = canCancel ? h('button', { type: 'button', class: 'btn btn-sm', title: `${t('sql.stopHint')} (Esc)`, onClick: () => stop() }, icon('square'), h('span', {}, t('sql.stop'))) : null;
        setChildren(resultsBox, h('div', { class: 'flex flex-1 flex-col items-center justify-center gap-2' },
          spinner({ size: 'size-5', label: t('sql.running') }), tickerEl, resultStopBtn));
        updateRunBtn();
        return;
      }
      tickerEl = null;
      resultStopBtn = null;
      const items = r.items;
      const tabsEl = items.length > 1 ? createTabs({
        variant: 'pill',
        ariaLabel: t('sql.results'),
        className: 'border-b border-border px-2 py-1 overflow-x-auto',
        items: items.map((q, i) => ({ id: String(i), label: q.label || (q.error ? t('sql.resultError', { n: i + 1 }) : t('sql.resultN', { n: i + 1 })), icon: q.error ? 'circle-alert' : q.is_result_set ? 'table-2' : 'check', badge: q.is_result_set ? fmtNumber(q.rows.length) : null })),
        active: String(r.active),
        onSelect: (id) => { r.active = Number(id); renderResults(); },
      }) : null;
      const q = items[r.active] || items[0];
      const notices = [];
      if (r.cancelled) notices.push(callout('warning', t('sql.cancelledAfter', { time: secs(r.elapsed ?? q.time_ms ?? 0) })));
      (r.warnings || []).forEach((w) => notices.push(callout('warning', w)));
      const noticeBox = notices.length ? h('div', { class: 'space-y-1.5 border-b border-border px-3 py-2' }, notices) : null;
      const shown = [resultHeader(q, r), resultBody(q)];
      setChildren(resultsBox, noticeBox, tabsEl?.el, tabsEl ? tabsEl.panel(h('div', { class: 'flex min-h-0 flex-1 flex-col' }, shown)) : shown);
    }

    function resultHeader(q, r = {}) {
      const bits = [];
      if (q.error_code === 'cancelled') bits.push(h('span', { class: 'badge badge-warning' }, icon('square'), t('sql.cancelled')));
      else if (q.error) bits.push(h('span', { class: 'badge badge-danger' }, icon('circle-alert'), t('sql.failed')));
      else if (q.is_result_set) bits.push(h('span', { class: 'badge badge-success' }, icon('check'), plural('sql.rows', q.rows.length)));
      else bits.push(h('span', { class: 'badge badge-success' }, icon('check'), plural('sql.affected', q.affected ?? 0)));
      if (q.truncated) bits.push(h('span', { class: 'badge badge-warning', title: t('sql.truncatedHint') }, t('sql.truncated')));
      if (q.insert_id) bits.push(h('span', { class: 'badge' }, t('sql.insertId', { id: q.insert_id })));
      if (r.rolledBack) bits.push(h('span', { class: 'badge badge-warning', title: r.tx === 'dryrun' ? t('sql.rolledBackDryRun') : t('sql.rolledBackAtomic') }, icon('refresh'), t('sql.rolledBack')));
      else if (r.tx === 'atomic' && !r.cancelled) bits.push(h('span', { class: 'badge badge-success', title: t('sql.committedHint') }, icon('layers'), t('sql.committed')));
      if (q.editable && !ctx.readOnly) bits.push(h('span', { class: 'badge badge-accent', title: t('sql.editableHint') }, icon('pencil'), t('sql.editable')));
      const actions = [];
      if (q.is_result_set && !q.error) {
        const exp = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-label': t('browse.export'), title: q.more_results?.length ? t('sql.exportFirstSet') : null }, icon('download'), h('span', { class: 'hidden sm:inline' }, t('browse.export')));
        const all = q.rows.map((_, i) => i);
        bindMenu(exp, () => [
          { label: t('grid.copyRowTsv'), icon: 'table-2', onSelect: () => copyText(currentGrid.serialize(all, 'tsv')).then(() => toast.success(t('grid.copiedRows', { n: all.length }))) },
          { label: t('grid.copyJson'), icon: 'braces', onSelect: () => copyText(currentGrid.serialize(all, 'json')).then(() => toast.success(t('grid.copiedRows', { n: all.length }))) },
          { label: t('browse.downloadJson'), icon: 'file-down', onSelect: () => downloadText('result.json', currentGrid.serialize(all, 'json'), 'application/json') },
          { label: t('browse.downloadTsv'), icon: 'file-down', onSelect: () => downloadText('result.tsv', currentGrid.serialize(all, 'tsv'), 'text/tab-separated-values') },
          { separator: true },
          { label: t('export.queryResult'), icon: 'download', onSelect: () => navigate(href.export(conn, { db: activeTab()?.db || undefined, schema: activeTab()?.schema || undefined, query: q.sql })) },
        ], { align: 'end' });
        actions.push(exp);
      }
      return h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border px-3 py-1.5 text-xs' },
        ...bits,
        h('span', { class: 'flex items-center gap-1 text-muted tabular-nums' }, icon('timer', 'size-3.5'), fmtDuration(q.time_ms)),
        h('code', { class: 'min-w-0 flex-1 truncate font-mono text-[11.5px] text-muted', dir: 'ltr', title: q.sql }, q.sql),
        ...actions);
    }

    function resultBody(q) {
      const editable = !ctx.readOnly /* read-only connection */ && q.is_result_set && !q.error && q.editable && q.editable.primary_key?.every((k) => q.columns.some((c) => c.name === k));
      const tb = activeTab();
      const view = createResultView(q, {
        quote,
        columns: q.is_result_set ? q.columns.map((c) => ({ name: c.name, type: c.type, primary: !!q.editable?.primary_key?.includes(c.name) && c.table === q.editable?.table })) : undefined,
        tableName: q.editable?.table || 'result',
        editable: !!editable,
        onEdit: editable ? async (di, ci, value, orig) => {
          const key = Object.fromEntries(q.editable.primary_key.map((k) => [k, orig[q.columns.findIndex((c) => c.name === k)]]));
          await api('table.update', { conn, db: tb.db, schema: tb.schema || undefined, table: q.editable.table, key, values: { [q.columns[ci].name]: value } });
        } : undefined,
      });
      currentGrid = view.grid;
      extraGrids.push(...view.grids.slice(1));
      return view.el;
    }

    // ----- running
    function updateRunBtn() {
      if (!runState) {
        runBtn.className = 'btn btn-primary btn-sm';
        runBtn.disabled = false;
        runBtn.title = t('sql.runTitle', { one: `${mod}+Enter`, all: `${mod}+Shift+Enter` });
        runBtn.setAttribute('aria-keyshortcuts', isMac() ? 'Meta+Enter' : 'Control+Enter');
        setChildren(runBtn, ...runIdle);
        return;
      }
      if (!canCancel) {
        runBtn.disabled = true;
        setChildren(runBtn, icon('loader', 'spin'), h('span', {}, t('sql.running')));
        return;
      }
      const label = runState.stopping ? t('sql.stopping') : t('sql.stop');
      runBtn.className = 'btn btn-danger btn-sm';
      runBtn.disabled = runState.stopping;
      runBtn.title = `${t('sql.stopHint')} (Esc)`;
      runBtn.setAttribute('aria-keyshortcuts', 'Escape');
      setChildren(runBtn, icon(runState.stopping ? 'loader' : 'square', runState.stopping ? 'spin' : undefined), h('span', {}, label));
      if (resultStopBtn) {
        resultStopBtn.disabled = runState.stopping;
        setChildren(resultStopBtn, icon('square'), h('span', {}, label));
      }
    }

    function beginRun(tabId) {
      runState = { tabId, runId: newRunId(), started: performance.now(), stopping: false };
      clearInterval(tickTimer);
      tickTimer = setInterval(() => { if (tickerEl && runState) tickerEl.textContent = secs(performance.now() - runState.started); }, 100);
      results.set(tabId, { running: true });
      renderResults();
      updateRunBtn();
      resultsBox.setAttribute('aria-busy', 'true');
      announce(t('sql.running'));
      return runState;
    }

    function endRun() {
      clearInterval(tickTimer);
      tickTimer = null;
      runState = null;
      updateRunBtn();
      resultsBox.removeAttribute('aria-busy');
    }

    /** Screen-reader summary of a finished run: rows / affected, cancelled or the first error. */
    function announceResult(r) {
      const items = r?.items || [];
      const err = items.find((x) => x.error && x.error_code !== 'cancelled');
      if (r?.cancelled || items.some((x) => x.error_code === 'cancelled')) announce(t('sql.cancelled'));
      else if (err) announce(t('a11y.sqlFailed', { message: err.error }), { assertive: true });
      else {
        const q = items.find((x) => x.is_result_set) || items[items.length - 1];
        const sets = (q?.more_results?.length || 0) + 1;
        if (q && sets > 1) announce(plural('sql.resultSetsCount', sets));
        else if (q) announce(t('a11y.sqlDone', { result: q.is_result_set ? plural('sql.rows', q.rows.length) : plural('sql.affected', q.affected ?? 0) }));
      }
    }
    cleanup.push(() => clearInterval(tickTimer));

    /** Ask the server to interrupt the running query (sql.cancel); retried while the run is not registered yet. */
    async function stop() {
      const rs = runState;
      if (!rs || rs.stopping || !canCancel) return;
      rs.stopping = true;
      updateRunBtn();
      for (let attempt = 0; attempt < 6 && runState === rs; attempt++) {
        try {
          const res = await api('sql.cancel', { conn, run_id: rs.runId });
          if (res?.cancelled) return; // the running request now ends with "cancelled"
          if (res?.found) break;      // found but the engine refused (privileges)
        } catch (e) {
          if (runState === rs) toastError(e);
          rs.stopping = false;
          updateRunBtn();
          return;
        }
        await new Promise((r) => setTimeout(r, 400));
      }
      if (runState === rs) {
        toast.error(t('sql.cancelFailed'));
        rs.stopping = false;
        updateRunBtn();
      }
    }
    // Esc in the editor stops the running query (autocomplete handles Esc first when open).
    editor.textarea.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && runState && !e.defaultPrevented) { e.preventDefault(); stop(); }
    });

    function runSelectionOrAll() {
      const ta = editor.textarea;
      const sql = ta.selectionStart !== ta.selectionEnd ? ta.value.slice(ta.selectionStart, ta.selectionEnd) : ta.value;
      run(sql);
    }

    let guarding = false;
    async function run(sql) {
      if (runState || guarding) return;
      sql = (sql || '').trim();
      if (!sql) { toast.info(t('sql.nothingToRun')); return; }
      const tx = txMode;
      if (guardEnabled()) {
        const risks = risksForTx(analyzeRisk(sql, overview?.info?.driver || 'mysql'), tx);
        if (risks.length) {
          guarding = true;
          let ok = false;
          try { ok = await confirmRisks(risks); } finally { guarding = false; }
          if (!ok) { editor.focus(); return; }
        }
      }
      const tb = activeTab();
      const tabId = tb.id;
      const rs = beginRun(tabId);
      let ok = true;
      try {
        const res = await api('sql.execute', {
          conn, db: tb.db || undefined, schema: tb.schema || undefined, sql, limit: Number(limitSel.value) || 1000,
          tx: tx === 'none' ? undefined : tx, run_id: canCancel ? rs.runId : undefined,
        });
        const items = res.results || [];
        ok = !items.some((x) => x.error) && !res.cancelled;
        const firstErr = items.findIndex((x) => x.error);
        results.set(tabId, {
          items, active: firstErr >= 0 ? firstErr : Math.max(0, items.findIndex((x) => x.is_result_set)),
          tx: res.tx || tx, rolledBack: !!res.rolled_back, cancelled: !!res.cancelled, warnings: res.warnings || [],
          elapsed: performance.now() - rs.started,
        });
        if (!res.rolled_back && splitStatements(sql, { dialect: overview?.info?.driver }).some((s) => MUTATING.test(s.text)) && tb.db) invalidate(conn, tb.db);
      } catch (e) {
        ok = false;
        results.set(tabId, { items: [{ sql: e.sql || sql, error: e.message, is_result_set: false, columns: [], rows: [], time_ms: performance.now() - rs.started }], active: 0 });
      } finally {
        endRun();
      }
      addHistory({ sql, db: tb.db, ok, ms: Math.round(performance.now() - rs.started) });
      announceResult(results.get(tabId));
      if (store.active === tabId) renderResults();
    }

    async function explain() {
      if (runState || guarding) return;
      const sql = editor.getRunnable().trim();
      if (!sql) { toast.info(t('sql.nothingToRun')); return; }
      const tb = activeTab();
      const rs = beginRun(tb.id);
      try {
        const q = await api('sql.explain', { conn, db: tb.db || undefined, schema: tb.schema || undefined, sql, run_id: canCancel ? rs.runId : undefined });
        results.set(tb.id, { items: [{ ...q, label: t('sql.explain') }], active: 0 });
      } catch (e) {
        const cancelled = e.code === 'cancelled';
        results.set(tb.id, {
          items: [{ sql: e.sql || sql, error: e.message, error_code: cancelled ? 'cancelled' : null, is_result_set: false, columns: [], rows: [], time_ms: performance.now() - rs.started, label: t('sql.explain') }],
          active: 0, cancelled, elapsed: performance.now() - rs.started,
        });
      } finally {
        endRun();
      }
      announceResult(results.get(tb.id));
      if (store.active === tb.id) renderResults();
    }

    // ----- history
    function addHistory(entry) {
      const list = storage.get('sql.history', []) || [];
      if (list[0]?.sql === entry.sql && list[0]?.db === entry.db) list.shift();
      list.unshift({ ...entry, at: Date.now() });
      storage.set('sql.history', list.slice(0, HISTORY_MAX));
      if (panelKind === 'history') renderPanel();
    }

    // ----- saved queries (browser-local; migrated from the old "snippets")
    const connLabel = () => connections().find((c) => String(c.id) === String(conn))?.label || '';
    const setTabDb = (tb, db, schema) => {
      tb.db = db || null;
      tb.schema = schema || null;
      if (db && ![...dbSel.options].some((o) => o.value === db)) dbSel.appendChild(h('option', { value: db }, db));
      dbSel.value = tb.db || '';
    };
    const commitSaved = (list) => {
      if (!persistSaved(storage, list)) { toast.error(t('savedQueries.storageFailed')); return false; }
      if (panelKind === 'saved') renderPanel();
      return true;
    };

    /** Save the active tab (updates the saved query it was opened from / saved as, else asks for a name). */
    async function saveCurrent() {
      const tb = activeTab();
      const sql = editor.getValue().trim();
      if (!sql) { toast.info(t('sql.nothingToSave')); return; }
      let list = loadSaved(storage);
      const linked = tb.savedId ? list.find((e) => e.id === tb.savedId) : null;
      if (linked) {
        if (commitSaved(updateEntry(list, linked.id, { sql: editor.getValue(), db: tb.db || undefined, conn: connLabel() || undefined }))) toast.success(t('savedQueries.updated'));
        return;
      }
      if (list.length >= MAX_SAVED) { toast.error(t('savedQueries.limit', { n: MAX_SAVED })); return; }
      const name = await promptDialog({ title: t('savedQueries.save'), label: t('savedQueries.name'), value: sql.split('\n')[0].slice(0, 40) });
      if (!name) return;
      list = loadSaved(storage);
      const res = addEntry(list, { name, sql: editor.getValue(), db: tb.db || undefined, conn: connLabel() || undefined });
      if (!res) { toast.error(t('savedQueries.limit', { n: MAX_SAVED })); return; }
      if (!commitSaved(res.list)) return;
      tb.savedId = res.entry.id;
      tb.title = res.entry.name;
      save();
      refreshTabs();
      toast.success(t('savedQueries.saved'));
    }

    function openSaved(e) {
      const cur = activeTab();
      let tb = cur;
      if (editor.getValue().trim()) {
        tb = newTab(e.sql, e.db ?? cur.db);
        refreshTabs();
        switchTab(store.active, true);
      } else {
        editor.replaceAll(e.sql);
        if (e.db) setTabDb(cur, e.db, null);
      }
      tb.title = e.name;
      tb.savedId = e.id;
      save();
      refreshTabs();
      loadCompletions();
      panelModal?.close();
      editor.focus();
    }

    async function renameSaved(e) {
      const name = await promptDialog({ title: t('savedQueries.rename'), label: t('savedQueries.name'), value: e.name });
      if (!name) return;
      commitSaved(updateEntry(loadSaved(storage), e.id, { name }));
      store.tabs.forEach((tb) => { if (tb.savedId === e.id) tb.title = name.trim(); });
      save();
      refreshTabs();
    }

    async function deleteSaved(e) {
      const ok = await confirmDialog({ title: t('savedQueries.deleteTitle'), message: t('savedQueries.deleteMessage', { name: e.name }), danger: true, confirmLabel: t('common.delete') });
      if (!ok) return;
      if (commitSaved(removeEntry(loadSaved(storage), e.id))) toast.success(t('savedQueries.deleted'));
      store.tabs.forEach((tb) => { if (tb.savedId === e.id) delete tb.savedId; });
      save();
    }

    function duplicateSaved(e) {
      const res = duplicateEntry(loadSaved(storage), e.id);
      if (!res) { toast.error(t('savedQueries.limit', { n: MAX_SAVED })); return; }
      commitSaved(res.list);
    }

    function exportSaved() {
      const list = loadSaved(storage);
      if (!list.length) { toast.info(t('savedQueries.empty')); return; }
      downloadText('ldb-saved-queries.json', serializeExport(list), 'application/json');
      toast.success(plural('savedQueries.exported', list.length));
    }

    async function importSavedFile(file) {
      if (!file) return;
      try {
        if (file.size > MAX_IMPORT_BYTES) throw Object.assign(new Error('too_large'), { code: 'too_large' });
        const { entries, skipped } = parseImport(await file.text());
        const r = mergeImport(loadSaved(storage), entries);
        if (!commitSaved(r.list)) return;
        toast.success(t('savedQueries.imported', { added: r.added, renamed: r.renamed, duplicates: r.duplicates, skipped }));
        if (r.dropped) toast.warning(t('savedQueries.importDropped', { n: r.dropped, max: MAX_SAVED }));
      } catch (err) {
        toast.error(err.code ? t(`savedQueries.err.${err.code}`) : t('savedQueries.err.bad_json'));
      }
    }

    /** Copy a shareable link carrying this SQL (base64url in ?q=, never auto-run on open). */
    async function copySqlLink(sql, db, schema) {
      const enc = encodeSqlParam(sql);
      if (!enc.ok) { toast.warning(enc.reason === 'empty' ? t('share.sqlEmpty') : t('share.sqlTooLarge', { kb: MAX_LINK_SQL_BYTES / 1024 })); return; }
      const hint = serverHint(connections().find((c) => String(c.id) === String(conn)));
      const url = absoluteUrl(href.sql(conn, { db: db || undefined, schema: schema || undefined, q: enc.value, srv: hint || undefined }));
      if (await copyText(url)) toast.success(t('share.linkCopied')); else toast.error(t('share.copyFailed'));
    }
    const copyCurrentLink = () => { const tb = activeTab(); return copySqlLink(editor.getValue(), tb?.db, tb?.schema); };

    cleanup.push(on(window, 'keydown', (e) => {
      if (!modKey(e) || !e.shiftKey || e.altKey || e.key.toLowerCase() !== 's' || !el.isConnected) return;
      e.preventDefault();
      if (document.querySelector('dialog[open]')) return;
      saveCurrent();
    }, true));

    let panelKind = null;
    let panelModal = null;
    let panelFilter = '';
    const panelBody = h('div', { class: 'min-h-0 flex-1 overflow-auto' });
    const panelSearch = h('input', { type: 'search', class: 'input input-sm', placeholder: t('sql.searchHistory'), 'aria-label': t('sql.searchHistory'), onInput: () => { panelFilter = panelSearch.value.toLowerCase(); renderPanel(); } });
    const panelTitle = h('h2', { class: 'me-auto min-w-0 truncate text-[13px] font-semibold' });
    const importIn = h('input', { type: 'file', accept: '.json,application/json', hidden: true, onChange: () => { const f = importIn.files[0]; importIn.value = ''; importSavedFile(f); } });
    const tool = (ic, label, fn) => h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: label, 'aria-label': label, onClick: fn }, icon(ic));
    const panelTools = h('div', { class: 'flex items-center gap-0.5', hidden: true },
      tool('bookmark-plus', t('savedQueries.saveHint'), () => saveCurrent()),
      tool('upload', t('savedQueries.import'), () => importIn.click()),
      tool('download', t('savedQueries.export'), () => exportSaved()),
      importIn);
    const panelEl = h('div', { class: 'flex h-full min-h-0 min-w-0 flex-col' },
      h('div', { class: 'flex min-w-0 items-center gap-2 border-b border-border px-3 py-2' }, panelTitle, panelTools,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon max-lg:hidden', 'aria-label': t('common.close'), onClick: () => togglePanel(null) }, icon('x'))),
      h('div', { class: 'p-2' }, panelSearch),
      panelBody);
    const aside = h('aside', { class: 'hidden w-80 shrink-0 border-s border-border bg-surface', 'aria-label': t('sql.sidePanel') });

    function togglePanel(kind) {
      panelKind = panelKind === kind ? null : kind;
      historyBtn.setAttribute('aria-pressed', String(panelKind === 'history'));
      savedBtn.setAttribute('aria-pressed', String(panelKind === 'saved'));
      panelFilter = '';
      panelSearch.value = '';
      if (!panelKind) { aside.classList.add('hidden'); aside.classList.remove('flex'); panelModal?.close(); return; }
      renderPanel();
      if (isCompact()) {
        panelModal?.close();
        panelModal = openModal({ title: panelKind === 'history' ? t('sql.history') : t('savedQueries.title'), body: panelEl, size: 'lg', onClose: () => { panelModal = null; if (isCompact()) { panelKind = null; historyBtn.setAttribute('aria-pressed', 'false'); savedBtn.setAttribute('aria-pressed', 'false'); } } });
        panelModal.body.classList.remove('px-5', 'py-4');
        panelModal.body.classList.add('p-0');
      } else {
        aside.replaceChildren(panelEl);
        aside.classList.remove('hidden');
        aside.classList.add('flex');
      }
    }

    function openSql(sql, db) {
      const cur = activeTab();
      if (!editor.getValue().trim()) { editor.replaceAll(sql); if (db) { cur.db = db; dbSel.value = db; } }
      else { newTab(sql, db ?? cur.db); refreshTabs(); switchTab(store.active, true); }
      panelModal?.close();
      editor.focus();
    }

    function renderPanel() {
      panelTitle.textContent = panelKind === 'history' ? t('sql.history') : t('savedQueries.title');
      panelTools.hidden = panelKind !== 'saved';
      panelSearch.placeholder = panelKind === 'history' ? t('sql.searchHistory') : t('savedQueries.search');
      panelSearch.setAttribute('aria-label', panelSearch.placeholder);
      const entry = (title, sqlText, meta, onOpen, onDelete, okState) => h('li', { class: 'group relative border-b border-border' },
        h('button', { type: 'button', class: 'block w-full px-3 py-2 text-start hover:bg-surface-2', onClick: onOpen },
          title ? h('div', { class: 'mb-0.5 truncate text-[13px] font-medium', dir: 'auto' }, title) : null,
          h('pre', { class: 'line-clamp-3 whitespace-pre-wrap break-all font-mono text-[11.5px]', dir: 'ltr' }, highlightSql(sqlText.slice(0, 400))),
          h('div', { class: 'mt-1 flex items-center gap-2 text-[11px] text-muted' },
            okState === false ? icon('circle-alert', 'size-3 text-danger') : okState === true ? icon('check', 'size-3 text-success') : null,
            meta)),
        h('div', { class: 'absolute end-1 top-1 flex gap-0.5' },
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.copy'), onClick: () => copyText(sqlText).then(() => toast.success(t('common.copied'))) }, icon('copy', 'size-3.5')),
          onDelete ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.delete'), onClick: onDelete }, icon('trash', 'size-3.5')) : null));
      if (panelKind === 'history') {
        const list = (storage.get('sql.history', []) || []).filter((x) => !panelFilter || x.sql.toLowerCase().includes(panelFilter) || (x.db || '').toLowerCase().includes(panelFilter));
        setChildren(panelBody, list.length
          ? h('ul', {}, list.map((x) => entry(null, x.sql, [x.db, fmtDate(x.at, { dateStyle: 'short', timeStyle: 'short' }), fmtDuration(x.ms)].filter(Boolean).join(' · '), () => openSql(x.sql, x.db), null, x.ok)))
          : emptyState({ icon: 'history', title: t('sql.noHistory') }),
        list.length ? h('div', { class: 'p-2' }, h('button', { type: 'button', class: 'btn btn-ghost btn-sm w-full', onClick: () => { storage.set('sql.history', []); renderPanel(); } }, icon('trash'), t('sql.clearHistory'))) : null);
      } else {
        const all = loadSaved(storage);
        const list = filterEntries(all, panelFilter);
        setChildren(panelBody, list.length
          ? [h('ul', {}, list.map(savedEntry)), h('p', { class: 'px-3 py-2 text-xs text-muted' }, plural('savedQueries.count', all.length))]
          : emptyState({ icon: 'bookmark', title: t('savedQueries.empty'), message: all.length ? undefined : t('savedQueries.emptyHint', { keys: `${mod}+Shift+S` }) }));
      }
    }

    function savedEntry(e) {
      const more = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.moreActionsFor', { name: e.name }) }, icon('ellipsis', 'size-3.5'));
      bindMenu(more, () => [
        { label: t('savedQueries.open'), icon: 'external-link', onSelect: () => openSaved(e) },
        { label: t('common.copy'), icon: 'copy', onSelect: () => copyText(e.sql).then(() => toast.success(t('common.copied'))) },
        { label: t('share.queryLink'), icon: 'link', onSelect: () => copySqlLink(e.sql, e.db, null) },
        { separator: true },
        { label: t('savedQueries.rename'), icon: 'pencil', onSelect: () => renameSaved(e) },
        { label: t('savedQueries.duplicate'), icon: 'copy-plus', onSelect: () => duplicateSaved(e) },
        { label: t('savedQueries.delete'), icon: 'trash', danger: true, onSelect: () => deleteSaved(e) },
      ], { align: 'end' });
      const meta = [e.db ? t('savedQueries.forDb', { db: e.db }) : null, e.conn, fmtDate(e.updated, { dateStyle: 'short' })].filter(Boolean).join(' · ');
      return h('li', { class: 'group relative border-b border-border' },
        h('button', { type: 'button', class: 'block w-full px-3 py-2 pe-10 text-start hover:bg-surface-2', title: t('savedQueries.open'), onClick: () => openSaved(e) },
          h('div', { class: 'mb-0.5 truncate text-[13px] font-medium', dir: 'auto' }, e.name),
          h('pre', { class: 'line-clamp-3 whitespace-pre-wrap break-all font-mono text-[11.5px]', dir: 'ltr' }, highlightSql(e.sql.slice(0, 400))),
          h('div', { class: 'mt-1 truncate text-[11px] text-muted', dir: 'auto' }, meta)),
        h('div', { class: 'absolute end-1 top-1' }, more));
    }

    // ----- split layout
    editorTabs?.panel(editor.el);
    const editorPane = h('div', { class: 'flex min-h-[120px] flex-col' }, editorTabs?.el, editor.el);
    let ratio = Number(storage.get('sql.split', 0.42)) || 0.42;
    const applyRatio = () => { editorPane.style.flex = `0 0 ${Math.round(ratio * 100)}%`; };
    applyRatio();
    const splitter = h('div', {
      role: 'separator', 'aria-orientation': 'horizontal', tabindex: '0', 'aria-label': t('sql.resizeSplit'),
      'aria-valuenow': String(Math.round(ratio * 100)), 'aria-valuemin': '15', 'aria-valuemax': '85',
      class: 'relative h-2 shrink-0 cursor-row-resize touch-none border-y border-border bg-surface-2 hover:bg-accent/20 focus-visible:bg-accent/30',
      onPointerdown: (e) => {
        const total = split.getBoundingClientRect().height;
        const start = ratio;
        dragPointer(e, (dx, dy) => { ratio = Math.max(0.15, Math.min(0.85, start + dy / total)); applyRatio(); }, () => { storage.set('sql.split', ratio); splitter.setAttribute('aria-valuenow', String(Math.round(ratio * 100))); editor.refresh(); });
      },
      onKeydown: (e) => {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        ratio = Math.max(0.15, Math.min(0.85, ratio + (e.key === 'ArrowDown' ? 0.05 : -0.05)));
        applyRatio();
        storage.set('sql.split', ratio);
        splitter.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
      },
    }, h('span', { class: 'absolute start-1/2 top-1/2 h-0.5 w-8 -translate-x-1/2 -translate-y-1/2 rounded bg-border-strong rtl:translate-x-1/2' }));
    const split = h('div', { class: 'flex min-h-0 min-w-0 flex-1 flex-col' }, editorPane, splitter, resultsBox);

    setChildren(el, embedded ? null : h('h1', { class: 'sr-only' }, t('editor.label')), toolbar, h('div', { class: 'flex min-h-0 flex-1' }, split, aside));
    cleanup.push(() => { currentGrid?.destroy(); panelModal?.close(); });
    cleanup.push(on(window, 'resize', () => { if (panelKind && !isCompact() && panelModal) { panelModal.close(); togglePanel(panelKind); } }));

    updateRunBtn();
    renderResults();
    loadCompletions();
    requestAnimationFrame(() => { editor.refresh(); editor.focus(); });
  },

  destroy() {
    cleanup.forEach((fn) => { try { fn(); } catch { /* ignore */ } });
    cleanup = [];
  },
};
