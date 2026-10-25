/**
 * Table structure editor: columns (edit / drop / move, inline "add column" rows), indexes,
 * foreign keys (+ incoming references), triggers with DDL viewer and table options. Every
 * change is staged in a pending AlterSpec; "Review & apply" previews it through
 * table.alter {preview:true}, shows the generated SQL and executes it on confirm.
 */
import { h, cls, isMobile, copyText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmDialog, viewerDialog } from '../ui/modal.js';
import { bindMenu } from '../ui/menu.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, callout, refreshButton } from '../ui/feedback.js';
import { t, plural, fmtNumber } from '../i18n/index.js';
import { api } from '../api.js';
import { href } from '../router.js';
import { getTableInfo, getObjects, invalidate } from '../data.js';
import {
  loadMeta, colToDef, toDefinition, typeLabel, defaultLabel, openColumnDialog, createColumnRows,
  openIndexDialog, openFkDialog, previewAndAlter,
} from './_table-editor.js';

let cleanup = null;

const posToSpec = (v) => (v === 'first' ? { first: true } : v && v.startsWith('after:') ? { after: v.slice(6) } : {});
const sameDef = (a, b, P) => JSON.stringify({ ...toDefinition(a, P), p: a._position || '' }) === JSON.stringify({ ...toDefinition(b, P), p: b._position || '' });

