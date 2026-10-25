/**
 * Lightweight SQL editor: a transparent <textarea> stacked over a syntax-highlighted <pre>.
 *
 * Features: highlighting, line numbers, auto-indent, bracket/quote pairing, Tab / Shift+Tab
 * indentation (Esc then Tab leaves the editor), Ctrl/Cmd+/ comment toggle, Ctrl/Cmd+Enter run
 * (selection or statement under the cursor; +Shift runs everything), Ctrl/Cmd+Shift+F format,
 * autocomplete (tables, `table.`/`alias.` columns, keywords, functions; Ctrl+Space to force).
 * Always dir="ltr". IME safe: while a composition (Japanese/Chinese/Korean input) is active
 * the editor never touches the textarea value, shows the native text and hides the overlay.
 * All edits go through execCommand('insertText') so native undo/redo keeps working.
 * @module ui/sqleditor
 */
import { h, cls, uid, modKey } from './dom.js';
import { highlightSql, KEYWORDS } from '../sql/highlight.js';
import { statementAt } from '../sql/split.js';
import { t, plural } from '../i18n/index.js';
import { announce } from './toast.js';

const PAIRS = { '(': ')', '[': ']', '{': '}', "'": "'", '"': '"', '`': '`' };
const CLOSERS = new Set([')', ']', '}']);
const WORD = /[\w$\u0080-￿]/;
const INDENT = '  ';

/**
 * @typedef {object} Completions
 * @property {Record<string, string[]>} [tables]  table name → column names
 * @property {string[]} [functions]
 * @property {string[]} [keywords]
 */

/**
 * @typedef {object} SqlEditor
 * @property {HTMLElement} el
 * @property {HTMLTextAreaElement} textarea
 * @property {() => string} getValue
 * @property {(v: string) => void} setValue         replaces content (resets undo history)
 * @property {() => void} focus
 * @property {() => string} getRunnable             selection, else statement under the cursor
 * @property {(text: string) => void} insert        insert at cursor (undoable)
 * @property {(text: string) => void} replaceAll    replace whole content (undoable)
 * @property {(c: Completions|null) => void} setCompletions
 * @property {() => Promise<void>} format
 * @property {() => void} refresh                   re-measure after becoming visible
 * @property {() => void} destroy
 */

/**
 * Create an SQL editor.
 * @param {object} [o]
 * @param {string} [o.value]
 * @param {(sql: string, mode: 'selection'|'statement'|'all') => void} [o.onRun]
 * @param {(value: string) => void} [o.onChange]
 * @param {(sql: string) => Promise<string>} [o.onFormat]  e.g. sql => api('sql.format',{sql}).then(r => r.sql)
 * @param {Completions} [o.completions]
 * @param {string} [o.dialect]  driver id (mysql | mariadb | pgsql | sqlite | sqlsrv) for statement splitting
 * @param {string} [o.quote='`']  identifier quote char used when completing odd names
 * @param {string} [o.placeholder]
 * @param {string} [o.ariaLabel]
 * @param {string} [o.className]
 * @returns {SqlEditor}
 */
