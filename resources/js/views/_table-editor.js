/**
 * Shared building blocks of the structure editor, the create-table view and table operations:
 * driver profiles, type traits, ColumnDefinition helpers, the searchable type picker, the column
 * form (dialog; full-screen sheet on phones), inline column rows, index / foreign-key dialogs and
 * the SQL review dialog. Lazily loaded with those views (esbuild puts it in a shared chunk).
 * @module views/_table-editor
 */
import { h, cls, uid, copyText, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openModal } from '../ui/modal.js';
import { callout } from '../ui/feedback.js';
import { toast } from '../ui/toast.js';
import { t, plural } from '../i18n/index.js';
import { api } from '../api.js';
import { getOverview, getTableInfo } from '../data.js';
import { highlightSql } from '../sql/highlight.js';

/* ------------------------------------------------------------------ driver profiles */

const PROFILES = {
  mysql: { positions: true, engine: true, tableCollation: true, unsigned: true, onUpdate: true, prefix: true, indexKinds: ['index', 'unique', 'primary', 'fulltext', 'spatial'], id: { type: 'INT', unsigned: true }, blank: { type: 'VARCHAR', length: '255' } },
  pgsql: { indexKinds: ['index', 'unique', 'primary'], id: { type: 'integer' }, blank: { type: 'character varying', length: '255' } },
  sqlite: { positions: true, sqliteOptions: true, indexKinds: ['index', 'unique', 'primary'], id: { type: 'INTEGER' }, blank: { type: 'TEXT', length: '' } },
  sqlsrv: { indexKinds: ['index', 'unique', 'primary'], id: { type: 'int' }, blank: { type: 'nvarchar', length: '255' } },
};

/**
 * Feature profile of a driver, narrowed by the connection capabilities.
 * @param {string} driver  mysql | pgsql | sqlite | sqlsrv
 * @param {object} caps
 */
export function profile(driver, caps = {}) {
  const p = { ...(PROFILES[driver] || PROFILES.mysql), driver: PROFILES[driver] ? driver : 'mysql' };
  p.comments = caps.comments !== false;
  p.autoIncrement = caps.auto_increment !== false;
  p.unsigned = !!p.unsigned && caps.unsigned !== false;
  p.collations = caps.collations !== false;
  p.engine = !!p.engine && !!caps.engines;
  p.fks = caps.foreign_keys !== false;
  p.alterColumn = caps.alter_column !== false;
  p.dropColumn = caps.drop_column !== false;
  p.quote = p.driver === 'mysql' ? '`' : '"';
  return p;
}

const metaCache = new Map();

/**
 * Driver profile + editor lookups (types, collations, engines) for a connection, cached.
 * @returns {Promise<{driver: string, prof: object, types: Record<string,string[]>, collations: string[], engines: string[]}>}
 */
export function loadMeta(conn, caps = {}) {
  if (!metaCache.has(conn)) {
    const p = (async () => {
      const ov = await getOverview(conn).catch(() => null);
      const driver = ov?.info?.driver || 'mysql';
      const [types, collations, engines] = await Promise.all([
        api('server.dataTypes', { conn }).catch(() => ({})),
        caps.collations !== false ? api('server.collations', { conn }).catch(() => []) : [],
        caps.engines ? api('server.engines', { conn }).catch(() => []) : [],
      ]);
      return {
        driver,
        prof: profile(driver, caps),
        types: types && typeof types === 'object' && !Array.isArray(types) ? types : {},
        collations: (collations || []).map((c) => (typeof c === 'string' ? c : c?.name)).filter(Boolean),
        engines: (engines || []).filter((e) => !/^(no|disabled)$/i.test(String(e.support || ''))).map((e) => e.name).filter(Boolean),
      };
    })();
    p.catch(() => metaCache.delete(conn));
    metaCache.set(conn, p);
  }
  return metaCache.get(conn);
}

/** Quote an identifier for display (never sent to the server). */
export const quoteIdent = (prof, name) => {
  const q = prof.quote;
  return `${q}${String(name).split(q).join(q + q)}${q}`;
};

/* ------------------------------------------------------------------ type traits */

const RX_LEN = /^(n?(var)?char|(var)?binary|character( varying)?|bit( varying)?|varbit|nvarchar|nchar)$/;
const RX_PREC = /^(decimal|numeric|dec|fixed)$/;
const RX_FLOAT = /^(float|double|real|double precision)$/;
const RX_VALUES = /^(enum|set)$/;
const RX_FSP = /^(datetime|timestamp|time)( with(out)? time zone)?$|^(datetime2|datetimeoffset)$/;
const RX_INT = /^((tiny|small|medium|big)?int(eger)?|int[248]|(small|big)?serial[248]?)$/;
const RX_TEXT = /char|text|^enum$|^set$|clob|citext|^string$|^sysname$/;

/**
 * Which column attributes are meaningful for a type on this driver.
 * @param {string} type
 * @param {object} prof  from profile()
 */
export function typeTraits(type, prof) {
  const ty = String(type || '').trim().toLowerCase().replace(/(\[\d*\])+$/, '');
  const isInt = RX_INT.test(ty);
  let length = null;
  if (RX_VALUES.test(ty) && prof.driver === 'mysql') length = 'values';
  else if (RX_PREC.test(ty)) length = 'precision';
  else if (RX_LEN.test(ty)) length = 'length';
  else if (RX_FSP.test(ty)) length = 'fsp';
  return {
    length,
    lengthRequired: prof.driver === 'mysql' && (/^var(char|binary)$/.test(ty) || length === 'values'),
    unsigned: prof.unsigned && (isInt || RX_PREC.test(ty) || RX_FLOAT.test(ty)),
    collation: prof.collations && RX_TEXT.test(ty) && !/binary|bytea|blob/.test(ty),
    autoIncrement: prof.autoIncrement && (isInt || (prof.driver === 'sqlsrv' && RX_PREC.test(ty))),
    serial: /serial/.test(ty),
    onUpdate: !!prof.onUpdate && /^(datetime|timestamp)$/.test(ty),
  };
}

/** Length to use after a type change: kept when the kind of length is unchanged, else reset. */
export function lengthAfterTypeChange(d, oldType, prof) {
  const before = typeTraits(oldType, prof).length;
  const tr = typeTraits(d.type, prof);
  if (before === tr.length && String(d.length ?? '') !== '') return d.length;
  return tr.lengthRequired && /^var/i.test(d.type) ? '255' : '';
}

const GROUP_KEYS = {
  numeric: 'structure.typeGroup.numeric', 'date and time': 'structure.typeGroup.datetime', 'date/time': 'structure.typeGroup.datetime',
  string: 'structure.typeGroup.string', spatial: 'structure.typeGroup.spatial', other: 'structure.typeGroup.other',
  boolean: 'structure.typeGroup.boolean', binary: 'structure.typeGroup.binary', network: 'structure.typeGroup.network',
  geometric: 'structure.typeGroup.geometric', range: 'structure.typeGroup.range', 'text search': 'structure.typeGroup.textSearch',
  array: 'structure.typeGroup.array', 'user-defined': 'structure.typeGroup.userDefined', affinity: 'structure.typeGroup.affinity',
};
/** Translated label of a server-provided type group name (unknown names are shown as is). */
export const groupLabel = (g) => (GROUP_KEYS[String(g).toLowerCase()] ? t(GROUP_KEYS[String(g).toLowerCase()]) : g);

