/**
 * Table browse tab: data grid with server-side paging/sorting/filtering, search, inline edit,
 * insert / edit / duplicate / delete rows, export selection, FK navigation and the executed
 * SQL with "open in SQL editor". Initial state may come from the route params:
 * ?where=<json filters> ?order=<json [{column,dir}]> ?search=<text> ?cols=<json [names]>
 * ?page=<n> ?per_page=<n> (cols limits the visible columns; a toolbar chip shows them all again).
 * "Copy link" builds such a URL from the current view state (+ `srv`, a non-secret server hint
 * so a fresh session can reconnect first, see app.js guard / login.js).
 *
 * Batch mode (default, toggle in the toolbar's edit-settings menu, persisted in localStorage):
 * inline edits, row deletes and row-form inserts/duplicates/edits are staged in a pending model
 * (views/_pending-rows.js) and shown in the grid (edited cells, struck-through deleted rows, new
 * rows). The pending bar / Ctrl+S previews the batch through table.applyChanges {preview:true}
 * in the SQL review dialog and applies it in one transaction. Paging, sorting, filtering and
 * leaving the view ask to apply or discard first. "Save edits immediately" keeps the old
 * one-request-per-edit behaviour.
 */
import { h, debounce, copyText, downloadText, modKey, isMac } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { createDataGrid } from '../ui/datagrid.js';
import { createFilterBuilder } from '../ui/filterbuilder.js';
import { openRowForm } from '../ui/rowform.js';
import { confirmDialog, openModal } from '../ui/modal.js';
import { bindMenu } from '../ui/menu.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { refreshButton } from '../ui/feedback.js';
import { t, plural, fmtNumber, fmtDuration } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore, storage } from '../store.js';
import { connections } from '../session.js';
import { serverHint, absoluteUrl } from '../share.js';
import { href, navigate, setLeaveGuard } from '../router.js';
import { getTableInfo, getOverview } from '../data.js';
import { highlightSql } from '../sql/highlight.js';
import { reviewSqlDialog } from './_table-editor.js';
import { createPendingRows } from './_pending-rows.js';

const PAGE_SIZES = [25, 50, 100, 250, 500, 1000];
const MODE_KEY = 'browse.editMode'; // 'batch' (default) | 'immediate'

let abort = null;
let cleanup = null;

