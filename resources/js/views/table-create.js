/**
 * Create-table view (#/c/{conn}/db/{db}[@schema]/create): table name, inline column editor with
 * primary-key flags, indexes, foreign keys and options; the generated CREATE statement is
 * previewed live (debounced table.create {preview:true}); "Create" executes it and opens the new
 * table's structure tab.
 */
import { h, debounce } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, callout, spinner, readOnlyState } from '../ui/feedback.js';
import { t } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getObjects, invalidate } from '../data.js';
import {
  loadMeta, idColumn, toDefinition, createColumnRows, openIndexDialog, openFkDialog, sqlBlock,
} from './_table-editor.js';

let abort = null;

export default {
  title: (ctx) => t('createTable.title', { db: ctx.schema ? `${ctx.db}.${ctx.schema}` : ctx.db }),

  async render(el, ctx) {
    const { conn, db, schema, caps } = ctx;
    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' }, skeleton({ rows: 8 }));
    el.replaceChildren(scroller);
    if (ctx.readOnly) { scroller.replaceChildren(readOnlyState()); return; }
    let meta;
    try { meta = await loadMeta(conn, caps); } catch (e) { scroller.replaceChildren(errorState(e, { onRetry: () => this.render(el, ctx) })); return; }
    const P = meta.prof;
    const indexes = [];
    const fks = [];
    const opts = { engine: '', collation: '', comment: '', without_rowid: false, strict: false };

    // ----- table card
    const nameIn = h('input', { id: 'ct-name', class: 'input font-mono', dir: 'auto', autocomplete: 'off', spellcheck: 'false', 'data-autofocus': '', placeholder: t('createTable.namePlaceholder'), onInput: () => { nameIn.removeAttribute('aria-invalid'); changed(); } });
    const fieldBox = (id, label, control, help) => h('div', {}, h('label', { class: 'label', for: id }, label), control, help ? h('p', { class: 'help' }, help) : null);
    const optFields = [];
    if (P.engine && meta.engines.length) {
      const sel = h('select', { id: 'ct-engine', class: 'select', onChange: () => { opts.engine = sel.value; changed(); } },
        h('option', { value: '' }, t('createTable.serverDefault')), meta.engines.map((e) => h('option', { value: e }, e)));
      optFields.push(fieldBox('ct-engine', t('structure.opt.engine'), sel));
    }
    if (P.tableCollation && meta.collations.length) {
      const sel = h('select', { id: 'ct-coll', class: 'select', onChange: () => { opts.collation = sel.value; changed(); } },
        h('option', { value: '' }, t('createTable.dbDefault')), meta.collations.map((c) => h('option', { value: c }, c)));
      optFields.push(fieldBox('ct-coll', t('structure.opt.collation'), sel));
    }
    if (P.comments) {
      const inp = h('input', { id: 'ct-comment', class: 'input', dir: 'auto', onInput: () => { opts.comment = inp.value; changed(); } });
      optFields.push(fieldBox('ct-comment', t('structure.opt.comment'), inp));
    }
    if (P.sqliteOptions) {
      const cb = (key, label, help) => {
        const c = h('input', { type: 'checkbox', class: 'checkbox', onChange: () => { opts[key] = c.checked; changed(); } });
        return h('div', {}, h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, c, label), h('p', { class: 'help' }, help));
      };
      optFields.push(cb('without_rowid', 'WITHOUT ROWID', t('createTable.withoutRowidHelp')));
      // STRICT tables need SQLite 3.37 (capability strict_tables).
      if (caps.strict_tables !== false) optFields.push(cb('strict', 'STRICT', t('createTable.strictHelp')));
    }

    // ----- columns
    const rows = createColumnRows({ meta, mode: 'create', rows: [idColumn(P)], onChange: () => changed() });
    const addCol = () => { if (window.matchMedia('(max-width: 639.98px)').matches) rows.addViaDialog(); else rows.add(); };

    // ----- indexes & foreign keys
    const idxList = h('div');
    const fkList = h('div');
    const colNames = () => rows.getDefs().map((d) => String(d.name || '').trim()).filter(Boolean);
    const listCard = (items, empty, render) => (items.length
      ? h('ul', { class: 'card divide-y divide-border' }, items.map(render))
      : h('p', { class: 'text-[13px] text-muted' }, empty));
    const removeBtn = (label, onClick) => h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': label, onClick }, icon('x'));
    function drawLists() {
      idxList.replaceChildren(listCard(indexes, t('structure.idx.none'), (ix, i) => h('li', { class: 'flex items-center gap-2 px-3 py-2 text-[13px]' },
        h('span', { class: 'badge' }, ix.type || (ix.unique ? 'UNIQUE' : 'INDEX')),
        h('span', { class: 'min-w-0 flex-1 truncate font-mono', dir: 'auto' }, `${ix.name ? `${ix.name} ` : ''}(${ix.columns.join(', ')})`),
        removeBtn(t('structure.col.removeNamed', { name: ix.name || ix.columns.join(', ') }), () => { indexes.splice(i, 1); drawLists(); changed(); }))));
      fkList.replaceChildren(listCard(fks, t('structure.fk.none'), (fk, i) => h('li', { class: 'flex items-center gap-2 px-3 py-2 text-[13px]' },
        icon('link', 'text-muted'),
        h('span', { class: 'min-w-0 flex-1 truncate font-mono', dir: 'ltr' }, `(${fk.columns.join(', ')}) → ${fk.ref_table} (${fk.ref_columns.join(', ')})`),
        removeBtn(t('structure.col.removeNamed', { name: fk.name || fk.columns.join(', ') }), () => { fks.splice(i, 1); drawLists(); changed(); }))));
    }
    async function addIndex() {
      if (!colNames().length) { toast.warning(t('createTable.needColumns')); return; }
      const res = await openIndexDialog({ meta, table: nameIn.value.trim() || 'table', columns: colNames(), hasPrimary: rows.getDefs().some((d) => d._pk) || indexes.some((x) => x.primary), names: indexes.map((x) => x.name).filter(Boolean) });
      if (!res) return;
      indexes.push(res);
      drawLists();
      changed();
    }
    async function addFk() {
      if (!colNames().length) { toast.warning(t('createTable.needColumns')); return; }
      let tables = [];
      try { tables = ((await getObjects(conn, db, schema)).tables || []).filter((x) => x.type !== 'view').map((x) => x.name); } catch (e) { toastError(e); return; }
      const res = await openFkDialog({ meta, base: { conn, db, schema }, table: nameIn.value.trim() || 'table', columns: colNames(), tables, selfColumns: colNames(), names: fks.map((x) => x.name).filter(Boolean) });
      if (!res) return;
      fks.push(res);
      drawLists();
      changed();
    }

    // ----- SQL preview
    const previewBox = h('div', { class: 'min-h-16', 'aria-live': 'polite' });
    const previewStatus = h('span', { class: 'text-xs text-muted' });

    function definition() {
      const defs = rows.getDefs();
      const options = {};
      if (opts.engine) options.engine = opts.engine;
      if (opts.collation) options.collation = opts.collation;
      if (opts.comment) options.comment = opts.comment;
      if (opts.without_rowid) options.without_rowid = true;
      if (opts.strict) options.strict = true;
      return {
        name: nameIn.value.trim(),
        columns: defs.map((d) => toDefinition(d, P)),
        primary_key: defs.filter((d) => d._pk && String(d.name || '').trim()).map((d) => d.name.trim()),
        indexes: indexes.filter((ix) => ix.columns.every((c) => colNames().includes(c.replace(/\(\d+\)$/, '')))),
        foreign_keys: fks.filter((fk) => fk.columns.every((c) => colNames().includes(c))),
        options,
      };
    }

    async function preview() {
      abort?.abort();
      const def = definition();
      if (!def.name || !def.columns.length || def.columns.some((c) => !c.name || !c.type)) {
        previewStatus.textContent = '';
        previewBox.replaceChildren(h('p', { class: 'text-[13px] text-muted' }, t('createTable.previewHint')));
        return;
      }
      abort = new AbortController();
      const my = abort;
      previewStatus.replaceChildren(spinner({ size: 'size-3.5' }));
      try {
        const res = await api('table.create', { conn, db, schema, definition: def, preview: true }, { signal: my.signal });
        if (my !== abort) return;
        previewBox.replaceChildren(sqlBlock(res.sql || [], 'max-h-[50dvh]'));
        previewStatus.textContent = '';
      } catch (e) {
        if (e.code === 'aborted' || my !== abort) return;
        previewStatus.textContent = '';
        previewBox.replaceChildren(callout('danger', e.message));
      }
    }
    const debouncedPreview = debounce(preview, 400);
    function changed() { debouncedPreview(); }

    // ----- create
    const createBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => create() }, icon('check'), t('createTable.create'));
    async function create() {
      const def = definition();
      let err = null;
      if (!def.name) { err = t('createTable.err.name'); nameIn.setAttribute('aria-invalid', 'true'); nameIn.focus(); }
      else if (!def.columns.length) err = t('createTable.needColumns');
      else err = rows.validate();
      if (err) { toast.error(err); return; }
      createBtn.disabled = true;
      try {
        await api('table.create', { conn, db, schema, definition: def });
        toast.success(t('createTable.created', { name: def.name }));
        invalidate(conn, db);
        navigate(href.table(conn, db, schema, def.name, 'structure'));
      } catch (e) {
        toastError(e, t('createTable.failed'));
        createBtn.disabled = false;
      }
    }

    const section = (title, actions, ...body) => h('section', { class: 'space-y-2' },
      h('div', { class: 'flex flex-wrap items-center gap-2' }, h('h2', { class: 'me-auto text-[14px] font-semibold' }, title), actions), ...body);
    const smallBtn = (ic, label, onClick, extra = '') => h('button', { type: 'button', class: `btn btn-sm ${extra}`, onClick }, icon(ic), label);

    scroller.replaceChildren(
      h('div', { class: 'w-full space-y-6 p-3 sm:p-6' },
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          h('h1', { class: 'flex min-w-0 items-center gap-2 text-xl font-semibold tracking-tight' }, icon('table', 'size-5 text-muted'),
            h('span', { class: 'truncate' }, t('createTable.heading')),
            h('span', { class: 'truncate text-[14px] font-normal text-muted', dir: 'auto' }, schema ? `${db}.${schema}` : db))),
        h('div', { class: 'card grid gap-4 p-4 sm:grid-cols-2' },
          fieldBox('ct-name', t('createTable.name'), nameIn),
          ...optFields),
        section(t('structure.columns'), smallBtn('plus', t('structure.col.add'), addCol, 'btn-primary'),
          h('div', { class: 'card overflow-hidden px-2 py-1' }, rows.el),
          h('p', { class: 'help' }, t('createTable.columnsHelp'))),
        section(t('structure.indexes'), smallBtn('plus', t('structure.idx.add'), addIndex), idxList),
        P.fks ? section(t('structure.fks'), smallBtn('plus', t('structure.fk.add'), addFk), fkList) : null,
        section(t('createTable.preview'), previewStatus, previewBox)),
      h('div', { class: 'sticky bottom-0 z-10 flex items-center justify-end gap-2 border-t border-border bg-surface/95 px-3 py-2 backdrop-blur pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:px-6' },
        h('a', { class: 'btn btn-ghost', href: href.db(conn, db, schema) }, t('common.cancel')),
        createBtn));
    drawLists();
    preview();
    nameIn.focus();
  },

  destroy() {
    abort?.abort();
    abort = null;
  },
};