/* ------------------------------------------------------------------ column definitions */

let keySeq = 0;
const nextKey = () => `c${++keySeq}`;

/** A fresh column definition for this driver. */
export function blankColumn(prof, patch = {}) {
  return {
    _key: nextKey(), name: '', type: prof.blank.type, length: prof.blank.length, unsigned: false, nullable: false,
    default_kind: 'none', default: '', auto_increment: false, comment: '', collation: '', on_update: '', extra: null, generated: null,
    ...patch,
  };
}

/** Starter primary key column (driver-appropriate auto-increment integer). */
export const idColumn = (prof) => blankColumn(prof, { name: 'id', ...prof.id, length: '', auto_increment: prof.autoIncrement, _pk: true });

/** Editable definition from a TableInfo Column. */
export function colToDef(col) {
  const m = /on update ([a-z_]+(?:\(\d*\))?)/i.exec(col.extra || '');
  return {
    _key: nextKey(),
    name: col.name, type: col.type || '', length: col.length ?? '', unsigned: !!col.unsigned, nullable: !!col.nullable,
    default_kind: col.default_kind || 'none', default: col.default ?? '', auto_increment: !!col.auto_increment,
    comment: col.comment ?? '', collation: col.collation ?? '', on_update: m ? m[1] : '',
    extra: col.extra || null, generated: col.generated || null,
  };
}

/**
 * Clean ColumnDefinition for the API (drops UI-only keys and attributes the type/driver ignore).
 * @param {object} d  editable definition
 * @param {object} prof
 */
export function toDefinition(d, prof) {
  const tr = typeTraits(d.type, prof);
  const out = {
    name: String(d.name || '').trim(),
    type: String(d.type || '').trim(),
    length: tr.length && String(d.length ?? '').trim() !== '' ? String(d.length).trim() : null,
    unsigned: tr.unsigned && !!d.unsigned,
    nullable: !!d.nullable,
    default_kind: d.default_kind || 'none',
    default: null,
    auto_increment: tr.autoIncrement && !!d.auto_increment,
    comment: prof.comments && String(d.comment ?? '').trim() !== '' ? String(d.comment) : null,
    collation: tr.collation && d.collation ? d.collation : null,
  };
  if (out.default_kind === 'value' || out.default_kind === 'expression') out.default = String(d.default ?? '');
  if (out.default_kind === 'null' && !out.nullable) out.default_kind = 'none';
  if (out.auto_increment) { out.default_kind = 'none'; out.default = null; }
  if (d.generated) { out.generated = d.generated; out.default_kind = 'none'; out.default = null; }
  if (prof.driver === 'mysql') {
    // keep generated STORED/VIRTUAL; ON UPDATE comes from the explicit field only
    const extra = String(d.extra || '').replace(/on update [a-z_]+(\(\d*\))?/ig, '').replace(/auto_increment/ig, '').trim();
    if (extra) out.extra = extra;
    if (tr.onUpdate && d.on_update) out.on_update = d.on_update;
  } else if (d.extra) {
    out.extra = d.extra;
  }
  return out;
}

/** Display string of a definition's type, e.g. "VARCHAR(255) UNSIGNED". */
export function typeLabel(d, prof) {
  const tr = typeTraits(d.type, prof);
  const len = tr.length && String(d.length ?? '') !== '' ? `(${d.length})` : '';
  return `${d.type || '?'}${len}${tr.unsigned && d.unsigned ? ' UNSIGNED' : ''}`;
}

/** Display string of a definition's default. */
export function defaultLabel(d) {
  if (d.auto_increment || d.generated) return '';
  if (d.default_kind === 'null') return 'NULL';
  if (d.default_kind === 'value') return `'${d.default ?? ''}'`;
  if (d.default_kind === 'expression') return String(d.default ?? '');
  return '';
}

/**
 * Validate a definition. Returns an error message or null.
 * @param {object} d
 * @param {object} prof
 * @param {string[]} [otherNames]  names that must not be reused
 */
export function validateDef(d, prof, otherNames = []) {
  return checkDef(d, prof, otherNames)?.message ?? null;
}

/** Like validateDef, but also names the offending field ('name' | 'type' | 'length' | 'default'). */
export function checkDef(d, prof, otherNames = []) {
  const name = String(d.name || '').trim();
  if (!name) return { message: t('structure.err.nameRequired'), field: 'name' };
  if (otherNames.includes(name)) return { message: t('structure.err.duplicateColumn', { name }), field: 'name' };
  if (!String(d.type || '').trim()) return { message: t('structure.err.typeRequired', { name }), field: 'type' };
  const tr = typeTraits(d.type, prof);
  if (tr.lengthRequired && !String(d.length ?? '').trim()) return { message: t('structure.err.lengthRequired', { name }), field: 'length' };
  if (d.default_kind === 'expression' && !d.auto_increment && !d.generated && !String(d.default ?? '').trim()) return { message: t('structure.err.expressionRequired', { name }), field: 'default' };
  return null;
}

/**
 * Show a dialog error and tie it to the offending control (aria-invalid + aria-describedby, focus),
 * clearing the previous link first. The control's own `input` event clears the flag again.
 */
function flagError(errorBox, message, el) {
  if (!errorBox.id) errorBox.id = uid('err');
  if (errorBox._flagged) unflag(errorBox);
  errorBox.textContent = message;
  errorBox.hidden = false;
  if (!el) return;
  el.setAttribute('aria-invalid', 'true');
  el.setAttribute('aria-describedby', [...(el.getAttribute('aria-describedby') || '').split(' ').filter(Boolean), errorBox.id].join(' '));
  errorBox._flagged = el;
  el.focus();
  const clear = () => { if (errorBox._flagged === el) unflag(errorBox); };
  el.addEventListener('input', clear, { once: true });
  el.addEventListener('change', clear, { once: true });
}

function unflag(errorBox) {
  const el = errorBox._flagged;
  errorBox._flagged = null;
  if (!el) return;
  el.removeAttribute('aria-invalid');
  const rest = (el.getAttribute('aria-describedby') || '').split(' ').filter((x) => x && x !== errorBox.id);
  if (rest.length) el.setAttribute('aria-describedby', rest.join(' ')); else el.removeAttribute('aria-describedby');
}

/* ------------------------------------------------------------------ type picker */

