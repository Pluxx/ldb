/**
 * Command palette (Ctrl/Cmd+K): fuzzy search over items supplied by registered providers
 * (databases, tables, views, actions). Items may open a nested page (`children`), e.g.
 * "Change language" → list of languages. Backspace on an empty query goes back.
 * @module ui/palette
 */
import { h, cls, uid, modKey } from './dom.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';

/**
 * @typedef {object} PaletteItem
 * @property {string} id
 * @property {string} label
 * @property {string} [group]     group heading
 * @property {string} [hint]      secondary text (e.g. database name)
 * @property {string} [icon]
 * @property {string} [keywords]  extra search text
 * @property {string} [shortcut]
 * @property {string} [lang]
 * @property {() => void} [run]
 * @property {() => PaletteItem[]|Promise<PaletteItem[]>} [children]  opens a nested page
 */

const providers = new Set();
let openDialog = null;

/**
 * Register a provider. Called on every palette open; may be async.
 * @param {() => PaletteItem[]|Promise<PaletteItem[]>} fn
 * @returns {() => void} unregister
 */
export function registerPaletteProvider(fn) {
  providers.add(fn);
  return () => providers.delete(fn);
}

/** Install the global Ctrl/Cmd+K shortcut. Call once. */
export function initPalette() {
  window.addEventListener('keydown', (e) => {
    if (modKey(e) && !e.shiftKey && !e.altKey && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (openDialog) openDialog.close(); else openPalette();
    }
  });
}

/**
 * Score `text` against a fuzzy query (subsequence match, bonus for word starts and
 * contiguous runs). Returns -1 when not matching.
 * @param {string} text
 * @param {string} query  lower-cased
 */
export function fuzzyScore(text, query) {
  if (!query) return 0;
  const s = text.toLowerCase();
  const direct = s.indexOf(query);
  if (direct >= 0) return 1000 - direct * 2 - (s.length - query.length) * 0.1 + (direct === 0 ? 200 : 0);
  let score = 0;
  let qi = 0;
  let prev = -2;
  for (let i = 0; i < s.length && qi < query.length; i++) {
    if (s[i] === query[qi]) {
      score += prev === i - 1 ? 8 : 1;
      if (i === 0 || /[\s._\-/]/.test(s[i - 1])) score += 6;
      prev = i;
      qi++;
    }
  }
  return qi === query.length ? score : -1;
}

/**
 * Open the palette.
 * @param {{items?: PaletteItem[], placeholder?: string}} [opts]  explicit items skip providers
 */