export function createSqlEditor(o = {}) {
  const listId = uid('ac');
  const gutter = h('div', { class: 'sqled-gutter', 'aria-hidden': 'true' });
  const pre = h('pre', { class: 'sqled-pre', 'aria-hidden': 'true' });
  // Stays a multi-line textbox (a combobox role would break line-by-line reading); the popup is
  // linked with aria-controls + aria-activedescendant and its opening is announced.
  const hint = h('span', { id: `${listId}-hint`, class: 'sr-only' }, t('a11y.editorHint'));
  const ta = h('textarea', {
    class: 'sqled-input',
    spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off', autocorrect: 'off', wrap: 'off',
    dir: 'ltr', 'aria-label': o.ariaLabel || t('editor.label'), 'aria-multiline': 'true',
    'aria-autocomplete': 'list', 'aria-describedby': hint.id,
    title: t('a11y.editorHint'),
    placeholder: o.placeholder || '',
  });
  ta.value = o.value || '';
  const stack = h('div', { class: 'sqled-stack' }, pre, ta);
  const scroll = h('div', { class: 'sqled-scroll' }, gutter, stack);
  const el = h('div', { class: cls('sqled', o.className), dir: 'ltr' }, scroll, hint);

  let completions = o.completions || null;
  let composing = false;
  let tabEscapes = false;
  let lineCount = 0;
  let raf = 0;
  let lastValue = null;

  /* ------------------------------------------------------------ rendering */
  function render() {
    raf = 0;
    const v = ta.value;
    if (v !== lastValue) {
      lastValue = v;
      const frag = highlightSql(v);
      frag.appendChild(document.createTextNode('\n ')); // keep height for a trailing empty line
      pre.replaceChildren(frag);
      const lines = v.split('\n').length;
      if (lines !== lineCount) {
        lineCount = lines;
        let s = '';
        for (let i = 1; i <= lines; i++) s += i === lines ? `${i}` : `${i}\n`;
        gutter.textContent = s;
      }
    }
    fit();
  }
  const schedule = () => { if (!raf) raf = requestAnimationFrame(render); };

  function fit() {
    const hgt = scroll.clientHeight;
    if (hgt) {
      pre.style.minHeight = `${hgt}px`;
      gutter.style.minHeight = `${hgt}px`;
    }
  }

  /* ------------------------------------------------------------ measuring */
  let measureCtx = null;
  function metrics() {
    const cs = getComputedStyle(ta);
    if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
    measureCtx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    return { lh: parseFloat(cs.lineHeight) || 20, pt: parseFloat(cs.paddingTop) || 0, pl: parseFloat(cs.paddingLeft) || 0 };
  }
  /** Caret position (px) relative to the stack's top-left. */
  function caretXY(pos = ta.selectionStart) {
    const m = metrics();
    const v = ta.value;
    const lineStart = v.lastIndexOf('\n', pos - 1) + 1;
    const line = v.slice(0, pos).split('\n').length - 1;
    const prefix = v.slice(lineStart, pos).replace(/\t/g, INDENT);
    const x = m.pl + measureCtx.measureText(prefix).width;
    return { x, y: m.pt + line * m.lh, lh: m.lh };
  }
  function ensureCaretVisible() {
    const { x, y, lh } = caretXY(ta.selectionEnd);
    const gw = gutter.offsetWidth;
    const viewH = scroll.clientHeight;
    const viewW = scroll.clientWidth - gw;
    if (y < scroll.scrollTop) scroll.scrollTop = y - 4;
    else if (y + lh > scroll.scrollTop + viewH) scroll.scrollTop = y + lh - viewH + 8;
    if (x < scroll.scrollLeft + 16) scroll.scrollLeft = Math.max(0, x - 32);
    else if (x > scroll.scrollLeft + viewW - 16) scroll.scrollLeft = x - viewW + 48;
  }

  /* ------------------------------------------------------------ editing primitives */
  function insertText(text) {
    ta.focus();
    let ok = false;
    try { ok = document.execCommand('insertText', false, text); } catch { ok = false; }
    if (!ok) {
      ta.setRangeText(text, ta.selectionStart, ta.selectionEnd, 'end');
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }
  function setSel(a, b = a) { ta.selectionStart = a; ta.selectionEnd = b; }
  function replaceRange(start, end, text, selA, selB) {
    setSel(start, end);
    insertText(text);
    if (selA !== undefined) setSel(selA, selB ?? selA);
  }

  function transformLines(fn) {
    const v = ta.value;
    const s = ta.selectionStart;
    const e = ta.selectionEnd;
    const blockStart = v.lastIndexOf('\n', s - 1) + 1;
    let blockEnd = v.indexOf('\n', e > s && v[e - 1] === '\n' ? e - 1 : e);
    if (blockEnd < 0) blockEnd = v.length;
    const lines = v.slice(blockStart, blockEnd).split('\n');
    const out = fn(lines);
    const text = out.join('\n');
    replaceRange(blockStart, blockEnd, text, blockStart, blockStart + text.length);
    if (s === e) {
      const delta = out[0].length - lines[0].length;
      setSel(Math.max(blockStart, s + delta));
    }
  }

  function indent(outdent) {
    if (!outdent && ta.selectionStart === ta.selectionEnd) { insertText(INDENT); return; }
    transformLines((lines) => lines.map((l) => (outdent ? l.replace(/^( {1,2}|\t)/, '') : INDENT + l)));
  }

  function toggleComment() {
    transformLines((lines) => {
      const all = lines.filter((l) => l.trim()).every((l) => /^\s*--/.test(l));
      return lines.map((l) => (all ? l.replace(/^(\s*)-- ?/, '$1') : (l.trim() ? `-- ${l}` : l)));
    });
  }

  function newline() {
    const v = ta.value;
    const s = ta.selectionStart;
    const lineStart = v.lastIndexOf('\n', s - 1) + 1;
    const before = v.slice(lineStart, s);
    const ind = /^[ \t]*/.exec(before)[0];
    if (v[s - 1] === '(' && v[ta.selectionEnd] === ')') {
      insertText(`\n${ind}${INDENT}\n${ind}`);
      setSel(s + 1 + ind.length + INDENT.length);
      return;
    }
    insertText(`\n${ind}${/\(\s*$/.test(before) ? INDENT : ''}`);
  }

  /* ------------------------------------------------------------ autocomplete */
  const popup = h('div', { id: listId, role: 'listbox', 'aria-label': t('a11y.suggestions'), class: 'popover fixed z-[95] max-h-60 min-w-48 max-w-[min(90vw,360px)] overflow-auto p-1 font-mono text-[12.5px]', hidden: true, dir: 'ltr' });
  let acItems = [];
  let acIndex = 0;
  let acRange = null; // [start, end] of the word being completed

  function aliasMap(sql) {
    const map = {};
    const re = /\b(?:FROM|JOIN|UPDATE|INTO)\s+[`"[]?([\w$\u0080-￿]+)[`"\]]?(?:\s+(?:AS\s+)?([\w$\u0080-￿]+))?/gi;
    let m;
    while ((m = re.exec(sql))) {
      map[m[1].toLowerCase()] = m[1];
      if (m[2] && !KEYWORDS.has(m[2].toUpperCase())) map[m[2].toLowerCase()] = m[1];
    }
    return map;
  }

  function findTable(name) {
    if (!completions?.tables) return null;
    if (completions.tables[name]) return name;
    const lower = name.toLowerCase();
    return Object.keys(completions.tables).find((k) => k.toLowerCase() === lower) || null;
  }

  function computeSuggestions(force) {
    // Before the schema completions arrive, keywords are still offered; setCompletions()
    // re-opens the popup once tables/columns are known.
    const comp = completions || {};
    const v = ta.value;
    const pos = ta.selectionStart;
    if (pos !== ta.selectionEnd) return null;
    const before = v.slice(Math.max(0, pos - 200), pos);
    // inside a string or comment? (cheap check on the current line)
    const line = before.slice(before.lastIndexOf('\n') + 1);
    if (/--/.test(line) || ((line.match(/'/g) || []).length % 2 === 1)) return null;
    const q = /[`"[]?([\w$\u0080-￿]+)[`"\]]?\.([\w$\u0080-￿]*)$/.exec(before);
    let prefix;
    let items = [];
    if (q) {
      prefix = q[2];
      const aliases = aliasMap(v);
      const table = findTable(aliases[q[1].toLowerCase()] || q[1]);
      if (!table) return null;
      items = comp.tables[table].map((c) => ({ text: c, kind: 'column', detail: table }));
    } else {
      const w = /([\w$\u0080-￿]*)$/.exec(before);
      prefix = w ? w[1] : '';
      if (!force && prefix.length < 1) return null;
      const used = Object.values(aliasMap(v)).map(findTable).filter(Boolean);
      const seen = new Set();
      const add = (text, kind, detail) => { const k = `${kind}:${text}`; if (!seen.has(k)) { seen.add(k); items.push({ text, kind, detail }); } };
      for (const tname of Object.keys(comp.tables || {})) add(tname, 'table');
      for (const tname of used) for (const c of comp.tables[tname]) add(c, 'column', tname);
      for (const k of comp.keywords || [...KEYWORDS]) add(k, 'keyword');
      for (const f of comp.functions || []) add(f, 'function');
    }
    const lp = prefix.toLowerCase();
    const filtered = items
      .filter((it) => it.text.toLowerCase().startsWith(lp) && it.text.toLowerCase() !== lp)
      .slice(0, 60);
    if (!filtered.length) return null;
    return { items: filtered, start: pos - prefix.length, end: pos };
  }

  let wantAc = false; // user is typing a word: reopen when completions arrive late
  function openAc(force = false) {
    const res = computeSuggestions(force);
    if (!res) { closeAc(); wantAc = !completions; return; }
    wantAc = true;
    acItems = res.items;
    acRange = [res.start, res.end];
    acIndex = 0;
    renderAc();
    if (!popup.isConnected) el.appendChild(popup); // inside the page landmarks (fixed position escapes the scroller)
    if (popup.hidden) announce(plural('a11y.suggestionCount', acItems.length));
    popup.hidden = false;
    ta.setAttribute('aria-controls', listId); // only while the popup is in the DOM
    placeAc();
  }
  function closeAc() {
    wantAc = false;
    if (popup.hidden) return;
    popup.hidden = true;
    acItems = [];
    ta.removeAttribute('aria-activedescendant');
    ta.removeAttribute('aria-controls');
  }
  const KIND_CLS = { table: 'text-info', column: 'text-success', keyword: 'tok-kw', function: 'tok-fn' };
  function renderAc() {
    popup.replaceChildren(...acItems.map((it, i) => h('div', {
      id: `${listId}-${i}`, role: 'option', 'aria-selected': String(i === acIndex),
      class: cls('flex cursor-pointer items-center gap-3 rounded px-2 py-1', i === acIndex && 'bg-surface-2'),
      onPointerdown: (e) => { e.preventDefault(); acIndex = i; acceptAc(); },
    },
    h('span', { class: 'min-w-0 flex-1 truncate' }, it.text),
    h('span', { class: cls('font-sans text-[11px]', KIND_CLS[it.kind]) }, it.detail ? `${t(`editor.kind.${it.kind}`)} · ${it.detail}` : t(`editor.kind.${it.kind}`)))));
    ta.setAttribute('aria-activedescendant', `${listId}-${acIndex}`);
    popup.children[acIndex]?.scrollIntoView({ block: 'nearest' });
  }
  function placeAc() {
    const { x, y, lh } = caretXY();
    const r = stack.getBoundingClientRect();
    let left = r.left + x;
    let top = r.top + y + lh + 2;
    const pw = popup.offsetWidth;
    const ph = popup.offsetHeight;
    if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
    if (top + ph > window.innerHeight - 8) top = r.top + y - ph - 2;
    popup.style.left = `${Math.max(8, left)}px`;
    popup.style.top = `${Math.max(8, top)}px`;
  }
  function acceptAc() {
    const it = acItems[acIndex];
    if (!it || !acRange) return;
    let text = it.text;
    if ((it.kind === 'table' || it.kind === 'column') && /[^\w$\u0080-￿]/.test(text)) {
      const qc = o.quote || '`';
      text = qc + text.split(qc).join(qc + qc) + qc;
    }
    const isFn = it.kind === 'function';
    replaceRange(acRange[0], acRange[1], isFn ? `${text}()` : text);
    if (isFn) setSel(ta.selectionStart - 1);
    closeAc();
  }

  /* ------------------------------------------------------------ events */
  ta.addEventListener('compositionstart', () => {
    composing = true;
    closeAc();
    el.classList.add('sqled-composing');
  });
  ta.addEventListener('compositionend', () => {
    composing = false;
    el.classList.remove('sqled-composing');
    lastValue = null;
    schedule();
    o.onChange?.(ta.value);
  });

  ta.addEventListener('input', (e) => {
    schedule();
    if (composing || e.isComposing) return;
    o.onChange?.(ta.value);
    const typed = e.data;
    if (e.inputType === 'insertText' && typed && (WORD.test(typed.slice(-1)) || typed === '.')) openAc(false);
    else if (e.inputType && e.inputType.startsWith('delete') && !popup.hidden) openAc(false);
    else closeAc();
    requestAnimationFrame(ensureCaretVisible);
  });

  ta.addEventListener('keydown', (e) => {
    if (composing || e.isComposing || e.keyCode === 229) return;
    const mod = modKey(e);
    const acOpen = !popup.hidden && acItems.length;

    if (acOpen) {
      if (e.key === 'ArrowDown') { e.preventDefault(); acIndex = (acIndex + 1) % acItems.length; renderAc(); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); acIndex = (acIndex - 1 + acItems.length) % acItems.length; renderAc(); return; }
      if ((e.key === 'Enter' && !mod) || e.key === 'Tab') { e.preventDefault(); acceptAc(); return; }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeAc(); return; }
    }

    if (e.key === 'Enter' && mod) {
      e.preventDefault();
      if (e.shiftKey) o.onRun?.(ta.value, 'all');
      else {
        const sel = ta.selectionStart !== ta.selectionEnd;
        o.onRun?.(getRunnable(), sel ? 'selection' : 'statement');
      }
      return;
    }
    if (mod && e.shiftKey && (e.key === 'F' || e.key === 'f')) { e.preventDefault(); format(); return; }
    if (mod && e.key === '/') { e.preventDefault(); toggleComment(); return; }
    if (e.ctrlKey && (e.key === ' ' || e.code === 'Space')) { e.preventDefault(); openAc(true); return; }

    if (e.key === 'Escape') { tabEscapes = true; return; }
    if (e.key === 'Tab') {
      if (tabEscapes) { tabEscapes = false; return; } // let focus leave the editor
      e.preventDefault();
      indent(e.shiftKey);
      return;
    }
    tabEscapes = false;

    if (e.key === 'Enter' && !e.shiftKey && !e.altKey && !mod) { e.preventDefault(); newline(); return; }

    const s = ta.selectionStart;
    const end = ta.selectionEnd;
    const v = ta.value;
    const next = v[end];
    const prev = v[s - 1];

    if (e.key === 'Backspace' && s === end && s > 0 && PAIRS[prev] && PAIRS[prev] === next) {
      e.preventDefault();
      replaceRange(s - 1, s + 1, '');
      return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;

    // skip over an auto-inserted closer
    if ((CLOSERS.has(e.key) || e.key === "'" || e.key === '"' || e.key === '`') && s === end && next === e.key) {
      e.preventDefault();
      setSel(s + 1);
      return;
    }
    if (PAIRS[e.key]) {
      const isQuote = !CLOSERS.has(PAIRS[e.key]) && e.key === PAIRS[e.key];
      if (s !== end) { // wrap selection
        e.preventDefault();
        const inner = v.slice(s, end);
        replaceRange(s, end, e.key + inner + PAIRS[e.key], s + 1, s + 1 + inner.length);
        return;
      }
      const nextOk = next === undefined || /[\s)\]},;]/.test(next);
      const prevOk = !isQuote || prev === undefined || !WORD.test(prev);
      if (nextOk && prevOk) {
        e.preventDefault();
        insertText(e.key + PAIRS[e.key]);
        setSel(s + 1);
      }
    }
  });

  ta.addEventListener('blur', () => setTimeout(closeAc, 100));
  ta.addEventListener('click', closeAc);
  ta.addEventListener('keyup', (e) => {
    if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(e.key)) {
      if (!popup.hidden && (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End')) closeAc();
      ensureCaretVisible();
    }
  });
  scroll.addEventListener('scroll', () => { if (!popup.hidden) closeAc(); }, { passive: true });

  const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => fit()) : null;
  ro?.observe(scroll);

  render();

  /* ------------------------------------------------------------ public API */
  function getRunnable() {
    const s = ta.selectionStart;
    const e = ta.selectionEnd;
    if (s !== e) return ta.value.slice(s, e);
    return statementAt(ta.value, s, { dialect: o.dialect })?.text.trim() || '';
  }

  async function format() {
    if (!o.onFormat) return;
    const hasSel = ta.selectionStart !== ta.selectionEnd;
    const src = hasSel ? ta.value.slice(ta.selectionStart, ta.selectionEnd) : ta.value;
    if (!src.trim()) return;
    const start = hasSel ? ta.selectionStart : 0;
    const end = hasSel ? ta.selectionEnd : ta.value.length;
    try {
      const out = await o.onFormat(src);
      if (typeof out === 'string' && out !== src) replaceRange(start, end, out, start, start + out.length);
    } catch (err) {
      console.error(err);
      throw err;
    }
  }

  return {
    el,
    textarea: ta,
    getValue: () => ta.value,
    setValue(v) { ta.value = v ?? ''; lastValue = null; render(); },
    focus: () => ta.focus({ preventScroll: true }),
    getRunnable,
    insert: (text) => insertText(text),
    replaceAll: (text) => replaceRange(0, ta.value.length, text, text.length),
    setCompletions(c) {
      completions = c;
      if (wantAc && !composing && document.activeElement === ta) openAc(false);
    },
    format,
    refresh() { lastValue = null; render(); },
    destroy() { ro?.disconnect(); closeAc(); popup.remove(); if (raf) cancelAnimationFrame(raf); },
  };
}