/**
 * Searchable, grouped type combobox (free text allowed for custom/user types). The list is a
 * top-layer popover so it is not clipped by scroll containers or dialogs.
 * @param {object} o
 * @param {string} o.value
 * @param {Record<string,string[]>} o.groups
 * @param {(v: string) => void} o.onChange
 * @param {string} [o.label]  accessible name
 * @param {string} [o.id]
 * @param {boolean} [o.small]
 * @returns {{el: HTMLElement, input: HTMLInputElement, setValue: (v: string) => void}}
 */
export function createTypePicker(o) {
  const listId = uid('tp');
  const flat = Object.entries(o.groups || {}).flatMap(([g, arr]) => (arr || []).map((ty) => ({ g, ty: String(ty) })));
  const input = h('input', {
    id: o.id, class: cls('input font-mono', o.small && 'input-sm'), value: o.value || '', role: 'combobox',
    'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': listId, 'aria-label': o.label,
    autocomplete: 'off', spellcheck: 'false', autocapitalize: 'off', dir: 'ltr', placeholder: t('structure.col.typePlaceholder'),
  });
  const list = h('div', { id: listId, role: 'listbox', class: 'popover overflow-auto p-1 text-[13px]', 'aria-label': o.label || t('structure.col.type') });
  const canPopover = typeof list.showPopover === 'function';
  if (canPopover) list.setAttribute('popover', 'manual');
  let open = false;
  let items = [];
  let active = -1;

  const commit = () => o.onChange?.(input.value.trim());

  function render() {
    const q = input.value.trim().toLowerCase();
    const exact = flat.some((x) => x.ty.toLowerCase() === q);
    items = !q || exact ? flat : flat.filter((x) => x.ty.toLowerCase().includes(q));
    if (q && !exact) items = [...items].sort((a, b) => Number(!a.ty.toLowerCase().startsWith(q)) - Number(!b.ty.toLowerCase().startsWith(q)));
    list.replaceChildren();
    let last = null;
    items.forEach((x, i) => {
      if (x.g !== last && (!q || exact)) {
        list.appendChild(h('div', { class: 'px-2 pb-0.5 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted', role: 'presentation' }, groupLabel(x.g)));
        last = x.g;
      }
      list.appendChild(h('div', {
        id: `${listId}-${i}`, role: 'option', 'aria-selected': String(i === active),
        class: cls('flex min-h-8 cursor-pointer items-center justify-between gap-3 rounded px-2 py-1 font-mono text-[12.5px]', i === active && 'bg-surface-2'),
        onPointerdown: (e) => { e.preventDefault(); choose(i); },
        onPointermove: () => { if (active !== i) setActive(i, false); },
      }, x.ty, q && !exact ? h('span', { class: 'font-sans text-[11px] text-muted' }, groupLabel(x.g)) : null));
    });
    if (!items.length) list.appendChild(h('div', { class: 'px-2 py-2 text-[12.5px] text-muted' }, t('structure.col.customType')));
  }

  function place() {
    const r = input.getBoundingClientRect();
    const w = Math.max(r.width, 220);
    const rtl = document.documentElement.dir === 'rtl';
    const left = Math.max(8, Math.min(rtl ? r.right - w : r.left, window.innerWidth - w - 8));
    const below = window.innerHeight - r.bottom - 12;
    const above = r.top - 12;
    const useBelow = below >= 180 || below >= above;
    const maxH = Math.max(120, Math.min(300, useBelow ? below : above));
    Object.assign(list.style, { position: 'fixed', margin: '0', inset: 'auto', zIndex: '95', width: `${w}px`, maxHeight: `${maxH}px`, left: `${left}px` });
    list.style.top = useBelow ? `${r.bottom + 4}px` : `${Math.max(8, r.top - 4 - Math.min(list.scrollHeight, maxH))}px`;
  }

  const onScroll = (e) => { if (!list.contains(e.target)) place(); };
  function show() {
    if (open) return;
    open = true;
    (input.closest('dialog') || document.body).appendChild(list);
    if (canPopover) { try { list.showPopover(); } catch { /* ignore */ } }
    active = -1;
    render();
    place();
    const cur = items.findIndex((x) => x.ty.toLowerCase() === input.value.trim().toLowerCase());
    if (cur >= 0) setActive(cur, true);
    input.setAttribute('aria-expanded', 'true');
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', hide);
  }
  function hide() {
    if (!open) return;
    open = false;
    if (canPopover) { try { list.hidePopover(); } catch { /* ignore */ } }
    list.remove();
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', hide);
  }
  function setActive(i, scroll = true) {
    active = i;
    list.querySelectorAll('[role=option]').forEach((el) => {
      const sel = el.id === `${listId}-${i}`;
      el.setAttribute('aria-selected', String(sel));
      el.classList.toggle('bg-surface-2', sel);
      if (sel && scroll) el.scrollIntoView({ block: 'nearest' });
    });
    if (i >= 0) input.setAttribute('aria-activedescendant', `${listId}-${i}`);
  }
  function choose(i) {
    const it = items[i];
    if (!it) return;
    input.value = it.ty;
    hide();
    commit();
  }

  input.addEventListener('input', () => {
    if (!open) show();
    render();
    place();
    setActive(items.length ? 0 : -1);
    commit();
  });
  input.addEventListener('click', () => (open ? hide() : show()));
  input.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== input) { hide(); commit(); } }, 120));
  input.addEventListener('keydown', (e) => {
    if (e.isComposing || e.keyCode === 229) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) show(); else setActive(Math.min(items.length - 1, active + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (open) setActive(Math.max(0, active - 1)); }
    else if (e.key === 'Enter' && open) { e.preventDefault(); if (active >= 0) choose(active); else { hide(); commit(); } }
    else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); hide(); }
    else if (e.key === 'Tab') hide();
  });

  return {
    el: input,
    input,
    setValue(v) { input.value = v || ''; },
  };
}

/* ------------------------------------------------------------------ small form helpers */

const field = (labelText, control, help, id) => h('div', {},
  h('label', { class: 'label', for: id || control.id }, labelText), control, help ? h('p', { class: 'help' }, help) : null);
const check = (labelText, input) => h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, input, labelText);
const option = (value, label, selected) => h('option', { value, selected: String(value) === String(selected ?? '') }, label);

/** <select> of default kinds. */
function defaultKindSelect(d, extra = {}) {
  return h('select', { class: 'select', ...extra },
    option('none', t('structure.default.none'), d.default_kind),
    option('null', 'NULL', d.default_kind),
    option('value', t('structure.default.value'), d.default_kind),
    option('expression', t('structure.default.expression'), d.default_kind));
}

/** Placeholder / label for the length field of a trait. */
const lengthLabel = (kind) => t(`structure.col.len.${kind || 'length'}`);
const lengthPlaceholder = (kind) => ({ length: '255', precision: '10,2', values: "'a','b'", fsp: '0–6' }[kind] || '');

/* ------------------------------------------------------------------ column dialog */