export async function openPalette(opts = {}) {
  if (openDialog) return;
  const listId = uid('pal');
  const stack = [];
  let all = [];
  let shown = [];
  let index = 0;
  const previous = document.activeElement;

  const input = h('input', {
    class: 'h-12 w-full min-w-0 bg-transparent px-1 text-[15px] outline-none placeholder:text-subtle focus-visible:outline-none',
    placeholder: opts.placeholder || t('palette.placeholder'),
    role: 'combobox', 'aria-expanded': 'true', 'aria-controls': listId, 'aria-autocomplete': 'list',
    'aria-label': t('palette.label'), autocomplete: 'off', spellcheck: 'false', dir: 'auto',
  });
  const back = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', hidden: true, 'aria-label': t('common.back'), onClick: () => pop() }, icon('arrow-left'));
  const list = h('div', { id: listId, role: 'listbox', class: 'max-h-[min(60dvh,440px)] overflow-auto p-1.5' });
  const footer = h('div', { class: 'hidden items-center gap-3 border-t border-border px-3 py-2 text-[11.5px] text-muted sm:flex' },
    h('span', { class: 'flex items-center gap-1' }, h('kbd', { class: 'kbd' }, '↑'), h('kbd', { class: 'kbd' }, '↓'), t('palette.navigate')),
    h('span', { class: 'flex items-center gap-1' }, h('kbd', { class: 'kbd' }, '↵'), t('palette.select')),
    h('span', { class: 'flex items-center gap-1' }, h('kbd', { class: 'kbd' }, 'Esc'), t('palette.close')));
  const dialog = h('dialog', { class: 'modal modal-sheet mt-[10dvh] mb-auto sm:max-w-xl', 'aria-label': t('palette.label') },
    h('div', { class: 'flex items-center gap-2 border-b border-border px-3' }, back, icon('search', 'text-muted'), input, h('kbd', { class: 'kbd hidden sm:inline-flex' }, 'Esc')),
    list, footer);

  const close = () => { if (dialog.open) dialog.close(); };
  dialog.addEventListener('close', () => {
    dialog.remove();
    openDialog = null;
    if (previous?.isConnected) previous.focus?.();
  });
  dialog.addEventListener('pointerdown', (e) => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientY < r.top || e.clientY > r.bottom || e.clientX < r.left || e.clientX > r.right) close(); } });
  openDialog = { close };

  async function loadRoot() {
    if (opts.items) return opts.items;
    const res = await Promise.all([...providers].map((p) => Promise.resolve().then(p).catch(() => [])));
    return res.flat();
  }

  function filter() {
    const q = input.value.trim().toLowerCase();
    const scored = [];
    for (const it of all) {
      const sc = q ? Math.max(fuzzyScore(it.label, q), fuzzyScore(`${it.hint || ''} ${it.keywords || ''}`, q) - 50) : 0;
      if (sc >= 0) scored.push({ it, sc });
    }
    if (q) scored.sort((a, b) => b.sc - a.sc);
    shown = scored.slice(0, 120).map((x) => x.it);
    index = 0;
    render();
  }

  function render() {
    list.replaceChildren();
    if (!shown.length) {
      list.appendChild(h('div', { class: 'px-3 py-8 text-center text-[13px] text-muted' }, t('palette.empty')));
      input.removeAttribute('aria-activedescendant');
      return;
    }
    const q = input.value.trim();
    let lastGroup = null;
    shown.forEach((it, i) => {
      if (!q && it.group && it.group !== lastGroup) {
        list.appendChild(h('div', { class: 'px-2 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted', role: 'presentation' }, it.group));
        lastGroup = it.group;
      }
      list.appendChild(h('div', {
        id: `${listId}-${i}`, role: 'option', 'aria-selected': String(i === index),
        class: cls('flex min-h-10 cursor-pointer items-center gap-3 rounded-md px-2.5 py-1.5 text-[13.5px]', i === index && 'bg-surface-2'),
        onPointermove: () => { if (index !== i) { index = i; highlight(); } },
        onClick: () => choose(it),
      },
      icon(it.icon || 'command', 'text-muted'),
      h('span', { class: 'min-w-0 flex-1 truncate', dir: 'auto', lang: it.lang }, it.label),
      it.hint ? h('span', { class: 'max-w-[40%] truncate text-xs text-muted', dir: 'auto' }, it.hint) : null,
      it.shortcut ? h('kbd', { class: 'kbd' }, it.shortcut) : null,
      it.children ? icon('chevron-right', 'text-muted') : null));
    });
    highlight();
  }

  function highlight() {
    list.querySelectorAll('[role=option]').forEach((el) => {
      const sel = el.id === `${listId}-${index}`;
      el.setAttribute('aria-selected', String(sel));
      el.classList.toggle('bg-surface-2', sel);
      if (sel) el.scrollIntoView({ block: 'nearest' });
    });
    input.setAttribute('aria-activedescendant', `${listId}-${index}`);
  }

  async function choose(it) {
    if (!it) return;
    if (it.children) {
      stack.push({ items: all, query: input.value, placeholder: input.placeholder });
      all = await it.children();
      input.value = '';
      input.placeholder = it.label;
      back.hidden = false;
      filter();
      input.focus();
      return;
    }
    close();
    it.run?.();
  }

  function pop() {
    const prev = stack.pop();
    if (!prev) return;
    all = prev.items;
    input.value = prev.query;
    input.placeholder = prev.placeholder;
    back.hidden = stack.length === 0;
    filter();
    input.focus();
  }

  input.addEventListener('input', filter);
  input.addEventListener('keydown', (e) => {
    if (e.isComposing || e.keyCode === 229) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); index = Math.min(shown.length - 1, index + 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); index = Math.max(0, index - 1); highlight(); }
    else if (e.key === 'Home' && e.ctrlKey) { e.preventDefault(); index = 0; highlight(); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(shown[index]); }
    else if (e.key === 'Backspace' && !input.value && stack.length) { e.preventDefault(); pop(); }
  });

  document.body.appendChild(dialog);
  dialog.showModal();
  input.focus();
  list.appendChild(h('div', { class: 'px-3 py-6 text-center text-[13px] text-muted' }, t('common.loading')));
  all = await loadRoot();
  if (!dialog.open) return;
  filter();
}
