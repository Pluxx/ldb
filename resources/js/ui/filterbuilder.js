/**
 * Filter builder for table browsing: rows of [column] [operator] [value(s)] producing the
 * `filters:[{column, op, value}]` array of the table.browse API.
 * BETWEEN → value [a, b]; IN / NOT IN → array split on commas; IS (NOT) NULL → no value.
 * @module ui/filterbuilder
 */
import { h } from './dom.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';

/** Operators in display order; `label` keys are translated where they are words. */
export const FILTER_OPS = ['=', '!=', '<', '>', '<=', '>=', 'contains', 'starts', 'ends', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS NULL', 'IS NOT NULL'];
export const NO_VALUE = new Set(['IS NULL', 'IS NOT NULL']);
export const WORD_OPS = { contains: 'filter.op.contains', starts: 'filter.op.starts', ends: 'filter.op.ends', IN: 'filter.op.in', 'NOT IN': 'filter.op.notIn', BETWEEN: 'filter.op.between', 'IS NULL': 'filter.op.isNull', 'IS NOT NULL': 'filter.op.isNotNull', LIKE: 'filter.op.like', 'NOT LIKE': 'filter.op.notLike' };

/**
 * @typedef {{column: string, op: string, value?: any}} Filter
 */

/**
 * Create a filter builder.
 * @param {object} o
 * @param {{name: string}[]} o.columns
 * @param {Filter[]} [o.value]
 * @param {(filters: Filter[]) => void} o.onApply
 * @returns {{el: HTMLElement, getValue: () => Filter[], setValue: (f: Filter[]) => void, focus: () => void}}
 */
export function createFilterBuilder(o) {
  let rows = (o.value && o.value.length ? o.value : [{ column: o.columns[0]?.name || '', op: '=', value: '' }]).map((f) => ({ ...f }));
  const list = h('div', { class: 'flex flex-col gap-2' });
  const el = h('form', {
    class: 'flex flex-col gap-3 border-b border-border bg-surface-2/40 p-3',
    'aria-label': t('filter.title'),
    onSubmit: (e) => { e.preventDefault(); o.onApply(getValue()); },
  },
  list,
  h('div', { class: 'flex flex-wrap items-center gap-2' },
    h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { rows.push({ column: o.columns[0]?.name || '', op: '=', value: '' }); render(); list.lastElementChild?.querySelector('select')?.focus(); } }, icon('plus'), t('filter.add')),
    h('div', { class: 'flex-1' }),
    h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { rows = [{ column: o.columns[0]?.name || '', op: '=', value: '' }]; render(); o.onApply([]); } }, icon('filter-x'), t('filter.clear')),
    h('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, icon('filter'), t('filter.apply'))));

  function render() {
    list.replaceChildren(...rows.map((f, i) => {
      const colSel = h('select', { class: 'select input-sm w-auto min-w-0 max-w-[12rem] flex-1 sm:flex-none', 'aria-label': t('filter.column'), onChange: (e) => { f.column = e.target.value; } },
        o.columns.map((c) => h('option', { value: c.name, selected: c.name === f.column }, c.name)));
      const opSel = h('select', { class: 'select input-sm w-auto', 'aria-label': t('filter.operator'), onChange: (e) => { f.op = e.target.value; render(); } },
        FILTER_OPS.map((op) => h('option', { value: op, selected: op === f.op }, WORD_OPS[op] ? t(WORD_OPS[op]) : op)));
      let valueEl = null;
      if (f.op === 'BETWEEN') {
        const [a, b] = Array.isArray(f.value) ? f.value : ['', ''];
        f.value = [a, b];
        valueEl = h('div', { class: 'flex min-w-0 flex-1 items-center gap-1.5' },
          h('input', { class: 'input input-sm min-w-0', value: a, dir: 'auto', 'aria-label': t('filter.from'), onInput: (e) => { f.value[0] = e.target.value; } }),
          h('span', { class: 'text-muted text-xs' }, t('filter.and')),
          h('input', { class: 'input input-sm min-w-0', value: b, dir: 'auto', 'aria-label': t('filter.to'), onInput: (e) => { f.value[1] = e.target.value; } }));
      } else if (!NO_VALUE.has(f.op)) {
        const v = Array.isArray(f.value) ? f.value.join(', ') : (f.value ?? '');
        valueEl = h('input', {
          class: 'input input-sm min-w-0 flex-1', value: v, dir: 'auto',
          placeholder: f.op === 'IN' || f.op === 'NOT IN' ? t('filter.listPlaceholder') : t('filter.value'),
          'aria-label': t('filter.value'),
          onInput: (e) => { f.value = e.target.value; },
        });
      }
      return h('div', { class: 'flex flex-wrap items-center gap-1.5 sm:flex-nowrap' },
        h('span', { class: 'w-10 shrink-0 text-xs text-muted' }, i === 0 ? t('filter.where') : t('filter.and')),
        colSel, opSel, valueEl || h('div', { class: 'flex-1' }),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('filter.remove'), onClick: () => { rows.splice(i, 1); if (!rows.length) rows.push({ column: o.columns[0]?.name || '', op: '=', value: '' }); render(); } }, icon('x')));
    }));
  }

  function getValue() {
    return rows
      .filter((f) => f.column && (NO_VALUE.has(f.op) || (Array.isArray(f.value) ? f.value.some((x) => x !== '') : String(f.value ?? '') !== '')))
      .map((f) => {
        if (NO_VALUE.has(f.op)) return { column: f.column, op: f.op };
        if (f.op === 'IN' || f.op === 'NOT IN') {
          const list2 = Array.isArray(f.value) ? f.value : String(f.value).split(',').map((s) => s.trim()).filter((s) => s !== '');
          return { column: f.column, op: f.op, value: list2 };
        }
        return { column: f.column, op: f.op, value: f.value };
      });
  }

  render();
  return {
    el,
    getValue,
    setValue(f) { rows = (f.length ? f : [{ column: o.columns[0]?.name || '', op: '=', value: '' }]).map((x) => ({ ...x })); render(); },
    focus: () => list.querySelector('input,select')?.focus(),
  };
}