/**
 * Full column form in a dialog (full-screen sheet on phones).
 * @param {object} o
 * @param {string} o.title
 * @param {object} o.def        editable definition (not mutated; a copy is returned)
 * @param {object} o.meta       from loadMeta()
 * @param {string[]} [o.otherNames]  names in use by other columns
 * @param {{value: string, options: {value: string, label: string}[]}} [o.position]  "first" | "after:<col>" | ""
 * @param {string} [o.submitLabel]
 * @returns {Promise<object|null>} the edited definition (with `_position` when a position select was shown)
 */
export async function openColumnDialog(o) {
  const P = o.meta.prof;
  const d = { ...o.def };
  const id = (s) => `${baseId}-${s}`;
  const baseId = uid('col');
  const errorBox = h('div', { class: 'rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-[13px] text-danger', role: 'alert', hidden: true, dir: 'auto' });

  const nameIn = h('input', { id: id('name'), class: 'input font-mono', value: d.name, dir: 'auto', autocomplete: 'off', spellcheck: 'false', onInput: () => { d.name = nameIn.value; } });
  const picker = createTypePicker({ id: id('type'), value: d.type, groups: o.meta.types, label: t('structure.col.type'), onChange: (v) => { const was = d.type; d.type = v; if (v !== was) onTypeChange(was); } });
  const lenIn = h('input', { id: id('len'), class: 'input font-mono', value: d.length ?? '', dir: 'ltr', autocomplete: 'off', spellcheck: 'false', onInput: () => { d.length = lenIn.value; } });
  const lenLabel = h('label', { class: 'label', for: id('len') });
  const lenBox = h('div', {}, lenLabel, lenIn);
  const unsignedIn = h('input', { type: 'checkbox', class: 'checkbox', checked: d.unsigned, onChange: () => { d.unsigned = unsignedIn.checked; } });
  const unsignedBox = check('UNSIGNED', unsignedIn);
  const nullIn = h('input', { type: 'checkbox', class: 'checkbox', checked: d.nullable, onChange: () => { d.nullable = nullIn.checked; sync(); } });
  const aiIn = h('input', { type: 'checkbox', class: 'checkbox', checked: d.auto_increment, onChange: () => { d.auto_increment = aiIn.checked; sync(); } });
  const aiBox = check(t('structure.col.autoIncrement'), aiIn);
  const kindSel = defaultKindSelect(d, { id: id('dk'), onChange: () => { d.default_kind = kindSel.value; sync(); } });
  const defIn = h('input', { class: 'input font-mono', value: d.default ?? '', dir: 'auto', autocomplete: 'off', spellcheck: 'false', 'aria-label': t('structure.col.defaultValue'), onInput: () => { d.default = defIn.value; } });
  const defaultBox = h('div', {},
    h('label', { class: 'label', for: id('dk') }, t('structure.col.default')),
    h('div', { class: 'flex flex-col gap-2 sm:flex-row' }, h('div', { class: 'sm:w-40 sm:shrink-0' }, kindSel), h('div', { class: 'min-w-0 flex-1' }, defIn)));
  const onUpdIn = h('input', { type: 'checkbox', class: 'checkbox', checked: !!d.on_update, onChange: () => { d.on_update = onUpdIn.checked ? 'current_timestamp()' : ''; } });
  const onUpdBox = check('ON UPDATE CURRENT_TIMESTAMP', onUpdIn);
  const collSel = h('select', { id: id('coll'), class: 'select', onChange: () => { d.collation = collSel.value; } },
    option('', t('structure.col.collationDefault'), d.collation),
    [...new Set([...(d.collation ? [d.collation] : []), ...o.meta.collations])].map((c) => option(c, c, d.collation)));
  const collBox = field(t('structure.col.collation'), collSel);
  const commentIn = h('input', { id: id('cmt'), class: 'input', value: d.comment ?? '', dir: 'auto', autocomplete: 'off', onInput: () => { d.comment = commentIn.value; } });
  let posSel = null;
  if (o.position) {
    posSel = h('select', { id: id('pos'), class: 'select' }, o.position.options.map((op) => option(op.value, op.label, o.position.value)));
  }

  function onTypeChange(was) {
    d.length = lengthAfterTypeChange(d, was, P);
    lenIn.value = d.length;
    sync();
  }
  function sync() {
    const tr = typeTraits(d.type, P);
    lenBox.hidden = !tr.length;
    lenLabel.textContent = lengthLabel(tr.length);
    lenIn.placeholder = lengthPlaceholder(tr.length);
    unsignedBox.hidden = !tr.unsigned;
    aiBox.hidden = !tr.autoIncrement || !!d.generated;
    onUpdBox.hidden = !tr.onUpdate || !!d.generated;
    collBox.hidden = !tr.collation;
    if (!d.nullable && kindSel.value === 'null') { kindSel.value = 'none'; d.default_kind = 'none'; }
    kindSel.querySelector('option[value=null]').disabled = !d.nullable;
    defaultBox.hidden = (tr.autoIncrement && d.auto_increment) || !!d.generated || tr.serial;
    defIn.hidden = !(d.default_kind === 'value' || d.default_kind === 'expression');
    defIn.placeholder = d.default_kind === 'expression' ? 'CURRENT_TIMESTAMP' : '';
  }

  const body = h('div', { class: 'space-y-4' },
    h('div', { class: 'grid gap-4 sm:grid-cols-2' },
      field(t('structure.col.name'), nameIn),
      field(t('structure.col.type'), picker.el)),
    d.generated ? callout('info', h('span', {}, t('structure.col.generatedNote'), ' ', h('code', { class: 'font-mono text-xs', dir: 'ltr' }, d.generated))) : null,
    h('div', { class: 'grid gap-4 sm:grid-cols-2' }, lenBox, collBox),
    h('div', { class: 'flex flex-wrap gap-x-5 gap-y-1' }, check(t('structure.col.nullable'), nullIn), unsignedBox, aiBox, onUpdBox),
    defaultBox,
    P.comments ? field(t('structure.col.comment'), commentIn) : null,
    posSel ? field(t('structure.col.position'), posSel) : null,
    errorBox);
  sync();

  const submit = () => {
    const bad = checkDef(d, P, o.otherNames || []);
    if (bad) { flagError(errorBox, bad.message, { name: nameIn, type: picker.input, length: lenIn, default: defIn }[bad.field]); return; }
    errorBox.hidden = true;
    if (posSel) d._position = posSel.value;
    m.close(d);
  };
  body.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.getAttribute('role') !== 'combobox') { e.preventDefault(); submit(); } });
  const m = openModal({
    title: o.title,
    body,
    size: 'lg',
    initialFocus: o.def.name ? picker.input : nameIn,
    actions: [
      h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')),
      h('button', { type: 'button', class: 'btn btn-primary', onClick: submit }, o.submitLabel || t('common.save')),
    ],
  });
  return (await m.closed) || null;
}

/* ------------------------------------------------------------------ inline column rows */

