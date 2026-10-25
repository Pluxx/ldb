/**
 * Row insert/edit form generated from TableInfo columns: type-appropriate inputs, NULL toggle,
 * DEFAULT handling, enum/set selects and a searchable foreign-key value picker.
 * Opens as a dialog (full-screen sheet on phones).
 * @module ui/rowform
 */
import { h, cls, uid, debounce } from './dom.js';
import { icon } from './icons.js';
import { openModal } from './modal.js';
import { t } from '../i18n/index.js';

const INT = /^(tiny|small|medium|big)?int|^integer|^serial|^bigserial|^year/i;
const DEC = /^dec|^numeric|^float|^double|^real|^money/i;
const TEXT = /text|clob|^json|^xml|blob|^long/i;

function enumValues(fullType) {
  const m = /^(?:enum|set)\s*\((.*)\)\s*$/is.exec(fullType || '');
  if (!m) return null;
  const out = [];
  const re = /'((?:[^']|'')*)'/g;
  let x;
  while ((x = re.exec(m[1]))) out.push(x[1].replace(/''/g, "'"));
  return out;
}

/** Map a column to an input descriptor. */
function inputKind(col) {
  const type = String(col.type || '').toLowerCase();
  const full = String(col.full_type || col.type || '');
  if (/^enum/i.test(full)) return { kind: 'enum', options: enumValues(full) || [] };
  if (/^set/i.test(full)) return { kind: 'set', options: enumValues(full) || [] };
  if (/^(bool|boolean)$/.test(type) || /^tinyint\(1\)/i.test(full) || type === 'bit') return { kind: 'bool' };
  if (INT.test(type)) return { kind: 'number', step: '1' };
  if (DEC.test(type)) return { kind: 'decimal' };
  if (type === 'date') return { kind: 'date' };
  if (/^(datetime|timestamp)/.test(type)) return { kind: 'datetime' };
  if (/^time/.test(type)) return { kind: 'time' };
  if (/json/.test(type)) return { kind: 'json' };
  if (TEXT.test(type)) return { kind: 'textarea' };
  return { kind: 'text' };
}

/**
 * Open the row form.
 * @param {object} o
 * @param {'insert'|'edit'|'duplicate'} o.mode
 * @param {string} o.table
 * @param {object[]} o.columns  TableInfo.columns (Column shape)
 * @param {object[]} [o.foreignKeys]  TableInfo.foreign_keys
 * @param {Record<string, any>} [o.values]  current values (edit/duplicate)
 * @param {(column: string, term: string) => Promise<{value: any, label: string}[]>} [o.fkSearch]
 * @param {string} [o.submitLabel]  primary button label (default: Save / Insert)
 * @param {(values: Record<string, any>) => Promise<any>} o.onSubmit  receives only the columns to
 *   write (insert: non-default fields; edit: changed fields). Throw to keep the form open.
 * @returns {Promise<any>} result of onSubmit, or null when cancelled
 */
