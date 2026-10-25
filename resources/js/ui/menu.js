/**
 * Dropdown and context menus: keyboard navigation (arrows, Home/End, typeahead, Esc),
 * outside-click dismissal, viewport clamping, RTL-aware alignment, touch long-press.
 * @module ui/menu
 */
import { h, cls, on } from './dom.js';
import { icon } from './icons.js';

/**
 * @typedef {object} MenuItem
 * @property {string} [label]
 * @property {string} [icon]       icon name (ui/icons.js)
 * @property {() => void} [onSelect]
 * @property {string} [href]       render as link instead of action
 * @property {boolean} [danger]
 * @property {boolean} [disabled]
 * @property {boolean} [checked]   renders a check mark column (radio-like menus)
 * @property {string} [hint]       right-aligned hint, e.g. a shortcut
 * @property {boolean} [separator] horizontal rule
 * @property {string} [header]     non-interactive group heading
 * @property {string} [dir]        text direction for the label (e.g. 'auto' for identifiers)
 * @property {string} [lang]       lang attribute for the label (native language names)
 */

let current = null;

/** Close the currently open menu, if any. */
export function closeMenu() {
  current?.close();
}

/**
 * Open a menu anchored to an element (below it) or at a point (context menu).
 * @param {Element|{x: number, y: number}} anchor
 * @param {MenuItem[]} items
 * @param {{align?: 'start'|'end', minWidth?: number, onClose?: () => void, label?: string}} [opts]
 * @returns {{el: HTMLElement, close: () => void}}
 */
export function openMenu(anchor, items, opts = {}) {
  closeMenu();
  const anchorEl = anchor instanceof Element ? anchor : null;
  const previous = document.activeElement;
  const hasChecks = items.some((it) => it.checked !== undefined);
  const buttons = [];
  const menu = h('div', {
    class: 'popover fixed z-[90] max-h-[min(70dvh,480px)] overflow-auto p-1 text-[13px]',
    role: 'menu',
    'aria-label': opts.label,
    tabindex: '-1',
    style: { minWidth: `${opts.minWidth || 180}px`, maxWidth: 'min(92vw, 360px)' },
  });

  for (const it of items) {
    if (it.separator) { menu.appendChild(h('div', { class: 'my-1 h-px bg-border', role: 'separator' })); continue; }
    if (it.header) { menu.appendChild(h('div', { class: 'px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted', role: 'presentation', dir: 'auto' }, it.header)); continue; }
    const content = [
      hasChecks ? h('span', { class: 'flex w-4 justify-center' }, it.checked ? icon('check', 'text-accent') : null) : null,
      it.icon ? icon(it.icon) : null,
      h('span', { class: 'min-w-0 flex-1 truncate', dir: it.dir, lang: it.lang }, it.label),
      it.hint ? h('span', { class: 'ms-4 text-xs text-muted' }, it.hint) : null,
    ];
    const role = it.checked !== undefined ? 'menuitemradio' : 'menuitem';
    const btn = it.href && !it.disabled
      ? h('a', { href: it.href, class: cls('menu-item', it.danger && 'is-danger'), role, tabindex: '-1', 'aria-checked': it.checked !== undefined ? String(!!it.checked) : null, onClick: () => close() }, content)
      : h('button', {
        type: 'button', class: cls('menu-item', it.danger && 'is-danger'), role, tabindex: '-1',
        'aria-disabled': it.disabled ? 'true' : null,
        'aria-checked': it.checked !== undefined ? String(!!it.checked) : null,
        onClick: () => {
          if (it.disabled) return;
          close(true);
          it.onSelect?.();
        },
      }, content);
    btn.addEventListener('pointermove', () => { if (document.activeElement !== btn) btn.focus({ preventScroll: true }); });
    buttons.push(btn);
    menu.appendChild(btn);
  }

  document.body.appendChild(menu);
  position(menu, anchor, opts.align || 'start');

  const focusAt = (i) => {
    if (!buttons.length) return;
    const idx = (i + buttons.length) % buttons.length;
    buttons[idx].focus();
  };
  const idxOf = () => buttons.indexOf(document.activeElement);

  let typeahead = '';
  let typeTimer;
  const keydown = (e) => {
    switch (e.key) {
      case 'ArrowDown': e.preventDefault(); focusAt(idxOf() + 1); break;
      case 'ArrowUp': e.preventDefault(); focusAt(idxOf() - 1); break;
      case 'Home': e.preventDefault(); focusAt(0); break;
      case 'End': e.preventDefault(); focusAt(buttons.length - 1); break;
      case 'Escape': e.preventDefault(); e.stopPropagation(); close(); break;
      case 'Tab': close(); break;
      default:
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
          typeahead += e.key.toLowerCase();
          clearTimeout(typeTimer);
          typeTimer = setTimeout(() => { typeahead = ''; }, 600);
          const start = Math.max(0, idxOf());
          for (let k = 0; k < buttons.length; k++) {
            const b = buttons[(start + k + (typeahead.length === 1 ? 1 : 0)) % buttons.length];
            if (b.textContent.trim().toLowerCase().startsWith(typeahead)) { b.focus(); break; }
          }
        }
    }
  };
  menu.addEventListener('keydown', keydown);

  const offs = [];
  const outside = (e) => { if (!menu.contains(e.target) && !(anchorEl && anchorEl.contains(e.target))) close(); };
  // defer so the opening click doesn't immediately close it
  setTimeout(() => {
    offs.push(on(document, 'pointerdown', outside, true));
    offs.push(on(window, 'resize', () => close()));
    offs.push(on(window, 'blur', () => close()));
    offs.push(on(document, 'scroll', (e) => { if (!menu.contains(e.target)) close(); }, true));
  });

  if (anchorEl) anchorEl.setAttribute('aria-expanded', 'true');
  focusAt(0);

  let closed = false;
  function close(selected) {
    if (closed) return;
    closed = true;
    offs.forEach((off) => off());
    menu.remove();
    if (anchorEl) anchorEl.setAttribute('aria-expanded', 'false');
    // return focus: to the trigger on dismiss; after a choice only if the action didn't move it
    const back = anchorEl || previous;
    const restore = () => { if (back?.isConnected && (!selected || !document.activeElement || document.activeElement === document.body)) back.focus?.({ preventScroll: true }); };
    if (selected) setTimeout(restore); else restore();
    if (current === handle) current = null;
    opts.onClose?.();
  }
  const handle = { el: menu, close: () => close() };
  current = handle;
  return handle;
}