/**
 * Inline editor rows for new columns (create-table and "add columns" in the structure view).
 * Desktop: one table row of inputs per column; phones: compact cards that open the full form
 * in a full-screen sheet.
 * @param {object} o
 * @param {object} o.meta
 * @param {'create'|'add'} o.mode
 * @param {object[]} [o.rows]  initial editable definitions
 * @param {() => string[]} [o.existingNames]  names of existing columns (add mode)
 * @param {() => {value: string, label: string}[]} [o.positionOptions]  add mode + driver supports positions
 * @param {() => void} [o.onChange]
 * @returns {{el: HTMLElement, add: (def?: object) => object, getDefs: () => object[], clear: () => void,
 *   validate: () => string|null, count: () => number, setRows: (rows: object[]) => void}}
 */
export function createColumnRows(o) {
  const P = o.meta.prof;
  let rows = o.rows ? [...o.rows] : [];
  const create = o.mode === 'create';
  const changed = () => o.onChange?.();
  const tbody = h('tbody');
  const cards = h('ul', { class: 'divide-y divide-border sm:hidden' });
  const table = h('div', { class: 'hidden overflow-x-auto sm:block' }, h('table', { class: 'table-grid min-w-[56rem]' },
    h('thead', {}, h('tr', {},
      create ? h('th', { class: 'w-14' }, h('span', { class: 'sr-only' }, t('structure.col.order'))) : null,
      h('th', {}, t('structure.col.name')),
      h('th', {}, t('structure.col.type')),
      h('th', {}, t('structure.col.length')),
      P.unsigned ? h('th', { title: 'UNSIGNED' }, 'UNSIGNED') : null,
      h('th', {}, t('structure.col.null')),
      h('th', {}, t('structure.col.default')),
      P.autoIncrement ? h('th', { title: t('structure.col.autoIncrement') }, 'A_I') : null,
      create ? h('th', { title: t('structure.col.primary') }, 'PK') : null,
      P.comments ? h('th', {}, t('structure.col.comment')) : null,
      !create && P.positions ? h('th', {}, t('structure.col.position')) : null,
      h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
    tbody));
  const el = h('div', {}, table, cards);

  const others = (d) => [...(o.existingNames ? o.existingNames() : []), ...rows.filter((r) => r !== d).map((r) => String(r.name || '').trim()).filter(Boolean)];

  function rowEl(d, i) {
    const tr = h('tr', { 'data-key': d._key });
    const traits = () => typeTraits(d.type, P);
    const cb = (key, label, onChange) => {
      const c = h('input', { type: 'checkbox', class: 'checkbox', checked: !!d[key], 'aria-label': `${label} (${d.name || i + 1})`, onChange: () => { d[key] = c.checked; onChange?.(); sync(); changed(); } });
      return c;
    };
    const name = h('input', { class: 'input input-sm w-40 font-mono', value: d.name, dir: 'auto', autocomplete: 'off', spellcheck: 'false', 'aria-label': t('structure.col.nameN', { n: i + 1 }), placeholder: t('structure.col.name'), onInput: () => { d.name = name.value; name.removeAttribute('aria-invalid'); renderCards(); changed(); } });
    const picker = createTypePicker({ value: d.type, groups: o.meta.types, small: true, label: t('structure.col.typeN', { n: i + 1 }), onChange: (v) => { if (v === d.type) return; const was = d.type; d.type = v; d.length = lengthAfterTypeChange(d, was, P); len.value = d.length; sync(); renderCards(); changed(); } });
    picker.input.classList.add('w-44');
    const len = h('input', { class: 'input input-sm w-24 font-mono', value: d.length ?? '', dir: 'ltr', autocomplete: 'off', 'aria-label': t('structure.col.lengthN', { n: i + 1 }), onInput: () => { d.length = len.value; changed(); } });
    const uns = P.unsigned ? cb('unsigned', 'UNSIGNED') : null;
    const nul = cb('nullable', t('structure.col.null'));
    const kind = defaultKindSelect(d, { class: 'select input-sm w-28', 'aria-label': t('structure.col.default'), onChange: () => { d.default_kind = kind.value; sync(); changed(); } });
    const defIn = h('input', { class: 'input input-sm w-32 font-mono', value: d.default ?? '', dir: 'auto', autocomplete: 'off', 'aria-label': t('structure.col.defaultValue'), onInput: () => { d.default = defIn.value; changed(); } });
    const ai = P.autoIncrement ? cb('auto_increment', t('structure.col.autoIncrement')) : null;
    const pk = create ? cb('_pk', t('structure.col.primary'), () => { if (d._pk) { d.nullable = false; nul.checked = false; } }) : null;
    const cmt = P.comments ? h('input', { class: 'input input-sm w-36', value: d.comment ?? '', dir: 'auto', autocomplete: 'off', 'aria-label': t('structure.col.comment'), onInput: () => { d.comment = cmt.value; changed(); } }) : null;
    let pos = null;
    if (!create && P.positions && o.positionOptions) {
      pos = h('select', { class: 'select input-sm w-36', 'aria-label': t('structure.col.position'), onChange: () => { d._position = pos.value; changed(); } },
        o.positionOptions().map((op) => option(op.value, op.label, d._position || '')));
    }
    const sync = () => {
      const tt = traits();
      len.disabled = !tt.length;
      len.placeholder = tt.length ? lengthPlaceholder(tt.length) : '—';
      if (uns) uns.disabled = !tt.unsigned;
      if (ai) ai.disabled = !tt.autoIncrement;
      if (!d.nullable && kind.value === 'null') { kind.value = 'none'; d.default_kind = 'none'; }
      kind.querySelector('option[value=null]').disabled = !d.nullable;
      const noDefault = (tt.autoIncrement && d.auto_increment) || tt.serial || !!d.generated;
      kind.disabled = noDefault;
      defIn.hidden = noDefault || !(d.default_kind === 'value' || d.default_kind === 'expression');
      defIn.placeholder = d.default_kind === 'expression' ? 'CURRENT_TIMESTAMP' : '';
    };
    sync();
    const mv = (delta) => { const j = i + delta; if (j < 0 || j >= rows.length) return; [rows[i], rows[j]] = [rows[j], rows[i]]; render(); changed(); tbody.querySelector(`[data-key="${d._key}"] [data-move="${delta}"]`)?.focus(); };
    setChildren(tr,
      create ? h('td', { class: 'whitespace-nowrap' },
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'data-move': '-1', disabled: i === 0, 'aria-label': t('structure.moveUp', { name: d.name || i + 1 }), onClick: () => mv(-1) }, icon('arrow-up')),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'data-move': '1', disabled: i === rows.length - 1, 'aria-label': t('structure.moveDown', { name: d.name || i + 1 }), onClick: () => mv(1) }, icon('arrow-down'))) : null,
      h('td', {}, name),
      h('td', {}, picker.el),
      h('td', {}, len),
      P.unsigned ? h('td', { class: 'text-center' }, uns) : null,
      h('td', { class: 'text-center' }, nul),
      h('td', {}, h('div', { class: 'flex items-center gap-1.5' }, kind, defIn)),
      P.autoIncrement ? h('td', { class: 'text-center' }, ai) : null,
      create ? h('td', { class: 'text-center' }, pk) : null,
      P.comments ? h('td', {}, cmt) : null,
      pos ? h('td', {}, pos) : null,
      h('td', { class: 'whitespace-nowrap text-end' },
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('structure.col.moreOptions'), 'aria-label': t('structure.col.moreOptionsFor', { name: d.name || i + 1 }), onClick: () => editInDialog(d) }, icon('sliders')),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', title: t('structure.col.remove'), 'aria-label': t('structure.col.removeNamed', { name: d.name || i + 1 }), onClick: () => remove(d) }, icon('x'))));
    return tr;
  }

  function cardEl(d, i) {
    return h('li', { class: 'flex items-center gap-2 py-2' },
      h('button', { type: 'button', class: 'flex min-h-10 min-w-0 flex-1 flex-col items-start text-start', onClick: () => editInDialog(d) },
        h('span', { class: cls('truncate font-mono text-[13px] font-medium', !d.name && 'text-danger'), dir: 'auto' }, d.name || t('structure.col.unnamed', { n: i + 1 })),
        h('span', { class: 'truncate font-mono text-xs text-muted', dir: 'ltr' },
          [typeLabel(d, P), d.nullable ? 'NULL' : 'NOT NULL', d.auto_increment ? 'AUTO_INCREMENT' : '', d._pk ? 'PK' : '', defaultLabel(d) ? `= ${defaultLabel(d)}` : ''].filter(Boolean).join(' · '))),
      create ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', disabled: i === 0, 'aria-label': t('structure.moveUp', { name: d.name || i + 1 }), onClick: () => { [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]]; render(); changed(); } }, icon('arrow-up')) : null,
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('structure.col.removeNamed', { name: d.name || i + 1 }), onClick: () => remove(d) }, icon('x')));
  }

  function renderCards() {
    cards.replaceChildren(...rows.map(cardEl));
  }
  function render() {
    tbody.replaceChildren(...rows.map(rowEl));
    renderCards();
    el.hidden = !rows.length;
  }
  function remove(d) {
    rows = rows.filter((r) => r !== d);
    render();
    changed();
  }
  async function editInDialog(d) {
    const res = await openColumnDialog({
      title: d.name ? t('structure.col.editTitle', { name: d.name }) : t('structure.col.newTitle'),
      def: d, meta: o.meta, otherNames: others(d),
      position: !create && P.positions && o.positionOptions ? { value: d._position || '', options: o.positionOptions() } : undefined,
    });
    if (!res) return;
    if (res._position === undefined) delete res._position;
    Object.assign(d, res);
    if (create && d._pk) d.nullable = false;
    render();
    changed();
  }

  render();
  return {
    el,
    add(def) {
      const d = def || blankColumn(P);
      rows.push(d);
      render();
      changed();
      requestAnimationFrame(() => tbody.querySelector(`[data-key="${d._key}"] input`)?.focus());
      return d;
    },
    /** Add via the full form (used on phones). */
    async addViaDialog() {
      const d = blankColumn(P);
      const res = await openColumnDialog({
        title: t('structure.col.newTitle'), def: d, meta: o.meta, otherNames: others(d), submitLabel: t('structure.col.add'),
        position: !create && P.positions && o.positionOptions ? { value: '', options: o.positionOptions() } : undefined,
      });
      if (!res) return null;
      rows.push(res);
      render();
      changed();
      return res;
    },
    getDefs: () => rows,
    setRows(r) { rows = [...r]; render(); },
    clear() { rows = []; render(); },
    count: () => rows.length,
    validate() {
      for (const d of rows) {
        const err = validateDef(d, P, others(d));
        if (err) {
          const inp = tbody.querySelector(`[data-key="${d._key}"] input`);
          if (inp && !String(d.name || '').trim()) inp.setAttribute('aria-invalid', 'true');
          return err;
        }
      }
      return null;
    },
  };
}

