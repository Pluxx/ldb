/**
 * Table operations: rename, copy (structure / structure + data, optional target database or
 * schema), move (copy + drop), maintenance (optimize / analyze / check / repair / vacuum per
 * driver, results in a grid), comment / engine / collation (table.alter options with SQL
 * review) and the danger zone (empty / truncate / drop with typed confirmation).
 */
import { h } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, callout } from '../ui/feedback.js';
import { createDataGrid } from '../ui/datagrid.js';
import { t, plural } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getTableInfo, getDatabases, getSchemas, invalidate } from '../data.js';
import { loadMeta, previewAndAlter } from './_table-editor.js';

const MAINTENANCE = {
  mysql: ['optimize', 'analyze', 'check', 'repair'],
  pgsql: ['vacuum', 'analyze', 'optimize'],
  sqlite: ['analyze', 'check', 'optimize', 'vacuum'],
  sqlsrv: ['analyze', 'optimize', 'check'],
};

let grid = null;

export default {
  title: (ctx) => t('table.title.ops', { table: ctx.table }),

  async render(el, ctx) {
    const { conn, db, schema, table, caps } = ctx;
    const base = { conn, db, schema, table };
    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' }, skeleton({ rows: 8 }));
    el.replaceChildren(scroller);
    let meta;
    let info;
    try {
      [meta, info] = await Promise.all([loadMeta(conn, caps), getTableInfo(conn, db, schema, table, true)]);
    } catch (e) {
      scroller.replaceChildren(errorState(e, { onRetry: () => this.render(el, ctx) }));
      return;
    }
    const P = meta.prof;
    const o = info.options || {};

    // copy/move targets: other databases (MySQL) or schemas of this database (PostgreSQL, SQL Server)
    let targets = [];
    let targetKind = null;
    try {
      if (caps.schemas) { targets = await getSchemas(conn, db); targetKind = 'schema'; }
      else if (P.driver === 'mysql') { targets = (await getDatabases(conn)).map((d) => d.name).filter((n) => !/^(information_schema|performance_schema|mysql|sys)$/i.test(n)); targetKind = 'db'; }
    } catch { targets = []; }
    const current = targetKind === 'schema' ? (schema || '') : db;

    const card = (id, title, desc, ...body) => h('section', { class: 'card space-y-3 p-4', 'aria-labelledby': id },
      h('div', {}, h('h2', { id, class: 'text-[14px] font-semibold' }, title), desc ? h('p', { class: 'mt-0.5 text-[13px] text-muted' }, desc) : null),
      ...body);
    const fieldBox = (id, label, control, help) => h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: id }, label), control, help ? h('p', { class: 'help' }, help) : null);
    const nameInput = (id, value) => h('input', { id, class: 'input font-mono', value, dir: 'auto', autocomplete: 'off', spellcheck: 'false' });
    const busy = async (btn, fn) => { btn.disabled = true; try { await fn(); } finally { if (btn.isConnected) btn.disabled = false; } };
    const targetSelect = (id) => h('select', { id, class: 'select' }, [...new Set([current, ...targets])].filter((x) => x !== undefined).map((n) => h('option', { value: n, selected: n === current }, n || t('ops.defaultSchema'))));
    const routeFor = (target, name, tab) => (targetKind === 'schema' ? href.table(conn, db, target || undefined, name, tab) : href.table(conn, target || db, schema, name, tab));

    const sections = [];

    // ----- rename
    if (caps.rename_table !== false) {
      const inp = nameInput('ops-rename', table);
      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('pencil'), t('ops.rename.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        const nn = inp.value.trim();
        if (!nn || nn === table) { inp.focus(); return; }
        try {
          await api('table.rename', { ...base, new_name: nn });
          toast.success(t('ops.rename.done', { name: nn }));
          invalidate(conn, db);
          navigate(href.table(conn, db, schema, nn, 'ops'));
        } catch (e) { toastError(e); }
      }));
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') b.click(); });
      sections.push(card('ops-h-rename', t('ops.rename.title'), null,
        h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-end' }, h('div', { class: 'flex-1' }, fieldBox('ops-rename', t('ops.rename.newName'), inp)), b)));
    }

    // ----- copy / move
    if (caps.copy_table !== false) {
      const inp = nameInput('ops-copy-name', `${table}_copy`);
      const tgt = targets.length ? targetSelect('ops-copy-target') : null;
      const modeName = `ops-copy-mode-${Date.now()}`;
      const radio = (value, label, checked) => h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, h('input', { type: 'radio', class: 'checkbox', name: modeName, value, checked }), label);
      const modes = h('div', { class: 'flex flex-wrap gap-x-5', role: 'radiogroup', 'aria-label': t('ops.copy.mode') }, radio('structure', t('ops.copy.structure'), false), radio('data', t('ops.copy.structureData'), true));
      const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('copy'), t('ops.copy.submit'));
      b.addEventListener('click', () => busy(b, async () => {
        const nn = inp.value.trim();
        const target = tgt ? tgt.value : current;
        if (!nn || (nn === table && target === current)) { inp.focus(); toast.warning(t('ops.copy.sameName')); return; }
        const withData = modes.querySelector('input:checked')?.value === 'data';
        try {
          await api('table.copy', { ...base, new_name: nn, with_data: withData, target_db: tgt && target !== current ? target : undefined });
          invalidate(conn);
          toast.success(t('ops.copy.done', { name: nn }), { action: { label: t('ops.open'), onClick: () => navigate(routeFor(target, nn, 'structure')) } });
        } catch (e) { toastError(e); }
      }));
      sections.push(card('ops-h-copy', t('ops.copy.title'), t('ops.copy.desc'),
        h('div', { class: 'grid gap-3 sm:grid-cols-2' }, fieldBox('ops-copy-name', t('ops.copy.newName'), inp), tgt ? fieldBox('ops-copy-target', t(targetKind === 'schema' ? 'ops.targetSchema' : 'ops.targetDb'), tgt) : null),
        modes, h('div', { class: 'flex justify-end' }, b)));

      if (targets.length > 1) {
        const mt = targetSelect('ops-move-target');
        const mb = h('button', { type: 'button', class: 'btn' }, icon('arrow-right'), t('ops.move.submit'));
        mb.addEventListener('click', () => busy(mb, async () => {
          const target = mt.value;
          if (target === current) { toast.warning(t('ops.move.sameTarget')); return; }
          const ok = await confirmDialog({ title: t('ops.move.confirmTitle', { name: table, target }), message: t('ops.move.confirmMessage'), confirmLabel: t('ops.move.submit'), danger: true });
          if (!ok) return;
          try {
            await api('table.copy', { ...base, new_name: table, with_data: true, target_db: target });
            await api('table.drop', { conn, db, schema, tables: [table] });
            invalidate(conn);
            toast.success(t('ops.move.done', { name: table, target }));
            navigate(routeFor(target, table, 'structure'));
          } catch (e) { toastError(e); }
        }));
        sections.push(card('ops-h-move', t('ops.move.title'), t('ops.move.desc'),
          h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-end' }, h('div', { class: 'flex-1' }, fieldBox('ops-move-target', t(targetKind === 'schema' ? 'ops.targetSchema' : 'ops.targetDb'), mt)), mb)));
      }
    }

    // ----- options (comment / engine / collation) via table.alter
    {
      const fields = [];
      const read = {};
      if (P.comments) {
        const inp = h('input', { id: 'ops-comment', class: 'input', dir: 'auto', value: o.comment ?? '' });
        read.comment = () => inp.value;
        fields.push(fieldBox('ops-comment', t('structure.opt.comment'), inp));
      }
      if (P.engine && meta.engines.length) {
        const sel = h('select', { id: 'ops-engine', class: 'select' }, [...new Set([o.engine, ...meta.engines].filter(Boolean))].map((e) => h('option', { value: e, selected: e === o.engine }, e)));
        read.engine = () => sel.value;
        fields.push(fieldBox('ops-engine', t('structure.opt.engine'), sel));
      }
      if (P.tableCollation && meta.collations.length) {
        const sel = h('select', { id: 'ops-coll', class: 'select' }, [...new Set([o.collation, ...meta.collations].filter(Boolean))].map((c) => h('option', { value: c, selected: c === o.collation }, c)));
        read.collation = () => sel.value;
        fields.push(fieldBox('ops-coll', t('structure.opt.collation'), sel, t('structure.opt.collationHelp')));
      }
      if (fields.length) {
        const b = h('button', { type: 'button', class: 'btn btn-primary' }, icon('save'), t('ops.options.submit'));
        b.addEventListener('click', () => busy(b, async () => {
          const options = {};
          for (const [k, fn] of Object.entries(read)) if (String(fn() ?? '') !== String(o[k] ?? '')) options[k] = fn();
          if (!Object.keys(options).length) { toast.info(t('ops.options.unchanged')); return; }
          try {
            if (await previewAndAlter(base, { options })) {
              toast.success(t('structure.applied', { table }));
              invalidate(conn, db);
              Object.assign(o, options);
            }
          } catch (e) { toastError(e, t('structure.previewFailed')); }
        }));
        sections.push(card('ops-h-opt', t('ops.options.title'), null, h('div', { class: 'grid gap-3 sm:grid-cols-2' }, fields), h('div', { class: 'flex justify-end' }, b)));
      }
    }

    // ----- maintenance
    if (caps.maintenance !== false && MAINTENANCE[P.driver]) {
      const result = h('div', { hidden: true });
      // Read-only connections: only the non-modifying CHECK (the server refuses the others).
      const buttons = MAINTENANCE[P.driver].filter((op) => !ctx.readOnly || op === 'check').map((op) => {
        const b = h('button', { type: 'button', class: 'btn', title: t(`ops.maint.help.${op}`) }, icon(op === 'check' ? 'circle-check' : op === 'repair' ? 'wrench' : op === 'analyze' ? 'gauge' : 'sparkles'), t(`ops.maint.${op}`));
        b.addEventListener('click', () => busy(b, async () => {
          try {
            const res = await api('table.maintenance', { conn, db, schema, tables: [table], op });
            grid?.destroy?.();
            grid = createDataGrid({ columns: (res.columns || []).map((c) => (typeof c === 'string' ? { name: c } : c)), rows: res.rows || [], selectable: false, ariaLabel: t('ops.maint.result', { op }), className: 'h-48 rounded-lg border border-border' });
            result.replaceChildren(h('h3', { class: 'mb-1.5 text-[12.5px] font-medium text-muted' }, t('ops.maint.result', { op: t(`ops.maint.${op}`) })), grid.el);
            result.hidden = false;
            invalidate(conn, db);
          } catch (e) { toastError(e); }
        }));
        return b;
      });
      sections.push(card('ops-h-maint', t('ops.maint.title'), t(`ops.maint.desc.${P.driver}`), h('div', { class: 'flex flex-wrap gap-2' }, buttons), result));
    }

    // ----- danger zone
    const dangerRow = (title, desc, label, ic, fn) => h('div', { class: 'flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center' },
      h('div', { class: 'min-w-0 flex-1' }, h('div', { class: 'text-[13.5px] font-medium' }, title), h('p', { class: 'text-[12.5px] text-muted' }, desc)),
      h('button', { type: 'button', class: 'btn btn-danger shrink-0', onClick: fn }, icon(ic), label));
    const destructive = async (action, kind) => {
      const ok = await confirmDialog({
        title: t(`ops.danger.${kind}.confirmTitle`, { name: table }),
        message: t(`ops.danger.${kind}.confirmMessage`),
        requireText: table, danger: true, confirmLabel: t(`ops.danger.${kind}.submit`),
      });
      if (!ok) return;
      try {
        const res = await api(action, { conn, db, schema, tables: [table] });
        invalidate(conn, db);
        if (kind === 'drop') {
          toast.success(t('ops.danger.drop.done', { name: table }));
          navigate(href.db(conn, db, schema));
        } else {
          toast.success(kind === 'empty' && typeof res?.affected === 'number' ? plural('ops.danger.empty.done', res.affected) : t('ops.danger.truncate.done', { name: table }));
        }
      } catch (e) { toastError(e); }
    };
    sections.push(h('section', { class: 'card space-y-3 border-danger/40 p-4', 'aria-labelledby': 'ops-h-danger' },
      h('h2', { id: 'ops-h-danger', class: 'flex items-center gap-2 text-[14px] font-semibold text-danger' }, icon('warning'), t('ops.danger.title')),
      h('div', { class: 'divide-y divide-border' },
        dangerRow(t('ops.danger.empty.title'), t('ops.danger.empty.desc'), t('ops.danger.empty.submit'), 'eraser', () => destructive('table.empty', 'empty')),
        dangerRow(t('ops.danger.truncate.title'), t('ops.danger.truncate.desc'), t('ops.danger.truncate.submit'), 'scroll', () => destructive('table.truncate', 'truncate')),
        dangerRow(t('ops.danger.drop.title'), t('ops.danger.drop.desc'), t('ops.danger.drop.submit'), 'trash', () => destructive('table.drop', 'drop')))));

    if (ctx.readOnly) {
      // Rename / copy / move / options / danger zone all modify the database.
      const keep = sections.filter((s) => s.getAttribute('aria-labelledby') === 'ops-h-maint' && s.querySelector('button'));
      sections.length = 0;
      sections.push(callout('info', h('span', { class: 'inline-flex items-center gap-1.5' }, icon('lock', 'size-3.5'), t('readonly.badgeTitle'))), ...keep);
    }
    scroller.replaceChildren(h('div', { class: 'mx-auto w-full max-w-4xl space-y-4 p-3 sm:p-4' },
      info.type === 'view' ? callout('info', t('ops.viewNote')) : null,
      sections));
  },

  destroy() {
    grid?.destroy?.();
    grid = null;
  },
};