export default {
  title: (ctx) => t('table.title.structure', { table: ctx.table }),

  async render(el, ctx) {
    const { conn, db, schema, table, caps } = ctx;
    const base = { conn, db, schema, table };
    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' }, skeleton({ rows: 10 }));
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
    const isView = info.type === 'view';
    const ro = !!ctx.readOnly; // read-only connection: structure is shown but not editable
    const editable = !isView && !ro;
    const pending = { modify: new Map(), drop: new Set(), addIndex: [], dropIndex: new Set(), addFk: [], dropFk: new Set(), options: {} };

    const onBeforeUnload = (e) => { if (countChanges()) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', onBeforeUnload);
    cleanup = () => window.removeEventListener('beforeunload', onBeforeUnload);

    // ----- layout skeleton (persistent nodes; sections redraw their own bodies)
    const colsBody = h('div');
    const idxBody = h('div');
    const fkBody = h('div');
    const trigBody = h('div');
    const refBody = h('div');
    const optBody = h('div');
    const bar = h('div', { class: 'sticky bottom-0 z-10 border-t border-accent/30 bg-surface/95 px-3 py-2 backdrop-blur pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:px-4', role: 'region', 'aria-label': t('structure.pending.label'), hidden: true });

    const positionOptions = () => [
      { value: '', label: t('structure.pos.end') },
      { value: 'first', label: t('structure.pos.first') },
      ...info.columns.map((c) => ({ value: `after:${c.name}`, label: t('structure.pos.after', { name: c.name }) })),
    ];
    const addRows = createColumnRows({
      meta, mode: 'add',
      existingNames: () => info.columns.map((c) => (pending.modify.get(c.name)?.def.name || c.name)),
      positionOptions: P.positions ? positionOptions : undefined,
      onChange: () => { addRows.el.parentElement?.classList.toggle('hidden', !addRows.count()); drawBar(); },
    });

    const btn = (ic, label, onClick, extra = '') => h('button', { type: 'button', class: `btn btn-sm ${extra}`, onClick }, icon(ic), h('span', {}, label));
    const counts = {};
    const section = (id, title, count, actions, ...body) => h('section', { class: 'space-y-2', 'aria-labelledby': id },
      h('div', { class: 'flex flex-wrap items-center gap-2' },
        h('h2', { id, class: 'me-auto flex items-center gap-2 text-[14px] font-semibold' }, title, count !== null ? h('span', { class: 'text-xs font-normal text-muted', ref: (x) => { counts[id] = x; } }, fmtNumber(count)) : null),
        actions),
      ...body);

    const addColumn = async () => {
      if (isMobile()) await addRows.addViaDialog();
      else addRows.add();
    };

    const content = h('div', { class: 'w-full space-y-6 p-3 sm:p-4' },
      isView ? callout('info', t('structure.viewReadonly')) : ro ? callout('info', t('readonly.badgeTitle')) : null,
      section('st-cols', t('structure.columns'), info.columns.length,
        h('div', { class: 'flex gap-1.5' },
          ro || isView ? null : btn('plus', t('structure.col.add'), addColumn, 'btn-primary'),
          refreshButton(() => reload())),
        h('div', { class: 'card overflow-hidden' }, colsBody, h('div', { class: 'border-t border-border px-2 py-1' }, addRows.el))),
      section('st-idx', t('structure.indexes'), info.indexes.length,
        editable ? btn('plus', t('structure.idx.add'), () => addIndex()) : null,
        h('div', { class: 'card overflow-hidden' }, idxBody)),
      P.fks && !isView ? section('st-fk', t('structure.fks'), info.foreign_keys.length,
        ro ? null : btn('plus', t('structure.fk.add'), () => addFk()),
        h('div', { class: 'card overflow-hidden' }, fkBody)) : null,
      (info.referenced_by || []).length ? section('st-ref', t('structure.referencedBy'), info.referenced_by.length, null, h('div', { class: 'card overflow-hidden' }, refBody)) : null,
      caps.triggers !== false && !isView ? section('st-trg', t('structure.triggers'), (info.triggers || []).length,
        ro ? null : h('a', { class: 'btn btn-sm', href: href.dbObject(conn, db, schema, 'trigger', null, { new: 1, table }) }, icon('plus'), t('routines.newTrigger')),
        h('div', { class: 'card overflow-hidden' }, trigBody)) : null,
      isView ? null : section('st-opt', t('structure.options'), null, null, optBody));
    addRows.el.parentElement.classList.toggle('hidden', true);
    scroller.replaceChildren(content, bar);

    // ----- helpers
    const allColNames = () => [...info.columns.filter((c) => !pending.drop.has(c.name)).map((c) => pending.modify.get(c.name)?.def.name || c.name), ...addRows.getDefs().map((d) => d.name).filter(Boolean)];
    const indexMembers = (name) => info.indexes.filter((ix) => !ix.primary && ix.columns.some((c) => c === name || c.startsWith(`${name}(`)));
    const fkOf = (name) => info.foreign_keys.find((f) => f.columns.includes(name));
    const emptyRow = (cols, text) => h('tr', {}, h('td', { colspan: String(cols), class: 'py-6 text-center text-[13px] text-muted' }, text));
    const stateBadge = (kind) => h('span', { class: cls('badge', kind === 'drop' ? 'badge-danger' : kind === 'new' ? 'badge-success' : 'badge-warning') }, t(`structure.state.${kind}`));
    const undoBtn = (label, onClick) => h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-label': label, title: label, onClick }, icon('history'), h('span', { class: 'max-sm:hidden' }, t('structure.undo')));

    // ----- columns
    function drawColumns() {
      const rows = info.columns.map((col, i) => {
        const m = pending.modify.get(col.name);
        const dropped = pending.drop.has(col.name);
        const d = m ? m.def : colToDef(col);
        const fk = fkOf(col.name);
        const idx = indexMembers(col.name);
        const renamed = m && d.name !== col.name;
        const actions = [];
        if (editable) {
          if (dropped) actions.push(undoBtn(t('structure.undoFor', { name: col.name }), () => { pending.drop.delete(col.name); redraw(); }));
          else {
            if (P.alterColumn) actions.push(h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('structure.col.edit'), 'aria-label': t('structure.col.editNamed', { name: col.name }), onClick: () => editColumn(col) }, icon('pencil')));
            const more = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.moreActionsFor', { name: col.name }) }, icon('ellipsis'));
            bindMenu(more, () => columnMenu(col, i, m), { align: 'end' });
            actions.push(more);
          }
        }
        const extra = [
          d.auto_increment && !(P.driver !== 'mysql' && /identity|serial|autoincrement/i.test(col.extra || '')) ? 'AUTO_INCREMENT' : null,
          d.generated ? `GENERATED (${d.generated})` : null,
          d.on_update ? `ON UPDATE ${d.on_update}` : null,
          col.extra && !/auto_?increment|on update|generated|identity|serial/i.test(col.extra) ? col.extra : null,
          P.driver !== 'mysql' && col.extra && /identity|serial|autoincrement/i.test(col.extra) ? col.extra.replace(/^serial: .*/, 'serial') : null,
        ].filter(Boolean).join(' ');
        const dl = defaultLabel(d);
        return h('tr', { class: cls(dropped && 'opacity-60', m && !dropped && 'bg-warning/5'), 'data-col': col.name },
          h('td', { class: 'w-10 text-xs tabular-nums text-muted max-sm:hidden' }, String(i + 1)),
          h('td', { class: 'max-w-[18rem]' },
            h('div', { class: 'flex flex-wrap items-center gap-1.5' },
              h('span', { class: cls('truncate font-mono text-[13px] font-medium', dropped && 'line-through'), dir: 'auto' }, renamed ? `${col.name} → ${d.name}` : col.name),
              col.primary ? h('span', { class: 'badge badge-accent', title: t('structure.badge.pkTitle') }, icon('key'), 'PK') : null,
              fk ? h('a', { class: 'badge', href: href.table(conn, db, schema, fk.ref_table, 'structure'), title: t('structure.badge.fkTitle', { table: fk.ref_table, column: fk.ref_columns[fk.columns.indexOf(col.name)] || '' }) }, icon('link'), 'FK') : null,
              idx.length ? h('span', { class: 'badge', title: idx.map((x) => x.name).join(', ') }, idx.some((x) => x.unique) ? 'UQ' : 'IDX') : null,
              dropped ? stateBadge('drop') : m ? stateBadge('modified') : null,
              m && d._position ? h('span', { class: 'badge', dir: 'auto' }, d._position === 'first' ? t('structure.pos.first') : t('structure.pos.after', { name: d._position.slice(6) })) : null)),
          h('td', {}, h('span', { class: cls('font-mono text-[12.5px]', dropped && 'line-through'), dir: 'ltr' }, typeLabel(d, P)),
            d.collation && P.driver === 'mysql' ? h('div', { class: 'hidden font-mono text-[11px] text-muted lg:block' }, d.collation) : null),
          h('td', { class: 'text-[12.5px]' }, d.nullable ? t('structure.yes') : h('span', { class: 'text-muted' }, t('structure.no'))),
          h('td', { class: 'max-w-[12rem] truncate max-md:hidden' }, d.default_kind === 'null' && d.nullable && !d.auto_increment ? h('span', { class: 'badge badge-null' }, 'NULL') : dl && d.default_kind !== 'null' ? h('span', { class: 'font-mono text-[12px]', dir: 'auto', title: dl }, dl) : h('span', { class: 'text-subtle' }, '—')),
          h('td', { class: 'max-w-[14rem] truncate font-mono text-[11.5px] text-muted max-md:hidden', title: extra, dir: 'ltr' }, extra),
          h('td', { class: 'max-w-[16rem] truncate text-[12.5px] text-muted max-lg:hidden', dir: 'auto', title: d.comment || '' }, d.comment || ''),
          h('td', { class: 'w-px whitespace-nowrap text-end' }, actions));
      });
      colsBody.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {},
          h('th', { class: 'max-sm:hidden' }, '#'),
          h('th', {}, t('structure.col.name')),
          h('th', {}, t('structure.col.type')),
          h('th', {}, t('structure.col.null')),
          h('th', { class: 'max-md:hidden' }, t('structure.col.default')),
          h('th', { class: 'max-md:hidden' }, t('structure.col.extra')),
          h('th', { class: 'max-lg:hidden' }, t('structure.col.comment')),
          h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        h('tbody', {}, rows.length ? rows : emptyRow(8, t('structure.noColumns'))))));
      addRows.el.parentElement.classList.toggle('hidden', !addRows.count());
    }

    function columnMenu(col, i, m) {
      const items = [];
      if (P.positions && P.alterColumn) {
        const names = info.columns.map((c) => c.name);
        items.push(
          { label: t('structure.moveUp', { name: col.name }), icon: 'arrow-up', disabled: i === 0, onSelect: () => stagePosition(col, i <= 1 ? 'first' : `after:${names[i - 2]}`) },
          { label: t('structure.moveDown', { name: col.name }), icon: 'arrow-down', disabled: i === names.length - 1, onSelect: () => stagePosition(col, `after:${names[i + 1]}`) },
          { label: t('structure.moveFirst'), icon: 'chevrons-left', disabled: i === 0, onSelect: () => stagePosition(col, 'first') },
          { separator: true },
        );
      }
      if (m) items.push({ label: t('structure.revert'), icon: 'history', onSelect: () => { pending.modify.delete(col.name); redraw(); } });
      items.push({ label: t('common.copyName'), icon: 'copy', onSelect: () => copyText(col.name).then(() => toast.success(t('common.copied'))) });
      if (P.dropColumn) {
        items.push({ separator: true }, {
          label: t('structure.col.drop'), icon: 'trash', danger: true,
          disabled: info.columns.length - pending.drop.size <= 1,
          onSelect: () => { pending.drop.add(col.name); redraw(); },
        });
      }
      return items;
    }

    function stagePosition(col, pos) {
      const m = pending.modify.get(col.name);
      const def = m ? m.def : colToDef(col);
      def._position = pos;
      pending.modify.set(col.name, { column: col.name, def });
      redraw();
    }

    async function editColumn(col) {
      const m = pending.modify.get(col.name);
      const orig = colToDef(col);
      const res = await openColumnDialog({
        title: t('structure.col.editTitle', { name: col.name }),
        def: m ? m.def : orig,
        meta,
        otherNames: allColNames().filter((n) => n !== (m?.def.name || col.name)),
        position: P.positions ? { value: m?.def._position || '', options: [{ value: '', label: t('structure.pos.keep') }, ...positionOptions().slice(1).filter((op) => op.value !== `after:${col.name}`)] } : undefined,
      });
      if (!res) return;
      if (!res._position) delete res._position;
      if (sameDef(res, orig, P)) pending.modify.delete(col.name);
      else pending.modify.set(col.name, { column: col.name, def: res });
      redraw();
    }

    // ----- indexes
    const kindOf = (ix) => (ix.primary ? 'PRIMARY' : /fulltext|spatial/i.test(ix.type || '') ? String(ix.type).toUpperCase() : ix.unique ? 'UNIQUE' : 'INDEX');
    function drawIndexes() {
      const rows = info.indexes.map((ix) => {
        const dropped = pending.dropIndex.has(ix.name);
        return h('tr', { class: cls(dropped && 'opacity-60') },
          h('td', {}, h('span', { class: cls('font-mono text-[13px] font-medium', dropped && 'line-through'), dir: 'auto' }, ix.name), dropped ? h('span', { class: 'ms-2' }, stateBadge('drop')) : null),
          h('td', {}, h('span', { class: cls('badge', ix.primary && 'badge-accent') }, kindOf(ix)), ix.type && !/fulltext|spatial/i.test(ix.type) ? h('span', { class: 'ms-1.5 text-[11px] text-muted max-sm:hidden' }, ix.type) : null),
          h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' }, ix.columns.join(', ')),
          h('td', { class: 'w-px whitespace-nowrap text-end' }, !editable ? null : dropped
            ? undoBtn(t('structure.undoFor', { name: ix.name }), () => { pending.dropIndex.delete(ix.name); redraw(); })
            : h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('structure.idx.drop'), 'aria-label': t('structure.idx.dropNamed', { name: ix.name }), onClick: () => { pending.dropIndex.add(ix.name); redraw(); } }, icon('trash'))));
      });
      pending.addIndex.forEach((ix, i) => rows.push(h('tr', { class: 'bg-success/5' },
        h('td', {}, h('span', { class: 'font-mono text-[13px] font-medium', dir: 'auto' }, ix.name || t('structure.idx.autoName')), h('span', { class: 'ms-2' }, stateBadge('new'))),
        h('td', {}, h('span', { class: 'badge' }, ix.primary ? 'PRIMARY' : ix.type || (ix.unique ? 'UNIQUE' : 'INDEX'))),
        h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' }, ix.columns.join(', ')),
        h('td', { class: 'w-px text-end' }, h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('structure.col.removeNamed', { name: ix.name || ix.columns.join(', ') }), onClick: () => { pending.addIndex.splice(i, 1); redraw(); } }, icon('x'))))));
      idxBody.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {}, h('th', {}, t('structure.idx.name')), h('th', {}, t('structure.idx.kind')), h('th', {}, t('structure.idx.columns')), h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        h('tbody', {}, rows.length ? rows : emptyRow(4, t('structure.idx.none'))))));
    }

    async function addIndex() {
      const hasPrimary = (info.indexes.some((x) => x.primary) && !info.indexes.some((x) => x.primary && pending.dropIndex.has(x.name))) || pending.addIndex.some((x) => x.primary);
      const res = await openIndexDialog({
        meta, table, columns: allColNames(), hasPrimary,
        names: [...info.indexes.map((x) => x.name), ...pending.addIndex.map((x) => x.name).filter(Boolean)],
      });
      if (!res) return;
      pending.addIndex.push(res);
      redraw();
    }

    // ----- foreign keys
    const fkTarget = (fk) => `${fk.ref_schema && fk.ref_schema !== (schema || db) ? `${fk.ref_schema}.` : ''}${fk.ref_table}`;
    const fkActions = (fk) => [fk.on_delete ? `ON DELETE ${fk.on_delete}` : null, fk.on_update ? `ON UPDATE ${fk.on_update}` : null].filter(Boolean).join(' · ');
    function drawFks() {
      const rows = info.foreign_keys.map((fk) => {
        const dropped = pending.dropFk.has(fk.name);
        return h('tr', { class: cls(dropped && 'opacity-60') },
          h('td', {}, h('span', { class: cls('font-mono text-[13px] font-medium', dropped && 'line-through'), dir: 'auto' }, fk.name), dropped ? h('span', { class: 'ms-2' }, stateBadge('drop')) : null),
          h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' }, fk.columns.join(', ')),
          h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' },
            h('a', { class: 'hover:text-accent hover:underline', href: href.table(conn, db, schema, fk.ref_table, 'structure') }, fkTarget(fk)), ` (${fk.ref_columns.join(', ')})`),
          h('td', { class: 'text-[11.5px] text-muted max-md:hidden' }, fkActions(fk)),
          h('td', { class: 'w-px whitespace-nowrap text-end' }, ro ? null : dropped
            ? undoBtn(t('structure.undoFor', { name: fk.name }), () => { pending.dropFk.delete(fk.name); redraw(); })
            : h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('structure.fk.drop'), 'aria-label': t('structure.fk.dropNamed', { name: fk.name }), onClick: () => { pending.dropFk.add(fk.name); redraw(); } }, icon('trash'))));
      });
      pending.addFk.forEach((fk, i) => rows.push(h('tr', { class: 'bg-success/5' },
        h('td', {}, h('span', { class: 'font-mono text-[13px] font-medium', dir: 'auto' }, fk.name || t('structure.idx.autoName')), h('span', { class: 'ms-2' }, stateBadge('new'))),
        h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' }, fk.columns.join(', ')),
        h('td', { class: 'font-mono text-[12.5px]', dir: 'ltr' }, `${fk.ref_table} (${fk.ref_columns.join(', ')})`),
        h('td', { class: 'text-[11.5px] text-muted max-md:hidden' }, fkActions(fk)),
        h('td', { class: 'w-px text-end' }, h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('structure.col.removeNamed', { name: fk.name || fk.columns.join(', ') }), onClick: () => { pending.addFk.splice(i, 1); redraw(); } }, icon('x'))))));
      fkBody.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {}, h('th', {}, t('structure.fk.name')), h('th', {}, t('structure.fk.columns')), h('th', {}, t('structure.fk.references')), h('th', { class: 'max-md:hidden' }, t('structure.fk.actions')), h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        h('tbody', {}, rows.length ? rows : emptyRow(5, t('structure.fk.none'))))));
    }

    async function addFk() {
      let tables = [];
      try {
        const objs = await getObjects(conn, db, schema);
        tables = (objs.tables || []).filter((x) => x.type !== 'view').map((x) => x.name);
      } catch (e) { toastError(e); return; }
      const res = await openFkDialog({
        meta, base: { conn, db, schema }, table, columns: allColNames(), tables,
        names: [...info.foreign_keys.map((x) => x.name), ...pending.addFk.map((x) => x.name).filter(Boolean)],
      });
      if (!res) return;
      pending.addFk.push(res);
      redraw();
    }

    function drawRefs() {
      refBody.replaceChildren(h('ul', { class: 'divide-y divide-border' }, (info.referenced_by || []).map((r) => h('li', { class: 'flex flex-wrap items-center gap-x-2 gap-y-0.5 px-4 py-2 text-[13px]' },
        icon('link', 'text-muted'),
        h('a', { class: 'font-mono font-medium hover:text-accent hover:underline', dir: 'auto', href: href.table(conn, db, r.schema && r.schema !== db ? r.schema : schema, r.table, 'structure') }, r.table),
        h('span', { class: 'font-mono text-xs text-muted', dir: 'ltr' }, `(${r.columns.join(', ')}) → (${r.ref_columns.join(', ')})`),
        h('span', { class: 'text-xs text-muted' }, r.name)))));
    }

    // ----- triggers
    function drawTriggers() {
      const trs = info.triggers || [];
      trigBody.replaceChildren(h('div', { class: 'overflow-x-auto' }, h('table', { class: 'table-grid' },
        h('thead', {}, h('tr', {}, h('th', {}, t('structure.trg.name')), h('th', {}, t('structure.trg.when')), h('th', { class: 'max-md:hidden' }, t('structure.trg.statement')), h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
        h('tbody', {}, trs.length ? trs.map((tr) => h('tr', {},
          h('td', {}, h('span', { class: 'font-mono text-[13px] font-medium', dir: 'auto' }, tr.name)),
          h('td', {}, h('span', { class: 'badge' }, `${tr.timing || ''} ${tr.event || ''}`.trim())),
          h('td', { class: 'max-w-[28rem] truncate font-mono text-[12px] text-muted max-md:hidden', dir: 'ltr', title: tr.statement || '' }, String(tr.statement || '').replace(/\s+/g, ' ')),
          h('td', { class: 'w-px whitespace-nowrap text-end' },
            h('a', { class: 'btn btn-ghost btn-sm', href: href.dbObject(conn, db, schema, 'trigger', tr.name, { table }), 'aria-label': t('routines.editNamed', { name: tr.name }) }, icon(ro ? 'eye' : 'pencil'), h('span', { class: 'max-sm:hidden' }, ro ? t('routines.view') : t('routines.edit'))),
            h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-label': t('structure.trg.ddlFor', { name: tr.name }), onClick: () => showTrigger(tr) }, icon('file-code'), h('span', { class: 'max-sm:hidden' }, t('structure.trg.ddl')))))) : emptyRow(4, t('structure.trg.none'))))));
    }
    async function showTrigger(tr) {
      let sql;
      try {
        sql = (await api('table.ddl', { conn, db, schema, table: tr.name, type: 'trigger' })).sql;
      } catch {
        sql = `CREATE TRIGGER ${tr.name} ${tr.timing || ''} ${tr.event || ''} ON ${table}\nFOR EACH ROW\n${tr.statement || ''}`;
      }
      viewerDialog({ title: t('structure.trg.ddlFor', { name: tr.name }), text: sql, language: 'sql' });
    }

    // ----- table options
    function drawOptions() {
      const o = info.options || {};
      const fields = [];
      const setOpt = (key, value, original) => {
        if (String(value ?? '') === String(original ?? '')) delete pending.options[key];
        else pending.options[key] = key === 'auto_increment' ? (value === '' ? null : Number(value)) : value;
        drawBar();
      };
      const fieldBox = (id, label, control, help) => h('div', {}, h('label', { class: 'label', for: id }, label), control, help ? h('p', { class: 'help' }, help) : null);
      if (P.engine && meta.engines.length) {
        const sel = h('select', { id: 'st-engine', class: 'select', onChange: () => setOpt('engine', sel.value, o.engine) },
          [...new Set([o.engine, ...meta.engines].filter(Boolean))].map((e) => h('option', { value: e, selected: e === (pending.options.engine ?? o.engine) }, e)));
        fields.push(fieldBox('st-engine', t('structure.opt.engine'), sel));
      }
      if (P.tableCollation && meta.collations.length) {
        const sel = h('select', { id: 'st-coll', class: 'select', onChange: () => setOpt('collation', sel.value, o.collation) },
          [...new Set([o.collation, ...meta.collations].filter(Boolean))].map((c) => h('option', { value: c, selected: c === (pending.options.collation ?? o.collation) }, c)));
        fields.push(fieldBox('st-coll', t('structure.opt.collation'), sel, t('structure.opt.collationHelp')));
      }
      if (P.comments) {
        const inp = h('input', { id: 'st-comment', class: 'input', dir: 'auto', value: pending.options.comment ?? o.comment ?? '', onInput: () => setOpt('comment', inp.value, o.comment ?? '') });
        fields.push(fieldBox('st-comment', t('structure.opt.comment'), inp));
      }
      if (P.autoIncrement && info.columns.some((c) => c.auto_increment)) {
        const inp = h('input', { id: 'st-ai', class: 'input tabular-nums', type: 'number', min: '1', inputmode: 'numeric', value: pending.options.auto_increment ?? o.auto_increment ?? '', onInput: () => setOpt('auto_increment', inp.value, o.auto_increment ?? '') });
        fields.push(fieldBox('st-ai', t('structure.opt.autoIncrement'), inp, t('structure.opt.autoIncrementHelp')));
      }
      optBody.replaceChildren(fields.length
        ? h('div', { class: 'card grid gap-4 p-4 sm:grid-cols-2' }, fields)
        : h('p', { class: 'text-[13px] text-muted' }, t('structure.opt.none')));
      if (ro) optBody.querySelectorAll('input, select, textarea').forEach((x) => { x.disabled = true; });
    }

    // ----- pending changes
    function changeList() {
      const out = [];
      addRows.getDefs().forEach((d) => out.push(['plus', t('structure.change.addColumn', { name: d.name || '?' })]));
      for (const m of pending.modify.values()) if (!pending.drop.has(m.column)) out.push(['pencil', m.def.name !== m.column ? t('structure.change.renameColumn', { name: m.column, to: m.def.name }) : t('structure.change.modifyColumn', { name: m.column })]);
      pending.drop.forEach((n) => out.push(['trash', t('structure.change.dropColumn', { name: n })]));
      pending.addIndex.forEach((x) => out.push(['plus', t('structure.change.addIndex', { name: x.name || x.columns.join(', ') })]));
      pending.dropIndex.forEach((n) => out.push(['trash', t('structure.change.dropIndex', { name: n })]));
      pending.addFk.forEach((x) => out.push(['plus', t('structure.change.addFk', { name: x.name || x.columns.join(', ') })]));
      pending.dropFk.forEach((n) => out.push(['trash', t('structure.change.dropFk', { name: n })]));
      Object.keys(pending.options).forEach((k) => out.push(['settings', t('structure.change.option', { name: t(`structure.opt.${k === 'auto_increment' ? 'autoIncrement' : k}`) })]));
      return out;
    }
    function countChanges() { return changeList().length; }

    function buildSpec() {
      const spec = {};
      const add = addRows.getDefs().map((d) => ({ ...toDefinition(d, P), ...posToSpec(d._position) }));
      const modify = [...pending.modify.values()].filter((m) => !pending.drop.has(m.column))
        .map((m) => ({ column: m.column, definition: { ...toDefinition(m.def, P), ...posToSpec(m.def._position) } }));
      if (add.length) spec.add = add;
      if (modify.length) spec.modify = modify;
      if (pending.drop.size) spec.drop = [...pending.drop];
      if (pending.addIndex.length) spec.add_index = pending.addIndex;
      if (pending.dropIndex.size) spec.drop_index = [...pending.dropIndex];
      if (pending.addFk.length) spec.add_fk = pending.addFk;
      if (pending.dropFk.size) spec.drop_fk = [...pending.dropFk];
      if (Object.keys(pending.options).length) spec.options = { ...pending.options };
      return spec;
    }

    let listOpen = false;
    function drawBar() {
      const list = ro ? [] : changeList();
      bar.hidden = !list.length;
      if (!list.length) { bar.replaceChildren(); return; }
      const toggle = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': String(listOpen), 'aria-controls': 'st-pending-list', onClick: () => { listOpen = !listOpen; drawBar(); } },
        icon(listOpen ? 'chevron-down' : 'chevron-up'), h('span', { class: 'font-medium text-fg' }, plural('structure.pending.count', list.length)));
      bar.replaceChildren(
        listOpen ? h('ul', { id: 'st-pending-list', class: 'mb-2 max-h-40 space-y-0.5 overflow-auto text-[12.5px]' }, list.map(([ic, text]) => h('li', { class: 'flex items-center gap-2', dir: 'auto' }, icon(ic, 'size-3.5 text-muted'), text))) : null,
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          toggle,
          h('div', { class: 'flex-1' }),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: () => discard() }, icon('x'), t('structure.pending.discard')),
          h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: () => apply() }, icon('play'), t('structure.pending.apply'))));
    }

    async function discard() {
      const ok = await confirmDialog({ title: t('structure.pending.discardTitle'), message: plural('structure.pending.discardMessage', countChanges()), confirmLabel: t('structure.pending.discard'), danger: true });
      if (!ok) return;
      resetPending();
      redraw();
      drawOptions();
    }
    function resetPending() {
      pending.modify.clear(); pending.drop.clear(); pending.addIndex = []; pending.dropIndex.clear();
      pending.addFk = []; pending.dropFk.clear(); pending.options = {};
      addRows.clear();
    }

    async function apply() {
      const err = addRows.validate();
      if (err) { toast.error(err); return; }
      try {
        const done = await previewAndAlter(base, buildSpec());
        if (!done) return;
        toast.success(t('structure.applied', { table }));
        resetPending();
        invalidate(conn, db);
        await reload();
      } catch (e) { toastError(e, t('structure.previewFailed')); }
    }

    async function reload() {
      try {
        info = await getTableInfo(conn, db, schema, table, true);
      } catch (e) { toastError(e); return; }
      redraw();
      drawOptions();
      drawTriggers();
      drawRefs();
    }

    function redraw() {
      const setCount = (id, n) => { if (counts[id]) counts[id].textContent = fmtNumber(n); };
      setCount('st-cols', info.columns.length);
      setCount('st-idx', info.indexes.length);
      setCount('st-fk', info.foreign_keys.length);
      setCount('st-trg', (info.triggers || []).length);
      drawColumns();
      drawIndexes();
      if (P.fks && !isView) drawFks();
      drawBar();
    }

    redraw();
    drawRefs();
    if (caps.triggers !== false && !isView) drawTriggers();
    if (!isView) drawOptions();
  },

  destroy() {
    cleanup?.();
    cleanup = null;
  },
};
