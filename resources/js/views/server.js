/**
 * Server overview: server info cards, quick links, database list with create / drop and sizes.
 */
import { h, debounce, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { formDialog, confirmDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, statCard, refreshButton } from '../ui/feedback.js';
import { bindContextMenu } from '../ui/menu.js';
import { t, fmtNumber, fmtBytes, fmtUptime } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getOverview, getDatabases, invalidate } from '../data.js';

export default {
  title: () => t('nav.server'),

  async render(el, ctx) {
    const { conn, caps } = ctx;
    const ro = !!ctx.readOnly; // read-only connection: no create/drop database, no import
    const body = h('div', { class: 'w-full space-y-6 p-4 sm:p-6' }, skeleton({ rows: 6 }));
    el.replaceChildren(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, body));

    let overview;
    try { overview = await getOverview(conn); } catch (e) { setChildren(body, errorState(e, { onRetry: () => this.render(el, ctx) })); return; }
    const info = overview.info || {};

    const stat = (label, value, ic, mono) => statCard({ label, value, icon: ic, mono });

    const quick = (label, ic, link, show = true) => (show ? h('a', { href: link, class: 'btn btn-sm' }, icon(ic), label) : null);

    // ----- database list
    let dbs = overview.databases || null;
    let filter = '';
    const listBox = h('div', { class: 'card overflow-hidden' });
    const filterIn = h('input', { type: 'search', class: 'input input-sm w-full sm:w-56', placeholder: t('server.filterDbs'), 'aria-label': t('server.filterDbs'), onInput: debounce(() => { filter = filterIn.value.trim().toLowerCase(); renderList(); }, 100) });

    const renderList = () => {
      const rows = (dbs || []).filter((d) => !filter || d.name.toLowerCase().includes(filter));
      if (!rows.length) { listBox.replaceChildren(emptyState({ icon: 'database', title: filter ? t('sidebar.noMatches') : t('sidebar.noDatabases') })); return; }
      const totalSize = rows.reduce((s, d) => s + (Number(d.size) || 0), 0);
      const tbody = h('tbody', {}, rows.map((d) => h('tr', { 'data-db': d.name },
        h('td', {}, h('a', { href: href.db(conn, d.name), class: 'inline-flex max-w-full items-center gap-2 font-medium hover:text-accent' }, icon('database', 'text-muted'), h('span', { class: 'truncate', dir: 'auto' }, d.name))),
        h('td', { class: 'hidden text-muted sm:table-cell' }, h('span', { class: 'font-mono text-xs' }, d.collation || '—')),
        h('td', { class: 'num' }, d.tables === undefined || d.tables === null ? '—' : fmtNumber(d.tables)),
        h('td', { class: 'num' }, fmtBytes(d.size)),
        h('td', { class: 'w-10 text-end' }, caps.create_database !== false && !ro
          ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('server.dropDbNamed', { name: d.name }), title: t('server.dropDb'), onClick: () => dropDb(d.name) }, icon('trash'))
          : null))));
      bindContextMenu(tbody, (e) => {
        const tr = e.target?.closest?.('tr[data-db]');
        if (!tr) return null;
        const name = tr.dataset.db;
        return [
          { label: t('sidebar.menu.open'), icon: 'database', onSelect: () => navigate(href.db(conn, name)) },
          { label: t('sidebar.menu.newQuery'), icon: 'terminal', onSelect: () => navigate(href.sql(conn, { db: name })) },
          { label: t('sidebar.menu.export'), icon: 'download', onSelect: () => navigate(href.export(conn, { db: name })) },
          ...(ro ? [] : [
            { separator: true },
            { label: t('server.dropDb'), icon: 'trash', danger: true, onSelect: () => dropDb(name) },
          ]),
        ];
      });
      listBox.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {},
          h('th', {}, t('server.col.database')),
          h('th', { class: 'hidden sm:table-cell' }, t('server.col.collation')),
          h('th', { class: 'num' }, t('server.col.tables')),
          h('th', { class: 'num' }, t('server.col.size')),
          h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        tbody,
        h('tfoot', {}, h('tr', {},
          h('td', { class: 'border-t border-border font-medium text-muted' }, t('server.totalDbs', { n: fmtNumber(rows.length) })),
          h('td', { class: 'hidden border-t border-border sm:table-cell' }),
          h('td', { class: 'border-t border-border' }),
          h('td', { class: 'num border-t border-border font-medium' }, fmtBytes(totalSize)),
          h('td', { class: 'border-t border-border' }))))));
    };

    async function reloadDbs() {
      try { dbs = await getDatabases(conn, true); renderList(); } catch (e) { listBox.replaceChildren(errorState(e, { onRetry: reloadDbs })); }
    }

    async function createDb() {
      let collations = [];
      if (caps.collations) {
        try { collations = await api('server.collations', { conn }); } catch { collations = []; }
      }
      const res = await formDialog({
        title: t('server.createDb'),
        submitLabel: t('common.create'),
        fields: [
          { name: 'name', label: t('server.dbName'), required: true, mono: true, validate: (v) => (/^\s|\s$/.test(v) ? t('server.dbNameSpaces') : null) },
          ...(collations.length ? [{ name: 'collation', label: t('server.col.collation'), type: 'select', value: '', options: [{ value: '', label: t('server.defaultCollation') }, ...collations.map((c) => ({ value: c.name, label: c.name }))] }] : []),
        ],
        onSubmit: async (v) => { await api('db.create', { conn, name: v.name, collation: v.collation || undefined }); return v; },
      });
      if (!res) return;
      toast.success(t('server.dbCreated', { name: res.name }));
      invalidate(conn);
      await reloadDbs();
    }

    async function dropDb(name) {
      const ok = await confirmDialog({ title: t('server.dropDbTitle', { name }), message: t('server.dropDbMessage'), requireText: name, danger: true, confirmLabel: t('server.dropDb') });
      if (!ok) return;
      try {
        await api('db.drop', { conn, name });
        toast.success(t('server.dbDropped', { name }));
        invalidate(conn);
        await reloadDbs();
      } catch (e) { toastError(e); }
    }

    setChildren(body, 
      h('div', { class: 'flex flex-wrap items-end justify-between gap-3' },
        h('div', { class: 'min-w-0' },
          h('h1', { class: 'flex min-w-0 items-center gap-2 text-xl font-semibold tracking-tight' },
            h('span', { class: 'truncate', dir: 'auto' }, info.name || info.host || t('nav.server')),
            ro || overview.read_only ? h('span', { class: 'badge badge-warning', title: t('readonly.badgeTitle') }, icon('lock'), t('readonly.badge')) : null),
          h('p', { class: 'mt-0.5 truncate text-[13px] text-muted', dir: 'auto' }, [info.version_comment, info.version].filter(Boolean).join(' · '))),
        h('div', { class: 'flex flex-wrap gap-2' },
          quick(t('nav.sql'), 'terminal', href.sql(conn)),
          quick(t('nav.import'), 'upload', href.import(conn), !ro),
          quick(t('nav.export'), 'download', href.export(conn)))),
      h('section', { class: 'grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4', 'aria-label': t('server.info') },
        stat(t('server.version'), info.version, 'server', true),
        stat(t('server.host'), info.host, 'plug', true),
        stat(t('server.user'), info.user, 'users', true),
        stat(t('server.charset'), info.charset, 'hash', true),
        info.uptime !== undefined && info.uptime !== null ? stat(t('server.uptime'), fmtUptime(info.uptime), 'clock') : null,
        stat(t('server.driver'), info.driver, 'database', true)),
      h('section', { class: 'flex flex-wrap gap-2', 'aria-label': t('server.admin') },
        quick(t('nav.variables'), 'sliders', href.serverTab(conn, 'variables'), caps.variables !== false),
        quick(t('nav.status'), 'gauge', href.serverTab(conn, 'status'), caps.status !== false),
        quick(t('monitor.title'), 'chart-line', href.serverTab(conn, 'monitor'), !!caps.monitor),
        quick(t('nav.processes'), 'activity', href.serverTab(conn, 'processes'), !!caps.processes),
        quick(t('nav.users'), 'users', href.serverTab(conn, 'users'), !!caps.users)),
      h('section', { class: 'space-y-3', 'aria-labelledby': 'srv-dbs' },
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          h('h2', { id: 'srv-dbs', class: 'me-auto text-[15px] font-semibold' }, t('server.databases')),
          filterIn,
          caps.create_database !== false && !ro ? h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: createDb }, icon('plus'), t('server.createDb')) : null,
          refreshButton(() => { invalidate(conn); reloadDbs(); })),
        listBox),
    );
    if (dbs) renderList(); else reloadDbs();
  },
};
