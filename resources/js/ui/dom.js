/**
 * DOM helpers. Everything user- or database-provided is inserted as text nodes or attribute
 * values — never through innerHTML — so markup is escaped by construction.
 * @module ui/dom
 */

const PROP_KEYS = new Set(['value', 'checked', 'selected', 'indeterminate', 'textContent', 'muted', 'defaultValue']);

function applyProps(el, props) {
  for (const [key, val] of Object.entries(props)) {
    if (val === undefined || val === null || val === false) {
      if (key === 'value' || key === 'checked') el[key] = key === 'checked' ? false : '';
      continue;
    }
    if (key === 'class' || key === 'className') {
      const c = cls(val);
      if (c) el.setAttribute('class', c);
    } else if (key === 'style' && typeof val === 'object') {
      for (const [p, v] of Object.entries(val)) {
        if (v === null || v === undefined) continue;
        if (p.startsWith('--')) el.style.setProperty(p, String(v));
        else el.style[p] = v;
      }
    } else if (key === 'dataset') {
      for (const [k, v] of Object.entries(val)) if (v !== undefined && v !== null) el.dataset[k] = String(v);
    } else if (key === 'ref') {
      val(el);
    } else if (key === 'text') {
      el.textContent = String(val);
    } else if (key.startsWith('on') && typeof val === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), val);
    } else if (PROP_KEYS.has(key)) {
      el[key] = val;
    } else if (val === true) {
      el.setAttribute(key, '');
    } else {
      el.setAttribute(key, String(val));
    }
  }
}

function appendChildren(el, children) {
  for (const child of children) {
    if (child === null || child === undefined || child === false || child === true) continue;
    if (Array.isArray(child)) appendChildren(el, child);
    else if (child instanceof Node) el.appendChild(child);
    else el.appendChild(document.createTextNode(String(child)));
  }
}

const isProps = (p) => p !== null && typeof p === 'object' && !(p instanceof Node) && !Array.isArray(p);

/**
 * Hyperscript: create an HTML element.
 *
 * Props: `class` (string | array | {name: bool}), `style` (object), `dataset` (object),
 * `on<event>` listeners (`onClick`, `onKeydown` …), `ref(el)`, `text`, DOM properties
 * (`value`, `checked`, …), any other key becomes an attribute (`true` → empty attribute,
 * `false`/`null`/`undefined` → omitted). Children: Nodes, strings/numbers (as text), arrays,
 * falsy values are skipped.
 *
 * @param {string} tag
 * @param {object|Node|string|Array} [props]
 * @param {...any} children
 * @returns {HTMLElement}
 * @example h('button', { class: 'btn', onClick: save }, icon('save'), t('common.save'))
 */
export function h(tag, props, ...children) {
  const el = document.createElement(tag);
  if (isProps(props)) applyProps(el, props);
  else if (props !== undefined) children.unshift(props);
  appendChildren(el, children);
  return el;
}

/**
 * Like h() but creates SVG elements (for diagrams such as the ERD).
 * @param {string} tag
 * @param {object} [props]
 * @param {...any} children
 * @returns {SVGElement}
 */
export function s(tag, props, ...children) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  if (isProps(props)) applyProps(el, props);
  else if (props !== undefined) children.unshift(props);
  appendChildren(el, children);
  return el;
}

/**
 * Join class names. Accepts strings, arrays and {name: condition} objects.
 * @returns {string}
 */
export function cls(...parts) {
  const out = [];
  for (const p of parts) {
    if (!p) continue;
    if (typeof p === 'string') out.push(p);
    else if (Array.isArray(p)) out.push(cls(...p));
    else if (typeof p === 'object') for (const [k, v] of Object.entries(p)) if (v) out.push(k);
  }
  return out.filter(Boolean).join(' ');
}

/**
 * Replace an element's children using h() child semantics (null/false skipped, arrays
 * flattened, strings as text). Prefer this over el.replaceChildren() when children may be null.
 * @returns {HTMLElement}
 */
export function setChildren(el, ...children) {
  el.replaceChildren();
  appendChildren(el, children);
  return el;
}

/** Remove all children of an element; returns it. */
export function clear(el) {
  el.replaceChildren();
  return el;
}

/**
 * Add an event listener and get a disposer back.
 * @returns {() => void}
 */
export function on(target, type, fn, opts) {
  target.addEventListener(type, fn, opts);
  return () => target.removeEventListener(type, fn, opts);
}

/** Debounce a function. The returned function has `.cancel()`. */
export function debounce(fn, ms = 200) {
  let timer;
  const d = (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
  d.cancel = () => clearTimeout(timer);
  return d;
}

let uidCounter = 0;
/** Unique id for aria relationships. */
export const uid = (prefix = 'o') => `${prefix}-${++uidCounter}`;

/** True below the `lg` breakpoint (sidebar becomes a drawer). */
export const isCompact = () => window.matchMedia('(max-width: 1023.98px)').matches;

/** True below the `sm` breakpoint (phones: sheets instead of dialogs). */
export const isMobile = () => window.matchMedia('(max-width: 639.98px)').matches;

/** True on Apple platforms (⌘ instead of Ctrl in shortcuts). */
export const isMac = () => /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/** Platform modifier key pressed (Cmd on macOS, Ctrl elsewhere). */
export const modKey = (e) => (isMac() ? e.metaKey : e.ctrlKey);

/**
 * Copy text to the clipboard (Clipboard API with execCommand fallback).
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = h('textarea', { class: 'fixed opacity-0 pointer-events-none', 'aria-hidden': 'true' });
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    return ok;
  }
}

/**
 * Offer a client-side generated file for download.
 * @param {string} filename
 * @param {string|Blob} content  text (wrapped in a Blob of `mime`) or a ready Blob
 * @param {string} [mime]
 */
export function downloadText(filename, content, mime = 'text/plain;charset=utf-8') {
  const url = URL.createObjectURL(content instanceof Blob ? content : new Blob([content], { type: mime }));
  const a = h('a', { href: url, download: filename, hidden: true });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** All focusable, visible descendants of a container. */
export function focusables(root) {
  return [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),[contenteditable="true"]')]
    .filter((el) => el.offsetParent !== null || el === document.activeElement);
}

/**
 * Pointer-driven drag helper (mouse + touch + pen). Calls onMove(dx, dy, ev) during the drag.
 * @param {PointerEvent} startEv
 * @param {(dx: number, dy: number, ev: PointerEvent) => void} onMove
 * @param {() => void} [onEnd]
 */
export function dragPointer(startEv, onMove, onEnd) {
  const x0 = startEv.clientX;
  const y0 = startEv.clientY;
  const target = startEv.currentTarget || startEv.target;
  try { target.setPointerCapture?.(startEv.pointerId); } catch { /* ignore */ }
  const move = (e) => onMove(e.clientX - x0, e.clientY - y0, e);
  const up = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', up);
    window.removeEventListener('pointercancel', up);
    document.body.classList.remove('select-none');
    onEnd?.();
  };
  document.body.classList.add('select-none');
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
  window.addEventListener('pointercancel', up);
}
