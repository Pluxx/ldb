/**
 * Toast notifications (aria-live region, bottom-end corner, safe-area aware).
 * @module ui/toast
 */
import { h } from './dom.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';

let region = null;
const live = {};

/**
 * Announce a message to screen readers through a persistent, visually hidden live region
 * (regions that exist before the text changes are announced reliably; freshly inserted ones are not).
 * @param {string} message
 * @param {{assertive?: boolean}} [opts]  assertive for errors
 */
export function announce(message, opts = {}) {
  const k = opts.assertive ? 'assertive' : 'polite';
  let el = live[k];
  if (!el || !el.isConnected) {
    el = live[k] = h('div', { class: 'sr-only', 'aria-live': k, 'aria-atomic': 'true', role: opts.assertive ? 'alert' : 'status' });
    document.body.appendChild(el);
  }
  el.textContent = '';
  // next frame: an identical repeated message still counts as a change
  setTimeout(() => { el.textContent = message; }, 60);
}

function ensureRegion() {
  if (region && region.isConnected) return region;
  region = h('div', {
    class: 'pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:items-end pb-[max(1rem,env(safe-area-inset-bottom))]',
    role: 'region',
    'aria-label': t('toast.region'),
  });
  document.body.appendChild(region);
  return region;
}

const TYPES = {
  success: { icon: 'circle-check', cls: 'text-success' },
  error: { icon: 'circle-alert', cls: 'text-danger' },
  warning: { icon: 'warning', cls: 'text-warning' },
  info: { icon: 'info', cls: 'text-info' },
};

/**
 * Show a toast.
 * @param {string} message
 * @param {{type?: 'success'|'error'|'warning'|'info', detail?: string, timeout?: number,
 *   action?: {label: string, onClick: () => void}}} [opts]
 * @returns {{close: () => void}}
 */
export function toast(message, opts = {}) {
  const type = opts.type || 'info';
  const conf = TYPES[type] || TYPES.info;
  const timeout = opts.timeout ?? (type === 'error' ? 8000 : 4000);
  let timer = null;

  const close = () => {
    clearTimeout(timer);
    el.remove();
  };
  const el = h('div', {
    class: 'popover animate-toast pointer-events-auto flex w-full max-w-sm items-start gap-3 p-3 text-[13px] shadow-pop',
    onMouseenter: () => clearTimeout(timer),
    onMouseleave: () => arm(),
    onFocusin: () => clearTimeout(timer),
  },
  icon(conf.icon, `mt-0.5 ${conf.cls}`),
  h('div', { class: 'min-w-0 flex-1' },
    h('div', { class: 'font-medium break-words' }, message),
    opts.detail ? h('div', { class: 'mt-0.5 break-words font-mono text-xs text-muted line-clamp-4', dir: 'auto' }, opts.detail) : null,
    opts.action ? h('button', { class: 'mt-2 text-xs font-semibold text-accent hover:underline', onClick: () => { opts.action.onClick(); close(); } }, opts.action.label) : null),
  h('button', { class: 'btn btn-ghost btn-sm btn-icon -m-1', 'aria-label': t('common.close'), onClick: close }, icon('x')));

  function arm() {
    if (timeout > 0) timer = setTimeout(close, timeout);
  }
  ensureRegion().appendChild(el);
  announce(opts.detail ? `${message}. ${opts.detail}` : message, { assertive: type === 'error' });
  arm();
  return { close };
}

toast.success = (msg, opts) => toast(msg, { ...opts, type: 'success' });
toast.error = (msg, opts) => toast(msg, { ...opts, type: 'error' });
toast.warning = (msg, opts) => toast(msg, { ...opts, type: 'warning' });
toast.info = (msg, opts) => toast(msg, { ...opts, type: 'info' });

/**
 * Show an error toast for any thrown value (ApiError aware: shows the failing SQL as detail).
 * @param {any} err
 * @param {string} [prefix]  translated context, e.g. t('browse.error.save')
 */
export function toastError(err, prefix) {
  if (err && err.code === 'aborted') return;
  if (err && err.code === 'read_only') {
    // Server-side read-only enforcement (route flag / SQL statement filter).
    toast.error(t('readonly.error'), { detail: err.message && err.message !== 'This connection is read-only' ? err.message : undefined });
    return;
  }
  const msg = err?.message || String(err);
  toast.error(prefix ? `${prefix}: ${msg}` : msg, { detail: err?.sql || undefined });
}
