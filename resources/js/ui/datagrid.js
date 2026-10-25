/**
 * Virtualized data grid for result sets and table browsing.
 *
 * - sticky header and sticky key column (first primary-key column is moved to the front)
 * - row virtualization (fixed row height), horizontal scroll on narrow screens
 * - column resize (pointer drag, RTL aware), sort by header click (Shift+click = multi sort)
 * - typed cell rendering: NULL badge, end-aligned numbers, JSON viewer, binary badge,
 *   long-text truncation with an expand button, dir="auto" cells, FK links
 * - inline edit: double-click / Enter / F2, Enter or Tab commits, Esc cancels, blur commits
 * - row selection: checkboxes, Shift-range, Space, Ctrl/Cmd+A
 * - copy: Ctrl/Cmd+C, context menu (cell, rows as TSV / JSON / SQL INSERT)
 * - keyboard navigation (arrows, Home/End, PageUp/PageDown, Ctrl+Home/End) with a roving tabindex
 *   (one tab stop; header row = row -1, checkbox column = column -1), empty and loading states
 * - pending-change states (rowLabel/cellLabel) are exposed as visually hidden text, not only colour
 * @module ui/datagrid
 */
import { h, cls, uid, copyText, modKey, dragPointer, isMobile } from './dom.js';
import { icon } from './icons.js';
import { bindContextMenu } from './menu.js';
import { viewerDialog } from './modal.js';
import { toast, toastError } from './toast.js';
import { spinner, emptyState } from './feedback.js';
import { t, fmtBytes } from '../i18n/index.js';

const NUMERIC_TYPE = /^(tiny|small|medium|big)?int|^integer|^dec|^numeric|^float|^double|^real|^money|^smallmoney|^serial|^bigserial|^number|^bit\b|^year/i;
const JSON_TYPE = /json/i;
const CHECK_W = 40;
const OVERSCAN = 8;
const LONG_TEXT = 120;

/**
 * @typedef {object} GridColumn
 * @property {string} name
 * @property {string} [type]
 * @property {boolean} [nullable]
 * @property {boolean} [primary]
 * @property {{table: string, column: string}|null} [fk]
 */

/**
 * @typedef {object} DataGrid
 * @property {HTMLElement} el
 * @property {(d: {columns?: GridColumn[], rows: any[][], sort?: {column:string,dir:'ASC'|'DESC'}[], visible?: string[]|null}) => void} setData
 *   `visible` limits the displayed columns by name (null shows all; omit to keep the current choice)
 * @property {(loading: boolean) => void} setLoading
 * @property {() => number[]} getSelection       selected data-row indexes
 * @property {() => any[][]} getSelectedRows
 * @property {() => void} clearSelection
 * @property {(i: number) => any[]} getRow
 * @property {(i: number, row: any[]) => void} updateRow
 * @property {() => void} redraw                 re-render visible rows (row/cell classes changed)
 * @property {(indexes: number[]) => void} setSelection
 * @property {() => GridColumn[]} getColumns
 * @property {() => void} focus
 * @property {() => void} destroy
 */

/**
 * Create a data grid.
 * @param {object} o
 * @param {GridColumn[]} o.columns
 * @param {any[][]} [o.rows]
 * @param {boolean} [o.selectable=true]  checkbox column
 * @param {boolean|((col: GridColumn) => boolean)} [o.editable=false]
 * @param {{column: string, dir: 'ASC'|'DESC'}[]} [o.sort]
 * @param {(sort: {column: string, dir: 'ASC'|'DESC'}[]) => void} [o.onSort]  server-side sort; omit for local sort
 * @param {(rowIndex: number, colIndex: number, value: string|null, originalRow: any[]) => Promise<void>} [o.onEdit]
 *   persist a cell edit; `originalRow` holds the values before the edit (use it for the key). Throw to reject.
 * @param {(col: GridColumn, value: any, rowIndex: number) => void} [o.onFkClick]
 * @param {(indexes: number[]) => void} [o.onSelectionChange]
 * @param {(rowIndex: number) => void} [o.onRowOpen]  Enter on a non-editable grid
 * @param {(indexes: number[]) => void} [o.onDeleteKey]
 * @param {(indexes: number[], colIndex: number) => import('./menu.js').MenuItem[]} [o.rowMenu]  extra context-menu items
 * @param {(rowIndex: number) => string|null} [o.rowClass]  extra <tr> classes (e.g. pending-change state)
 * @param {(rowIndex: number, colIndex: number) => string|null} [o.cellClass]  extra <td> classes
 * @param {(rowIndex: number) => string|null} [o.rowLabel]  screen-reader text for a row state (e.g. "deleted")
 * @param {(rowIndex: number, colIndex: number) => string|null} [o.cellLabel]  screen-reader text for a cell state
 * @param {(rowIndex: number, colIndex: number) => boolean} [o.onCellEscape]  Esc on the active cell
 *   (not editing); return true when handled (e.g. a pending edit was reverted)
 * @param {string} [o.tableName]  used by "Copy as SQL INSERT"
 * @param {string} [o.quote='`']  identifier quote for SQL copy
 * @param {string} [o.emptyMessage]
 * @param {string} [o.ariaLabel]
 * @param {string} [o.className]
 * @returns {DataGrid}
 */
