/**
 * Shared rendering of one QueryResult (SQL editor results, routine calls): cancelled / error
 * callouts, "n rows affected" state, a data grid for the first result set and read-only grids
 * for further result sets (`more_results`, e.g. CALL with several SELECTs).
 * @module ui/resultview
 */
import { h } from './dom.js';
import { createDataGrid } from './datagrid.js';
import { callout, emptyState } from './feedback.js';
import { t, plural } from '../i18n/index.js';
import { highlightSql } from '../sql/highlight.js';

/**
 * @param {object} q  QueryResult (+ error_code, more_results)
 * @param {object} [o]
 * @param {string} [o.quote]  identifier quote char for grid copy-as-SQL
 * @param {string} [o.ariaLabel]
 * @param {boolean} [o.editable]  first grid inline-editable
 * @param {Function} [o.onEdit]  datagrid onEdit
 * @param {string} [o.tableName]
 * @param {Array} [o.columns]  grid columns for the first set (default: q.columns)
 * @param {string} [o.setHeight='h-72']  height class of each set when there are several
 * @returns {{el: HTMLElement, grid: object|null, grids: object[], destroy: () => void}}  grid = first set's grid
 */
export function createResultView(q, o = {}) {
  const grids = [];
  const done = (el, grid = null) => ({ el, grid, grids, destroy: () => grids.forEach((g) => g.destroy()) });
  if (q.error_code === 'cancelled') {
    return done(h('div', { class: 'flex-1 overflow-auto p-4' }, callout('warning', h('div', {},
      h('div', { class: 'font-medium' }, t('sql.cancelledStatement')),
      h('div', { class: 'mt-1 text-xs text-muted', dir: 'auto' }, q.error),
      q.sql ? h('pre', { class: 'mt-2 overflow-auto rounded-md bg-surface p-2 font-mono text-xs', dir: 'ltr' }, highlightSql(q.sql)) : null))));
  }
  if (q.error) {
    return done(h('div', { class: 'flex-1 overflow-auto p-4' }, callout('danger', h('div', {},
      h('div', { class: 'font-medium' }, q.error),
      q.sql ? h('pre', { class: 'mt-2 overflow-auto rounded-md bg-surface p-2 font-mono text-xs', dir: 'ltr' }, highlightSql(q.sql)) : null))));
  }
  if (!q.is_result_set) {
    return done(h('div', { class: 'flex flex-1 items-center justify-center p-6' }, emptyState({ icon: 'circle-check', title: plural('sql.affected', q.affected ?? 0), message: q.insert_id ? t('sql.insertId', { id: q.insert_id }) : undefined })));
  }
  const label = o.ariaLabel || t('sql.results');
  const first = createDataGrid({
    columns: o.columns || q.columns.map((c) => ({ name: c.name, type: c.type })),
    rows: q.rows,
    tableName: o.tableName || 'result',
    quote: o.quote,
    ariaLabel: label,
    editable: !!o.editable,
    onEdit: o.onEdit,
    className: 'min-h-0 flex-1',
  });
  grids.push(first);
  if (!q.more_results?.length) return done(first.el, first);
  const height = o.setHeight || 'h-72';
  // Several result sets: the first keeps the caller's actions, the others are read-only grids below.
  const sets = [h('section', { class: `flex ${height} shrink-0 flex-col`, 'aria-label': t('sql.resultSetN', { n: 1 }) },
    h('div', { class: 'border-b border-border bg-surface-2 px-3 py-1 text-xs font-medium' }, t('sql.resultSetN', { n: 1 })), first.el)];
  q.more_results.forEach((m, i) => {
    const setLabel = t('sql.resultSetN', { n: i + 2 });
    const meta = m.is_result_set ? plural('sql.rows', m.rows.length) : plural('sql.affected', m.affected ?? 0);
    let body;
    if (m.is_result_set) {
      const g = createDataGrid({
        columns: m.columns.map((c) => ({ name: c.name, type: c.type })), rows: m.rows, tableName: 'result', quote: o.quote,
        ariaLabel: `${label}: ${setLabel}`, editable: false, className: 'min-h-0 flex-1',
      });
      grids.push(g);
      body = g.el;
    } else {
      body = h('div', { class: 'p-3 text-xs text-muted' }, meta);
    }
    sets.push(h('section', { class: `flex shrink-0 flex-col border-t border-border ${m.is_result_set ? height : ''}`, 'aria-label': setLabel },
      h('div', { class: 'flex items-center gap-2 border-b border-border bg-surface-2 px-3 py-1 text-xs' },
        h('span', { class: 'font-medium' }, setLabel), h('span', { class: 'text-muted tabular-nums' }, meta),
        m.truncated ? h('span', { class: 'badge badge-warning', title: t('sql.truncatedHint') }, t('sql.truncated')) : null),
      body));
  });
  return done(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, sets), first);
}
