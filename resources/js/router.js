/**
 * Hash router. Routes (ARCHITECTURE.md → Frontend):
 *   #/login
 *   #/c/{conn}                                  server overview
 *   #/c/{conn}/server/{tab}                     variables | status | processes | users
 *   #/c/{conn}/server/monitor                   server monitor (own view)
 *   #/c/{conn}/sql                              SQL editor (?db=…)
 *   #/c/{conn}/security                         account security (two-factor authentication)
 *   #/c/{conn}/import  #/c/{conn}/export        (?db=…&tables=…)
 *   #/c/{conn}/db/{db}[@{schema}]               database overview
 *   #/c/{conn}/db/{db}[@{schema}]/erd | /search | /create | /settings
 *   #/c/{conn}/db/{db}[@{schema}]/diff          schema diff (?sc,sdb,ss source · tc,tdb,ts target)
 *   #/c/{conn}/db/{db}[@{schema}]/t/{table}/{tab}  tab = browse|structure|sql|search|insert|ddl|ops
 *   #/c/{conn}/db/{db}[@{schema}]/routine[/{name}]  routine editor (?new=procedure|function, ?type=, ?sig= PG overload)
 *   #/c/{conn}/db/{db}[@{schema}]/trigger[/{name}]  trigger editor (?new=1&table=…, ?table= to disambiguate)
 *   #/c/{conn}/db/{db}[@{schema}]/event[/{name}]    event editor (?new=1)
 * Segments are URI-encoded ('@' too, since it separates db and schema). A query string after
 * "?" becomes ctx.params. Views are lazy-loaded and destroyed on navigation.
 * @module router
 */
import { h } from './ui/dom.js';
import { skeleton, errorState } from './ui/feedback.js';
import { t } from './i18n/index.js';

const enc = (s) => encodeURIComponent(String(s)).replace(/@/g, '%40');
const qs = (params) => {
  if (!params) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') p.set(k, String(v));
  const s = p.toString();
  return s ? `?${s}` : '';
};
const dbSeg = (db, schema) => `${enc(db)}${schema ? `@${enc(schema)}` : ''}`;

/** Hash builders — always use these instead of concatenating routes by hand. */
export const href = {
  login: (params) => `#/login${qs(params)}`,
  server: (conn) => `#/c/${enc(conn)}`,
  serverTab: (conn, tab) => `#/c/${enc(conn)}/server/${enc(tab)}`,
  sql: (conn, params) => `#/c/${enc(conn)}/sql${qs(params)}`,
  security: (conn) => `#/c/${enc(conn)}/security`,
  import: (conn, params) => `#/c/${enc(conn)}/import${qs(params)}`,
  export: (conn, params) => `#/c/${enc(conn)}/export${qs(params)}`,
  db: (conn, db, schema) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}`,
  erd: (conn, db, schema) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/erd`,
  dbSearch: (conn, db, schema, params) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/search${qs(params)}`,
  createTable: (conn, db, schema) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/create`,
  dbSettings: (conn, db, schema) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/settings`,
  diff: (conn, db, schema, params) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/diff${qs(params)}`,
  /** Routine / trigger / event editor; name omitted (or null) for a new object. kind = routine|trigger|event */
  dbObject: (conn, db, schema, kind, name, params) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/${kind}${name ? `/${enc(name)}` : ''}${qs(params)}`,
  table: (conn, db, schema, table, tab = 'browse', params) => `#/c/${enc(conn)}/db/${dbSeg(db, schema)}/t/${enc(table)}/${enc(tab)}${qs(params)}`,
};