/* ------------------------------------------------------------------ ordered column picker */

/**
 * Ordered multi-column picker (index / FK columns): chosen list with move/remove and optional
 * prefix length, plus an "add column" select.
 * @param {object} o
 * @param {string[]} o.available
 * @param {{name: string, prefix?: string}[]} [o.value]
 * @param {boolean} [o.prefix]  show prefix length inputs (MySQL)
 * @param {string} o.label
 * @param {() => void} [o.onChange]
 */
export function createColumnPicker(o) {
  let chosen = (o.value || []).map((c) => ({ ...c }));
  const list = h('ol', { class: 'space-y-1.5', 'aria-label': o.label });
  const addSel = h('select', { class: 'select', 'aria-label': t('structure.idx.addColumn'), onChange: () => { if (addSel.value) { chosen.push({ name: addSel.value, prefix: '' }); render(); o.onChange?.(); } } });
  const el = h('div', { class: 'space-y-2' }, list, addSel);
  function render() {
    list.replaceChildren(...chosen.map((c, i) => h('li', { class: 'flex items-center gap-1.5 rounded-lg border border-border bg-surface-2/50 px-2 py-1' },
      h('span', { class: 'w-5 text-center text-xs tabular-nums text-muted' }, String(i + 1)),
      h('span', { class: 'min-w-0 flex-1 truncate font-mono text-[13px]', dir: 'auto' }, c.name),
      o.prefix ? h('input', { class: 'input input-sm w-20 font-mono', value: c.prefix || '', inputmode: 'numeric', placeholder: t('structure.idx.prefix'), 'aria-label': t('structure.idx.prefixFor', { name: c.name }), onInput: (e) => { c.prefix = e.target.value.replace(/\D/g, ''); o.onChange?.(); } }) : null,
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', disabled: i === 0, 'aria-label': t('structure.moveUp', { name: c.name }), onClick: () => { [chosen[i - 1], chosen[i]] = [chosen[i], chosen[i - 1]]; render(); o.onChange?.(); } }, icon('arrow-up')),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', disabled: i === chosen.length - 1, 'aria-label': t('structure.moveDown', { name: c.name }), onClick: () => { [chosen[i + 1], chosen[i]] = [chosen[i], chosen[i + 1]]; render(); o.onChange?.(); } }, icon('arrow-down')),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('structure.col.removeNamed', { name: c.name }), onClick: () => { chosen.splice(i, 1); render(); o.onChange?.(); } }, icon('x')))));
    if (!chosen.length) list.appendChild(h('li', { class: 'text-[13px] text-muted' }, t('structure.idx.noColumns')));
    const rest = o.available.filter((n) => !chosen.some((c) => c.name === n));
    addSel.replaceChildren(h('option', { value: '' }, t('structure.idx.addColumn')), ...rest.map((n) => h('option', { value: n }, n)));
    addSel.value = '';
    addSel.hidden = !rest.length;
  }
  render();
  return { el, get: () => chosen.map((c) => ({ ...c })), focus: () => addSel.focus(), control: addSel };
}