export async function openRowForm(o) {
  const isEdit = o.mode === 'edit';
  const fkByCol = {};
  (o.foreignKeys || []).forEach((fk) => { if (fk.columns.length === 1) fkByCol[fk.columns[0]] = fk; });
  const fields = [];
  const form = h('form', { class: 'grid gap-4', novalidate: true, onSubmit: (e) => { e.preventDefault(); submit(); } });

  for (const col of o.columns) {
    const id = uid('rf');
    const k = inputKind(col);
    const orig = o.values ? o.values[col.name] : undefined;
    const isBinary = orig && typeof orig === 'object' && orig.$binary !== undefined;
    const skipDefault = !isEdit && (col.auto_increment || (o.mode === 'insert' && col.default_kind && col.default_kind !== 'none'));
    let start = orig;
    if (o.mode === 'duplicate' && (col.auto_increment)) start = undefined;
    const startStr = start === null || start === undefined ? '' : typeof start === 'object' ? (isBinary ? '' : JSON.stringify(start)) : String(start);

    let input;
    const common = { id, name: col.name, 'aria-describedby': `${id}-h`, disabled: isBinary };
    switch (k.kind) {
      case 'enum':
        input = h('select', { ...common, class: 'select' },
          h('option', { value: '' }, '—'),
          k.options.map((v) => h('option', { value: v, selected: v === startStr }, v)));
        break;
      case 'set': {
        const cur = new Set(startStr ? startStr.split(',') : []);
        input = h('select', { ...common, class: 'select h-auto py-1', multiple: true, size: String(Math.min(5, k.options.length)) },
          k.options.map((v) => h('option', { value: v, selected: cur.has(v) }, v)));
        break;
      }
      case 'bool':
        input = h('select', { ...common, class: 'select' },
          h('option', { value: '' }, '—'),
          h('option', { value: '1', selected: startStr === '1' || startStr === 'true' }, t('rowform.true')),
          h('option', { value: '0', selected: startStr === '0' || startStr === 'false' }, t('rowform.false')));
        break;
      case 'textarea':
      case 'json':
        input = h('textarea', { ...common, class: cls('textarea', k.kind === 'json' && 'font-mono text-[12.5px]'), rows: k.kind === 'json' ? 6 : 3, dir: k.kind === 'json' ? 'ltr' : 'auto', spellcheck: 'false' });
        input.value = k.kind === 'json' && startStr ? prettyJson(startStr) : startStr;
        break;
      case 'date':
        input = h('input', { ...common, class: 'input', type: 'date', value: startStr.slice(0, 10) });
        break;
      case 'datetime':
        input = h('input', { ...common, class: 'input', type: 'datetime-local', step: '1', value: startStr.replace(' ', 'T').slice(0, 19) });
        break;
      case 'time':
        input = h('input', { ...common, class: 'input', type: 'time', step: '1', value: startStr });
        break;
      case 'number':
        input = h('input', { ...common, class: 'input font-mono', type: 'text', inputmode: 'numeric', value: startStr, dir: 'ltr' });
        break;
      case 'decimal':
        input = h('input', { ...common, class: 'input font-mono', type: 'text', inputmode: 'decimal', value: startStr, dir: 'ltr' });
        break;
      default:
        input = h('input', { ...common, class: 'input', type: 'text', value: startStr, dir: 'auto', spellcheck: 'false' });
    }
    if (skipDefault && !startStr) input.placeholder = col.auto_increment ? t('rowform.auto') : `${t('rowform.default')}${col.default !== null && col.default !== undefined ? `: ${col.default}` : ''}`;

    const nullBox = col.nullable ? h('input', { type: 'checkbox', class: 'checkbox', checked: start === null && o.mode !== 'insert', 'aria-label': t('rowform.nullFor', { column: col.name }) }) : null;
    const syncNull = () => { if (nullBox) { input.disabled = nullBox.checked || isBinary; input.classList.toggle('opacity-50', nullBox.checked); } };
    nullBox?.addEventListener('change', () => { syncNull(); if (!nullBox.checked) input.focus(); });
    syncNull();

    let picker = null;
    const fk = fkByCol[col.name];
    if (fk && o.fkSearch && ['text', 'number', 'decimal'].includes(k.kind)) picker = fkPicker(input, col.name, fk, o.fkSearch);

    const dirty = { value: false };
    input.addEventListener('input', () => { dirty.value = true; });
    input.addEventListener('change', () => { dirty.value = true; });
    nullBox?.addEventListener('change', () => { dirty.value = true; });

    fields.push({ col, input, nullBox, kind: k.kind, orig: start, origStr: startStr, skipDefault, dirty, isBinary });
    form.appendChild(h('div', { class: 'grid gap-1' },
      h('div', { class: 'flex items-center gap-2' },
        h('label', { for: id, class: 'label mb-0 min-w-0 flex-1 truncate', dir: 'auto' },
          col.primary ? icon('key-round', 'me-1 inline size-3 text-warning') : null, col.name),
        nullBox ? h('label', { class: 'flex items-center gap-1.5 text-xs text-muted' }, nullBox, 'NULL') : null),
      h('div', { class: 'relative' }, input, picker),
      h('p', { id: `${id}-h`, class: 'help font-mono', dir: 'ltr' },
        (col.full_type || col.type || '').toLowerCase(),
        col.auto_increment ? ' · auto_increment' : '',
        fk ? ` → ${fk.ref_table}.${fk.ref_columns[0]}` : '',
        isBinary ? ` · ${t('rowform.binaryReadonly')}` : '',
        col.comment ? ` · ${col.comment}` : '')));
  }

  const errorBox = h('div', { class: 'rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-[13px] text-danger', role: 'alert', hidden: true, dir: 'auto' });
  form.prepend(errorBox);
  form.appendChild(h('button', { type: 'submit', hidden: true, tabindex: '-1' }));

  const titles = { insert: 'rowform.titleInsert', edit: 'rowform.titleEdit', duplicate: 'rowform.titleDuplicate' };
  const submitBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => submit() }, o.submitLabel || (isEdit ? t('common.save') : t('rowform.insert')));
  const m = openModal({
    title: t(titles[o.mode], { table: o.table }),
    body: form,
    size: 'lg',
    actions: [h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')), submitBtn],
  });

  function readField(f) {
    if (f.nullBox?.checked) return null;
    if (f.kind === 'set') return [...f.input.selectedOptions].map((x) => x.value).join(',');
    let v = f.input.value;
    if (f.kind === 'datetime' && v) v = v.replace('T', ' ');
    return v;
  }

  async function submit() {
    const values = {};
    for (const f of fields) {
      if (f.isBinary) continue;
      const v = readField(f);
      if (isEdit) {
        const origNull = f.orig === null;
        const changed = v === null ? !origNull : (origNull || v !== f.origStr);
        if (changed && f.dirty.value) values[f.col.name] = v;
      } else {
        // empty + (auto_increment | has a DEFAULT) → omit so the database fills it in
        if (v === '' && (f.skipDefault || (f.col.default_kind && f.col.default_kind !== 'none'))) continue;
        // empty non-text field without default: NULL when allowed, otherwise let the DB complain
        if (v === '' && !['text', 'textarea'].includes(f.kind) && f.col.nullable) { values[f.col.name] = null; continue; }
        values[f.col.name] = v;
      }
    }
    if (isEdit && !Object.keys(values).length) { m.close(null); return; }
    errorBox.hidden = true;
    submitBtn.disabled = true;
    try {
      const res = await o.onSubmit(values);
      m.close(res === undefined ? values : res);
    } catch (e) {
      errorBox.textContent = e?.message || String(e);
      errorBox.hidden = false;
      errorBox.scrollIntoView({ block: 'nearest' });
    } finally {
      submitBtn.disabled = false;
    }
  }

  return (await m.closed) ?? null;
}