const C = '([^/]+)';
const ROUTES = [
  { name: 'login', re: /^login$/, keys: [], load: () => import('./views/login.js'), layout: false },
  { name: 'server', re: new RegExp(`^c/${C}$`), keys: ['conn'], load: () => import('./views/server.js') },
  { name: 'serverMonitor', re: new RegExp(`^c/${C}/server/monitor$`), keys: ['conn'], load: () => import('./views/server-monitor.js') },
  { name: 'serverAdmin', re: new RegExp(`^c/${C}/server/${C}$`), keys: ['conn', 'tab'], load: () => import('./views/server-admin.js') },
  { name: 'sql', re: new RegExp(`^c/${C}/sql$`), keys: ['conn'], load: () => import('./views/sql.js') },
  { name: 'security', re: new RegExp(`^c/${C}/security$`), keys: ['conn'], load: () => import('./views/security.js') },
  { name: 'import', re: new RegExp(`^c/${C}/import$`), keys: ['conn'], load: () => import('./views/import.js') },
  { name: 'export', re: new RegExp(`^c/${C}/export$`), keys: ['conn'], load: () => import('./views/export.js') },
  { name: 'db', re: new RegExp(`^c/${C}/db/${C}$`), keys: ['conn', 'dbs'], load: () => import('./views/database.js') },
  { name: 'erd', re: new RegExp(`^c/${C}/db/${C}/erd$`), keys: ['conn', 'dbs'], load: () => import('./views/erd.js') },
  { name: 'dbSearch', re: new RegExp(`^c/${C}/db/${C}/search$`), keys: ['conn', 'dbs'], load: () => import('./views/db-search.js') },
  { name: 'createTable', re: new RegExp(`^c/${C}/db/${C}/create$`), keys: ['conn', 'dbs'], load: () => import('./views/table-create.js') },
  { name: 'dbSettings', re: new RegExp(`^c/${C}/db/${C}/settings$`), keys: ['conn', 'dbs'], load: () => import('./views/database-settings.js') },
  { name: 'diff', re: new RegExp(`^c/${C}/db/${C}/diff$`), keys: ['conn', 'dbs'], load: () => import('./views/schema-diff.js') },
  { name: 'dbObject', re: new RegExp(`^c/${C}/db/${C}/(routine|trigger|event)(?:/${C})?$`), keys: ['conn', 'dbs', 'kind', 'object'], load: () => import('./views/routine-editor.js') },
  { name: 'table', re: new RegExp(`^c/${C}/db/${C}/t/${C}(?:/${C})?$`), keys: ['conn', 'dbs', 'table', 'tab'], load: () => import('./views/table.js') },
];

const dec = (s) => { try { return decodeURIComponent(s); } catch { return s; } };

/**
 * Parse a location hash into a route descriptor.
 * @param {string} hash
 * @returns {{name: string, conn?: string, db?: string, schema?: string, table?: string, tab?: string,
 *   params: Record<string,string>, layout: boolean, load: Function, path: string}|null}
 */