/* ------------------------------------------------------------------ index dialog */

/**
 * Ask for a new index.
 * @param {object} o
 * @param {object} o.meta
 * @param {string} o.table
 * @param {string[]} o.columns     available column names
 * @param {string[]} [o.names]     index names already used
 * @param {boolean} [o.hasPrimary] hide PRIMARY KEY
 * @returns {Promise<{name: string|null, columns: string[], unique: boolean, primary?: boolean, type?: string}|null>}
 */
export async function openIndexDialog(o) {
  const P = o.meta.prof;
  const kinds = P.indexKinds.filter((k) => !(k === 'primary' && o.hasPrimary));
  const errorBox = h('div', { class: 'rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-[13px] text-danger', role: 'alert', hidden: true });
  const kindId = uid('ik');
  const nameId = uid('in');
  const kindSel = h('select', { id: kindId, class: 'select', onChange: () => { nameBox.hidden = kindSel.value === 'primary'; suggest(); } },
    kinds.map((k) => option(k, t(`structure.idx.kind.${k}`), 'index')));
  let nameTouched = false;
  const nameIn = h('input', { id: nameId, class: 'input font-mono', dir: 'auto', autocomplete: 'off', spellcheck: 'false', onInput: () => { nameTouched = true; } });
  const nameBox = field(t('structure.idx.name'), nameIn, null, nameId);
  const picker = createColumnPicker({ available: o.columns, prefix: P.prefix, label: t('structure.idx.columns'), onChange: () => suggest() });
  function suggest() {
    if (nameTouched) return;
    const cols = picker.get().map((c) => c.name);
    const prefix = kindSel.value === 'unique' ? 'uq' : kindSel.value === 'fulltext' ? 'ft' : kindSel.value === 'spatial' ? 'sp' : 'idx';
    nameIn.value = cols.length ? `${prefix}_${P.driver === 'mysql' ? '' : `${o.table}_`}${cols.join('_')}`.slice(0, 60) : '';
  }
  const submit = () => {
    const cols = picker.get();
    const kind = kindSel.value;
    const name = nameIn.value.trim();
    let err = null;
    let bad = null;
    if (!cols.length) { err = t('structure.err.indexColumns'); bad = picker; }
    else if (kind !== 'primary' && name && (o.names || []).includes(name)) { err = t('structure.err.duplicateIndex', { name }); bad = nameIn; }
    if (err) { flagError(errorBox, err, bad === picker ? picker.control : bad); return; }
    m.close({
      name: kind === 'primary' ? (P.driver === 'mysql' ? 'PRIMARY' : null) : (name || null),
      columns: cols.map((c) => (c.prefix ? `${c.name}(${c.prefix})` : c.name)),
      unique: kind === 'unique' || kind === 'primary',
      ...(kind === 'primary' ? { primary: true } : {}),
      ...(kind === 'fulltext' || kind === 'spatial' ? { type: kind.toUpperCase() } : {}),
    });
  };
  const m = openModal({
    title: t('structure.idx.addTitle'),
    size: 'md',
    body: h('div', { class: 'space-y-4' },
      field(t('structure.idx.kind'), kindSel, null, kindId),
      nameBox,
      h('div', {}, h('div', { class: 'label' }, t('structure.idx.columns')), picker.el, P.prefix ? h('p', { class: 'help' }, t('structure.idx.prefixHelp')) : null),
      errorBox),
    actions: [
      h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')),
      h('button', { type: 'button', class: 'btn btn-primary', onClick: submit }, t('structure.idx.add')),
    ],
  });
  return (await m.closed) || null;
}

/* ------------------------------------------------------------------ foreign key dialog */

const FK_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];

/**
 * Ask for a new foreign key. Referenced columns are loaded from table.info of the target table.
 * @param {object} o
 * @param {object} o.meta
 * @param {{conn: string, db: string, schema?: string}} o.base
 * @param {string} o.table          the referencing table (new table name in create mode)
 * @param {string[]} o.columns      local column names
 * @param {string[]} o.tables       candidate referenced tables
 * @param {string[]} [o.names]      constraint names already used
 * @param {string[]} [o.selfColumns]  columns of the table itself when it is not created yet
 * @param {object} [o.value]        prefill (ForeignKey; ref_columns omitted = referenced primary key)
 * @param {string} [o.title]
 * @param {string} [o.submitLabel]
 * @param {Node} [o.notice]         shown above the form (e.g. a type warning)
 * @returns {Promise<object|null>} ForeignKey
 */
