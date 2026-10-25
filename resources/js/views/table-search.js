/**
 * Table "Search" tab — query by example: one row per column with operator and value(s),
 * which columns to show and an ORDER BY. Submitting opens the Browse tab with those filters
 * (?where=&order=&cols=) so results get the full grid: paging, sorting, inline editing when a
 * primary key exists, export. The form state is remembered per table for the session.
 */
import { h, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { FILTER_OPS, NO_VALUE, WORD_OPS } from '../ui/filterbuilder.js';
import { errorState, skeleton } from '../ui/feedback.js';
import { t } from '../i18n/index.js';
import { href, navigate } from '../router.js';
import { getTableInfo } from '../data.js';

const TEXTY = /char|text|clob|json|uuid|xml|citext|string/i;
const memo = new Map();

export default {
  title: (ctx) => t('table.title.search', { table: ctx.table }),

  async render(el, ctx) {
    const { conn, db, schema, table } = ctx;
    el.replaceChildren(h('div', { class: 'p-4' }, skeleton({ rows: 6 })));
    let info;
    try {
      info = await getTableInfo(conn, db, schema, table);
    } catch (e) {
      el.replaceChildren(errorState(e, { onRetry: () => this.render(el, ctx) }));
      return;
    }
    const columns = info.columns || [];
    const key = [conn, db, schema, table].join('\u0001');
    const fresh = () => ({
      rows: columns.map((c) => ({ column: c.name, op: /^enum|^set\b/i.test(c.type) || !TEXTY.test(c.type) ? '=' : 'contains', value: '', show: true })),
      order: '', dir: 'ASC',
    });
    let state = memo.get(key) || fresh();
    if (state.rows.length !== columns.length) state = fresh();
    memo.set(key, state);

    const opLabel = (op) => (WORD_OPS[op] ? t(WORD_OPS[op]) : op);
    const enumValues = (c) => {
      const m = /^(?:enum|set)\s*\((.*)\)$/i.exec(c.full_type || '');
      return m ? [...m[1].matchAll(/'((?:[^']|'')*)'/g)].map((x) => x[1].replace(/''/g, "'")) : null;
    };

    function valueControl(c, r) {
      if (NO_VALUE.has(r.op)) return h('span', { class: 'text-xs text-subtle' }, '—');
      const label = t('tableSearch.valueFor', { column: c.name });
      if (r.op === 'BETWEEN') {
        const [a, b] = Array.isArray(r.value) ? r.value : ['', ''];
        r.value = [a, b];
        return h('div', { class: 'flex min-w-0 items-center gap-1.5' },
          h('input', { class: 'input input-sm min-w-0', value: a, dir: 'auto', 'aria-label': `${label} · ${t('filter.from')}`, placeholder: t('filter.from'), onInput: (e) => { r.value[0] = e.target.value; } }),
          h('span', { class: 'text-xs text-muted' }, t('filter.and')),
          h('input', { class: 'input input-sm min-w-0', value: b, dir: 'auto', 'aria-label': `${label} · ${t('filter.to')}`, placeholder: t('filter.to'), onInput: (e) => { r.value[1] = e.target.value; } }));
      }
      if (Array.isArray(r.value)) r.value = r.value.join(', ');
      const ev = enumValues(c);
      if (ev && (r.op === '=' || r.op === '!=')) {
        return h('select', { class: 'select input-sm', 'aria-label': label, onChange: (e) => { r.value = e.target.value; } },
          h('option', { value: '' }, ''), ev.map((v) => h('option', { value: v, selected: v === r.value }, v)));
      }
      return h('input', {
        class: 'input input-sm min-w-0', value: r.value, dir: 'auto', 'aria-label': label,
        placeholder: r.op === 'IN' || r.op === 'NOT IN' ? t('filter.listPlaceholder') : '',
        onInput: (e) => { r.value = e.target.value; },
      });
    }

    const list = h('div', { class: 'divide-y divide-border', role: 'list' });
    const allBox = h('input', { type: 'checkbox', class: 'checkbox', 'aria-label': t('tableSearch.showAll'), onChange: () => { state.rows.forEach((r) => { r.show = allBox.checked; }); draw(); } });
    function draw() {
      const shown = state.rows.filter((r) => r.show).length;
      allBox.checked = shown === state.rows.length;
      allBox.indeterminate = shown > 0 && shown < state.rows.length;
      setChildren(list, columns.map((c, i) => {
        const r = state.rows[i];
        const holder = h('div', { class: 'min-w-0 flex-1 sm:basis-0 sm:flex-[2]' }, valueControl(c, r));
        const opSel = h('select', {
          class: 'select input-sm w-auto shrink-0 sm:w-36', 'aria-label': t('tableSearch.operatorFor', { column: c.name }),
          onChange: (e) => { r.op = e.target.value; setChildren(holder, valueControl(c, r)); holder.querySelector('input,select')?.focus(); },
        }, FILTER_OPS.map((op) => h('option', { value: op, selected: op === r.op }, opLabel(op))));
        return h('div', { class: 'flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2 sm:flex-nowrap', role: 'listitem' },
          h('label', { class: 'flex min-w-0 basis-full cursor-pointer items-center gap-2 sm:basis-0 sm:flex-1' },
            h('input', { type: 'checkbox', class: 'checkbox', checked: r.show, title: t('tableSearch.show'), 'aria-label': t('tableSearch.showColumn', { column: c.name }), onChange: (e) => { r.show = e.target.checked; draw(); } }),
            c.primary ? icon('key-round', 'size-3.5 text-warning') : null,
            h('span', { class: 'min-w-0 truncate font-mono text-[12.5px]', dir: 'auto' }, c.name),
            h('span', { class: 'ms-auto truncate font-mono text-[11px] text-subtle sm:ms-1', dir: 'ltr' }, c.full_type || c.type, c.nullable ? ' · NULL' : '')),
          opSel, holder);
      }));
    }

    const orderSel = h('select', { class: 'select input-sm w-auto min-w-0 max-w-[14rem]', 'aria-label': t('tableSearch.orderBy'), onChange: (e) => { state.order = e.target.value; } },
      h('option', { value: '' }, t('tableSearch.noOrder')), columns.map((c) => h('option', { value: c.name, selected: c.name === state.order }, c.name)));
    const dirSel = h('select', { class: 'select input-sm w-auto', 'aria-label': t('tableSearch.direction'), onChange: (e) => { state.dir = e.target.value; } },
      ['ASC', 'DESC'].map((d) => h('option', { value: d, selected: d === state.dir }, t(`tableSearch.dir.${d}`))));

    function filters() {
      return state.rows.filter((r) => NO_VALUE.has(r.op) || (Array.isArray(r.value) ? r.value.some((x) => x !== '') : String(r.value ?? '') !== '')).map((r) => {
        if (NO_VALUE.has(r.op)) return { column: r.column, op: r.op };
        if (r.op === 'IN' || r.op === 'NOT IN') return { column: r.column, op: r.op, value: String(r.value).split(',').map((x) => x.trim()).filter((x) => x !== '') };
        return { column: r.column, op: r.op, value: r.value };
      });
    }
    function submit(e) {
      e.preventDefault();
      const shown = state.rows.filter((r) => r.show).map((r) => r.column);
      if (!shown.length) { allBox.focus(); return; }
      const f = filters();
      navigate(href.table(conn, db, schema, table, 'browse', {
        where: f.length ? JSON.stringify(f) : undefined,
        order: state.order ? JSON.stringify([{ column: state.order, dir: state.dir }]) : undefined,
        cols: shown.length < columns.length ? JSON.stringify(shown) : undefined,
      }));
    }

    const form = h('form', { class: 'card overflow-hidden', onSubmit: submit, 'aria-label': t('tableSearch.title') },
      h('div', { class: 'flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-surface-2/50 px-3 py-2 text-xs font-medium text-muted' },
        h('label', { class: 'flex min-w-0 flex-1 cursor-pointer items-center gap-2 sm:basis-0' }, allBox, t('tableSearch.column')),
        h('span', { class: 'hidden shrink-0 sm:block sm:w-36' }, t('filter.operator')),
        h('span', { class: 'hidden min-w-0 sm:block sm:basis-0 sm:flex-[2]' }, t('filter.value'))),
      list,
      h('div', { class: 'flex flex-wrap items-center gap-2 border-t border-border bg-surface-2/40 px-3 py-2.5' },
        h('span', { class: 'text-[12.5px] text-muted' }, t('tableSearch.orderBy')), orderSel, dirSel,
        h('div', { class: 'flex-1' }),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { state = fresh(); memo.set(key, state); orderSel.value = ''; dirSel.value = 'ASC'; draw(); } }, icon('eraser'), t('tableSearch.reset')),
        h('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, icon('search'), t('tableSearch.submit'))));

    draw();
    el.replaceChildren(h('div', { class: 'min-h-0 flex-1 overflow-auto' },
      h('div', { class: 'mx-auto w-full max-w-4xl space-y-3 p-3 sm:p-6' },
        h('p', { class: 'text-[13px] text-muted' }, t('tableSearch.hint')),
        form)));
    list.querySelector('input:not([type=checkbox])')?.setAttribute('data-autofocus', '');
  },
};