function position(menu, anchor, align) {
  const rtl = document.documentElement.dir === 'rtl';
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const mw = menu.offsetWidth;
  const mh = menu.offsetHeight;
  let x;
  let y;
  if (anchor instanceof Element) {
    const r = anchor.getBoundingClientRect();
    const alignStart = (align === 'start') !== rtl; // physical left alignment
    x = alignStart ? r.left : r.right - mw;
    y = r.bottom + 4;
    if (y + mh > vh - 8 && r.top - mh - 4 > 8) y = r.top - mh - 4;
  } else {
    x = rtl ? anchor.x - mw : anchor.x;
    y = anchor.y;
    if (y + mh > vh - 8) y = Math.max(8, anchor.y - mh);
  }
  x = Math.max(8, Math.min(x, vw - mw - 8));
  y = Math.max(8, Math.min(y, vh - mh - 8));
  menu.style.left = `${x}px`;
  menu.style.top = `${y}px`;
}

/**
 * Wire a button to toggle a dropdown menu (click, Enter/Space, ArrowDown).
 * @param {HTMLElement} button
 * @param {() => MenuItem[]|Promise<MenuItem[]>} getItems
 * @param {{align?: 'start'|'end', minWidth?: number, label?: string}} [opts]
 * @returns {() => void} disposer
 */
export function bindMenu(button, getItems, opts = {}) {
  button.setAttribute('aria-haspopup', 'menu');
  button.setAttribute('aria-expanded', 'false');
  let openHandle = null;
  const toggle = async () => {
    if (openHandle && openHandle.el.isConnected) { openHandle.close(); openHandle = null; return; }
    const items = await getItems();
    if (!items || !items.length) return;
    openHandle = openMenu(button, items, { ...opts, onClose: () => { openHandle = null; } });
  };
  const offClick = on(button, 'click', (e) => { e.preventDefault(); toggle(); });
  const offKey = on(button, 'keydown', (e) => { if (e.key === 'ArrowDown' && !openHandle) { e.preventDefault(); toggle(); } });
  return () => { offClick(); offKey(); };
}

/**
 * Attach a context menu: right click, the ContextMenu key / Shift+F10, and touch long-press.
 * @param {HTMLElement} el
 * @param {(ev: Event) => MenuItem[]|null} getItems  return null/empty to let the browser menu show
 * @returns {() => void} disposer
 */
export function bindContextMenu(el, getItems) {
  const show = (ev, point) => {
    const items = getItems(ev);
    if (!items || !items.length) return false;
    openMenu(point, items, { minWidth: 200 });
    return true;
  };
  const offs = [];
  let lastLongPress = 0;
  offs.push(on(el, 'contextmenu', (e) => {
    if (Date.now() - lastLongPress < 1000) { e.preventDefault(); return; } // Android fires both
    if (e.button === -1 || (e.clientX === 0 && e.clientY === 0)) {
      // keyboard-invoked: anchor to the focused element
      const target = document.activeElement && el.contains(document.activeElement) ? document.activeElement : el;
      const r = target.getBoundingClientRect();
      if (show(e, { x: r.left + 12, y: r.bottom })) e.preventDefault();
      return;
    }
    if (show(e, { x: e.clientX, y: e.clientY })) e.preventDefault();
  }));
  offs.push(on(el, 'keydown', (e) => {
    if (e.key === 'F10' && e.shiftKey) {
      const target = document.activeElement && el.contains(document.activeElement) ? document.activeElement : el;
      const r = target.getBoundingClientRect();
      if (show(e, { x: r.left + 12, y: r.bottom })) e.preventDefault();
    }
  }));
  // long-press for touch (iOS Safari doesn't fire contextmenu)
  let timer = null;
  let startPt = null;
  offs.push(on(el, 'touchstart', (e) => {
    if (e.touches.length !== 1) return;
    const tch = e.touches[0];
    startPt = { x: tch.clientX, y: tch.clientY };
    const target = e.target;
    timer = setTimeout(() => {
      timer = null;
      const fake = { target, clientX: startPt.x, clientY: startPt.y, type: 'longpress' };
      if (show(fake, startPt)) {
        lastLongPress = Date.now();
        if (navigator.vibrate) navigator.vibrate(10);
      }
    }, 550);
  }, { passive: true }));
  const cancel = () => { clearTimeout(timer); timer = null; };
  offs.push(on(el, 'touchmove', (e) => {
    if (!startPt) return;
    const tch = e.touches[0];
    if (Math.abs(tch.clientX - startPt.x) > 8 || Math.abs(tch.clientY - startPt.y) > 8) cancel();
  }, { passive: true }));
  offs.push(on(el, 'touchend', cancel));
  offs.push(on(el, 'touchcancel', cancel));
  return () => offs.forEach((off) => off());
}
