/**
 * Database overview: tables & views with rows / size / engine / collation / comment, search
 * filter, multi-select bulk actions (empty, drop, export, optimize), create-table entry point,
 * routines & triggers lists.
 */
import { h, cls, debounce, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmDialog, openModal } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, refreshButton } from '../ui/feedback.js';
import { bindMenu, bindContextMenu } from '../ui/menu.js';
import { createDataGrid } from '../ui/datagrid.js';
import { t, plural, fmtNumber, fmtBytes } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getObjects, invalidate } from '../data.js';

export default {
  title: (ctx) => ctx.db,

  async render(el, ctx) {
    const { conn, db, schema, caps } = ctx;
    const ro = !!ctx.readOnly; // read-only connection: no create/empty/drop/optimize/import
    const selected = new Set();
    let objects = null;
    let filter = '';
    let sortKey = 'name';
    let sortDir = 1;

    const content = h('div', { class: 'w-full space-y-4 p-4 sm:p-6' }, skeleton({ rows: 8 }));
    el.replaceChildren(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, content));

    const load = async (force) => {
      try {
        objects = await getObjects(conn, db, schema, force);
        draw();
      } catch (e) {
        setChildren(content, errorState(e, { onRetry: () => load(true) }));
      }
    };

    const filterIn = h('input', {
      type: 'search', class: 'input input-sm w-full sm:w-64', placeholder: t('db.filter'), 'aria-label': t('db.filter'),
      onInput: debounce(() => { filter = filterIn.value.trim().toLowerCase(); drawTable(); }, 100),
    });
    const tableBox = h('div', { class: 'card overflow-hidden' });
    const bulkBar = h('div', { class: 'flex flex-wrap items-center gap-2 rounded-lg border border-accent/30 bg-accent-soft px-3 py-2 text-[13px]', hidden: true, role: 'region', 'aria-label': t('db.bulk.label') });

    const allItems = () => {
      const seen = new Set();
      return [...(objects.tables || []), ...(objects.views || []).map((v) => ({ type: 'view', ...v }))].filter((x) => (seen.has(x.name) ? false : seen.add(x.name)));
    };

    function sorted(items) {
      const coll = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
      const val = (x) => (sortKey === 'size' ? (x.data_size || 0) + (x.index_size || 0) : x[sortKey]);
      return [...items].sort((a, b) => {
        const va = val(a);
        const vb = val(b);
        if (va === vb) return 0;
        if (va === null || va === undefined) return 1;
        if (vb === null || vb === undefined) return -1;
        return (typeof va === 'number' ? va - vb : coll.compare(String(va), String(vb))) * sortDir;
      });
    }

    function drawBulk() {
      const n = selected.size;
      bulkBar.hidden = n === 0;
      if (!n) return;
      const names = [...selected];
      bulkBar.replaceChildren(
        h('span', { class: 'me-auto font-medium' }, plural('db.bulk.selected', n)),
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => navigate(href.export(conn, { db, schema, tables: names.join(',') })) }, icon('download'), t('db.bulk.export')),
        caps.maintenance !== false && !ro ? h('button', { type: 'button', class: 'btn btn-sm', onClick: () => maintenance(names, 'optimize') }, icon('sparkles'), t('db.bulk.optimize')) : null,
        ro ? null : h('button', { type: 'button', class: 'btn btn-sm', onClick: () => bulk('table.empty', names) }, icon('eraser'), t('db.bulk.empty')),
        ro ? null : h('button', { type: 'button', class: 'btn btn-danger btn-sm', onClick: () => bulk('table.drop', names) }, icon('trash'), t('common.drop')),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('db.bulk.clear'), onClick: () => { selected.clear(); drawTable(); } }, icon('x')));
    }

    function drawTable() {
      const items = sorted(allItems().filter((x) => !filter || x.name.toLowerCase().includes(filter) || (x.comment || '').toLowerCase().includes(filter)));
      for (const n of [...selected]) if (!items.some((x) => x.name === n)) selected.delete(n);
      drawBulk();
      if (!items.length) {
        tableBox.replaceChildren(emptyState({
          icon: 'table', title: filter ? t('sidebar.noMatches') : t('db.noTables'),
          actions: filter || ro ? [] : [h('a', { href: href.createTable(conn, db, schema), class: 'btn btn-primary btn-sm' }, icon('plus'), t('db.createTable'))],
        }));
        return;
      }
      const sortBtn = (key, label, extra) => {
        const active = sortKey === key;
        return h('th', { class: extra, 'aria-sort': active ? (sortDir > 0 ? 'ascending' : 'descending') : 'none' },
          h('button', { type: 'button', class: cls('inline-flex items-center gap-1 hover:text-fg', active && 'text-fg'), onClick: () => { if (sortKey === key) sortDir = -sortDir; else { sortKey = key; sortDir = key === 'name' ? 1 : -1; } drawTable(); } },
            label, active ? icon(sortDir > 0 ? 'arrow-up' : 'arrow-down', 'size-3') : null));
      };
      const allChecked = items.every((x) => selected.has(x.name));
      const headCb = h('input', {
        type: 'checkbox', class: 'checkbox', checked: allChecked, 'aria-label': t('grid.selectAll'),
        onChange: () => { if (headCb.checked) items.forEach((x) => selected.add(x.name)); else selected.clear(); drawTable(); },
      });
      headCb.indeterminate = !allChecked && items.some((x) => selected.has(x.name));
      let anchor = null;
      const tbody = h('tbody', {}, items.map((x, i) => {
        const cb = h('input', {
          type: 'checkbox', class: 'checkbox', checked: selected.has(x.name), 'aria-label': t('db.selectTable', { name: x.name }),
          onClick: (e) => {
            if (e.shiftKey && anchor !== null) {
              const [a, b] = anchor < i ? [anchor, i] : [i, anchor];
              for (let k = a; k <= b; k++) selected.add(items[k].name);
            } else if (cb.checked) selected.add(x.name); else selected.delete(x.name);
            anchor = i;
            drawTable();
          },
        });
        const isView = x.type === 'view';
        return h('tr', { 'aria-selected': String(selected.has(x.name)), 'data-name': x.name, 'data-type': x.type },
          h('td', { class: 'w-10' }, cb),
          h('td', { class: 'max-w-[16rem]' },
            h('a', { href: href.table(conn, db, schema, x.name, 'browse'), class: 'inline-flex max-w-full items-center gap-2 font-medium hover:text-accent' },
              icon(isView ? 'eye' : 'table', 'text-muted'), h('span', { class: 'truncate', dir: 'auto' }, x.name)),
            isView ? h('span', { class: 'badge ms-2' }, t('db.view')) : null),
          h('td', { class: 'num' }, x.rows === null || x.rows === undefined ? '—' : fmtNumber(x.rows)),
          h('td', { class: 'num hidden sm:table-cell' }, isView ? '—' : fmtBytes((x.data_size || 0) + (x.index_size || 0))),
          h('td', { class: 'hidden text-muted md:table-cell' }, x.engine || '—'),
          h('td', { class: 'hidden lg:table-cell' }, h('span', { class: 'font-mono text-xs text-muted' }, x.collation || '—')),
          h('td', { class: 'hidden max-w-[18rem] truncate text-muted xl:table-cell', dir: 'auto', title: x.comment || '' }, x.comment || ''),
          h('td', { class: 'w-10 text-end' }, rowMenuBtn(x)));
      }));
      bindContextMenu(tbody, (e) => {
        const tr = e.target?.closest?.('tr[data-name]');
        return tr ? itemMenu({ name: tr.dataset.name, type: tr.dataset.type }) : null;
      });
      tableBox.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {},
          h('th', { class: 'w-10' }, headCb),
          sortBtn('name', t('db.col.name')),
          sortBtn('rows', t('db.col.rows'), 'num'),
          sortBtn('size', t('db.col.size'), 'num hidden sm:table-cell'),
          sortBtn('engine', t('db.col.engine'), 'hidden md:table-cell'),
          sortBtn('collation', t('db.col.collation'), 'hidden lg:table-cell'),
          h('th', { class: 'hidden xl:table-cell' }, t('db.col.comment')),
          h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        tbody)));
    }

    function itemMenu(x) {
      const isView = x.type === 'view';
      const go = (tab) => () => navigate(href.table(conn, db, schema, x.name, tab));
      return [
        { label: t('table.tab.browse'), icon: 'table-2', onSelect: go('browse') },
        { label: t('table.tab.structure'), icon: 'columns', onSelect: go('structure') },
        { label: t('table.tab.sql'), icon: 'terminal', onSelect: go('sql') },
        { label: t('table.tab.ddl'), icon: 'file-code', onSelect: go('ddl') },
        ...(isView ? [] : [{ label: t('table.tab.ops'), icon: 'wrench', onSelect: go('ops') }]),
        { separator: true },
        { label: t('export.thisTable'), icon: 'download', onSelect: () => navigate(href.export(conn, { db, schema, tables: x.name })) },
        ...(isView || ro ? [] : [{ label: t('import.intoTable'), icon: 'upload', onSelect: () => navigate(href.import(conn, { db, schema, table: x.name })) }]),
        ...(ro ? [] : [
          { separator: true },
          ...(isView ? [] : [{ label: t('db.bulk.empty'), icon: 'eraser', danger: true, onSelect: () => bulk('table.empty', [x.name]) }]),
          { label: t('common.drop'), icon: 'trash', danger: true, onSelect: () => bulk('table.drop', [x.name]) },
        ]),
      ];
    }
    function rowMenuBtn(x) {
      const b = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.moreActionsFor', { name: x.name }) }, icon('ellipsis'));
      bindMenu(b, () => itemMenu(x), { align: 'end' });
      return b;
    }

    async function bulk(action, names) {
      const drop = action === 'table.drop';
      const n = names.length;
      const list = names.length > 8 ? `${names.slice(0, 8).join(', ')}, …` : names.join(', ');
      const ok = await confirmDialog({
        title: drop ? t('db.confirm.dropTitle', { n }) : t('db.confirm.emptyTitle', { n }),
        message: drop ? t('db.confirm.dropMessage', { names: list }) : t('db.confirm.emptyMessage', { names: list }),
        danger: true,
        confirmLabel: drop ? t('common.drop') : t('db.bulk.empty'),
      });
      if (!ok) return;
      try {
        await api(action, { conn, db, schema, tables: names });
        toast.success(drop ? t('db.dropped', { n }) : t('db.emptied', { n }));
        names.forEach((x) => selected.delete(x));
        invalidate(conn, db);
        await load(true);
      } catch (e) { toastError(e); }
    }

    async function maintenance(names, op) {
      try {
        const res = await api('table.maintenance', { conn, db, schema, tables: names, op });
        const grid = createDataGrid({ columns: (res.columns || []).map((c) => (typeof c === 'string' ? { name: c } : c)), rows: res.rows || [], selectable: false, className: 'h-80 rounded-lg border border-border' });
        openModal({ title: t('db.maintenanceResult', { op }), body: grid.el, size: 'xl' }).closed.then(() => grid.destroy());
        invalidate(conn, db);
      } catch (e) { toastError(e); }
    }

    function draw() {
      const tables = (objects.tables || []).filter((x) => x.type !== 'view');
      const totalRows = tables.reduce((s, x) => s + (Number(x.rows) || 0), 0);
      const totalSize = tables.reduce((s, x) => s + (Number(x.data_size) || 0) + (Number(x.index_size) || 0), 0);
      const routines = objects.routines || [];
      const triggers = objects.triggers || [];
      const actions = h('div', { class: 'flex flex-wrap gap-2' },
        h('a', { href: href.sql(conn, { db, schema }), class: 'btn btn-sm' }, icon('terminal'), t('nav.sql')),
        h('a', { href: href.dbSearch(conn, db, schema), class: 'btn btn-sm max-sm:hidden' }, icon('text-search'), t('nav.dbSearch')),
        h('a', { href: href.erd(conn, db, schema), class: 'btn btn-sm max-sm:hidden' }, icon('network'), t('nav.erd')),
        ro ? null : h('a', { href: href.import(conn, { db, schema }), class: 'btn btn-sm max-sm:hidden' }, icon('upload'), t('nav.import')),
        h('a', { href: href.export(conn, { db, schema }), class: 'btn btn-sm max-sm:hidden' }, icon('download'), t('nav.export')),
        h('a', { href: href.dbSettings(conn, db, schema), class: 'btn btn-sm max-sm:hidden' }, icon('settings'), t('dbOps.menu')),
        h('a', { href: href.diff(conn, db, schema), class: 'btn btn-sm max-sm:hidden' }, icon('git-compare'), t('diff.compareMenu')),
        (() => {
          const more = h('button', { type: 'button', class: 'btn btn-sm btn-icon sm:hidden', 'aria-label': t('common.more') }, icon('ellipsis'));
          bindMenu(more, () => [
            { label: t('nav.dbSearch'), icon: 'text-search', onSelect: () => navigate(href.dbSearch(conn, db, schema)) },
            { label: t('nav.erd'), icon: 'network', onSelect: () => navigate(href.erd(conn, db, schema)) },
            ro ? null : { label: t('nav.import'), icon: 'upload', onSelect: () => navigate(href.import(conn, { db, schema })) },
            { label: t('nav.export'), icon: 'download', onSelect: () => navigate(href.export(conn, { db, schema })) },
            { label: t('dbOps.menu'), icon: 'settings', onSelect: () => navigate(href.dbSettings(conn, db, schema)) },
            { label: t('diff.compareMenu'), icon: 'git-compare', onSelect: () => navigate(href.diff(conn, db, schema)) },
          ].filter(Boolean), { align: 'end' });
          return more;
        })(),
        ro ? null : h('a', { href: href.createTable(conn, db, schema), class: 'btn btn-primary btn-sm' }, icon('plus'), t('db.createTable')));

      const objHref = (k, name, params) => href.dbObject(conn, db, schema, k, name, params);
      const newBtn = (label, k, params) => (ro ? null : h('a', { href: objHref(k, null, params), class: 'btn btn-sm' }, icon('plus'), label));
      const overloaded = (r) => r.signature !== undefined && routines.filter((x) => x.name === r.name).length > 1;
      const section = (title, items, ic, makeHref, actions = [], meta = () => null) => (items.length || actions.some(Boolean) ? h('section', { class: 'space-y-2' },
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          h('h2', { class: 'me-auto text-[14px] font-semibold' }, title, h('span', { class: 'ms-2 text-xs font-normal text-muted' }, fmtNumber(items.length))),
          ...actions),
        items.length ? h('div', { class: 'card divide-y divide-border' }, items.map((r) => h('a', { href: makeHref(r), class: 'flex items-center gap-3 px-4 py-2.5 text-[13px] hover:bg-surface-2' },
          icon(ic, 'text-muted'), h('span', { class: 'min-w-0 flex-1 truncate font-medium', dir: 'auto' }, overloaded(r) ? r.signature : r.name),
          r.type ? h('span', { class: 'badge' }, String(r.type)) : null,
          meta(r)))) : null) : null);
      const routineHref = (r) => objHref('routine', r.name, { type: String(r.type || '').toLowerCase() || undefined, sig: overloaded(r) ? r.arguments : undefined });
      const events = objects.events || [];

      setChildren(content, 
        h('div', { class: 'flex flex-wrap items-end justify-between gap-3' },
          h('div', { class: 'min-w-0' },
            h('h1', { class: 'flex items-center gap-2 text-xl font-semibold tracking-tight' }, icon('database', 'size-5 text-muted'), h('span', { class: 'truncate', dir: 'auto' }, schema ? `${db}.${schema}` : db)),
            h('p', { class: 'mt-0.5 text-[13px] text-muted' }, `${plural('db.tableCount', tables.length)} · ${t('db.totalRows', { n: fmtNumber(totalRows) })} · ${fmtBytes(totalSize)}`)),
          actions),
        h('div', { class: 'flex flex-wrap items-center gap-2' }, filterIn, h('div', { class: 'flex-1' }),
          refreshButton(() => { invalidate(conn, db); load(true); })),
        bulkBar,
        tableBox,
        caps.routines ? section(t('db.routines'), routines, 'function', routineHref, [newBtn(t('routines.newProcedure'), 'routine', { new: 'procedure' }), newBtn(t('routines.newFunction'), 'routine', { new: 'function' })],
          (r) => (r.returns ? h('span', { class: 'hidden max-w-[12rem] truncate font-mono text-xs text-muted sm:inline', dir: 'ltr' }, r.returns) : null)) : null,
        caps.triggers ? section(t('db.triggers'), triggers, 'zap', (r) => objHref('trigger', r.name, { table: r.table }), [newBtn(t('routines.newTrigger'), 'trigger', { new: 1 })],
          (r) => h('span', { class: 'truncate text-xs text-muted', dir: 'auto' }, `${r.timing || ''} ${r.event || ''} · ${r.table}`)) : null,
        caps.events ? section(t('routines.events'), events, 'calendar', (r) => objHref('event', r.name), [newBtn(t('routines.newEvent'), 'event', { new: 1 })],
          (r) => h('span', { class: 'flex min-w-0 items-center gap-2 truncate text-xs text-muted', dir: 'ltr' }, r.schedule || '', r.status && !/^enabled?$/i.test(r.status) ? h('span', { class: 'badge badge-warning' }, r.status) : null)) : null,
      );
      drawTable();
    }

    await load(false);
  },
};
