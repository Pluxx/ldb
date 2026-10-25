/**
 * Sidebar: database tree (databases → [schemas →] tables / views / routines), filter box,
 * counts, active highlight, per-item context menus, keyboard tree navigation, resizable on
 * desktop and an off-canvas drawer below the lg breakpoint.
 * @module layout/sidebar
 */
import { h, cls, debounce, copyText, dragPointer, on, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { bindContextMenu } from '../ui/menu.js';
import { confirmDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { t, fmtNumber } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore, bus, storage } from '../store.js';
import { href, navigate, currentRoute } from '../router.js';
import { getDatabases, getObjects, getSchemas, getCaps, invalidate } from '../data.js';
import { isReadOnly, withoutWrites } from '../session.js';

/**
 * Create the sidebar.
 * @returns {{el: HTMLElement, resizer: HTMLElement, update: (route: object) => void, destroy: () => void}}
 */
export function createSidebar() {
  let conn = null;
  let caps = {};
  let databases = null; // array | Error | 'loading'
  let filter = '';
  let route = null;
  const expanded = new Set();
  const nodes = new Map(); // key → data | 'loading' | Error
  const offs = [];

  const filterInput = h('input', {
    type: 'search', class: 'input input-sm ps-8', placeholder: t('sidebar.filter'), 'aria-label': t('sidebar.filter'),
    onInput: debounce(() => { filter = filterInput.value.trim().toLowerCase(); render(); }, 120),
    onKeydown: (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); rowsList()[0]?.focus(); } },
  });
  const tree = h('ul', { role: 'tree', class: 'flex flex-col gap-px px-2 pb-4', 'aria-label': t('sidebar.databases') });
  const footer = h('div', { class: 'flex items-center gap-1 border-t border-border p-2' });
  const el = h('aside', {
    id: 'sidebar',
    class: cls(
      'fixed inset-y-0 start-0 z-50 flex w-[86vw] max-w-[320px] flex-col border-e border-border bg-surface shadow-modal transition-transform duration-200',
      '-translate-x-full rtl:translate-x-full',
      'lg:relative lg:z-auto lg:w-[var(--o-sidebar-w)] lg:max-w-none lg:translate-x-0 lg:shadow-none lg:transition-none rtl:lg:translate-x-0',
    ),
    'aria-label': t('sidebar.label'),
  },
  h('div', { class: 'flex items-center gap-1.5 p-2 pt-[max(0.5rem,env(safe-area-inset-top))] lg:pt-2' },
    h('div', { class: 'relative min-w-0 flex-1' },
      icon('search', 'pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-subtle'),
      filterInput),
    h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('sidebar.refresh'), 'aria-label': t('sidebar.refresh'), onClick: () => reload() }, icon('refresh')),
    h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon lg:hidden', 'aria-label': t('common.close'), onClick: () => appStore.set({ sidebarOpen: false }) }, icon('x'))),
  h('nav', { class: 'min-h-0 flex-1 overflow-auto', 'aria-label': t('sidebar.databases') }, tree),
  footer);

  // ----- drawer behaviour
  const backdrop = h('div', { class: 'fixed inset-0 z-40 bg-black/40 lg:hidden', hidden: true, onClick: () => appStore.set({ sidebarOpen: false }) });
  document.body.appendChild(backdrop);
  offs.push(appStore.select('sidebarOpen', (open) => {
    el.classList.toggle('-translate-x-full', !open);
    el.classList.toggle('rtl:translate-x-full', !open);
    el.classList.toggle('translate-x-0', open);
    backdrop.hidden = !open;
    if (open) setTimeout(() => filterInput.focus(), 50);
  }));
  offs.push(on(el, 'keydown', (e) => { if (e.key === 'Escape' && appStore.get().sidebarOpen) appStore.set({ sidebarOpen: false }); }));
  offs.push(on(window, 'hashchange', () => { if (appStore.get().sidebarOpen) appStore.set({ sidebarOpen: false }); }));

  // ----- resizer (desktop)
  const applyWidth = (w) => document.documentElement.style.setProperty('--o-sidebar-w', `${w}px`);
  let width = Number(storage.get('sidebarWidth', 264)) || 264;
  applyWidth(width);
  const resizer = h('div', {
    role: 'separator', 'aria-orientation': 'vertical', 'aria-label': t('sidebar.resize'), tabindex: '0',
    'aria-valuemin': '200', 'aria-valuemax': '520', 'aria-valuenow': String(width),
    class: 'absolute inset-y-0 -end-1 z-10 hidden w-2 cursor-col-resize touch-none after:absolute after:inset-y-0 after:start-[3px] after:w-px hover:after:bg-accent focus-visible:after:bg-accent lg:block',
    onPointerdown: (e) => {
      const start = width;
      const mul = document.documentElement.dir === 'rtl' ? -1 : 1;
      dragPointer(e, (dx) => { width = Math.max(200, Math.min(520, start + dx * mul)); applyWidth(width); }, () => { storage.set('sidebarWidth', width); resizer.setAttribute('aria-valuenow', String(width)); });
    },
    onKeydown: (e) => {
      const rtl = document.documentElement.dir === 'rtl';
      const step = e.shiftKey ? 40 : 16;
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        const grow = (e.key === 'ArrowRight') !== rtl;
        width = Math.max(200, Math.min(520, width + (grow ? step : -step)));
        applyWidth(width);
        storage.set('sidebarWidth', width);
        resizer.setAttribute('aria-valuenow', String(width));
      }
    },
  });

  el.appendChild(resizer); // inside the <aside> landmark, pinned to its inline end

  // ----- data
  async function loadDatabases(force) {
    if (!conn) return;
    databases = 'loading';
    render();
    try {
      databases = await getDatabases(conn, force);
    } catch (e) {
      databases = e;
    }
    render();
  }

  async function loadNode(key, loader) {
    nodes.set(key, 'loading');
    render();
    try { nodes.set(key, await loader()); } catch (e) { nodes.set(key, e); }
    render();
  }

  function reload() {
    nodes.clear();
    invalidate(conn);
  }

  const storeKey = () => `tree.${conn}`;
  const saveExpanded = () => storage.set(storeKey(), [...expanded].slice(-50));

  function toggle(key, ensureLoad) {
    if (expanded.has(key)) expanded.delete(key);
    else {
      expanded.add(key);
      ensureLoad?.();
    }
    saveExpanded();
    render();
  }

  function ensureDb(db) {
    if (caps.schemas) {
      const k = `s|${db}`;
      if (!nodes.has(k)) loadNode(k, () => getSchemas(conn, db));
    } else {
      const k = `o|${db}|`;
      if (!nodes.has(k)) loadNode(k, () => getObjects(conn, db));
    }
  }
  function ensureSchema(db, schema) {
    const k = `o|${db}|${schema}`;
    if (!nodes.has(k)) loadNode(k, () => getObjects(conn, db, schema));
  }

  // ----- rendering
  const match = (name) => !filter || String(name).toLowerCase().includes(filter);

  function row(o) {
    const isLink = !!o.href;
    const attrs = {
      class: cls('tree-row', o.current && 'is-current'),
      role: 'treeitem',
      tabindex: '-1',
      'aria-expanded': o.expandable ? String(!!o.open) : null,
      'aria-current': o.current ? 'page' : null,
      'aria-level': String(o.level),
      'data-tree-row': '1',
      'data-kind': o.kind,
      'data-db': o.db,
      'data-schema': o.schema ?? '',
      'data-name': o.name ?? '',
      'data-key': o.key ?? '',
      style: { paddingInlineStart: `${0.4 + (o.level - 1) * 0.85}rem` },
      title: o.title,
    };
    const chevron = o.expandable
      ? h('span', {
        class: 'hit-24 inline-flex size-4 items-center justify-center rounded hover:bg-surface-3',
        'data-toggle': '1',
        onClick: (e) => { e.preventDefault(); e.stopPropagation(); o.onToggle(); },
      }, icon(o.open ? 'chevron-down' : 'chevron-right', 'size-3.5'))
      : h('span', { class: 'inline-block size-4' });
    const content = [
      chevron,
      icon(o.icon),
      h('span', { class: 'min-w-0 flex-1 truncate', dir: 'auto' }, o.label),
      o.count !== undefined && o.count !== null ? h('span', { class: 'ms-1 text-[11px] tabular-nums text-subtle' }, fmtNumber(o.count)) : null,
    ];
    const elRow = isLink
      ? h('a', { ...attrs, href: o.href, onClick: o.onClick }, content)
      : h('button', { ...attrs, type: 'button', onClick: o.onClick || o.onToggle }, content);
    return h('li', { role: 'none' }, elRow, o.children && o.open ? h('ul', { role: 'group', class: 'flex flex-col gap-px' }, o.children) : null);
  }

  function statusRow(state, level, retry) {
    const pad = { paddingInlineStart: `${0.4 + level * 0.85 + 1.25}rem` };
    if (state === 'loading') return h('li', { role: 'none', class: 'py-1 text-xs text-muted', style: pad }, h('span', { class: 'skeleton inline-block h-3 w-24 align-middle' }));
    if (state instanceof Error) {
      return h('li', { role: 'none', class: 'flex items-center gap-2 py-1 text-xs text-danger', style: pad },
        h('span', { class: 'truncate', title: state.message }, t('sidebar.loadError')),
        h('button', { type: 'button', class: 'underline', onClick: retry }, t('common.retry')));
    }
    return null;
  }

  function groupsFor(db, schema, level) {
    const key = `o|${db}|${schema ?? ''}`;
    const data = nodes.get(key);
    if (!data || data === 'loading' || data instanceof Error) {
      return [statusRow(data || 'loading', level, () => { nodes.delete(key); schema === undefined ? ensureDb(db) : ensureSchema(db, schema); })];
    }
    const out = [];
    const r = route || {};
    const groups = [
      { id: 'tables', items: (data.tables || []).filter((x) => x.type !== 'view'), icon: 'table', kind: 'table' },
      { id: 'views', items: [...(data.views || []), ...(data.tables || []).filter((x) => x.type === 'view')], icon: 'eye', kind: 'view' },
      { id: 'routines', items: data.routines || [], icon: 'function', kind: 'routine' },
      { id: 'triggers', items: data.triggers || [], icon: 'zap', kind: 'trigger', label: t('routines.groupTriggers') },
      { id: 'events', items: data.events || [], icon: 'calendar', kind: 'event', label: t('routines.groupEvents') },
    ];
    for (const g of groups) {
      // de-duplicate views listed both in tables and views
      const seen = new Set();
      // routines: PostgreSQL overloads share a name (one row per signature)
      const keyOf = (x) => (g.kind === 'routine' ? `${x.type}|${x.name}|${x.arguments ?? ''}` : g.kind === 'trigger' ? `${x.table}|${x.name}` : x.name);
      const items = g.items.filter((x) => { const k = keyOf(x); if (seen.has(k)) return false; seen.add(k); return true; }).filter((x) => match(x.name) || match(db));
      if (!items.length && (filter || g.id !== 'tables')) continue;
      const gkey = `g|${db}|${schema ?? ''}|${g.id}`;
      const open = filter ? true : !expanded.has(`${gkey}|closed`);
      out.push(row({
        kind: 'group', db, schema, level, key: gkey, icon: g.id === 'tables' ? 'layers' : g.icon,
        label: g.label || t(`sidebar.group.${g.id}`), count: items.length, expandable: true, open,
        onToggle: () => toggle(`${gkey}|closed`),
        children: items.map((it) => {
          const isObj = g.kind === 'routine' || g.kind === 'trigger' || g.kind === 'event';
          const multi = g.kind === 'routine' && it.arguments !== undefined && items.filter((x) => x.name === it.name).length > 1;
          const params = g.kind === 'routine' ? { type: String(it.type || '').toLowerCase() || undefined, sig: multi ? it.arguments : undefined }
            : g.kind === 'trigger' ? { table: it.table } : undefined;
          const sameDb = r.db === db && (r.schema ?? '') === (schema ?? '');
          return row({
            kind: g.kind, db, schema, name: it.name, level: level + 1, icon: g.icon, label: multi ? `${it.name}(${it.arguments})` : it.name,
            title: g.kind === 'trigger' ? `${it.timing || ''} ${it.event || ''} · ${it.table}` : (it.comment || it.name),
            current: isObj ? sameDb && r.name === 'dbObject' && r.kind === g.kind && r.object === it.name && (!multi || r.params?.sig === it.arguments)
              : sameDb && r.table === it.name,
            href: isObj ? href.dbObject(conn, db, schema, g.kind, it.name, params) : href.table(conn, db, schema, it.name, 'browse'),
          });
        }),
      }));
    }
    if (!out.length) out.push(h('li', { role: 'none', class: 'py-1 text-xs text-muted', style: { paddingInlineStart: `${0.4 + level * 0.85 + 1.25}rem` } }, t('sidebar.noObjects')));
    return out;
  }

  function render() {
    const focusedKey = document.activeElement?.closest?.('[data-tree-row]') ? rowId(document.activeElement.closest('[data-tree-row]')) : null;
    tree.replaceChildren();
    if (!conn) return;
    if (databases === 'loading' || databases === null) {
      tree.append(...Array.from({ length: 6 }, () => h('li', { role: 'none', class: 'px-2 py-1.5' }, h('div', { class: 'skeleton h-4 w-3/4' }))));
      return;
    }
    if (databases instanceof Error) {
      tree.appendChild(h('li', { role: 'none', class: 'p-3 text-[13px] text-danger' }, databases.message,
        h('button', { type: 'button', class: 'ms-2 underline', onClick: () => loadDatabases(true) }, t('common.retry'))));
      return;
    }
    const r = route || {};
    for (const d of databases) {
      const objKey = caps.schemas ? `s|${d.name}` : `o|${d.name}|`;
      const loaded = nodes.get(objKey);
      const childMatch = filter && loaded && typeof loaded === 'object' && !(loaded instanceof Error) && !caps.schemas
        && [...(loaded.tables || []), ...(loaded.views || []), ...(loaded.routines || []), ...(loaded.triggers || []), ...(loaded.events || [])].some((x) => match(x.name));
      if (!match(d.name) && !childMatch) continue;
      const key = `db|${d.name}`;
      const open = expanded.has(key) || !!childMatch;
      let children = null;
      if (open) {
        if (caps.schemas) {
          const sch = nodes.get(`s|${d.name}`);
          children = !Array.isArray(sch)
            ? [statusRow(sch || 'loading', 1, () => { nodes.delete(`s|${d.name}`); ensureDb(d.name); })]
            : sch.map((s) => {
              const skey = `sc|${d.name}|${s}`;
              const sopen = expanded.has(skey);
              return row({
                kind: 'schema', db: d.name, schema: s, name: s, level: 2, key: skey, icon: 'layers', label: s,
                expandable: true, open: sopen, current: r.db === d.name && r.schema === s && !r.table,
                href: href.db(conn, d.name, s),
                onToggle: () => toggle(skey, () => ensureSchema(d.name, s)),
                children: sopen ? groupsFor(d.name, s, 3) : null,
              });
            });
        } else {
          children = groupsFor(d.name, undefined, 2);
        }
      }
      tree.appendChild(row({
        kind: 'db', db: d.name, name: d.name, level: 1, key, icon: 'database', label: d.name,
        count: d.tables, expandable: true, open,
        current: r.db === d.name && !r.table && r.name === 'db',
        href: href.db(conn, d.name),
        onToggle: () => toggle(key, () => ensureDb(d.name)),
        children,
      }));
    }
    if (!tree.children.length) tree.appendChild(h('li', { role: 'none', class: 'p-3 text-[13px] text-muted' }, filter ? t('sidebar.noMatches') : t('sidebar.noDatabases')));
    // keep one row tabbable (roving tabindex)
    const rows = rowsList();
    const target = (focusedKey && rows.find((x) => rowId(x) === focusedKey)) || rows.find((x) => x.getAttribute('aria-current') === 'page') || rows[0];
    if (target) {
      target.tabIndex = 0;
      if (focusedKey && rowId(target) === focusedKey) target.focus({ preventScroll: true });
    }
  }

  const rowId = (x) => `${x.dataset.kind}|${x.dataset.db}|${x.dataset.schema}|${x.dataset.name}|${x.dataset.key}`;
  const rowsList = () => [...tree.querySelectorAll('[data-tree-row]')];

  // keyboard tree navigation
  tree.addEventListener('keydown', (e) => {
    const cur = e.target.closest('[data-tree-row]');
    if (!cur) return;
    const rows = rowsList();
    const i = rows.indexOf(cur);
    const rtl = document.documentElement.dir === 'rtl';
    const expandKey = rtl ? 'ArrowLeft' : 'ArrowRight';
    const collapseKey = rtl ? 'ArrowRight' : 'ArrowLeft';
    const focus = (el2) => { if (!el2) return; rows.forEach((r) => { r.tabIndex = -1; }); el2.tabIndex = 0; el2.focus(); };
    if (e.key === 'ArrowDown') { e.preventDefault(); focus(rows[i + 1]); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (i === 0) filterInput.focus(); else focus(rows[i - 1]); }
    else if (e.key === 'Home') { e.preventDefault(); focus(rows[0]); }
    else if (e.key === 'End') { e.preventDefault(); focus(rows[rows.length - 1]); }
    else if (e.key === expandKey) {
      e.preventDefault();
      if (cur.getAttribute('aria-expanded') === 'false') cur.querySelector('[data-toggle]')?.click();
      else if (cur.getAttribute('aria-expanded') === 'true') focus(rows[i + 1]);
    } else if (e.key === collapseKey) {
      e.preventDefault();
      if (cur.getAttribute('aria-expanded') === 'true') cur.querySelector('[data-toggle]')?.click();
      else {
        const parent = cur.parentElement?.parentElement?.closest('li')?.querySelector('[data-tree-row]');
        focus(parent);
      }
    } else if (e.key === ' ' && cur.getAttribute('aria-expanded') !== null) {
      e.preventDefault();
      cur.querySelector('[data-toggle]')?.click();
    }
  });

  // context menus
  // Items marked `write: true` are dropped for read-only connections.
  bindContextMenu(tree, (e) => withoutWrites(treeMenu(e), conn));
  function treeMenu(e) {
    const r = e.target?.closest?.('[data-tree-row]');
    if (!r) return null;
    const { kind, db } = r.dataset;
    const schema = r.dataset.schema || undefined;
    const name = r.dataset.name;
    const copy = () => copyText(name).then(() => toast.success(t('common.copied')));
    if (kind === 'db' || kind === 'schema') {
      return [
        { label: t('sidebar.menu.open'), icon: 'database', onSelect: () => navigate(href.db(conn, db, schema)) },
        { label: t('sidebar.menu.newQuery'), icon: 'terminal', onSelect: () => navigate(href.sql(conn, { db, schema })) },
        { label: t('sidebar.menu.search'), icon: 'text-search', onSelect: () => navigate(href.dbSearch(conn, db, schema)) },
        { label: t('sidebar.menu.erd'), icon: 'network', onSelect: () => navigate(href.erd(conn, db, schema)) },
        { label: t('dbOps.menu'), icon: 'settings', onSelect: () => navigate(href.dbSettings(conn, db, schema)) },
        { label: t('diff.compareMenu'), icon: 'git-compare', onSelect: () => navigate(href.diff(conn, db, schema)) },
        { label: t('sidebar.menu.createTable'), icon: 'plus', write: true, onSelect: () => navigate(href.createTable(conn, db, schema)) },
        { separator: true },
        { label: t('sidebar.menu.import'), icon: 'upload', write: true, onSelect: () => navigate(href.import(conn, { db, schema })) },
        { label: t('sidebar.menu.export'), icon: 'download', onSelect: () => navigate(href.export(conn, { db, schema })) },
        { label: t('sidebar.menu.refresh'), icon: 'refresh', onSelect: () => { nodes.clear(); invalidate(conn, db); } },
        { label: t('common.copyName'), icon: 'copy', onSelect: copy },
        ...(kind === 'db' ? [{ separator: true }, { label: t('server.dropDb'), icon: 'trash', danger: true, write: true, onSelect: () => dropDb(db) }] : []),
      ];
    }
    if (kind === 'table' || kind === 'view') {
      const tbl = (tab) => () => navigate(href.table(conn, db, schema, name, tab));
      return [
        { label: t('table.tab.browse'), icon: 'table-2', onSelect: tbl('browse') },
        { label: t('table.tab.structure'), icon: 'columns', onSelect: tbl('structure') },
        { label: t('table.tab.sql'), icon: 'terminal', onSelect: tbl('sql') },
        ...(kind === 'table' ? [{ label: t('table.tab.insert'), icon: 'plus', write: true, onSelect: tbl('insert') }] : []),
        { label: t('table.tab.ddl'), icon: 'file-code', onSelect: tbl('ddl') },
        ...(kind === 'table' ? [{ label: t('table.tab.ops'), icon: 'wrench', onSelect: tbl('ops') }] : []),
        { label: t('common.copyName'), icon: 'copy', onSelect: copy },
        { separator: true },
        ...(kind === 'table' ? [{ label: t('db.bulk.empty'), icon: 'eraser', danger: true, write: true, onSelect: () => tableAction('table.empty', db, schema, name) }] : []),
        { label: kind === 'view' ? t('sidebar.menu.dropView') : t('sidebar.menu.dropTable'), icon: 'trash', danger: true, write: true, onSelect: () => tableAction('table.drop', db, schema, name) },
      ];
    }
    if (kind === 'group' && /\|tables$/.test(r.dataset.key || '')) {
      return [{ label: t('sidebar.menu.createTable'), icon: 'plus', write: true, onSelect: () => navigate(href.createTable(conn, db, schema)) }];
    }
    const newObj = (k, params) => () => navigate(href.dbObject(conn, db, schema, k, null, params));
    if (kind === 'group' && /\|routines$/.test(r.dataset.key || '')) {
      return [
        { label: t('routines.newProcedure'), icon: 'plus', write: true, onSelect: newObj('routine', { new: 'procedure' }) },
        { label: t('routines.newFunction'), icon: 'plus', write: true, onSelect: newObj('routine', { new: 'function' }) },
      ];
    }
    if (kind === 'group' && /\|triggers$/.test(r.dataset.key || '')) return [{ label: t('routines.newTrigger'), icon: 'plus', write: true, onSelect: newObj('trigger', { new: 1 }) }];
    if (kind === 'group' && /\|events$/.test(r.dataset.key || '')) return [{ label: t('routines.newEvent'), icon: 'plus', write: true, onSelect: newObj('event', { new: 1 }) }];
    if (kind === 'routine' || kind === 'trigger' || kind === 'event') {
      const link = r.getAttribute('href');
      const params = new URLSearchParams(link.split('?')[1] || '');
      const type = kind === 'routine' ? (params.get('type') || 'routine') : kind;
      return [
        { label: t('routines.edit'), icon: 'pencil', onSelect: () => navigate(link) },
        { label: t('table.tab.ddl'), icon: 'file-code', onSelect: () => navigate(href.table(conn, db, schema, name, 'ddl', { type })) },
        { label: t('common.copyName'), icon: 'copy', onSelect: copy },
        { separator: true },
        { label: t('common.drop'), icon: 'trash', danger: true, write: true, onSelect: () => dropObject(kind, db, schema, name, params) },
      ];
    }
    return null;
  }

  async function dropDb(db) {
    const ok = await confirmDialog({ title: t('server.dropDbTitle', { name: db }), message: t('server.dropDbMessage'), requireText: db, danger: true, confirmLabel: t('server.dropDb') });
    if (!ok) return;
    try {
      await api('db.drop', { conn, name: db });
      toast.success(t('server.dbDropped', { name: db }));
      invalidate(conn);
      if (currentRoute()?.db === db) navigate(href.server(conn));
    } catch (e) { toastError(e); }
  }

  async function dropObject(kind, db, schema, name, params) {
    const ok = await confirmDialog({ title: t('routines.dropTitle', { name }), message: t('routines.dropMessage'), requireText: name, danger: true, confirmLabel: t('common.drop') });
    if (!ok) return;
    try {
      const p = { conn, db, schema, name };
      if (params.get('type')) p.type = params.get('type');
      if (params.has('sig')) p.signature = params.get('sig');
      if (params.get('table')) p.table = params.get('table');
      await api(`${kind}.drop`, p);
      toast.success(t('routines.dropped', { name }));
      nodes.delete(`o|${db}|${schema ?? ''}`);
      invalidate(conn, db);
      const cur = currentRoute();
      if (cur?.name === 'dbObject' && cur.kind === kind && cur.object === name && cur.db === db) navigate(href.db(conn, db, schema));
    } catch (e) { toastError(e); }
  }

  async function tableAction(action, db, schema, name) {
    const drop = action === 'table.drop';
    const ok = await confirmDialog({
      title: drop ? t('db.confirm.dropTitle', { n: 1 }) : t('db.confirm.emptyTitle', { n: 1 }),
      message: drop ? t('db.confirm.dropMessage', { names: name }) : t('db.confirm.emptyMessage', { names: name }),
      danger: true, confirmLabel: drop ? t('common.drop') : t('db.bulk.empty'),
    });
    if (!ok) return;
    try {
      await api(action, { conn, db, schema, tables: [name] });
      toast.success(drop ? t('db.dropped', { n: 1 }) : t('db.emptied', { n: 1 }));
      nodes.delete(`o|${db}|${schema ?? ''}`);
      invalidate(conn, db);
      const cur = currentRoute();
      if (drop && cur?.table === name && cur?.db === db) navigate(href.db(conn, db, schema));
    } catch (e) { toastError(e); }
  }

  offs.push(bus.on('data:changed', (p) => {
    if (!p || String(p.conn) !== String(conn)) return;
    if (p.db === undefined) { nodes.clear(); loadDatabases(true); return; }
    for (const k of [...nodes.keys()]) if (k.split('|')[1] === p.db) nodes.delete(k);
    loadDatabases(true).then(() => {
      for (const k of expanded) {
        if (k === `db|${p.db}`) ensureDb(p.db);
        else if (k.startsWith(`sc|${p.db}|`)) ensureSchema(p.db, k.split('|')[2]);
      }
    });
  }));

  function renderFooter() {
    const link = (hrefStr, ic, label) => h('a', { href: hrefStr, class: 'btn btn-ghost btn-sm btn-icon', title: label, 'aria-label': label }, icon(ic));
    setChildren(footer, 
      link(href.server(conn), 'server', t('nav.server')),
      link(href.sql(conn), 'terminal', t('nav.sql')),
      isReadOnly(conn) ? null : link(href.import(conn), 'upload', t('nav.import')),
      link(href.export(conn), 'download', t('nav.export')),
      caps.status !== false ? link(href.serverTab(conn, 'status'), 'gauge', t('nav.status')) : null,
      caps.processes ? link(href.serverTab(conn, 'processes'), 'activity', t('nav.processes')) : null,
      caps.users ? link(href.serverTab(conn, 'users'), 'users', t('nav.users')) : null,
    );
  }

  return {
    el,
    backdrop,
    resizer,
    /** Sync with the current route (connection switch, active item, auto-expand). */
    async update(r) {
      route = r;
      if (r.conn !== conn) {
        conn = r.conn;
        nodes.clear();
        expanded.clear();
        (storage.get(storeKey(), []) || []).forEach((k) => expanded.add(k));
        caps = await getCaps(conn);
        renderFooter();
        await loadDatabases(false);
        for (const k of expanded) {
          if (k.startsWith('db|')) ensureDb(k.slice(3));
          else if (k.startsWith('sc|')) { const [, d, s] = k.split('|'); ensureSchema(d, s); }
        }
      }
      if (r.db) {
        const k = `db|${r.db}`;
        if (!expanded.has(k)) { expanded.add(k); ensureDb(r.db); saveExpanded(); }
        if (caps.schemas && r.schema) {
          const sk = `sc|${r.db}|${r.schema}`;
          if (!expanded.has(sk)) { expanded.add(sk); ensureSchema(r.db, r.schema); saveExpanded(); }
        }
      }
      render();
      requestAnimationFrame(() => tree.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' }));
    },
    focusFilter: () => filterInput.focus(),
    destroy() { offs.forEach((f) => f()); backdrop.remove(); },
  };
}
