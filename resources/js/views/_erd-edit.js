/**
 * ER diagram editing actions (loaded on demand by views/erd.js together with the table editor):
 * add / edit / drop a relation, relation details, add column, rename and drop table. Every
 * structural change is previewed and reviewed through _table-editor's previewAndAlter().
 * @module views/_erd-edit
 */
import { h } from '../ui/dom.js';
import { callout } from '../ui/feedback.js';
import { openModal, confirmDialog, promptDialog } from '../ui/modal.js';
import { t } from '../i18n/index.js';
import { api } from '../api.js';
import { loadMeta, openFkDialog, openColumnDialog, blankColumn, toDefinition, previewAndAlter } from './_table-editor.js';

/**
 * @typedef {object} ErdCtx
 * @property {{conn: string, db: string, schema?: string}} base
 * @property {object} caps
 * @property {Map<string, {tb: {name: string, columns: object[]}}>} nodes
 * @property {object[]} rels  db.erd relations
 * @property {(msg: string, focus?: string, renamed?: string[]) => Promise<void>} done  toast + reload
 */

const ALIAS = { integer: 'int', int4: 'int', int8: 'bigint', int2: 'smallint', 'character varying': 'varchar', character: 'char', bool: 'boolean', 'double precision': 'double' };
/** Type without length / precision, aliases folded (soft FK compatibility check). */
export const baseType = (ty) => { const v = String(ty || '').toLowerCase().replace(/\(.*?\)/g, '').replace(/\s+/g, ' ').trim(); return ALIAS[v] || v; };

const colOf = (n, name) => n.tb.columns.find((c) => c.name === name);
const colNames = (n) => n.tb.columns.map((c) => c.name);
const fkNames = (x) => x.rels.map((r) => r.name).filter(Boolean);
const alter = (x, table, spec) => previewAndAlter({ ...x.base, table }, spec);
const fkDialog = async (x, from, o) => openFkDialog({ meta: await loadMeta(x.base.conn, x.caps), base: x.base, table: from.tb.name, columns: colNames(from), tables: [...x.nodes.keys()], ...o });

/** New relation from `from`(.fromCol) to `to`(.toCol; header = its primary key). */
export async function relate(x, from, fromCol, to, toCol) {
  const src = fromCol && colOf(from, fromCol);
  const dst = to && (toCol ? colOf(to, toCol) : to.tb.columns.find((c) => c.primary));
  const fk = await fkDialog(x, from, {
    names: fkNames(x),
    title: t('erd.newRelationTitle', { table: from.tb.name }),
    notice: src && dst && baseType(src.type) !== baseType(dst.type) ? callout('warning', t('erd.typeMismatch', { from: src.type, to: dst.type })) : null,
    value: { name: '', columns: fromCol ? [fromCol] : [], ref_table: to ? to.tb.name : '', ref_columns: toCol ? [toCol] : null },
  });
  if (fk && await alter(x, from.tb.name, { add_fk: [fk] })) await x.done(t('erd.relationAdded'), from.tb.name);
}

/** Edit = drop + add in one AlterSpec (drivers emit the DROP first; SQLite rebuilds once). */
export async function editRel(x, { r, from }) {
  const fk = await fkDialog(x, from, {
    names: fkNames(x).filter((n) => n !== r.name),
    title: t('erd.editRelationTitle', { name: r.name }), submitLabel: t('common.save'),
    value: { name: r.name, columns: r.from_columns, ref_table: r.to_table, ref_columns: r.to_columns, on_delete: r.on_delete, on_update: r.on_update },
  });
  if (fk && await alter(x, r.from_table, { drop_fk: [r.name], add_fk: [fk] })) await x.done(t('erd.relationUpdated', { name: fk.name || r.name }), r.from_table);
}

export async function dropRel(x, { r }) {
  const ok = await confirmDialog({ title: t('erd.dropRelationTitle', { name: r.name }), message: t('erd.dropRelationMessage', { name: r.name, table: r.from_table }), danger: true, confirmLabel: t('structure.fk.drop') });
  if (ok && await alter(x, r.from_table, { drop_fk: [r.name] })) await x.done(t('erd.relationDropped', { name: r.name }), r.from_table);
}

export function relDetails(x, { r }) {
  const row = (k, v) => h('div', { class: 'grid grid-cols-[7rem_1fr] gap-3 py-2' }, h('dt', { class: 'text-muted' }, k), h('dd', { class: 'break-words font-mono text-[12.5px]', dir: 'ltr' }, v));
  const a = `${r.from_table}.${r.from_columns.join(', ')}`;
  const b = `${r.to_table}.${r.to_columns.join(', ')}`;
  const m = openModal({
    title: r.name || `${a} → ${b}`,
    body: h('dl', { class: 'divide-y divide-border text-[13px]' },
      row(t('structure.fk.columns'), a), row(t('structure.fk.references'), b),
      row(t('structure.fk.actions'), `ON DELETE ${r.on_delete || '—'} · ON UPDATE ${r.on_update || '—'}`)),
    actions: [h('button', { type: 'button', class: 'btn', onClick: () => m.close() }, t('common.close'))],
  });
}

export async function addColumn(x, n) {
  const meta = await loadMeta(x.base.conn, x.caps);
  const d = await openColumnDialog({ title: t('structure.col.newTitle'), def: blankColumn(meta.prof), meta, otherNames: colNames(n), submitLabel: t('structure.col.add') });
  if (d && await alter(x, n.tb.name, { add: [toDefinition(d, meta.prof)] })) await x.done(t('erd.columnAdded', { name: String(d.name).trim() }), n.tb.name);
}

export async function renameTable(x, n) {
  const old = n.tb.name;
  const nn = (await promptDialog({ title: t('ops.rename.title'), label: t('ops.rename.newName'), value: old, mono: true, confirmLabel: t('ops.rename.submit') }))?.trim();
  if (!nn || nn === old) return;
  await api('table.rename', { ...x.base, table: old, new_name: nn });
  await x.done(t('ops.rename.done', { name: nn }), nn, [old, nn]);
}

export async function dropTable(x, n) {
  const name = n.tb.name;
  if (!await confirmDialog({ title: t('ops.danger.drop.confirmTitle', { name }), message: t('ops.danger.drop.confirmMessage'), requireText: name, danger: true, confirmLabel: t('ops.danger.drop.submit') })) return;
  await api('table.drop', { ...x.base, tables: [name] });
  await x.done(t('ops.danger.drop.done', { name }));
}
