/**
 * Database settings / operations (#/c/{conn}/db/{db}/settings): overview of the database-level
 * settings (db.info) and, per driver capability, changes through db.alter / db.copy with SQL
 * review: MySQL default charset + collation (optionally converting tables), PostgreSQL owner /
 * comment / connection limit, SQLite PRAGMAs, SQL Server collation, rename / copy / move and
 * the danger zone (drop database). Read-only connections only see the overview.
 */
import { h, cls, uid } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmDialog, openModal } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, callout, refreshButton } from '../ui/feedback.js';
import { t, plural, fmtNumber, fmtBytes } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { invalidate } from '../data.js';
import { reviewSqlDialog } from './_table-editor.js';

const WARN_KIND = { convert_rewrites_tables: 'warning', move_drops_source: 'danger', pg_rename_exclusive: 'info', pg_template_exclusive: 'info', sqlsrv_exclusive: 'warning', sqlite_vacuum_rewrites: 'warning', sqlite_auto_vacuum_needs_vacuum: 'warning' };
const warnNotes = (codes) => (codes || []).map((c) => callout(WARN_KIND[c] || 'info', t(`dbOps.warn.${c}`)));

export default {
  title: (ctx) => t('dbOps.title', { db: ctx.db }),

  async render(el, ctx) {
    const { conn, db, schema, caps } = ctx;
    const ro = !!ctx.readOnly;
    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' }, skeleton({ rows: 8 }));
    el.replaceChildren(scroller);
    const rerender = () => this.render(el, ctx);

    let info;
    let charsets = [];
    let collations = [];
    try {
      info = await api('db.info', { conn, db });
      if (!ro && caps.db_alter && (info.driver === 'mysql' || info.driver === 'sqlsrv')) {
        [charsets, collations] = await Promise.all([
          info.driver === 'mysql' ? api('server.charsets', { conn }).catch(() => []) : [],
          api('server.collations', { conn }).catch(() => []),
        ]);
      }
    } catch (e) {
      scroller.replaceChildren(errorState(e, { onRetry: rerender }));
      return;
    }
    const S = info.settings || {};
    const drv = info.driver;

    // ---------------------------------------------------------------- helpers
    const card = (id, title, desc, ...body) => h('section', { class: 'card space-y-3 p-4', 'aria-labelledby': id },
      h('div', {}, h('h2', { id, class: 'text-[14px] font-semibold' }, title), desc ? h('p', { class: 'mt-0.5 text-[13px] text-muted' }, desc) : null),
      ...body);
    const fieldBox = (id, label, control, help) => {
      const helpId = help ? `${id}-help` : null;
      if (helpId) control.setAttribute('aria-describedby', helpId);
      return h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: id }, label), control, help ? h('p', { id: helpId, class: 'help' }, help) : null);
    };
    const busy = async (btn, fn) => { btn.disabled = true; try { await fn(); } finally { if (btn.isConnected) btn.disabled = false; } };
    /** Inline field error wired through aria-invalid + aria-describedby. */
    const fieldError = (input) => {
      const id = uid('err');
      const box = h('p', { id, class: 'mt-1 text-[12.5px] text-danger', hidden: true, role: 'alert' });
      const base = () => (input.getAttribute('aria-describedby') || '').split(' ').filter((x) => x && x !== id);
      return {
        el: box,
        set(msg) {
          box.textContent = msg || '';
          box.hidden = !msg;
          if (msg) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', [...base(), id].join(' ')); input.focus(); }
          else { input.removeAttribute('aria-invalid'); const b = base(); if (b.length) input.setAttribute('aria-describedby', b.join(' ')); else input.removeAttribute('aria-describedby'); }
        },
      };
    };
    const nameInput = (id, value) => h('input', { id, class: 'input font-mono', value, dir: 'auto', autocomplete: 'off', spellcheck: 'false' });
    const afterChange = (renamedTo) => {
      invalidate(conn, db);
      if (renamedTo) invalidate(conn);
    };

    /** db.alter preview → review dialog → execute. Returns true when executed. */
    async function alter(changes, title) {
      const prev = await api('db.alter', { conn, db, changes, preview: true });
      if (!prev.sql?.length) { toast.info(t('dbOps.nothingToChange')); return false; }
      const res = await reviewSqlDialog({
        title, sql: prev.sql, notes: warnNotes(prev.warnings),
        execute: () => api('db.alter', { conn, db, changes }),
      });
      return !!res;
    }

    /** db.copy preview → review → execute; shows the report. Returns the result or null. */
    async function copy(target, dropSource) {
      const prev = await api('db.copy', { conn, db, target, drop_source: dropSource, preview: true });
      const notes = warnNotes(prev.warnings);
      if (prev.skipped?.length) notes.push(callout('warning', t('dbOps.copy.willSkip', { list: prev.skipped.map((s) => `${s.type} ${s.name}`).join(', ') })));
      return reviewSqlDialog({
        title: dropSource ? t('dbOps.move.reviewTitle', { db, target }) : t('dbOps.copy.reviewTitle', { db, target }),
        sql: prev.sql, notes, danger: dropSource,
        confirmLabel: dropSource ? t('dbOps.move.submit') : t('dbOps.copy.submit'),
        execute: () => api('db.copy', { conn, db, target, drop_source: dropSource }),
      });
    }

    function showSkipped(res, kept) {
      if (!res?.skipped?.length) return;
      const list = h('ul', { class: 'divide-y divide-border rounded-lg border border-border text-[13px]' },
        res.skipped.map((s) => h('li', { class: 'px-3 py-2' },
          h('div', { class: 'font-medium' }, h('span', { class: 'badge me-2' }, s.type), h('span', { dir: 'auto' }, s.name)),
          h('div', { class: 'mt-0.5 break-words text-xs text-muted', dir: 'auto' }, s.error))));
      openModal({
        title: t('dbOps.copy.skippedTitle'),
        description: kept ? t('dbOps.move.keptSource', { db }) : plural('dbOps.copy.skippedCount', res.skipped.length),
        size: 'lg', body: list,
        actions: [h('button', { type: 'button', class: 'btn btn-primary', onClick: (e) => e.target.closest('dialog')?.close() }, t('common.close'))],
      });
    }

    const sections = [];

    // ---------------------------------------------------------------- overview
    {
      const rows = [];
      const add = (label, value, o = {}) => { if (value !== undefined) rows.push([label, value, o]); };
      const yesNo = (b) => (b ? t('dbOps.yes') : t('dbOps.no'));
      if (drv === 'mysql') {
        add(t('dbOps.info.charset'), S.charset, { mono: true });
        add(t('dbOps.info.collation'), S.collation, { mono: true });
        add(t('dbOps.info.tables'), fmtNumber(info.table_count));
        add(t('dbOps.info.views'), fmtNumber(info.view_count));
        add(t('dbOps.info.size'), fmtBytes(info.size || 0));
        add(t('dbOps.info.objects'), t('dbOps.info.objectsValue', { routines: fmtNumber(info.objects?.routines || 0), triggers: fmtNumber(info.objects?.triggers || 0), events: fmtNumber(info.objects?.events || 0) }));
        add(t('dbOps.info.differing'), info.differing_tables ? t('dbOps.info.differingValue', { tables: fmtNumber(info.differing_tables), columns: fmtNumber(info.differing_columns) }) : t('dbOps.info.differingNone'), { warn: !!info.differing_tables, wide: true });
      } else if (drv === 'pgsql') {
        add(t('dbOps.info.owner'), S.owner, { mono: true });
        add(t('dbOps.info.encoding'), S.encoding, { mono: true });
        add(t('dbOps.info.lcCollate'), S.lc_collate, { mono: true });
        add(t('dbOps.info.lcCtype'), S.lc_ctype, { mono: true });
        add(t('dbOps.info.connLimit'), S.connection_limit < 0 ? t('dbOps.info.unlimited') : fmtNumber(S.connection_limit));
        add(t('dbOps.info.tablespace'), S.tablespace, { mono: true });
        add(t('dbOps.info.size'), info.size === null ? '—' : fmtBytes(info.size));
        if (info.table_count !== null) add(t('dbOps.info.tables'), fmtNumber(info.table_count));
        if (info.other_connections !== null) add(t('dbOps.info.otherConnections'), fmtNumber(info.other_connections));
        add(t('dbOps.info.comment'), S.comment || '—', { wide: true });
      } else if (drv === 'sqlite') {
        add(t('dbOps.info.file'), info.file, { mono: true, wrap: true });
        add(t('dbOps.info.size'), info.size === null ? '—' : fmtBytes(info.size));
        add(t('dbOps.info.tables'), fmtNumber(info.table_count));
        add(t('dbOps.info.views'), fmtNumber(info.view_count));
        add(t('dbOps.info.pageSize'), `${fmtNumber(S.page_size)} B × ${fmtNumber(S.page_count)}`);
        add(t('dbOps.info.freelist'), fmtNumber(S.freelist_count));
        add(t('dbOps.info.journalMode'), S.journal_mode, { mono: true });
        add(t('dbOps.info.autoVacuum'), S.auto_vacuum, { mono: true });
        add(t('dbOps.info.foreignKeys'), yesNo(S.foreign_keys));
        add(t('dbOps.info.userVersion'), String(S.user_version));
        add(t('dbOps.info.encoding'), S.encoding, { mono: true });
        add(t('dbOps.info.sqliteVersion'), info.sqlite_version, { mono: true });
      } else if (drv === 'sqlsrv') {
        add(t('dbOps.info.collation'), S.collation, { mono: true });
        add(t('dbOps.info.recovery'), S.recovery_model, { mono: true });
        add(t('dbOps.info.compat'), S.compatibility_level === null ? '—' : String(S.compatibility_level));
        add(t('dbOps.info.state'), S.state, { mono: true });
        add(t('dbOps.info.userAccess'), S.user_access, { mono: true });
        add(t('dbOps.info.readOnly'), yesNo(S.read_only));
        add(t('dbOps.info.created'), S.created || '—', { mono: true });
        add(t('dbOps.info.size'), info.size === null ? '—' : fmtBytes(info.size));
        if (info.other_connections !== null) add(t('dbOps.info.otherConnections'), fmtNumber(info.other_connections));
      }
      sections.push(card('dbs-h-overview', t('dbOps.overview.title'), null,
        h('dl', { class: 'grid grid-cols-2 gap-x-6 gap-y-2.5 text-[13px] lg:grid-cols-3' }, rows.map(([label, value, o]) => h('div', { class: cls('min-w-0', (o.wrap || o.wide) && 'col-span-2 lg:col-span-3') },
          h('dt', { class: 'text-xs text-muted' }, label),
          h('dd', { class: cls('mt-0.5', o.mono && 'font-mono text-[12.5px]', o.wrap ? 'break-all' : 'truncate', o.warn && 'font-medium'), dir: o.mono ? 'ltr' : 'auto', title: String(value ?? '') }, value === null || value === '' ? '—' : String(value)))))));
    }

    if (ro) {
      sections.push(callout('info', h('span', { class: 'inline-flex items-center gap-1.5' }, icon('lock', 'size-3.5'), t('readonly.badgeTitle'))));
    }

    // ---------------------------------------------------------------- MySQL charset & collation
    if (!ro && caps.db_alter && drv === 'mysql' && !info.system) {
      const csSel = h('select', { id: 'dbs-charset', class: 'select' }, charsets.map((c) => h('option', { value: c.name, selected: c.name === S.charset }, c.description ? `${c.name} — ${c.description}` : c.name)));
      if (!charsets.some((c) => c.name === S.charset)) csSel.prepend(h('option', { value: S.charset, selected: true }, S.charset));
      const collSel = h('select', { id: 'dbs-collation', class: 'select font-mono' });
      const fillCollations = (keep) => {
        const cs = csSel.value;
        const list = collations.filter((c) => c.charset === cs).map((c) => c.name);
        const def = charsets.find((c) => c.name === cs)?.default_collation;
        const pick = keep && list.includes(keep) ? keep : (cs === S.charset && list.includes(S.collation) ? S.collation : def);
        collSel.replaceChildren(...[...new Set(list.length ? list : [S.collation])].map((n) => h('option', { value: n, selected: n === pick }, n === def ? `${n} (${t('dbOps.charset.default')})` : n)));
      };
      csSel.addEventListener('change', () => fillCollations(null));
      fillCollations(S.collation);

      const baseTables = (info.tables || []).filter((x) => x.type === 'table');
      const picked = new Set(baseTables.map((x) => x.name));
      const convertCb = h('input', { id: 'dbs-convert', type: 'checkbox', class: 'checkbox', 'aria-describedby': 'dbs-convert-help', 'aria-controls': 'dbs-convert-list' });
      const listBox = h('div', { id: 'dbs-convert-list', class: 'space-y-2', hidden: true });
      const countLabel = h('span', { class: 'text-xs text-muted', 'aria-live': 'polite' });
      const drawList = () => {
        countLabel.textContent = t('dbOps.charset.selectedCount', { n: fmtNumber(picked.size), total: fmtNumber(baseTables.length) });
        listBox.replaceChildren(
          h('div', { class: 'flex flex-wrap items-center gap-2' },
            h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { baseTables.forEach((x) => picked.add(x.name)); drawList(); } }, t('dbOps.charset.selectAll')),
            h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { picked.clear(); baseTables.filter((x) => x.differs).forEach((x) => picked.add(x.name)); drawList(); } }, t('dbOps.charset.selectDiffering')),
            h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { picked.clear(); drawList(); } }, t('dbOps.charset.selectNone')),
            h('span', { class: 'flex-1' }), countLabel),
          baseTables.length ? h('ul', { class: 'max-h-72 divide-y divide-border overflow-auto rounded-lg border border-border', 'aria-label': t('dbOps.charset.tablesLabel') }, baseTables.map((x) => {
            const id = uid('cvt');
            return h('li', { class: 'flex min-h-10 items-center gap-3 px-3 py-1.5 text-[13px]' },
              h('input', { id, type: 'checkbox', class: 'checkbox', checked: picked.has(x.name), onChange: (e) => { if (e.target.checked) picked.add(x.name); else picked.delete(x.name); countLabel.textContent = t('dbOps.charset.selectedCount', { n: fmtNumber(picked.size), total: fmtNumber(baseTables.length) }); } }),
              h('label', { for: id, class: 'min-w-0 flex-1 truncate font-medium', dir: 'auto' }, x.name),
              x.columns_differing ? h('span', { class: 'hidden text-xs text-muted sm:inline' }, plural('dbOps.charset.columnsDiffering', x.columns_differing)) : null,
              h('span', { class: cls('badge font-mono', x.differs && 'badge-warning'), title: x.differs ? t('dbOps.charset.differsHint') : null }, x.differs ? icon('warning') : null, x.collation || '—', x.differs ? h('span', { class: 'sr-only' }, ` (${t('dbOps.charset.differsHint')})`) : null));
          })) : h('p', { class: 'text-[13px] text-muted' }, t('dbOps.charset.noTables')));
      };
      convertCb.addEventListener('change', () => { listBox.hidden = !convertCb.checked; if (convertCb.checked) drawList(); });

      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('save'), t('dbOps.charset.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        const changes = { charset: csSel.value, collation: collSel.value };
        if (convertCb.checked) {
          if (!picked.size) { toast.warning(t('dbOps.charset.noneSelected')); return; }
          changes.convert_tables = true;
          if (picked.size !== baseTables.length) changes.tables = [...picked];
        }
        try {
          if (await alter(changes, t('dbOps.charset.reviewTitle', { db }))) {
            toast.success(t('dbOps.saved'));
            afterChange();
            rerender();
          }
        } catch (e) { toastError(e); }
      }));
      sections.push(card('dbs-h-charset', t('dbOps.charset.title'), t('dbOps.charset.desc'),
        h('div', { class: 'grid gap-3 sm:grid-cols-2' }, fieldBox('dbs-charset', t('dbOps.info.charset'), csSel), fieldBox('dbs-collation', t('dbOps.info.collation'), collSel)),
        h('div', {},
          h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px] font-medium', for: 'dbs-convert' }, convertCb, t('dbOps.charset.convert')),
          h('p', { id: 'dbs-convert-help', class: 'help ms-6' }, t('dbOps.charset.convertHelp'))),
        listBox,
        h('div', { class: 'flex justify-end' }, b)));
    }

    // ---------------------------------------------------------------- PostgreSQL owner / comment / limit
    if (!ro && caps.db_alter && drv === 'pgsql') {
      const roles = [...new Set([S.owner, ...(info.roles || [])].filter(Boolean))];
      const ownerSel = h('select', { id: 'dbs-owner', class: 'select font-mono' }, roles.map((r) => h('option', { value: r, selected: r === S.owner }, r)));
      const comment = h('textarea', { id: 'dbs-comment', class: 'textarea', rows: 2, dir: 'auto' });
      comment.value = S.comment || '';
      const limit = h('input', { id: 'dbs-limit', type: 'number', class: 'input', min: '-1', step: '1', value: String(S.connection_limit ?? -1), inputmode: 'numeric' });
      const limitErr = fieldError(limit);
      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('save'), t('dbOps.pg.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        limitErr.set(null);
        const changes = {};
        if (ownerSel.value !== S.owner) changes.owner = ownerSel.value;
        if (comment.value !== (S.comment || '')) changes.comment = comment.value;
        const lv = limit.value.trim();
        if (!/^-?\d+$/.test(lv) || Number(lv) < -1) { limitErr.set(t('dbOps.pg.limitInvalid')); return; }
        if (Number(lv) !== S.connection_limit) changes.connection_limit = Number(lv);
        if (!Object.keys(changes).length) { toast.info(t('dbOps.nothingToChange')); return; }
        try {
          if (await alter(changes, t('dbOps.pg.reviewTitle', { db }))) { toast.success(t('dbOps.saved')); afterChange(); rerender(); }
        } catch (e) { toastError(e); }
      }));
      sections.push(card('dbs-h-pg', t('dbOps.pg.title'), t('dbOps.pg.desc'),
        h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          fieldBox('dbs-owner', t('dbOps.info.owner'), ownerSel),
          h('div', { class: 'min-w-0' }, fieldBox('dbs-limit', t('dbOps.info.connLimit'), limit, t('dbOps.pg.limitHelp')), limitErr.el)),
        fieldBox('dbs-comment', t('dbOps.info.comment'), comment),
        h('p', { class: 'help' }, t('dbOps.pg.localeNote')),
        h('div', { class: 'flex justify-end' }, b)));
    }

    // ---------------------------------------------------------------- SQLite pragmas
    if (!ro && caps.db_alter && drv === 'sqlite') {
      const opt = (values, cur) => values.map((v) => h('option', { value: v, selected: v === cur }, v.toUpperCase()));
      const jm = h('select', { id: 'dbs-journal', class: 'select font-mono' }, opt(['delete', 'truncate', 'persist', 'wal'], S.journal_mode));
      if (!['delete', 'truncate', 'persist', 'wal'].includes(S.journal_mode)) jm.prepend(h('option', { value: S.journal_mode, selected: true }, String(S.journal_mode).toUpperCase()));
      const av = h('select', { id: 'dbs-autovac', class: 'select font-mono' }, opt(['none', 'full', 'incremental'], S.auto_vacuum));
      const uv = h('input', { id: 'dbs-userver', type: 'number', class: 'input', step: '1', value: String(S.user_version ?? 0), inputmode: 'numeric' });
      const uvErr = fieldError(uv);
      const vac = h('input', { id: 'dbs-vacuum', type: 'checkbox', class: 'checkbox', 'aria-describedby': 'dbs-vacuum-help' });
      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('save'), t('dbOps.sqlite.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        uvErr.set(null);
        const pragmas = {};
        if (jm.value !== S.journal_mode) pragmas.journal_mode = jm.value;
        if (av.value !== S.auto_vacuum) pragmas.auto_vacuum = av.value;
        const u = uv.value.trim();
        if (!/^-?\d+$/.test(u) || Math.abs(Number(u)) > 2147483647) { uvErr.set(t('dbOps.sqlite.userVersionInvalid')); return; }
        if (Number(u) !== S.user_version) pragmas.user_version = Number(u);
        const changes = { pragmas };
        if (vac.checked) changes.vacuum = true;
        if (!Object.keys(pragmas).length && !vac.checked) { toast.info(t('dbOps.nothingToChange')); return; }
        try {
          if (await alter(changes, t('dbOps.sqlite.reviewTitle', { db }))) { toast.success(t('dbOps.saved')); afterChange(); rerender(); }
        } catch (e) { toastError(e); }
      }));
      sections.push(card('dbs-h-sqlite', t('dbOps.sqlite.title'), t('dbOps.sqlite.desc'),
        h('div', { class: 'grid gap-3 sm:grid-cols-3' },
          fieldBox('dbs-journal', t('dbOps.info.journalMode'), jm, t('dbOps.sqlite.journalHelp')),
          fieldBox('dbs-autovac', t('dbOps.info.autoVacuum'), av, t('dbOps.sqlite.autoVacuumHelp')),
          h('div', { class: 'min-w-0' }, fieldBox('dbs-userver', t('dbOps.info.userVersion'), uv, t('dbOps.sqlite.userVersionHelp')), uvErr.el)),
        h('div', {},
          h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px] font-medium', for: 'dbs-vacuum' }, vac, t('dbOps.sqlite.vacuum')),
          h('p', { id: 'dbs-vacuum-help', class: 'help ms-6' }, t('dbOps.sqlite.vacuumHelp'))),
        h('p', { class: 'help' }, t('dbOps.sqlite.foreignKeysNote')),
        h('div', { class: 'flex justify-end' }, b)));
    }

    // ---------------------------------------------------------------- SQL Server collation
    if (!ro && caps.db_alter && drv === 'sqlsrv') {
      const names = [...new Set([S.collation, ...collations.map((c) => c.name)].filter(Boolean))];
      const sel = h('select', { id: 'dbs-mscoll', class: 'select font-mono' }, names.map((n) => h('option', { value: n, selected: n === S.collation }, n)));
      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('save'), t('dbOps.charset.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        if (sel.value === S.collation) { toast.info(t('dbOps.nothingToChange')); return; }
        try {
          if (await alter({ collation: sel.value }, t('dbOps.charset.reviewTitle', { db }))) { toast.success(t('dbOps.saved')); afterChange(); rerender(); }
        } catch (e) { toastError(e); }
      }));
      sections.push(card('dbs-h-mscoll', t('dbOps.mssql.title'), t('dbOps.mssql.desc'),
        fieldBox('dbs-mscoll', t('dbOps.info.collation'), sel, t('dbOps.mssql.recoveryNote', { model: S.recovery_model || '—' })),
        h('div', { class: 'flex justify-end' }, b)));
    }

    // ---------------------------------------------------------------- rename / copy
    if (!ro && (caps.db_rename || caps.db_copy) && !info.system) {
      const parts = [];
      const loginNote = info.login_database ? callout('info', t('dbOps.rename.loginDb', { db })) : null;

      if (caps.db_copy) {
        const inp = nameInput('dbs-copy-name', `${db}_copy`);
        const err = fieldError(inp);
        const b = h('button', { type: 'button', class: 'btn' }, icon('copy'), t('dbOps.copy.submit'));
        b.addEventListener('click', () => busy(b, async () => {
          err.set(null);
          const target = inp.value.trim();
          if (!target || target === db) { err.set(t('dbOps.nameInvalid')); return; }
          try {
            const res = await copy(target, false);
            if (!res) return;
            afterChange(target);
            toast.success(t('dbOps.copy.done', { target }), { action: { label: t('dbOps.open'), onClick: () => navigate(href.db(conn, target)) } });
            showSkipped(res, false);
          } catch (e) { toastError(e); }
        }));
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') b.click(); });
        parts.push(h('div', { class: 'space-y-2' },
          h('h3', { class: 'text-[13.5px] font-medium' }, t('dbOps.copy.title')),
          h('p', { class: 'text-[12.5px] text-muted' }, t(`dbOps.copy.desc.${drv}`)),
          h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start' },
            h('div', { class: 'min-w-0 flex-1' }, h('label', { class: 'sr-only', for: 'dbs-copy-name' }, t('dbOps.copy.newName')), inp, err.el), b)));
      }

      if (caps.db_rename) {
        const inp = nameInput('dbs-rename-name', db);
        const err = fieldError(inp);
        const isMove = drv === 'mysql';
        const b = h('button', { type: 'button', class: cls('btn', isMove ? 'btn-danger' : 'btn-primary'), disabled: !!info.login_database }, icon('pencil'), t('dbOps.rename.submit'));
        b.addEventListener('click', () => busy(b, async () => {
          err.set(null);
          const target = inp.value.trim();
          if (!target || target === db) { err.set(t('dbOps.nameInvalid')); return; }
          try {
            if (isMove) {
              const ok = await confirmDialog({ title: t('dbOps.move.confirmTitle', { db, target }), message: t('dbOps.move.confirmMessage'), requireText: db, danger: true, confirmLabel: t('dbOps.move.continue') });
              if (!ok) return;
              const res = await copy(target, true);
              if (!res) return;
              afterChange(target);
              if (res.dropped) {
                toast.success(t('dbOps.rename.done', { db, target }));
                navigate(href.dbSettings(conn, target));
              } else {
                toast.warning(t('dbOps.move.keptSource', { db }));
                showSkipped(res, true);
              }
              return;
            }
            if (await alter({ rename: target }, t('dbOps.rename.reviewTitle', { db, target }))) {
              afterChange(target);
              toast.success(t('dbOps.rename.done', { db, target }));
              navigate(href.dbSettings(conn, target, schema));
            }
          } catch (e) { toastError(e); }
        }));
        inp.disabled = !!info.login_database;
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') b.click(); });
        parts.push(h('div', { class: 'space-y-2' },
          h('h3', { class: 'text-[13.5px] font-medium' }, t('dbOps.rename.title')),
          h('p', { class: 'text-[12.5px] text-muted' }, t(`dbOps.rename.desc.${drv}`)),
          isMove ? callout('warning', t('dbOps.rename.mysqlWarning')) : null,
          h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start' },
            h('div', { class: 'min-w-0 flex-1' }, h('label', { class: 'sr-only', for: 'dbs-rename-name' }, t('dbOps.rename.newName')), inp, err.el), b)));
      }
      sections.push(card('dbs-h-rename', t('dbOps.renameCopy.title'), null, loginNote, h('div', { class: 'space-y-5' }, parts)));
    }

    // ---------------------------------------------------------------- danger zone
    if (!ro && caps.create_database && !info.system) {
      const dropBtn = h('button', { type: 'button', class: 'btn btn-danger shrink-0' }, icon('trash'), t('server.dropDb'));
      dropBtn.addEventListener('click', () => busy(dropBtn, async () => {
        const ok = await confirmDialog({ title: t('server.dropDbTitle', { name: db }), message: t('server.dropDbMessage'), requireText: db, danger: true, confirmLabel: t('server.dropDb') });
        if (!ok) return;
        try {
          await api('db.drop', { conn, name: db });
          invalidate(conn);
          toast.success(t('server.dbDropped', { name: db }));
          navigate(href.server(conn));
        } catch (e) { toastError(e); }
      }));
      sections.push(h('section', { class: 'card space-y-3 border-danger/40 p-4', 'aria-labelledby': 'dbs-h-danger' },
        h('h2', { id: 'dbs-h-danger', class: 'flex items-center gap-2 text-[14px] font-semibold text-danger' }, icon('warning'), t('dbOps.danger.title')),
        h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-center' },
          h('div', { class: 'min-w-0 flex-1' }, h('div', { class: 'text-[13.5px] font-medium' }, t('server.dropDb')), h('p', { class: 'text-[12.5px] text-muted' }, info.login_database ? t('dbOps.danger.dropLoginDb') : t('dbOps.danger.dropDesc'))),
          dropBtn)));
    }

    scroller.replaceChildren(h('div', { class: 'w-full max-w-5xl space-y-4 p-3 sm:p-4' },
      h('div', { class: 'flex flex-wrap items-center gap-3' },
        h('a', { href: href.db(conn, db, schema), class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('dbOps.backToDb', { db }), title: t('dbOps.backToDb', { db }) }, icon('arrow-left', 'rtl:-scale-x-100')),
        h('h1', { class: 'flex min-w-0 flex-1 items-center gap-2 text-lg font-semibold tracking-tight' }, icon('settings', 'size-5 text-muted'),
          h('span', { class: 'truncate' }, t('dbOps.heading')), h('span', { class: 'truncate font-mono text-[15px] font-medium text-muted', dir: 'auto' }, db)),
        refreshButton(rerender)),
      sections));
  },
};
