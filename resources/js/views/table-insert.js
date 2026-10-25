/**
 * Insert tab: opens the generated row form (ui/rowform.js) right away; after inserting (or
 * cancelling) the user is taken to the browse tab. "Insert another" keeps the form flow going.
 */
import { h } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openRowForm } from '../ui/rowform.js';
import { emptyState, errorState, skeleton, readOnlyState } from '../ui/feedback.js';
import { toast } from '../ui/toast.js';
import { t } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getTableInfo } from '../data.js';

let alive = false;

export default {
  title: (ctx) => `${ctx.table} · ${t('table.tab.insert')}`,

  async render(el, ctx) {
    alive = true;
    const { conn, db, schema, table } = ctx;
    const base = { conn, db, schema, table };
    if (ctx.readOnly) { el.replaceChildren(h('div', { class: 'flex flex-1 items-center justify-center' }, readOnlyState())); return; }
    el.replaceChildren(skeleton({ rows: 4 }));
    let info;
    try { info = await getTableInfo(conn, db, schema, table); } catch (e) { el.replaceChildren(errorState(e)); return; }

    const open = async () => {
      const res = await openRowForm({
        mode: 'insert', table, columns: info.columns, foreignKeys: info.foreign_keys,
        fkSearch: (column, term) => api('table.fkValues', { ...base, column, term }),
        onSubmit: (values) => api('table.insert', { ...base, values }),
      });
      if (res) toast.success(t('browse.rowInserted'));
      return res;
    };
    el.replaceChildren(h('div', { class: 'flex flex-1 items-center justify-center' }, emptyState({
      icon: 'plus', title: t('insert.title', { table }), message: t('insert.message'),
      actions: [
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: open }, icon('plus'), t('browse.insert')),
        h('a', { href: href.table(conn, db, schema, table, 'browse'), class: 'btn btn-sm' }, icon('table-2'), t('table.tab.browse')),
      ],
    })));
    const res = await open();
    if (res && alive) navigate(href.table(conn, db, schema, table, 'browse'));
  },

  destroy() { alive = false; },
};
