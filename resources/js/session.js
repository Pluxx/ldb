/**
 * Session-level actions shared by layout, palette and views: auth state, logout, locale switch.
 * @module session
 */
import { api, setCsrf } from './api.js';
import { appStore, bus } from './store.js';
import { setLocale } from './i18n/index.js';
import { clearCache } from './data.js';
import { navigate, href } from './router.js';

/**
 * Store an auth.status / auth.login payload.
 * @param {object} status
 */
export function setAuth(status) {
  appStore.set({ auth: status });
  if (status?.csrf) setCsrf(status.csrf);
}

/** Connections of the current session. */
export const connections = () => appStore.get().auth?.connections || [];

/**
 * Whether a connection is read-only (login checkbox, read_only preset or global config).
 * The single switch for hiding write UI; the server enforces it regardless (403 `read_only`).
 * @param {string|number|object} conn  connection id or connection meta object
 * @returns {boolean}
 */
export function isReadOnly(conn) {
  if (conn && typeof conn === 'object') return !!conn.read_only;
  const c = connections().find((x) => String(x.id) === String(conn));
  return !!c?.read_only;
}

/**
 * Drop menu items marked `write: true` when the connection is read-only, then tidy up
 * leading/trailing/double separators.
 * @param {Array<object>|null} items  menu items (see ui/menu.js)
 * @param {string} conn
 * @returns {Array<object>|null}
 */
export function withoutWrites(items, conn) {
  if (!items || !isReadOnly(conn)) return items;
  const out = [];
  for (const it of items) {
    if (it.write) continue;
    if (it.separator && (!out.length || out[out.length - 1].separator)) continue;
    out.push(it);
  }
  while (out.length && out[out.length - 1].separator) out.pop();
  return out.length ? out : null;
}

/**
 * Log out one connection (or all when omitted) and route accordingly.
 * @param {string} [conn]
 */
export async function logout(conn) {
  const status = await api('auth.logout', conn ? { conn } : {});
  setAuth(status);
  clearCache();
  const rest = status?.connections || [];
  if (!status?.authenticated || !rest.length) navigate(href.login());
  else navigate(href.server(rest[0].id));
}

/**
 * Switch UI language; the app re-renders on 'locale:changed'.
 * @param {string} code
 */
export async function changeLocale(code) {
  await setLocale(code);
  bus.emit('locale:changed', code);
}
