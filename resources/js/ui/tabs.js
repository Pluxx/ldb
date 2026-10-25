/**
 * Accessible tab strip (role=tablist, roving tabindex, arrow keys, optional close/add buttons,
 * links instead of buttons when `href` is given). Scrolls horizontally on small screens.
 * @module ui/tabs
 */
import { h, cls, uid } from './dom.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';

/**
 * @typedef {object} TabItem
 * @property {string} id
 * @property {string} label
 * @property {string} [icon]
 * @property {string} [href]      navigate instead of calling onSelect
 * @property {boolean} [closable]
 * @property {string|number} [badge]
 * @property {string} [title]     tooltip
 * @property {string} [dir]       label direction (e.g. 'auto' for user-named tabs)
 */

/**
 * Create a tab strip.
 * @param {object} o
 * @param {TabItem[]} o.items
 * @param {string} [o.active]
 * @param {(id: string) => void} [o.onSelect]
 * @param {(id: string) => void} [o.onClose]
 * @param {() => void} [o.onAdd]       shows a "+" button
 * @param {(id: string) => void} [o.onDblClick]  e.g. rename
 * @param {string} [o.ariaLabel]
 * @param {'underline'|'pill'} [o.variant='underline']
 * @param {string} [o.className]
 * @returns {{el: HTMLElement, setItems: (items: TabItem[], active?: string) => void,
 *   setActive: (id: string) => void, getActive: () => string|undefined,
 *   panel: (el: HTMLElement) => HTMLElement}}  panel() marks el as the tabpanel of the active tab
 */
export function createTabs(o) {
  let items = o.items;
  let active = o.active ?? items[0]?.id;
  const variant = o.variant || 'underline';
  const pid = uid('tab');
  const tabId = (id) => `${pid}-${id}`;
  let panelEl = null;
  const list = h('div', { role: 'tablist', 'aria-label': o.ariaLabel, class: 'flex min-w-0 items-end gap-0.5 overflow-x-auto [scrollbar-width:none]' });
  const el = h('div', { class: cls('flex min-w-0 items-end gap-1', variant === 'underline' && 'border-b border-border', o.className) }, list);
  if (o.onAdd) {
    el.appendChild(h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon mb-1 shrink-0', 'aria-label': t('tabs.add'), title: t('tabs.add'), onClick: () => o.onAdd() }, icon('plus')));
  }

  function render() {
    list.replaceChildren();
    for (const it of items) {
      const selected = it.id === active;
      const tabCls = variant === 'pill'
        ? cls('inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[13px] whitespace-nowrap', selected ? 'bg-surface-2 text-fg font-medium' : 'text-muted hover:text-fg hover:bg-surface-2/60')
        : cls('relative inline-flex h-9 items-center gap-1.5 px-3 text-[13px] whitespace-nowrap border-b-2 -mb-px', selected ? 'border-accent text-fg font-medium' : 'border-transparent text-muted hover:text-fg');
      const attrs = {
        role: 'tab',
        id: tabId(it.id),
        'aria-controls': panelEl?.id,
        'aria-selected': String(selected),
        tabindex: selected ? '0' : '-1',
        class: tabCls,
        title: it.title,
        'data-id': it.id,
        onKeydown: (e) => keynav(e, it.id),
        onDblclick: o.onDblClick ? () => o.onDblClick(it.id) : null,
        onAuxclick: it.closable && o.onClose ? (e) => { if (e.button === 1) { e.preventDefault(); o.onClose(it.id); } } : null,
      };
      const children = [
        it.icon ? icon(it.icon, 'size-3.5') : null,
        h('span', { class: 'max-w-[14rem] truncate', dir: it.dir }, it.label),
        it.badge !== undefined && it.badge !== null && it.badge !== '' ? h('span', { class: 'badge h-4 px-1 text-[10px]' }, String(it.badge)) : null,
      ];
      const tab = it.href
        ? h('a', { ...attrs, href: it.href }, children)
        : h('button', { ...attrs, type: 'button', onClick: () => select(it.id) }, children);
      if (it.closable && o.onClose) {
        tab.appendChild(h('span', {
          role: 'button', tabindex: '-1', class: 'ms-1 -me-1.5 inline-flex size-6 items-center justify-center rounded text-muted hover:bg-surface-3 hover:text-fg',
          'aria-label': t('tabs.close', { name: it.label }),
          onClick: (e) => { e.stopPropagation(); e.preventDefault(); o.onClose(it.id); },
        }, icon('x', 'size-3')));
      }
      list.appendChild(tab);
    }
    list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    if (panelEl) panelEl.setAttribute('aria-labelledby', tabId(active));
  }

  function select(id) {
    if (id === active) return;
    active = id;
    render();
    o.onSelect?.(id);
  }

  function keynav(e, id) {
    const idx = items.findIndex((x) => x.id === id);
    const rtl = document.documentElement.dir === 'rtl';
    let next = null;
    if (e.key === (rtl ? 'ArrowLeft' : 'ArrowRight')) next = items[(idx + 1) % items.length];
    else if (e.key === (rtl ? 'ArrowRight' : 'ArrowLeft')) next = items[(idx - 1 + items.length) % items.length];
    else if (e.key === 'Home') next = items[0];
    else if (e.key === 'End') next = items[items.length - 1];
    else if (e.key === 'Delete' && o.onClose && items[idx].closable) { o.onClose(id); return; }
    if (!next) return;
    e.preventDefault();
    const nextEl = list.querySelector(`[data-id="${CSS.escape(next.id)}"]`);
    if (next.href) { nextEl?.focus(); return; }
    select(next.id);
    list.querySelector(`[data-id="${CSS.escape(next.id)}"]`)?.focus();
  }

  render();
  return {
    el,
    setItems(newItems, newActive) {
      items = newItems;
      if (newActive !== undefined) active = newActive;
      if (!items.some((x) => x.id === active)) active = items[0]?.id;
      render();
    },
    setActive(id) { active = id; render(); },
    getActive: () => active,
    panel(p) {
      panelEl = p;
      if (!p.id) p.id = `${pid}-panel`;
      p.setAttribute('role', 'tabpanel');
      render();
      return p;
    },
  };
}