function prettyJson(s) {
  try { return JSON.stringify(JSON.parse(s), null, 2); } catch { return s; }
}

/** Combobox-style suggestions under an input, fed by table.fkValues. */
function fkPicker(input, column, fk, search) {
  const listId = uid('fk');
  const list = h('div', { id: listId, role: 'listbox', class: 'popover absolute inset-x-0 top-full z-10 mt-1 max-h-56 overflow-auto p-1', hidden: true });
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', listId);
  input.setAttribute('aria-expanded', 'false');
  let items = [];
  let idx = -1;
  let seq = 0;
  const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); idx = -1; };
  const choose = (it) => { input.value = String(it.value); input.dispatchEvent(new Event('input', { bubbles: true })); close(); };
  const render = () => {
    list.replaceChildren(...(items.length ? items.map((it, i) => h('div', {
      role: 'option', id: `${listId}-${i}`, 'aria-selected': String(i === idx),
      class: cls('flex cursor-pointer items-center gap-3 rounded px-2 py-1.5 text-[13px]', i === idx && 'bg-surface-2'),
      onPointerdown: (e) => { e.preventDefault(); choose(it); },
    }, h('span', { class: 'font-mono text-xs text-muted', dir: 'ltr' }, String(it.value)), h('span', { class: 'min-w-0 flex-1 truncate', dir: 'auto' }, it.label ?? ''))) : [h('div', { class: 'px-2 py-1.5 text-[13px] text-muted' }, t('rowform.fkNone'))]));
    if (idx >= 0) input.setAttribute('aria-activedescendant', `${listId}-${idx}`); else input.removeAttribute('aria-activedescendant');
  };
  const load = debounce(async () => {
    const my = ++seq;
    try {
      const res = await search(column, input.value);
      if (my !== seq) return;
      items = res || [];
      idx = -1;
      render();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    } catch { close(); }
  }, 200);
  input.addEventListener('focus', load);
  input.addEventListener('input', load);
  input.addEventListener('blur', () => setTimeout(close, 120));
  input.addEventListener('keydown', (e) => {
    if (list.hidden) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); render(); }
    else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); choose(items[idx]); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
  });
  const btn = h('span', { class: 'pointer-events-none absolute end-2 top-2 text-subtle', title: t('rowform.fkHint', { table: fk.ref_table }) }, icon('link', 'size-4'));
  return h('span', {}, list, btn);
}
