/**
 * Table shell: header with the object name and tab strip (browse, structure, sql, search,
 * insert, ddl, ops); lazily loads the sub-view module for the active tab. Routines and
 * triggers (?type=routine|procedure|function|trigger) only get the DDL tab.
 */
import { h } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { createTabs } from '../ui/tabs.js';
import { t } from '../i18n/index.js';
import { href } from '../router.js';
import { getObjects } from '../data.js';

const TABS = {
  browse: { icon: 'table-2', load: () => import('./table-browse.js') },
  structure: { icon: 'columns', load: () => import('./table-structure.js') },
  sql: { icon: 'terminal', load: () => import('./sql.js') },
  search: { icon: 'text-search', load: () => import('./table-search.js') },
  insert: { icon: 'plus', load: () => import('./table-insert.js') },
  ddl: { icon: 'file-code', load: () => import('./table-ddl.js') },
  ops: { icon: 'wrench', load: () => import('./table-ops.js') },
};
const NON_TABLE = new Set(['routine', 'procedure', 'function', 'trigger', 'event']);

let sub = null;
let renders = 0;

export default {
  title: (ctx) => `${ctx.table} · ${t(`table.tab.${TABS[ctx.tab] ? ctx.tab : 'browse'}`)}`,

  async render(el, ctx) {
    const objType = ctx.params.type;
    const isRoutine = objType && NON_TABLE.has(objType);
    let tab = TABS[ctx.tab] ? ctx.tab : 'browse';
    if (isRoutine) tab = 'ddl';

    const typeBadge = h('span', { class: 'badge', hidden: true });
    let ids = isRoutine ? ['ddl'] : Object.keys(TABS).filter((id) => !(ctx.readOnly && id === 'insert'));
    const tabs = createTabs({
      ariaLabel: t('table.tabs'),
      active: tab,
      className: 'px-2 sm:px-3',
      items: ids.map((id) => ({ id, label: t(`table.tab.${id}`), icon: TABS[id].icon, href: href.table(ctx.conn, ctx.db, ctx.schema, ctx.table, id, isRoutine ? { type: objType } : undefined) })),
    });
    const body = tabs.panel(h('div', { class: 'flex min-h-0 flex-1 flex-col' }));
    el.replaceChildren(
      h('div', { class: 'shrink-0 bg-surface' },
        h('div', { class: 'flex min-w-0 items-center gap-2 px-3 pt-3 sm:px-4' },
          icon(isRoutine ? (objType === 'trigger' ? 'zap' : 'function') : 'table', 'text-muted'),
          h('h1', { class: 'min-w-0 truncate text-[15px] font-semibold', dir: 'auto' }, ctx.table),
          isRoutine ? h('span', { class: 'badge' }, objType) : typeBadge),
        tabs.el),
      body);

    // mark views (fewer tabs) once the object list is known — cached, usually instant
    if (!isRoutine) {
      getObjects(ctx.conn, ctx.db, ctx.schema).then((o) => {
        const isView = (o.views || []).some((v) => v.name === ctx.table) || (o.tables || []).some((x) => x.name === ctx.table && x.type === 'view');
        if (!isView) return;
        typeBadge.textContent = t('db.view');
        typeBadge.hidden = false;
        ids = ids.filter((id) => id !== 'insert' && id !== 'ops');
        tabs.setItems(ids.map((id) => ({ id, label: t(`table.tab.${id}`), icon: TABS[id].icon, href: href.table(ctx.conn, ctx.db, ctx.schema, ctx.table, id) })), tab);
      }).catch(() => {});
    }

    const token = ++renders;
    const mod = await TABS[tab].load();
    if (token !== renders) return; // navigated away while the tab module was loading
    sub = mod.default;
    await sub.render(body, { ...ctx, tab, embedded: true });
  },

  destroy() {
    renders++;
    try { sub?.destroy?.(); } finally { sub = null; }
  },
};
