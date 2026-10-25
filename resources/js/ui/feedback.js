/**
 * Loading / empty / error state building blocks.
 * @module ui/feedback
 */
import { h, cls } from './dom.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';

/**
 * Spinning loader icon.
 * @param {{size?: string, label?: string, className?: string}} [o]  size: tailwind size class
 * @returns {HTMLElement}
 */
export function spinner(o = {}) {
  return h('span', { class: cls('inline-flex items-center gap-2 text-muted', o.className), role: 'status' },
    icon('loader', cls('spin', o.size || 'size-4')),
    h('span', { class: o.label ? 'text-[13px]' : 'sr-only' }, o.label || t('common.loading')));
}

/**
 * Skeleton placeholder lines.
 * @param {{rows?: number, className?: string}} [o]
 * @returns {HTMLElement}
 */
export function skeleton(o = {}) {
  const rows = o.rows ?? 6;
  return h('div', { class: cls('space-y-2.5 p-4', o.className), 'aria-busy': 'true', 'aria-label': t('common.loading') },
    Array.from({ length: rows }, (_, i) => h('div', { class: 'skeleton h-4', style: { width: `${90 - ((i * 17) % 40)}%` } })));
}

/**
 * Centered empty state.
 * @param {{icon?: string, title: string, message?: string, actions?: Node[], className?: string}} o
 * @returns {HTMLElement}
 */
export function emptyState(o) {
  return h('div', { class: cls('flex flex-col items-center justify-center gap-2 px-6 py-12 text-center', o.className) },
    h('div', { class: 'mb-1 flex size-11 items-center justify-center rounded-xl bg-surface-2 text-muted' }, icon(o.icon || 'layers', 'size-5')),
    h('div', { class: 'text-[14px] font-medium' }, o.title),
    o.message ? h('p', { class: 'max-w-md text-[13px] text-muted', dir: 'auto' }, o.message) : null,
    o.actions?.length ? h('div', { class: 'mt-3 flex flex-wrap justify-center gap-2' }, o.actions) : null);
}

/**
 * Error state for failed loads, with optional retry.
 * @param {any} err  Error / ApiError
 * @param {{onRetry?: () => void, className?: string}} [o]
 * @returns {HTMLElement}
 */
export function errorState(err, o = {}) {
  return h('div', { class: cls('flex flex-col items-center justify-center gap-2 px-6 py-12 text-center', o.className), role: 'alert' },
    h('div', { class: 'mb-1 flex size-11 items-center justify-center rounded-xl bg-danger/10 text-danger' }, icon('circle-alert', 'size-5')),
    h('div', { class: 'text-[14px] font-medium' }, t('common.loadFailed')),
    h('p', { class: 'max-w-xl break-words text-[13px] text-muted', dir: 'auto' }, err?.message || String(err)),
    err?.sql ? h('pre', { class: 'mt-1 max-w-xl overflow-auto rounded-md bg-surface-2 p-2 text-start font-mono text-xs', dir: 'ltr' }, err.sql) : null,
    o.onRetry ? h('button', { type: 'button', class: 'btn btn-sm mt-3', onClick: o.onRetry }, icon('refresh'), t('common.retry')) : null);
}

/**
 * Placeholder for write-only views (insert, create table, import) on a read-only connection.
 * @param {{className?: string}} [o]
 * @returns {HTMLElement}
 */
export function readOnlyState(o = {}) {
  return emptyState({ icon: 'lock', title: t('readonly.unavailableTitle'), message: t('readonly.unavailable'), className: o.className });
}

/**
 * Small inline callout.
 * @param {'info'|'warning'|'danger'|'success'} kind
 * @param {string|Node} content
 * @returns {HTMLElement}
 */
export function callout(kind, content) {
  const map = {
    info: ['info', 'border-info/30 bg-info/10 text-fg', 'text-info'],
    warning: ['warning', 'border-warning/30 bg-warning/10 text-fg', 'text-warning'],
    danger: ['circle-alert', 'border-danger/30 bg-danger/10 text-fg', 'text-danger'],
    success: ['circle-check', 'border-success/30 bg-success/10 text-fg', 'text-success'],
  };
  const [ic, box, ico] = map[kind] || map.info;
  return h('div', { class: cls('flex items-start gap-2 rounded-lg border px-3 py-2 text-[13px]', box), role: kind === 'danger' ? 'alert' : null },
    icon(ic, cls('mt-0.5', ico)), h('div', { class: 'min-w-0 flex-1 break-words', dir: 'auto' }, content));
}

/**
 * Metric card: icon tile, label, value and optional hint (server info, status KPIs).
 * @param {{label: string, value: any, icon: string, hint?: string|null, mono?: boolean}} o
 * @returns {HTMLElement}
 */
export function statCard(o) {
  const value = o.value === null || o.value === undefined || o.value === '' ? '—' : String(o.value);
  return h('div', { class: 'card flex min-w-0 items-start gap-2.5 p-3 sm:gap-3 sm:p-4' },
    h('span', { class: 'flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent sm:size-9' }, icon(o.icon)),
    h('div', { class: 'min-w-0' },
      h('div', { class: 'truncate text-xs text-muted' }, o.label),
      h('div', { class: cls('truncate text-[15px] font-semibold tabular-nums', o.mono && 'font-mono text-[13px] font-medium'), title: value, dir: 'auto' }, value),
      o.hint ? h('div', { class: 'truncate text-xs text-muted' }, o.hint) : null));
}

/**
 * Standard toolbar refresh button: icon-only ghost button with tooltip + aria-label. Put it at
 * the END of a toolbar (after the primary actions) so it is in the same place everywhere.
 * @param {() => void} onClick
 * @param {{label?: string, class?: string}} [o]
 */
export function refreshButton(onClick, o = {}) {
  const label = o.label || t('common.refresh');
  return h('button', { type: 'button', class: `btn btn-ghost btn-sm btn-icon ${o.class || ''}`.trim(), title: label, 'aria-label': label, onClick }, icon('refresh'));
}