export default {
  title: (ctx) => ctx.table,

  async render(el, ctx) {
    const { conn, db, schema, table } = ctx;
    const base = { conn, db, schema, table };
    const ro = !!ctx.readOnly; // read-only connection: no insert/edit/duplicate/delete
    let initialFilters = [];
    try { initialFilters = ctx.params.where ? JSON.parse(ctx.params.where) : []; } catch { initialFilters = []; }
    const fromJson = (v) => { try { const x = v ? JSON.parse(v) : null; return Array.isArray(x) && x.length ? x : null; } catch { return null; } };

    const urlPerPage = Number(ctx.params.per_page);
    const urlPage = Math.floor(Number(ctx.params.page));
    const state = {
      page: urlPage >= 1 && urlPage < 1e9 ? urlPage : 1,
      perPage: PAGE_SIZES.includes(urlPerPage) ? urlPerPage : PAGE_SIZES.includes(Number(storage.get('grid.perPage'))) ? Number(storage.get('grid.perPage')) : 50,
      order: fromJson(ctx.params.order) || [],
      filters: Array.isArray(initialFilters) ? initialFilters : [],
      search: ctx.params.search || '',
      visible: fromJson(ctx.params.cols),
      result: null,
      selection: [],
    };
    let batch = storage.get(MODE_KEY) !== 'immediate';
    const pending = createPendingRows({ columns: [], primaryKey: [] });
    const allRows = () => pending.rows();
    const hasPending = () => batch && !ro && pending.count() > 0;
    const overview = await getOverview(conn).catch(() => null);
    const quote = overview?.info?.driver && overview.info.driver !== 'mysql' ? '"' : '`';

    // ----- toolbar
    const tbBtn = (ic, label, onClick, extra = '') => h('button', { type: 'button', class: `btn btn-sm ${extra}`, onClick, 'aria-label': label, title: label }, icon(ic), h('span', { class: 'hidden md:inline' }, label));
    const filterCount = h('span', { class: 'badge badge-accent h-4 px-1 text-[10px]', hidden: true });
    const filterBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', 'aria-controls': 'browse-filters', title: t('filter.title'), onClick: () => toggleFilters() },
      icon('list-filter'), h('span', { class: 'hidden md:inline' }, t('filter.title')), filterCount);
    const searchIn = h('input', {
      type: 'search', class: 'input input-sm w-full', placeholder: t('browse.search'), 'aria-label': t('browse.search'), dir: 'auto', value: state.search,
      onInput: debounce(async () => {
        if (!(await resolvePending())) { searchIn.value = state.search; return; }
        state.search = searchIn.value; state.page = 1; load();
      }, 350),
    });
    const insertBtn = tbBtn('plus', t('browse.insert'), () => rowForm('insert'), 'btn-primary');
    const editBtn = tbBtn('pencil', t('browse.edit'), () => rowForm('edit'));
    const dupBtn = tbBtn('copy-plus', t('browse.duplicate'), () => rowForm('duplicate'), 'max-sm:hidden');
    const delBtn = tbBtn('trash', t('browse.delete'), () => deleteRows(state.selection), 'max-sm:hidden');
    if (ro) [insertBtn, editBtn, dupBtn, delBtn].forEach((b) => { b.hidden = true; });
    const exportBtn = tbBtn('download', t('browse.export'), null, 'max-sm:hidden');
    bindMenu(exportBtn, () => exportItems(), { align: 'end' });
    const moreBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon sm:hidden', 'aria-label': t('common.more') }, icon('ellipsis'));
    bindMenu(moreBtn, () => (ro ? [...exportItems(), { separator: true }, { label: t('share.copyLink'), icon: 'link', onSelect: () => copyViewLink() }] : [
      { label: t('browse.duplicate'), icon: 'copy-plus', disabled: dupBtn.disabled, onSelect: () => rowForm('duplicate') },
      { label: t('browse.delete'), icon: 'trash', danger: true, disabled: delBtn.disabled, onSelect: () => deleteRows(state.selection) },
      { separator: true },
      ...exportItems(),
      { separator: true },
      { label: t('share.copyLink'), icon: 'link', onSelect: () => copyViewLink() },
    ]), { align: 'end' });
    const colsBtn = h('button', {
      type: 'button', class: 'btn btn-sm', hidden: !state.visible, title: t('tableSearch.showAllColumns'),
      onClick: () => { state.visible = null; colsBtn.hidden = true; grid.setData({ rows: state.result?.rows || [], visible: null }); },
    }, icon('columns'), h('span', { class: 'hidden md:inline' }, t('tableSearch.showAllColumns')), icon('x', 'size-3.5'));
    const refreshBtn = refreshButton(guarded(() => {}));
    const linkBtn = tbBtn('link', t('share.copyLink'), () => copyViewLink(), 'max-sm:hidden');
    const modeBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon', 'aria-label': t('browse.pending.settings'), title: t('browse.pending.settings'), hidden: ro }, icon('settings'));
    bindMenu(modeBtn, () => [
      { header: t('browse.pending.modeHeader') },
      { label: t('browse.pending.modeBatch'), checked: batch, onSelect: () => setMode(true) },
      { label: t('browse.pending.modeImmediate'), checked: !batch, onSelect: () => setMode(false) },
    ], { align: 'end' });

    const toolbar = h('div', { class: 'flex flex-wrap items-center gap-1.5 border-b border-border bg-surface px-3 py-2', role: 'toolbar', 'aria-label': t('browse.toolbar') },
      filterBtn,
      h('div', { class: 'relative min-w-[8rem] flex-1 sm:max-w-xs' }, searchIn),
      colsBtn,
      h('div', { class: 'flex-1 max-sm:hidden' }),
      insertBtn, editBtn, dupBtn, delBtn, exportBtn, linkBtn, modeBtn, moreBtn, refreshBtn);

    // ----- filters
    const filterPanel = h('div', { id: 'browse-filters', hidden: true });
    let builder = null;
    function toggleFilters(force) {
      const show = force ?? filterPanel.hidden;
      filterPanel.hidden = !show;
      filterBtn.setAttribute('aria-pressed', String(show));
      if (show && builder) builder.focus();
    }
    function updateFilterBadge() {
      filterCount.hidden = !state.filters.length;
      filterCount.textContent = String(state.filters.length);
    }

    // ----- SQL bar
    const sqlCode = h('code', { class: 'min-w-0 flex-1 truncate font-mono text-[12px]', dir: 'ltr' });
    const sqlMeta = h('span', { class: 'shrink-0 text-xs text-muted tabular-nums' });
    const sqlBar = h('div', { class: 'flex items-center gap-2 border-b border-border bg-surface-2/40 px-3 py-1.5', hidden: true },
      icon('terminal', 'size-3.5 text-muted'), sqlCode, sqlMeta,
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm shrink-0', onClick: () => openInEditor() }, icon('external-link'), h('span', { class: 'hidden sm:inline' }, t('browse.openInEditor'))));

    // ----- grid
    const grid = createDataGrid({
      columns: [],
      rows: [],
      ariaLabel: t('browse.gridLabel', { table }),
      tableName: table,
      quote,
      rowOffset: () => (state.page - 1) * state.perPage,
      rowTotal: () => state.result?.total,
      editable: (col) => canWrite() && !!col,
      onEdit: async (di, ci, value, orig) => {
        const r = state.result;
        if (batch) {
          if (pending.rowState(di) === 'deleted') throw new Error(t('browse.pending.rowDeleted'));
          pending.editCell(di, ci, orig);
          afterChange();
          return;
        }
        const key = keyOf(orig);
        await api('table.update', { ...base, key, values: { [r.columns[ci].name]: value } });
      },
      rowClass: (di) => {
        if (!batch) return null;
        if (pending.isFailed(di)) return 'dg-row-failed';
        const st = pending.rowState(di);
        return st === 'deleted' ? 'dg-row-deleted' : st === 'new' ? 'dg-row-new' : null;
      },
      cellClass: (di, ci) => (batch && pending.cellState(di, ci) ? 'dg-cell-edited' : null),
      rowLabel: (di) => {
        if (!batch) return null;
        if (pending.isFailed(di)) return t('a11y.rowFailed');
        const st = pending.rowState(di);
        return st === 'deleted' ? t('a11y.rowDeleted') : st === 'new' ? t('a11y.rowNew') : null;
      },
      cellLabel: (di, ci) => (batch && pending.cellState(di, ci) ? t('a11y.cellEdited') : null),
      onCellEscape: (di, ci) => {
        if (!batch || !pending.revertCell(di, ci)) return false;
        afterChange();
        return true;
      },
      onSort: async (order) => {
        if (!(await resolvePending())) { grid.setData({ rows: allRows(), sort: state.order }); return; }
        state.order = order; state.page = 1; load();
      },
      onSelectionChange: (sel) => { state.selection = sel; updateButtons(); },
      onFkClick: (col, value) => navigate(href.table(conn, db, schema, col.fk.table, 'browse', { where: JSON.stringify([{ column: col.fk.column, op: '=', value }]) })),
      onDeleteKey: (idx) => deleteRows(idx),
      onRowOpen: (di) => { if (ro) return; grid.clearSelection(); state.selection = [di]; rowForm('edit'); },
      rowMenu: (idx, ci) => (ro ? [] : rowMenuItems(idx, ci)),
      className: 'min-h-0 flex-1',
    });

    // ----- pagination
    const pageInfo = h('span', { class: 'text-[12.5px] text-muted tabular-nums', 'aria-live': 'polite' });
    const pageIn = h('input', { class: 'input input-sm w-14 text-center tabular-nums', inputmode: 'numeric', 'aria-label': t('browse.page'), onChange: guarded(() => { const p = Math.max(1, Number(pageIn.value) || 1); state.page = Math.min(p, totalPages() || p); }, () => { pageIn.value = String(state.page); }) });
    const pageCount = h('span', { class: 'text-[12.5px] text-muted tabular-nums' });
    const prevBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon', 'aria-label': t('browse.prev'), onClick: guarded(() => { state.page = Math.max(1, state.page - 1); }) }, icon('chevron-left'));
    const nextBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon', 'aria-label': t('browse.next'), onClick: guarded(() => { state.page++; }) }, icon('chevron-right'));
    const firstBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon max-sm:hidden', 'aria-label': t('browse.first'), onClick: guarded(() => { state.page = 1; }) }, icon('chevrons-left'));
    const lastBtn = h('button', { type: 'button', class: 'btn btn-sm btn-icon max-sm:hidden', 'aria-label': t('browse.last'), onClick: guarded(() => { state.page = totalPages(); }) }, icon('chevrons-right'));
    const sizeSel = h('select', {
      class: 'select input-sm w-auto', 'aria-label': t('browse.pageSize'),
      onChange: guarded(() => { state.perPage = Number(sizeSel.value); storage.set('grid.perPage', state.perPage); state.page = 1; }, () => { sizeSel.value = String(state.perPage); }),
    }, PAGE_SIZES.map((n) => h('option', { value: String(n), selected: n === state.perPage }, t('browse.perPage', { n }))));
    const footer = h('div', { class: 'flex flex-wrap items-center gap-2 border-t border-border bg-surface px-3 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]' },
      pageInfo, h('div', { class: 'flex-1' }), sizeSel,
      h('div', { class: 'flex items-center gap-1' }, firstBtn, prevBtn, pageIn, pageCount, nextBtn, lastBtn));

    // ----- pending changes bar (batch mode)
    const bar = h('div', { class: 'border-t border-accent/30 bg-surface/95 px-3 py-2 backdrop-blur sm:px-4', role: 'region', 'aria-label': t('browse.pending.label'), hidden: true });

    el.replaceChildren(toolbar, filterPanel, sqlBar, grid.el, bar, footer);

    // ----- helpers
    const totalPages = () => (state.result?.total === null || state.result?.total === undefined ? 0 : Math.max(1, Math.ceil(state.result.total / state.perPage)));
    const canWrite = () => !ro && !!state.result?.editable && !!state.result?.primary_key?.length;
    const colIndex = (name) => state.result.columns.findIndex((c) => c.name === name);
    const keyOf = (row) => Object.fromEntries(state.result.primary_key.map((k) => [k, row[colIndex(k)]]));
    const keyStr = (key) => JSON.stringify(Object.entries(key).map(([k, v]) => [k, v === null ? null : String(v)]));
    const rowObj = (row) => Object.fromEntries(state.result.columns.map((c, i) => [c.name, row[i]]));

    function updateButtons() {
      const n = state.selection.length;
      const write = canWrite();
      editBtn.disabled = !write || n !== 1;
      dupBtn.disabled = n !== 1;
      delBtn.disabled = !write || n === 0;
      const noPk = state.result && !write;
      editBtn.title = noPk ? t('browse.noPrimaryKey') : t('browse.edit');
      delBtn.title = noPk ? t('browse.noPrimaryKey') : t('browse.delete');
      delBtn.querySelector('span').textContent = n > 1 ? plural('browse.deleteRows', n) : t('browse.delete');
    }

    function updatePager() {
      const r = state.result;
      const count = r?.rows?.length || 0;
      const from = count ? (state.page - 1) * state.perPage + 1 : 0;
      const to = from ? from + count - 1 : 0;
      const tp = totalPages();
      pageInfo.textContent = r?.total === null || r?.total === undefined
        ? t('browse.rangeNoTotal', { from: fmtNumber(from), to: fmtNumber(to) })
        : t('browse.range', { from: fmtNumber(from), to: fmtNumber(to), total: fmtNumber(r.total) });
      pageIn.value = String(state.page);
      pageCount.textContent = tp ? `/ ${fmtNumber(tp)}` : '';
      prevBtn.disabled = firstBtn.disabled = state.page <= 1;
      const hasNext = tp ? state.page < tp : count === state.perPage;
      nextBtn.disabled = !hasNext;
      lastBtn.disabled = !tp || state.page >= tp;
    }

    async function load(opts = {}) {
      abort?.abort();
      abort = new AbortController();
      const my = abort;
      grid.setLoading(true);
      try {
        const res = await api('table.browse', { ...base, page: state.page, per_page: state.perPage, order: state.order, filters: state.filters, search: state.search || undefined }, { signal: my.signal });
        if (my !== abort) return;
        if (state.page > 1 && !res.rows.length && res.total > 0) { // page from a link is past the end
          state.page = Math.max(1, Math.ceil(res.total / state.perPage));
          return load(opts);
        }
        state.result = res;
        state.selection = [];
        pending.reset(res.rows, { columns: res.columns.map((c) => c.name), primaryKey: res.primary_key || [] });
        if (!builder) {
          builder = createFilterBuilder({
            columns: res.columns,
            value: state.filters,
            onApply: async (f) => {
              if (!(await resolvePending())) return;
              state.filters = f; state.page = 1; updateFilterBadge(); load();
            },
          });
          filterPanel.replaceChildren(builder.el);
          if (state.filters.length) toggleFilters(true);
        }
        grid.setData({ columns: res.columns, rows: allRows(), sort: state.order, visible: state.visible });
        if (opts.selectKeys) {
          const want = new Set(opts.selectKeys);
          grid.setSelection(res.rows.map((row, i) => (want.has(keyStr(keyOf(row))) ? i : -1)).filter((i) => i >= 0));
        }
        drawBar();
        sqlCode.replaceChildren(highlightSql(res.sql || ''));
        sqlCode.title = res.sql || '';
        sqlMeta.textContent = fmtDuration(res.time_ms);
        sqlBar.hidden = !res.sql;
        updatePager();
        updateButtons();
        updateFilterBadge();
      } catch (e) {
        if (e.code === 'aborted') return;
        toastError(e, t('browse.loadFailed'));
      } finally {
        if (my === abort) grid.setLoading(false);
      }
    }

    /** Copy a link that reproduces this view (filters, order, search, columns, page, page size). Never data or credentials. */
    async function copyViewLink() {
      const hint = serverHint(connections().find((c) => String(c.id) === String(conn)));
      const link = href.table(conn, db, schema, table, 'browse', {
        where: state.filters.length ? JSON.stringify(state.filters) : undefined,
        order: state.order.length ? JSON.stringify(state.order) : undefined,
        search: state.search || undefined,
        cols: state.visible ? JSON.stringify(state.visible) : undefined,
        page: state.page > 1 ? state.page : undefined,
        per_page: state.perPage,
        srv: hint || undefined,
      });
      if (await copyText(absoluteUrl(link))) toast.success(t('share.linkCopied')); else toast.error(t('share.copyFailed'));
    }

    function openInEditor() {
      appStore.set({ pendingSql: { sql: state.result?.sql || '', db, schema } });
      navigate(href.sql(conn, { db, schema }));
    }

    async function rowForm(mode) {
      if (ro) return;
      let info;
      try { info = await getTableInfo(conn, db, schema, table); } catch (e) { toastError(e); return; }
      const sel = state.selection;
      const di = sel[0];
      const row = mode === 'insert' ? null : allRows()[di];
      if (mode !== 'insert' && !row) return;
      if (mode === 'edit' && batch && pending.rowState(di) === 'deleted') { toast.warning(t('browse.pending.rowDeleted')); return; }
      const staged = batch; // batch mode: the form adds to the pending changes instead of saving
      const res = await openRowForm({
        mode, table, columns: info.columns, foreignKeys: info.foreign_keys,
        values: row ? rowObj(row) : undefined,
        submitLabel: staged ? t('browse.pending.addToPending') : undefined,
        fkSearch: (column, term) => api('table.fkValues', { ...base, column, term }),
        onSubmit: async (values) => {
          if (staged) {
            if (mode === 'edit') pending.setValues(di, values);
            else {
              const ndi = pending.addRow(values);
              grid.setData({ rows: allRows() });
              grid.setSelection([ndi]);
            }
            afterChange();
            return true;
          }
          return mode === 'edit'
            ? api('table.update', { ...base, key: keyOf(row), values })
            : api('table.insert', { ...base, values });
        },
      });
      if (!res) return;
      if (staged) { toast.success(t('browse.pending.staged')); return; }
      toast.success(mode === 'edit' ? t('browse.rowUpdated') : t('browse.rowInserted'));
      load();
    }

    async function deleteRows(idx) {
      if (!idx.length || ro) return;
      if (!canWrite()) { toast.warning(t('browse.noPrimaryKey')); return; }
      if (batch) {
        const dropsNew = idx.some((di) => pending.isNew(di));
        pending.toggleDelete(idx);
        if (dropsNew) { grid.setData({ rows: allRows() }); grid.clearSelection(); }
        afterChange();
        return;
      }
      const ok = await confirmDialog({ title: plural('browse.deleteRows', idx.length), message: plural('browse.deleteConfirm', idx.length), danger: true, confirmLabel: t('common.delete') });
      if (!ok) return;
      try {
        const keys = idx.map((i) => keyOf(state.result.rows[i]));
        const res = await api('table.delete', { ...base, keys });
        toast.success(plural('browse.deleted', res?.affected ?? keys.length));
        grid.clearSelection();
        load();
      } catch (e) { toastError(e); }
    }

    function rowMenuItems(idx, ci) {
      const single = idx.length === 1;
      const items = [
        { label: t('browse.editRow'), icon: 'pencil', disabled: !canWrite() || !single, onSelect: () => { state.selection = idx; rowForm('edit'); } },
        { label: t('browse.duplicate'), icon: 'copy-plus', disabled: !single, onSelect: () => { state.selection = idx; rowForm('duplicate'); } },
      ];
      const allDeleted = batch && idx.length > 0 && idx.every((di) => pending.rowState(di) === 'deleted');
      items.push(allDeleted
        ? { label: plural('browse.pending.restoreRows', idx.length), icon: 'history', onSelect: () => deleteRows(idx) }
        : { label: plural('browse.deleteRows', idx.length), icon: 'trash', danger: true, disabled: !canWrite(), onSelect: () => deleteRows(idx) });
      if (batch) {
        const changed = idx.filter((di) => pending.rowState(di) && pending.rowState(di) !== 'deleted');
        if (single && ci !== undefined && pending.cellState(idx[0], ci)) {
          items.push({ separator: true }, { label: t('browse.pending.revertCell'), icon: 'history', hint: 'Esc', onSelect: () => { pending.revertCell(idx[0], ci); afterChange(); } });
        } else if (changed.length) items.push({ separator: true });
        if (changed.length) {
          items.push({
            label: plural('browse.pending.revertRow', changed.length),
            icon: 'history',
            onSelect: () => {
              const dropsNew = changed.some((di) => pending.isNew(di));
              [...changed].sort((a, b) => b - a).forEach((di) => pending.revertRow(di));
              if (dropsNew) { grid.setData({ rows: allRows() }); grid.clearSelection(); }
              afterChange();
            },
          });
        }
      }
      return items;
    }

    // ----- pending changes (batch mode)
    function afterChange() {
      grid.redraw();
      drawBar();
      updateButtons();
    }

    let announced = 0;
    function drawBar() {
      const n = batch && !ro ? pending.count() : 0;
      if (n !== announced) { announced = n; if (n) announce(plural('browse.pending.count', n)); }
      bar.hidden = !n;
      if (!n) { bar.replaceChildren(); return; }
      const c = pending.counts();
      const parts = [[c.update, 'browse.pending.updates'], [c.delete, 'browse.pending.deletes'], [c.insert, 'browse.pending.inserts']]
        .filter(([k]) => k).map(([k, key]) => t(key, { n: fmtNumber(k) }));
      const shortcut = isMac() ? '⌘S' : 'Ctrl+S';
      bar.replaceChildren(h('div', { class: 'flex items-center gap-2' },
        h('span', { class: 'size-2 shrink-0 rounded-full bg-warning', 'aria-hidden': 'true' }),
        h('span', { class: 'shrink-0 whitespace-nowrap text-[13px] font-medium' }, plural('browse.pending.count', n)),
        h('span', { class: 'hidden min-w-0 truncate text-xs text-muted sm:inline' }, `· ${parts.join(' · ')}`),
        h('div', { class: 'min-w-0 flex-1' }),
        h('button', { type: 'button', class: 'btn btn-sm shrink-0', onClick: () => discard() }, icon('x'), t('browse.pending.discard')),
        h('button', { type: 'button', class: 'btn btn-primary btn-sm shrink-0', title: `${t('browse.pending.review')} (${shortcut})`, 'aria-keyshortcuts': isMac() ? 'Meta+S' : 'Control+S', onClick: () => review() },
          icon('play'), t('browse.pending.review'))));
    }

    function discardNow() {
      const dropsNew = pending.counts().insert > 0;
      pending.discardAll();
      grid.setData({ rows: allRows() });
      if (dropsNew) grid.clearSelection();
      afterChange();
    }

    async function discard() {
      const n = pending.count();
      if (!n) return;
      const ok = await confirmDialog({ title: t('browse.pending.discardTitle'), message: plural('browse.pending.discardMessage', n), confirmLabel: t('browse.pending.discard'), danger: true });
      if (ok) discardNow();
    }

    /** Point the grid at the change the server rejected (error.details.index). */
    function markFailed(e, rows) {
      const i = e?.details?.index;
      pending.setFailed(Number.isInteger(i) && rows[i] !== undefined ? rows[i] : null);
      grid.redraw();
    }

    /**
     * Preview the batch, show the SQL review dialog and apply on confirm.
     * @param {{reload?: boolean}} [opts]  reload=false when the caller loads anyway (paging …)
     * @returns {Promise<boolean>} true when applied
     */
    async function review(opts = {}) {
      if (!hasPending()) return false;
      if (document.activeElement?.classList?.contains('dg-editor')) document.activeElement.blur(); // commit the open cell editor first
      const { changes, rows } = pending.toChanges();
      let prev;
      try {
        prev = await api('table.applyChanges', { ...base, changes, preview: true });
      } catch (e) {
        markFailed(e, rows);
        toastError(e, t('browse.pending.previewFailed'));
        return false;
      }
      pending.setFailed(null);
      grid.redraw();
      const res = await reviewSqlDialog({
        title: t('browse.pending.reviewTitle', { table }),
        sql: prev.sql || [],
        danger: false,
        confirmLabel: t('browse.pending.apply'),
        execute: async () => {
          try {
            return await api('table.applyChanges', { ...base, changes });
          } catch (e) {
            markFailed(e, rows);
            throw e;
          }
        },
      });
      if (!res) {
        if (rows.some((di) => pending.isFailed(di))) toast.warning(t('browse.pending.failedRow'));
        return false;
      }
      const selectKeys = state.selection
        .filter((di) => !pending.isNew(di) && pending.rowState(di) !== 'deleted')
        .map((di) => keyStr(keyOf(allRows()[di])));
      pending.reset(state.result.rows);
      toast.success(plural('browse.pending.applied', changes.length));
      drawBar();
      if (opts.reload !== false) await load({ selectKeys });
      return true;
    }

    /** Ask to apply / discard pending changes. @returns {Promise<'apply'|'discard'|null>} */
    function pendingPrompt() {
      const n = pending.count();
      let m;
      const btn = (label, cl, value, ic) => h('button', { type: 'button', class: `btn ${cl}`, onClick: () => m.close(value) }, ic ? icon(ic) : null, label);
      const applyBtn = btn(t('browse.pending.review'), 'btn-primary', 'apply', 'play');
      m = openModal({
        title: t('browse.pending.leaveTitle'),
        size: 'sm',
        body: h('p', { class: 'text-[13.5px]' }, plural('browse.pending.leaveMessage', n)),
        actions: [
          btn(t('browse.pending.keepEditing'), 'btn-ghost me-auto', null),
          btn(t('browse.pending.discard'), 'btn-danger', 'discard', 'x'),
          applyBtn,
        ],
        initialFocus: applyBtn,
      });
      return m.closed.then((v) => v || null);
    }

    /** Before paging/sorting/filtering/leaving: true when it is OK to go on. */
    async function resolvePending() {
      if (!hasPending()) return true;
      const choice = await pendingPrompt();
      if (choice === 'apply') return review({ reload: false });
      if (choice === 'discard') { discardNow(); return true; }
      return false;
    }

    /** Event handler that resolves pending changes, then mutates state and reloads. */
    function guarded(mutate, onCancel) {
      return async () => {
        if (!(await resolvePending())) { onCancel?.(); return; }
        mutate();
        load();
      };
    }

    async function setMode(toBatch) {
      if (toBatch === batch) return;
      if (!toBatch && !(await resolvePending())) return;
      batch = toBatch;
      storage.set(MODE_KEY, batch ? 'batch' : 'immediate');
      afterChange();
      if (!batch) load(); // shows rows inserted by a batch applied just now
    }

    // guards: reload / close tab, in-app navigation, Ctrl/Cmd+S
    const onBeforeUnload = (e) => { if (hasPending()) { e.preventDefault(); e.returnValue = ''; } };
    const onKey = (e) => {
      if (!modKey(e) || e.altKey || (e.key !== 's' && e.key !== 'S') || ro || !el.isConnected) return;
      e.preventDefault();
      if (document.querySelector('dialog[open]')) return;
      review();
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('keydown', onKey, true);
    setLeaveGuard(() => resolvePending());
    cleanup = () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      window.removeEventListener('keydown', onKey, true);
    };

    function exportItems() {
      const idx = state.selection.length ? state.selection : allRows().map((_, i) => i);
      const scope = state.selection.length ? plural('browse.scopeSelected', state.selection.length) : t('browse.scopePage');
      const copy = (fmt) => copyText(grid.serialize(idx, fmt)).then((ok) => (ok ? toast.success(t('grid.copiedRows', { n: idx.length })) : toast.error(t('grid.copyFailed'))));
      const toCsv = () => {
        const cols = state.result.columns.map((c) => c.name);
        const esc = (v) => {
          if (v === null || v === undefined) return '';
          const s = typeof v === 'object' ? (v.$binary !== undefined ? '' : JSON.stringify(v)) : String(v);
          return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
        };
        return `﻿${[cols.map(esc).join(','), ...idx.map((i) => allRows()[i].map(esc).join(','))].join('\r\n')}`;
      };
      return [
        { header: scope },
        { label: t('grid.copyRowTsv'), icon: 'table-2', onSelect: () => copy('tsv') },
        { label: t('grid.copyJson'), icon: 'braces', onSelect: () => copy('json') },
        { label: t('grid.copySql'), icon: 'code', onSelect: () => copy('sql') },
        { separator: true },
        { label: t('browse.downloadCsv'), icon: 'file-down', onSelect: () => downloadText(`${table}.csv`, toCsv(), 'text/csv;charset=utf-8') },
        { label: t('browse.downloadJson'), icon: 'file-down', onSelect: () => downloadText(`${table}.json`, grid.serialize(idx, 'json'), 'application/json') },
        { separator: true },
        { label: t('browse.exportTable'), icon: 'download', onSelect: () => navigate(href.export(conn, { db, schema, tables: table })) },
      ];
    }

    updateButtons();
    await load();
    grid.focus();
  },

  destroy() {
    abort?.abort();
    abort = null;
    cleanup?.();
    cleanup = null;
    setLeaveGuard(null);
  },
};