export async function openFkDialog(o) {
  const errorBox = h('div', { class: 'rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-[13px] text-danger', role: 'alert', hidden: true, dir: 'auto' });
  const ids = { name: uid('fk'), table: uid('fk'), del: uid('fk'), upd: uid('fk') };
  let nameTouched = false;
  let refCols = [];
  let refPk = [];
  const pre = o.value || null;
  const nameIn = h('input', { id: ids.name, value: pre?.name || '', class: 'input font-mono', dir: 'auto', autocomplete: 'off', spellcheck: 'false', onInput: () => { nameTouched = true; } });
  const tables = [...new Set([...(o.selfColumns ? [o.table] : []), ...o.tables])].filter(Boolean);
  const tableSel = h('select', { id: ids.table, class: 'select', onChange: () => loadRef() },
    h('option', { value: '' }, t('structure.fk.chooseTable')), tables.map((n) => h('option', { value: n }, n)));
  const mapBox = h('div', { class: 'space-y-1.5' });
  const picker = createColumnPicker({ available: o.columns, value: (pre?.columns || []).map((name) => ({ name })), label: t('structure.fk.columns'), onChange: () => { drawMap(); suggest(); } });
  const actionSel = (id, v) => h('select', { id, class: 'select' }, h('option', { value: '' }, t('structure.fk.actionDefault')), [...new Set([...FK_ACTIONS, ...(v ? [String(v).toUpperCase()] : [])])].map((a) => option(a, a, String(v || '').toUpperCase())));
  const delSel = actionSel(ids.del, pre?.on_delete);
  const updSel = actionSel(ids.upd, pre?.on_update);
  const mapSels = [];

  function suggest() {
    if (nameTouched) return;
    const cols = picker.get().map((c) => c.name);
    nameIn.value = cols.length ? `fk_${o.table}_${cols.join('_')}`.slice(0, 60) : '';
  }
  function drawMap() {
    const cols = picker.get();
    mapSels.length = 0;
    if (!tableSel.value || !cols.length) { mapBox.replaceChildren(h('p', { class: 'text-[13px] text-muted' }, t('structure.fk.mapHint'))); return; }
    mapBox.replaceChildren(...cols.map((c, i) => {
      const sel = h('select', { class: 'select input-sm', 'aria-label': t('structure.fk.refColumnFor', { name: c.name }) },
        refCols.map((rc) => option(rc, rc, [pre?.ref_columns?.[i], refPk[i], c.name].find((x) => x && refCols.includes(x)) || refCols[0])));
      mapSels.push(sel);
      return h('div', { class: 'grid grid-cols-[1fr_auto_1fr] items-center gap-2' },
        h('span', { class: 'truncate font-mono text-[13px]', dir: 'auto' }, c.name), icon('arrow-right', 'text-muted'), sel);
    }));
  }
  async function loadRef() {
    const name = tableSel.value;
    refCols = [];
    refPk = [];
    if (name && o.selfColumns && name === o.table) {
      refCols = o.selfColumns;
    } else if (name) {
      mapBox.replaceChildren(h('p', { class: 'text-[13px] text-muted' }, t('common.loading')));
      try {
        const info = await getTableInfo(o.base.conn, o.base.db, o.base.schema, name);
        refCols = info.columns.map((c) => c.name);
        refPk = (info.indexes.find((x) => x.primary)?.columns || info.columns.filter((c) => c.primary).map((c) => c.name));
      } catch (e) {
        mapBox.replaceChildren(callout('danger', e.message));
        return;
      }
    }
    drawMap();
  }
  if (pre?.ref_table && tables.includes(pre.ref_table)) { tableSel.value = pre.ref_table; loadRef(); } else drawMap();
  if (pre && !pre.name) suggest();
  if (pre?.name) nameTouched = true;

  const submit = () => {
    const cols = picker.get().map((c) => c.name);
    let err = null;
    let bad = null;
    if (!cols.length) { err = t('structure.err.fkColumns'); bad = picker.control; }
    else if (!tableSel.value) { err = t('structure.err.fkTable'); bad = tableSel; }
    else if (mapSels.length !== cols.length) { err = t('structure.err.fkColumns'); bad = picker.control; }
    const name = nameIn.value.trim();
    if (!err && name && (o.names || []).includes(name)) { err = t('structure.err.duplicateFk', { name }); bad = nameIn; }
    if (err) { flagError(errorBox, err, bad); return; }
    m.close({
      name: name || null, columns: cols, ref_schema: o.base.schema || null, ref_table: tableSel.value,
      ref_columns: mapSels.map((s) => s.value), on_delete: delSel.value || null, on_update: updSel.value || null,
    });
  };
  const m = openModal({
    title: o.title || t('structure.fk.addTitle'),
    size: 'lg',
    body: h('div', { class: 'space-y-4' },
      o.notice || null,
      field(t('structure.fk.name'), nameIn, null, ids.name),
      h('div', { class: 'grid gap-4 sm:grid-cols-2' },
        h('div', {}, h('div', { class: 'label' }, t('structure.fk.columns')), picker.el),
        h('div', { class: 'space-y-3' }, field(t('structure.fk.refTable'), tableSel, null, ids.table), h('div', {}, h('div', { class: 'label' }, t('structure.fk.refColumns')), mapBox))),
      h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('ON DELETE', delSel, null, ids.del), field('ON UPDATE', updSel, null, ids.upd)),
      errorBox),
    actions: [
      h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')),
      h('button', { type: 'button', class: 'btn btn-primary', onClick: submit }, o.submitLabel || t('structure.fk.add')),
    ],
  });
  return (await m.closed) || null;
}

/* ------------------------------------------------------------------ SQL review */

/** Highlighted, read-only SQL block for a list of statements. */
export function sqlBlock(statements, extraClass = '') {
  const text = (statements || []).map((s) => `${String(s).replace(/;\s*$/, '')};`).join('\n\n');
  return h('pre', { class: cls('overflow-auto rounded-lg border border-border bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap break-words', extraClass), dir: 'ltr', tabindex: '0' }, highlightSql(text));
}

/**
 * Show generated SQL for review and execute it on confirm. Errors are shown inside the dialog
 * (it stays open so the user can copy the SQL or cancel).
 * @param {object} o
 * @param {string} o.title
 * @param {string[]} o.sql
 * @param {() => Promise<any>} o.execute
 * @param {string} [o.confirmLabel]
 * @param {boolean} [o.danger]  red confirm button; omitted = auto (statements contain DROP, with a warning)
 * @param {Array<Node|null>} [o.notes]  extra callouts shown above the SQL (e.g. warnings of a plan)
 * @returns {Promise<any>} execute() result, or null when cancelled
 */
export async function reviewSqlDialog(o) {
  const n = o.sql?.length || 0;
  const autoDanger = o.danger === undefined;
  const danger = autoDanger ? (o.sql || []).some((s) => /\bDROP\b/i.test(s)) : !!o.danger;
  const errorBox = h('div', { hidden: true });
  const text = (o.sql || []).map((s) => `${String(s).replace(/;\s*$/, '')};`).join('\n\n');
  const runBtn = h('button', { type: 'button', class: cls('btn', danger ? 'btn-danger' : 'btn-primary'), disabled: !n, onClick: () => run() }, icon('play'), o.confirmLabel || t('structure.review.execute'));
  const m = openModal({
    title: o.title,
    description: n ? plural('structure.review.statements', n) : t('structure.review.nothing'),
    size: 'xl',
    body: h('div', { class: 'space-y-3' },
      danger && autoDanger ? callout('warning', t('structure.review.dropWarning')) : null,
      ...(o.notes || []),
      n ? sqlBlock(o.sql, 'max-h-[55dvh]') : null,
      errorBox),
    actions: [
      h('button', { type: 'button', class: 'btn btn-ghost me-auto', disabled: !n, onClick: () => copyText(text).then(() => toast.success(t('common.copied'))) }, icon('copy'), h('span', { class: 'max-sm:sr-only' }, t('structure.review.copy'))),
      h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')),
      runBtn,
    ],
    initialFocus: runBtn,
  });
  async function run() {
    runBtn.disabled = true;
    errorBox.hidden = true;
    try {
      const res = await o.execute();
      m.close(res ?? true);
    } catch (e) {
      errorBox.replaceChildren(callout('danger', h('div', {}, h('div', { class: 'font-medium' }, t('structure.review.failed')), h('div', { class: 'mt-1 break-words' }, e?.message || String(e)),
        e?.sql ? h('pre', { class: 'mt-2 overflow-auto font-mono text-xs', dir: 'ltr' }, e.sql) : null)));
      errorBox.hidden = false;
      runBtn.disabled = false;
    }
  }
  return (await m.closed) ?? null;
}

/**
 * Preview an AlterSpec (table.alter preview:true), review it and execute on confirm.
 * @param {{conn: string, db: string, schema?: string, table: string}} base
 * @param {object} changes  AlterSpec
 * @param {string} [title]
 * @returns {Promise<boolean>} true when executed
 */
export async function previewAndAlter(base, changes, title) {
  const prev = await api('table.alter', { ...base, changes, preview: true });
  const res = await reviewSqlDialog({
    title: title || t('structure.review.title', { table: base.table }),
    sql: prev.sql || [],
    execute: () => api('table.alter', { ...base, changes }),
  });
  return !!res;
}
