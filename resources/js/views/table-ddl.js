/**
 * DDL tab: CREATE statement of a table / view / routine / trigger (table.ddl) with syntax
 * highlighting, copy, download and "open in SQL editor".
 */
import { h, copyText, downloadText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { skeleton, errorState } from '../ui/feedback.js';
import { toast } from '../ui/toast.js';
import { t } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore } from '../store.js';
import { href, navigate } from '../router.js';
import { highlightSql } from '../sql/highlight.js';

export default {
  title: (ctx) => `${ctx.table} · ${t('table.tab.ddl')}`,

  async render(el, ctx) {
    const { conn, db, schema, table } = ctx;
    const box = h('div', { class: 'min-h-0 flex-1 overflow-auto p-3 sm:p-4' }, skeleton({ rows: 10 }));
    el.replaceChildren(box);
    const load = async () => {
      let res;
      try {
        res = await api('table.ddl', { conn, db, schema, table, type: ctx.params.type || undefined });
      } catch (e) {
        box.replaceChildren(errorState(e, { onRetry: load }));
        return;
      }
      const sql = res.sql || '';
      box.replaceChildren(h('div', { class: 'card overflow-hidden' },
        h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border px-3 py-2' },
          h('h2', { class: 'me-auto text-[13px] font-medium text-muted' }, t('ddl.heading')),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: () => copyText(sql).then(() => toast.success(t('common.copied'))) }, icon('copy'), t('common.copy')),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: () => downloadText(`${table}.sql`, `${sql}\n`, 'application/sql') }, icon('file-down'), h('span', { class: 'hidden sm:inline' }, t('ddl.download'))),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { appStore.set({ pendingSql: { sql, db, schema } }); navigate(href.sql(conn, { db, schema })); } }, icon('external-link'), h('span', { class: 'hidden sm:inline' }, t('browse.openInEditor')))),
        h('pre', { class: 'overflow-auto p-4 font-mono text-[12.5px] leading-relaxed', dir: 'ltr', tabindex: '0', 'aria-label': t('ddl.heading') }, highlightSql(sql))));
    };
    await load();
  },
};