export function parseHash(hash) {
  let path = String(hash || '').replace(/^#\/?/, '');
  let query = '';
  const qi = path.indexOf('?');
  if (qi >= 0) { query = path.slice(qi + 1); path = path.slice(0, qi); }
  path = path.replace(/\/+$/, '');
  for (const r of ROUTES) {
    const m = r.re.exec(path);
    if (!m) continue;
    const route = { name: r.name, params: Object.fromEntries(new URLSearchParams(query)), layout: r.layout !== false, load: r.load, path };
    r.keys.forEach((k, i) => {
      const raw = m[i + 1];
      if (raw === undefined) return;
      if (k === 'dbs') {
        const at = raw.indexOf('@');
        route.db = dec(at >= 0 ? raw.slice(0, at) : raw);
        if (at >= 0) route.schema = dec(raw.slice(at + 1));
      } else {
        route[k] = dec(raw);
      }
    });
    if (route.name === 'table' && !route.tab) route.tab = 'browse';
    return route;
  }
  return null;
}

let hooks = null;
let seq = 0;
let currentView = null;
let current = null;
let leaveGuard = null;
let lastHash = null;
let guarding = false;

/**
 * Register an in-app navigation guard for the current view (e.g. unsaved changes). It is asked
 * before the route changes and resolves true to leave, false to stay; the URL is restored while
 * it decides. Cleared automatically when the route changes. Pass null to remove it.
 * Pair it with a `beforeunload` listener for reloads / closing the tab.
 * @param {(() => Promise<boolean>|boolean)|null} fn
 */
export function setLeaveGuard(fn) {
  leaveGuard = fn || null;
}

/**
 * Start routing.
 * @param {object} h
 * @param {(route: object) => string|null} h.guard    return a hash to redirect to
 * @param {(route: object) => Promise<HTMLElement>} h.mount  ensure the shell and return the outlet
 * @param {(route: object) => Promise<object>} h.context  build ctx extras (e.g. caps)
 * @param {(route: object, ctx: object) => void} [h.after]  called after render (breadcrumbs …)
 * @param {() => string} h.fallback  default hash when nothing matches
 */
export function startRouter(h) {
  hooks = h;
  window.addEventListener('hashchange', () => handle());
  handle();
}

/**
 * Navigate to a hash.
 * @param {string} hash
 * @param {{replace?: boolean}} [opts]
 */
export function navigate(hash, opts = {}) {
  if (opts.replace) {
    history.replaceState(history.state, '', hash);
    handle();
  } else if (location.hash === hash) {
    handle();
  } else {
    location.hash = hash;
  }
}

/** Re-render the current route (e.g. after a locale change). */
export const refresh = () => handle();

/** Current route descriptor. */
export const currentRoute = () => current;

async function handle() {
  if (leaveGuard) {
    const target = location.hash;
    const moved = lastHash !== null && target !== lastHash;
    if (moved) history.replaceState(history.state, '', lastHash || '#'); // stay put while the guard decides
    if (guarding) return;
    guarding = true;
    let ok = false;
    try { ok = await leaveGuard(); } catch (e) { console.error(e); ok = false; } finally { guarding = false; }
    if (!ok) return;
    leaveGuard = null;
    if (moved) history.pushState(history.state, '', target);
  }
  leaveGuard = null;
  lastHash = location.hash;
  const token = ++seq;
  const route = parseHash(location.hash);
  if (!route) { navigate(hooks.fallback(), { replace: true }); return; }
  const redirect = hooks.guard(route);
  if (redirect) { navigate(redirect, { replace: true }); return; }

  if (currentView) {
    try { currentView.destroy?.(); } catch (e) { console.error(e); }
    currentView = null;
  }
  current = route;
  const outlet = await hooks.mount(route);
  if (token !== seq) return;
  const loadingTimer = setTimeout(() => { if (token === seq) outlet.replaceChildren(skeleton({ rows: 8 })); }, 120);

  let mod;
  let ctx;
  try {
    [mod, ctx] = await Promise.all([route.load(), hooks.context(route)]);
  } catch (e) {
    clearTimeout(loadingTimer);
    if (token !== seq) return;
    outlet.replaceChildren(errorState(e, { onRetry: () => handle() }));
    return;
  }
  clearTimeout(loadingTimer);
  if (token !== seq) return;

  const view = mod.default;
  const fullCtx = { conn: route.conn, db: route.db, schema: route.schema, table: route.table, tab: route.tab, params: route.params, name: route.name, kind: route.kind, object: route.object, ...ctx };
  const container = h('div', { class: 'view flex h-full min-h-0 flex-col', 'data-view': route.name });
  const hadFocusInside = outlet.contains(document.activeElement);
  outlet.replaceChildren(container);
  currentView = view;
  try {
    document.title = `${view.title ? view.title(fullCtx) : ''} · LDB`.replace(/^ · /, '');
  } catch { document.title = 'LDB'; }
  try {
    await view.render(container, fullCtx);
  } catch (e) {
    console.error(e);
    if (token === seq) container.replaceChildren(errorState(e, { onRetry: () => handle() }));
  }
  if (token !== seq) return;
  hooks.after?.(route, fullCtx);
  if (hadFocusInside || document.activeElement === document.body) {
    const target = container.querySelector('[data-autofocus]') || outlet;
    target.focus?.({ preventScroll: true });
  }
}

/** Translated label of a table tab id. */
export const tabLabel = (tab) => t(`table.tab.${tab}`);