export function createDataGrid(o) {
  const gid = uid('dg');
  const selectable = o.selectable !== false;
  let columns = [];
  let rows = [];
  let order = [];      // display row → data row
  let display = [];    // display col → data col
  let visible = null;  // optional Set of column names to show (others hidden)
  let widths = [];     // per data col
  let numeric = [];
  let sort = o.sort ? [...o.sort] : [];
  const selected = new Set();
  let anchor = null;
  let active = { r: 0, c: 0 };
  let editing = null;
  let loading = false;
  let RH = 32;
  let rendered = [-1, -1];
  let initialized = false;

  const colgroup = h('colgroup');
  /** Absolute row offset of the first data row (browse passes the page offset). */
  const rowOffset = () => Math.max(0, Number(typeof o.rowOffset === 'function' ? o.rowOffset() : o.rowOffset) || 0);
  const headRow = h('tr', { role: 'row', 'aria-rowindex': '1' });
  const tbody = h('tbody');
  const hint = h('span', { id: `${gid}-hint`, class: 'sr-only' }, t('a11y.gridHint'));
  const table = h('table', { class: 'dg-table', role: 'grid', 'aria-label': o.ariaLabel, 'aria-describedby': hint.id, 'aria-multiselectable': selectable ? 'true' : null }, colgroup, h('thead', {}, headRow), tbody);
  // The scroll box is only a tab stop while the active cell is not rendered (virtualized away / no rows).
  const scroll = h('div', { class: 'dg-scroll min-h-0 flex-1', tabindex: '0' }, table, hint);
  const overlay = h('div', { class: 'pointer-events-none absolute inset-x-0 bottom-0 top-9 flex items-start justify-center', hidden: true });
  const el = h('div', { class: cls('relative flex min-h-0 flex-col bg-surface', o.className) }, scroll, overlay);

  const isRtl = () => document.documentElement.dir === 'rtl';
  const canEdit = (ci) => {
    if (!o.onEdit) return false;
    if (typeof o.editable === 'function') return o.editable(columns[ci]);
    return !!o.editable;
  };

  /* ------------------------------------------------------------ data */
  function setData(d) {
    commitEdit(false);
    let relayout = false;
    if (d.columns) {
      const same = d.columns.length === columns.length && d.columns.every((c, i) => c.name === columns[i]?.name);
      columns = d.columns;
      if (!same) {
        widths = [];
        selected.clear();
        relayout = true;
      }
    }
    if (d.visible !== undefined) {
      const next = d.visible ? new Set(d.visible) : null;
      if (String(next && [...next]) !== String(visible && [...visible])) { visible = next; relayout = true; }
    }
    if (relayout) {
      active = { r: 0, c: 0 };
      const pk = columns.findIndex((c) => c.primary);
      display = columns.map((_, i) => i);
      if (pk > 0) { display.splice(pk, 1); display.unshift(pk); }
      if (visible) display = display.filter((i) => visible.has(columns[i].name));
    }
    if (d.sort) sort = [...d.sort];
    rows = d.rows || [];
    numeric = columns.map((c, i) => (c.type ? NUMERIC_TYPE.test(c.type) : rows.slice(0, 20).some((r) => typeof r[i] === 'number')));
    if (!widths.length) widths = columns.map((c, i) => estimateWidth(c, i));
    selected.forEach((i) => { if (i >= rows.length) selected.delete(i); });
    buildOrder();
    active.r = Math.min(active.r, Math.max(0, rows.length - 1));
    renderHead();
    rendered = [-1, -1];
    renderBody(true);
    updateOverlay();
    if (initialized) emitSelection(); else updateHeadCheckbox();
  }

  function buildOrder() {
    order = rows.map((_, i) => i);
    if (!o.onSort && sort.length) {
      const keys = sort.map((s) => ({ i: columns.findIndex((c) => c.name === s.column), dir: s.dir === 'DESC' ? -1 : 1 })).filter((k) => k.i >= 0);
      const coll = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
      order.sort((a, b) => {
        for (const k of keys) {
          const va = rows[a][k.i];
          const vb = rows[b][k.i];
          if (va === vb) continue;
          if (va === null) return -1 * k.dir;
          if (vb === null) return 1 * k.dir;
          const r = typeof va === 'number' && typeof vb === 'number' ? va - vb : coll.compare(String(va), String(vb));
          if (r) return r * k.dir;
        }
        return a - b;
      });
    }
  }

  function estimateWidth(c, i) {
    // header = name + (from sm) the type in a smaller font, side by side
    let chars = c.name.length + (c.primary || c.fk ? 3 : 0) + 2;
    if (c.type && !isMobile()) chars += Math.ceil(Math.min(c.type.length, 16) * 0.8) + 1;
    for (let r = 0; r < Math.min(rows.length, 40); r++) {
      const v = rows[r][i];
      const len = v === null ? 4 : typeof v === 'object' ? 10 : String(v).length;
      if (len > chars) chars = len;
    }
    return Math.max(72, Math.min(340, Math.round(chars * 7.4 + 28)));
  }

  /* ------------------------------------------------------------ header */
  function renderHead() {
    const hadFocus = headRow.contains(document.activeElement);
    colgroup.replaceChildren();
    headRow.replaceChildren();
    if (selectable) {
      colgroup.appendChild(h('col', { style: { width: `${CHECK_W}px` } }));
      const all = h('input', {
        type: 'checkbox', class: 'checkbox', 'aria-label': t('grid.selectAll'), tabindex: '-1', 'data-hc': '-1',
        onChange: (e) => {
          if (e.target.checked) rows.forEach((_, i) => selected.add(i)); else selected.clear();
          renderBody(true);
          emitSelection();
        },
      });
      headRow.appendChild(h('th', { class: 'dg-sticky', style: { insetInlineStart: '0px', width: `${CHECK_W}px` }, role: 'columnheader', 'aria-colindex': '1' },
        h('div', { class: 'flex h-full items-center justify-center' }, all)));
    }
    display.forEach((ci, di) => {
      const c = columns[ci];
      colgroup.appendChild(h('col', { style: { width: `${widths[ci]}px` } }));
      const si = sort.findIndex((s) => s.column === c.name);
      const sdir = si >= 0 ? sort[si].dir : null;
      const sticky = di === 0;
      const handle = h('span', { class: 'dg-resize', 'aria-hidden': 'true', onPointerdown: (e) => startResize(e, ci, handle) });
      const btn = h('button', {
        type: 'button',
        tabindex: '-1',
        'data-hc': String(di),
        class: 'flex h-full w-full min-w-0 items-center gap-1.5 px-2.5 text-start hover:bg-surface-3/60',
        title: c.type ? `${c.name} · ${c.type}` : c.name,
        onClick: (e) => toggleSort(c.name, e.shiftKey),
      },
      c.primary ? icon('key-round', 'size-3 text-warning') : (c.fk ? icon('link', 'size-3 text-info') : null),
      h('span', { class: 'min-w-0 truncate text-[12.5px] font-semibold', dir: 'auto' }, c.name),
      c.type ? h('span', { class: 'hidden min-w-0 shrink-[4] truncate font-mono text-[10.5px] font-normal text-subtle sm:inline' }, c.type.toLowerCase()) : null,
      h('span', { class: 'ms-auto flex items-center text-accent' },
        sdir ? icon(sdir === 'ASC' ? 'arrow-up' : 'arrow-down', 'size-3.5') : null,
        sdir && sort.length > 1 ? h('span', { class: 'text-[10px]' }, String(si + 1)) : null));
      headRow.appendChild(h('th', {
        role: 'columnheader',
        class: cls(sticky && 'dg-sticky dg-sticky-last'),
        style: sticky ? { insetInlineStart: `${selectable ? CHECK_W : 0}px` } : null,
        'aria-sort': sdir ? (sdir === 'ASC' ? 'ascending' : 'descending') : 'none',
        'aria-colindex': String(di + 1 + (selectable ? 1 : 0)),
      }, btn, handle));
    });
    table.style.width = `${totalWidth()}px`;
    table.setAttribute('aria-colcount', String(display.length + (selectable ? 1 : 0)));
    updateHeadCheckbox();
    syncTabStop(hadFocus);
  }

  function totalWidth() {
    return display.reduce((s, ci) => s + widths[ci], selectable ? CHECK_W : 0);
  }

  function toggleSort(name, multi) {
    const idx = sort.findIndex((s) => s.column === name);
    let next;
    if (idx < 0) next = { column: name, dir: 'ASC' };
    else if (sort[idx].dir === 'ASC') next = { column: name, dir: 'DESC' };
    else next = null;
    if (multi) {
      sort = sort.filter((s) => s.column !== name);
      if (next) { if (idx >= 0) sort.splice(idx, 0, next); else sort.push(next); }
    } else {
      sort = next ? [next] : [];
    }
    if (o.onSort) o.onSort([...sort]);
    else { buildOrder(); renderHead(); renderBody(true); }
  }

  function startResize(e, ci, handle) {
    e.preventDefault();
    e.stopPropagation();
    const start = widths[ci];
    const dirMul = isRtl() ? -1 : 1;
    handle.classList.add('is-active');
    const colEl = colgroup.children[display.indexOf(ci) + (selectable ? 1 : 0)];
    dragPointer(e, (dx) => {
      widths[ci] = Math.max(48, Math.round(start + dx * dirMul));
      colEl.style.width = `${widths[ci]}px`;
      table.style.width = `${totalWidth()}px`;
    }, () => handle.classList.remove('is-active'));
  }

  /* ------------------------------------------------------------ body */
  function renderBody(force = false) {
    if (editing) return;
    const hadFocus = tbody.contains(document.activeElement);
    const total = order.length;
    const viewH = scroll.clientHeight || 600;
    const top = scroll.scrollTop;
    const first = Math.max(0, Math.floor(top / RH) - OVERSCAN);
    const last = Math.min(total, Math.ceil((top + viewH) / RH) + OVERSCAN);
    if (!force && first === rendered[0] && last === rendered[1]) return;
    rendered = [first, last];
    const span = display.length + (selectable ? 1 : 0);
    const frag = document.createDocumentFragment();
    frag.appendChild(spacer(first * RH, span));
    for (let r = first; r < last; r++) frag.appendChild(renderRow(r));
    frag.appendChild(spacer((total - last) * RH, span));
    tbody.replaceChildren(frag);
    const off = rowOffset();
    const tot = o.rowTotal?.();
    table.setAttribute('aria-rowcount', String((Number.isFinite(tot) ? tot : off + total) + 1));
    // calibrate the row height once real rows exist (touch devices use taller rows)
    const firstRow = tbody.children[1];
    if (firstRow && firstRow.tagName === 'TR' && first < last) {
      const real = firstRow.getBoundingClientRect().height;
      if (real > 0 && Math.abs(real - RH) > 0.5) { RH = real; renderBody(true); if (hadFocus) focusActive(); return; }
    }
    syncTabStop(hadFocus);
  }

  function spacer(height, span) {
    return h('tr', { 'aria-hidden': 'true' }, h('td', { colspan: String(span), style: { height: `${height}px`, padding: '0', border: '0' } }));
  }

  function renderRow(r) {
    const di = order[r];
    const row = rows[di];
    const isSel = selected.has(di);
    const tr = h('tr', { role: 'row', class: o.rowClass?.(di) || null, 'aria-rowindex': String(rowOffset() + r + 2), 'aria-selected': selectable ? String(isSel) : null, 'data-r': String(r) });
    const rowState = o.rowLabel?.(di) || null;
    const isActive = (dc) => active.r === r && active.c === dc;
    if (selectable) {
      const label = t('grid.selectRow', { n: r + 1 });
      tr.appendChild(h('td', { class: 'dg-sticky', style: { insetInlineStart: '0px', padding: '0' }, role: 'gridcell', 'aria-colindex': '1' },
        h('div', { class: 'flex h-full items-center justify-center' },
          h('input', { type: 'checkbox', class: 'checkbox', checked: isSel, tabindex: isActive(-1) ? '0' : '-1', 'aria-label': rowState ? `${label}, ${rowState}` : label, 'data-check': '1' }))));
    }
    display.forEach((ci, dc) => {
      const v = row[ci];
      const c = columns[ci];
      const td = h('td', {
        role: 'gridcell',
        id: `${gid}-${r}-${dc}`,
        tabindex: isActive(dc) ? '0' : '-1',
        'aria-colindex': String(dc + 1 + (selectable ? 1 : 0)),
        'data-c': String(dc),
        dir: 'auto',
        class: cls(
          numeric[ci] && v !== null && typeof v !== 'object' && 'dg-num',
          (JSON_TYPE.test(c.type || '') || numeric[ci]) && 'dg-mono',
          dc === 0 && 'dg-sticky dg-sticky-last',
          active.r === r && active.c === dc && 'dg-active',
          o.cellClass?.(di, ci),
        ),
        style: dc === 0 ? { insetInlineStart: `${selectable ? CHECK_W : 0}px` } : null,
        'aria-readonly': canEdit(ci) ? null : 'true',
      });
      fillCell(td, v, c, ci);
      const state = [dc === 0 ? rowState : null, o.cellLabel?.(di, ci)].filter(Boolean).join(', ');
      if (state) td.appendChild(h('span', { class: 'sr-only' }, ` (${state})`));
      tr.appendChild(td);
    });
    return tr;
  }

  function fillCell(td, v, c, ci) {
    if (v === undefined) { // staged new row: column left to its DEFAULT
      td.appendChild(h('span', { class: 'badge badge-null' }, 'DEFAULT'));
      return;
    }
    if (v === null) {
      td.appendChild(h('span', { class: 'badge badge-null' }, 'NULL'));
      return;
    }
    if (typeof v === 'object' && v.$binary !== undefined) {
      td.appendChild(h('span', { class: 'badge', title: t('grid.binaryTitle') }, icon('binary'), `${t('grid.binary')} · ${fmtBytes(v.length ?? 0)}`));
      return;
    }
    const str = typeof v === 'object' ? JSON.stringify(v) : String(v);
    const isJson = JSON_TYPE.test(c.type || '') || (typeof v === 'object');
    const isLong = str.length > LONG_TEXT || str.includes('\n');
    if (c.fk && o.onFkClick) {
      td.appendChild(h('span', { class: 'inline-flex max-w-full items-center gap-1' },
        h('span', { class: 'truncate' }, shorten(str)),
        h('button', { type: 'button', tabindex: '-1', class: 'hit-24 inline-flex rounded p-0.5 text-info hover:bg-info/10', 'data-fk': '1', title: t('grid.followFk', { table: c.fk.table }), 'aria-label': t('grid.followFk', { table: c.fk.table }) }, icon('arrow-right', 'size-3'))));
      return;
    }
    if (isJson || isLong) {
      td.appendChild(h('span', { class: 'flex min-w-0 items-center gap-1' },
        h('span', { class: 'min-w-0 flex-1 truncate' }, shorten(str)),
        h('button', { type: 'button', tabindex: '-1', class: 'hit-24 inline-flex shrink-0 rounded p-0.5 text-muted hover:bg-surface-3 hover:text-fg', 'data-expand': '1', title: t('grid.viewValue'), 'aria-label': t('grid.viewValue') }, icon(isJson ? 'braces' : 'maximize', 'size-3'))));
      return;
    }
    td.textContent = str;
  }

  const shorten = (s) => (s.length > 300 ? `${s.slice(0, 300)}…` : s).replace(/\r?\n/g, ' ↵ ');

  function cellEl(r, dc) {
    return document.getElementById(`${gid}-${r}-${dc}`);
  }
  /** Focusable element of a grid position: header (r = -1) button / select-all, row checkbox (c = -1) or cell. */
  function focusEl(r, c) {
    if (r < 0) return headRow.querySelector(`[data-hc="${c}"]`);
    if (c < 0) return tbody.querySelector(`tr[data-r="${r}"] input[data-check]`);
    return cellEl(r, c);
  }
  /** Roving tabindex: only the active position is tabbable; the scroll box stands in when it isn't rendered. */
  function syncTabStop(refocus) {
    table.querySelectorAll('[tabindex="0"]').forEach((x) => x.setAttribute('tabindex', '-1'));
    const cur = focusEl(active.r, active.c);
    if (cur) cur.setAttribute('tabindex', '0');
    scroll.setAttribute('tabindex', cur ? '-1' : '0');
    if (refocus) (cur || scroll).focus({ preventScroll: true });
  }
  function focusActive() { syncTabStop(true); }

  /* ------------------------------------------------------------ selection */
  function emitSelection() {
    updateHeadCheckbox();
    o.onSelectionChange?.([...selected].sort((a, b) => a - b));
  }
  function updateHeadCheckbox() {
    const cb = headRow.querySelector('input[type=checkbox]');
    if (!cb) return;
    cb.checked = rows.length > 0 && selected.size === rows.length;
    cb.indeterminate = selected.size > 0 && selected.size < rows.length;
  }
  function selectRange(fromR, toR, additive) {
    if (!additive) selected.clear();
    const [a, b] = fromR < toR ? [fromR, toR] : [toR, fromR];
    for (let r = a; r <= b; r++) selected.add(order[r]);
  }
  function toggleRow(r, shift) {
    const di = order[r];
    if (shift && anchor !== null) selectRange(anchor, r, true);
    else if (selected.has(di)) selected.delete(di);
    else selected.add(di);
    if (!shift) anchor = r;
    renderBody(true);
    emitSelection();
  }

  /* ------------------------------------------------------------ active cell */
  function setActive(r, c, { scrollTo = true } = {}) {
    if (!display.length) return;
    const focused = scroll.contains(document.activeElement) && !editing;
    r = Math.max(-1, Math.min(order.length - 1, r));
    c = Math.max(selectable ? -1 : 0, Math.min(display.length - 1, c));
    cellEl(active.r, active.c)?.classList.remove('dg-active');
    active = { r, c };
    if (scrollTo) scrollIntoView(r, c);
    renderBody();
    cellEl(r, c)?.classList.add('dg-active');
    syncTabStop(focused);
  }
  function scrollIntoView(r, c) {
    const headH = headRow.getBoundingClientRect().height || RH;
    const y = r * RH;
    const viewH = scroll.clientHeight - headH;
    if (r >= 0) {
      if (y < scroll.scrollTop) scroll.scrollTop = y;
      else if (y + RH > scroll.scrollTop + viewH) scroll.scrollTop = y + RH - viewH;
    }
    if (c <= 0) return;
    const stickyW = (selectable ? CHECK_W : 0) + widths[display[0]];
    let x = stickyW;
    for (let k = 1; k < c; k++) x += widths[display[k]];
    const w = widths[display[c]];
    const viewW = scroll.clientWidth;
    const rtl = isRtl();
    const cur = rtl ? -scroll.scrollLeft : scroll.scrollLeft; // RTL scrollLeft is negative in modern browsers
    let next = cur;
    if (x - stickyW < cur) next = x - stickyW;
    else if (x + w > cur + viewW) next = x + w - viewW;
    if (next !== cur) scroll.scrollLeft = rtl ? -next : next;
  }

  /* ------------------------------------------------------------ editing */
  function startEdit(r, dc) {
    if (editing) return;
    const di = order[r];
    const ci = display[dc];
    if (r < 0 || dc < 0) return;
    if (!canEdit(ci)) {
      if (o.onRowOpen) o.onRowOpen(di);
      return;
    }
    const v = rows[di][ci];
    if (v !== null && typeof v === 'object' && v.$binary !== undefined) { toast.info(t('grid.binaryNotEditable')); return; }
    setActive(r, dc);
    const td = cellEl(r, dc);
    if (!td) return;
    const str = v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v);
    const multi = str.includes('\n') || str.length > LONG_TEXT;
    const input = h(multi ? 'textarea' : 'input', { class: 'dg-editor', dir: 'auto', spellcheck: 'false', 'aria-label': t('grid.editCell', { column: columns[ci].name }) });
    input.value = str;
    if (v === null) input.placeholder = 'NULL';
    else if (v === undefined) input.placeholder = 'DEFAULT';
    td.classList.add('dg-editing');
    td.replaceChildren(input);
    editing = { r, dc, di, ci, input, td, original: v, top: scroll.scrollTop, left: scroll.scrollLeft };
    input.focus({ preventScroll: true });
    input.select();
    input.addEventListener('keydown', (e) => {
      if (e.isComposing || e.keyCode === 229) return;
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelEdit(); }
      else if (e.key === 'Enter' && (!multi || modKey(e))) { e.preventDefault(); e.stopPropagation(); commitEdit(true, multi ? undefined : 'down'); }
      else if (e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); commitEdit(true, e.shiftKey ? 'prev' : 'next'); }
      e.stopPropagation();
    });
    input.addEventListener('blur', () => commitEdit(false));
  }

  function cancelEdit() {
    if (!editing) return;
    const { r, dc } = editing;
    editing = null;
    renderBody(true);
    setActive(r, dc, { scrollTo: false });
    focusActive();
  }

  async function commitEdit(refocus, move) {
    if (!editing) return;
    const ed = editing;
    editing = null;
    const val = ed.input.value;
    const origStr = ed.original === null || ed.original === undefined ? null : typeof ed.original === 'object' ? JSON.stringify(ed.original) : String(ed.original);
    const unchanged = origStr === null ? val === '' : val === origStr;
    renderBody(true);
    if (refocus) {
      focusActive();
      if (move === 'down') setActive(ed.r + 1, ed.dc);
      else if (move === 'next') setActive(ed.r, ed.dc + 1);
      else if (move === 'prev') setActive(ed.r, ed.dc - 1);
      else setActive(ed.r, ed.dc);
    }
    if (unchanged) return;
    await saveValue(ed.di, ed.ci, val);
  }

  async function saveValue(di, ci, val) {
    const snapshot = [...rows[di]];
    const prev = rows[di][ci];
    rows[di][ci] = typeof prev === 'number' && val !== null && val !== '' && !Number.isNaN(Number(val)) ? Number(val) : val;
    renderBody(true);
    const r = order.indexOf(di);
    const td = cellEl(r, display.indexOf(ci));
    td?.classList.add('dg-dirty');
    try {
      await o.onEdit(di, ci, val, snapshot);
      td?.classList.remove('dg-dirty');
    } catch (e) {
      rows[di][ci] = prev;
      renderBody(true);
      toastError(e, t('grid.saveFailed'));
    }
  }

  /* ------------------------------------------------------------ copy */
  const cellText = (v) => (v === null || v === undefined ? 'NULL' : typeof v === 'object' ? (v.$binary !== undefined ? '[binary]' : JSON.stringify(v)) : String(v));
  const tsvField = (s) => (/[\t\n\r"]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s);

  function rowsFor(indexes) { return indexes.map((i) => rows[i]); }

  /** Serialize rows; exported via the returned object too. */
  function serialize(indexes, format) {
    const cols = columns.map((c) => c.name);
    const data = rowsFor(indexes);
    if (format === 'json') {
      return JSON.stringify(data.map((r) => Object.fromEntries(cols.map((c, i) => [c, r[i] && typeof r[i] === 'object' && r[i].$binary !== undefined ? null : r[i]]))), null, 2);
    }
    if (format === 'sql') {
      const q = o.quote || '`';
      const qi = (n) => q + String(n).split(q).join(q + q) + q;
      const lit = (v) => {
        if (v === null || v === undefined) return 'NULL';
        if (typeof v === 'number') return String(v);
        if (typeof v === 'object') return v.$binary !== undefined ? 'NULL' : `'${JSON.stringify(v).replace(/'/g, "''")}'`;
        let s = String(v).replace(/'/g, "''");
        if (q === '`') s = s.replace(/\\/g, '\\\\');
        return `'${s}'`;
      };
      const values = data.map((r) => `  (${r.map(lit).join(', ')})`).join(',\n');
      return `INSERT INTO ${qi(o.tableName || 'table')} (${cols.map(qi).join(', ')}) VALUES\n${values};`;
    }
    const head = cols.map(tsvField).join('\t');
    return [head, ...data.map((r) => r.map((v) => tsvField(cellText(v))).join('\t'))].join('\n');
  }

  async function copy(textValue, count) {
    const ok = await copyText(textValue);
    if (ok) toast.success(count ? t('grid.copiedRows', { n: count }) : t('common.copied'));
    else toast.error(t('grid.copyFailed'));
  }

  function targetRows(r) {
    const di = order[r];
    return selected.size && selected.has(di) ? [...selected].sort((a, b) => a - b) : [di];
  }

  function openValue(r, dc) {
    const di = order[r];
    const ci = display[dc];
    const v = rows[di][ci];
    if (v === null || (typeof v === 'object' && v?.$binary !== undefined)) return;
    const str = typeof v === 'object' ? JSON.stringify(v) : String(v);
    let lang = 'text';
    if (JSON_TYPE.test(columns[ci].type || '') || typeof v === 'object') lang = 'json';
    else if (/^\s*[[{]/.test(str)) { try { JSON.parse(str); lang = 'json'; } catch { /* text */ } }
    viewerDialog({ title: columns[ci].name, text: str, language: lang });
  }

  /* ------------------------------------------------------------ events */
  scroll.addEventListener('scroll', () => {
    // a late scroll event from bringing the cell into view (RTL) must not close the fresh editor
    if (editing && (Math.abs(scroll.scrollTop - editing.top) > 1 || Math.abs(scroll.scrollLeft - editing.left) > 1)) commitEdit(false);
    renderBody();
  }, { passive: true });

  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-r]');
    if (!tr) return;
    const r = Number(tr.dataset.r);
    if (e.target.closest('[data-check]')) { setActive(r, -1, { scrollTo: false }); toggleRow(r, e.shiftKey); return; }
    const td = e.target.closest('td[data-c]');
    if (!td || editing?.td === td) return;
    const dc = Number(td.dataset.c);
    if (e.target.closest('[data-fk]')) { const ci = display[dc]; o.onFkClick?.(columns[ci], rows[order[r]][ci], order[r]); return; }
    if (e.target.closest('[data-expand]')) { openValue(r, dc); return; }
    if (e.shiftKey && selectable) {
      selectRange(anchor ?? active.r, r, false);
      renderBody(true);
      emitSelection();
    } else if (modKey(e) && selectable) {
      toggleRow(r, false);
    } else {
      anchor = r;
    }
    setActive(r, dc, { scrollTo: false });
    focusActive();
  });

  // keep the roving position in sync with real focus (clicks, header buttons, checkboxes)
  table.addEventListener('focusin', (e) => {
    const x = e.target;
    let r;
    let c;
    if (x.dataset?.hc !== undefined) { r = -1; c = Number(x.dataset.hc); }
    else if (x.matches?.('input[data-check]')) { r = Number(x.closest('tr').dataset.r); c = -1; }
    else if (x.matches?.('td[data-c]')) { r = Number(x.closest('tr').dataset.r); c = Number(x.dataset.c); }
    else return;
    if (r === active.r && c === active.c) return;
    cellEl(active.r, active.c)?.classList.remove('dg-active');
    active = { r, c };
    cellEl(r, c)?.classList.add('dg-active');
    syncTabStop(false);
  });

  tbody.addEventListener('dblclick', (e) => {
    const td = e.target.closest('td[data-c]');
    const tr = e.target.closest('tr[data-r]');
    if (!td || !tr || e.target.closest('button,input,textarea')) return;
    startEdit(Number(tr.dataset.r), Number(td.dataset.c));
  });

  scroll.addEventListener('keydown', (e) => {
    if (editing || e.target.closest('.dg-editor')) return;
    const rtl = isRtl();
    const mod = modKey(e);
    const pageRows = Math.max(1, Math.floor(scroll.clientHeight / RH) - 2);
    const { r, c } = active;
    // header buttons / checkboxes keep their native Enter / Space
    if ((e.key === 'Enter' || e.key === ' ') && /^(BUTTON|INPUT)$/.test(e.target.tagName)) return;
    switch (e.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        e.preventDefault();
        const nr = e.key === 'ArrowDown' ? r + 1 : r - 1;
        if (e.shiftKey && selectable && r >= 0 && nr >= 0) {
          if (anchor === null) anchor = r;
          selectRange(anchor, Math.max(0, Math.min(order.length - 1, nr)), false);
          emitSelection();
          renderBody(true);
        }
        setActive(nr, c);
        break;
      }
      case 'ArrowRight': e.preventDefault(); setActive(r, rtl ? c - 1 : c + 1); break;
      case 'ArrowLeft': e.preventDefault(); setActive(r, rtl ? c + 1 : c - 1); break;
      case 'Home': e.preventDefault(); setActive(mod ? 0 : r, 0); break;
      case 'End': e.preventDefault(); setActive(mod ? order.length - 1 : r, display.length - 1); break;
      case 'PageDown': e.preventDefault(); setActive(Math.max(0, r + pageRows), c); break;
      case 'PageUp': e.preventDefault(); setActive(Math.max(0, r - pageRows), c); break;
      case 'Enter':
      case 'F2': e.preventDefault(); if (order.length && r >= 0) startEdit(r, c); break;
      case ' ':
        if (selectable && order.length && r >= 0) { e.preventDefault(); anchor = r; toggleRow(r, e.shiftKey); }
        break;
      case 'Escape':
        if (order.length && r >= 0 && c >= 0 && o.onCellEscape?.(order[r], display[c])) { e.preventDefault(); break; }
        if (selected.size) { e.preventDefault(); selected.clear(); renderBody(true); emitSelection(); }
        break;
      case 'Delete':
        if (o.onDeleteKey && order.length && r >= 0) { e.preventDefault(); o.onDeleteKey(targetRows(r)); }
        break;
      default:
        if (mod && (e.key === 'a' || e.key === 'A') && selectable) {
          e.preventDefault();
          rows.forEach((_, i) => selected.add(i));
          renderBody(true);
          emitSelection();
        } else if (mod && (e.key === 'c' || e.key === 'C') && order.length && r >= 0 && c >= 0) {
          if (window.getSelection()?.toString()) return;
          e.preventDefault();
          if (selected.size) copy(serialize([...selected].sort((a, b) => a - b), 'tsv'), selected.size);
          else copy(cellText(rows[order[r]][display[c]]));
        }
    }
  });

  bindContextMenu(tbody, (e) => {
    const tr = e.target?.closest?.('tr[data-r]');
    const td = e.target?.closest?.('td[data-c]');
    if (!tr) return null;
    const r = Number(tr.dataset.r);
    const dc = td ? Number(td.dataset.c) : Math.max(0, active.c);
    if (!selected.has(order[r])) setActive(r, dc, { scrollTo: false });
    const di = order[r];
    const ci = display[dc];
    const col = columns[ci];
    const v = rows[di][ci];
    const idx = targetRows(r);
    const multi = idx.length > 1;
    const items = [
      { label: t('grid.copyCell'), icon: 'copy', onSelect: () => copy(cellText(v)) },
      { label: multi ? t('grid.copyRowsTsv', { n: idx.length }) : t('grid.copyRowTsv'), icon: 'table-2', onSelect: () => copy(serialize(idx, 'tsv'), idx.length) },
      { label: t('grid.copyJson'), icon: 'braces', onSelect: () => copy(serialize(idx, 'json'), idx.length) },
      { label: t('grid.copySql'), icon: 'code', onSelect: () => copy(serialize(idx, 'sql'), idx.length) },
      { separator: true },
      { label: t('grid.viewValue'), icon: 'maximize', disabled: v === null || (typeof v === 'object' && v?.$binary !== undefined), onSelect: () => openValue(r, dc) },
    ];
    if (col.fk && o.onFkClick && v !== null) items.push({ label: t('grid.followFk', { table: col.fk.table }), icon: 'arrow-right', onSelect: () => o.onFkClick(col, v, di) });
    if (canEdit(ci)) {
      items.push({ label: t('grid.editCell', { column: col.name }), icon: 'pencil', onSelect: () => startEdit(order.indexOf(di), dc) });
      if (col.nullable !== false && v !== null) items.push({ label: t('grid.setNull'), icon: 'eraser', onSelect: () => saveValue(di, ci, null) });
    }
    const extra = o.rowMenu?.(idx, ci) || [];
    if (extra.length) items.push({ separator: true }, ...extra);
    return items;
  });

  const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => renderBody()) : null;
  ro?.observe(scroll);

  function updateOverlay() {
    overlay.replaceChildren();
    if (loading) {
      overlay.hidden = false;
      overlay.className = 'absolute inset-x-0 bottom-0 top-9 flex items-start justify-center bg-surface/60 pt-16';
      overlay.appendChild(spinner({ size: 'size-5', label: t('common.loading') }));
    } else if (!rows.length) {
      overlay.hidden = false;
      overlay.className = 'pointer-events-none absolute inset-x-0 bottom-0 top-9 flex items-start justify-center';
      overlay.appendChild(emptyState({ icon: 'rows', title: o.emptyMessage || t('grid.empty') }));
    } else {
      overlay.hidden = true;
    }
    el.setAttribute('aria-busy', String(loading));
  }

  setData({ columns: o.columns || [], rows: o.rows || [], sort: o.sort });
  initialized = true;

  return {
    el,
    setData,
    setLoading(b) { loading = b; updateOverlay(); },
    getSelection: () => [...selected].sort((a, b) => a - b),
    getSelectedRows: () => rowsFor([...selected].sort((a, b) => a - b)),
    clearSelection() { selected.clear(); renderBody(true); emitSelection(); },
    getRow: (i) => rows[i],
    updateRow(i, row) { rows[i] = row; renderBody(true); },
    redraw() { renderBody(true); },
    setSelection(indexes) {
      selected.clear();
      (indexes || []).forEach((i) => { if (i >= 0 && i < rows.length) selected.add(i); });
      renderBody(true);
      emitSelection();
    },
    getColumns: () => columns,
    /** Serialize data rows (by index) as 'tsv' | 'json' | 'sql'. */
    serialize,
    focus: focusActive,
    destroy() { ro?.disconnect(); },
  };
}
