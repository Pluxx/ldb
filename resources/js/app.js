/**
 * LDB frontend entry: bootstraps locale, theme, auth status, palette and the router.
 * Dev only: `?mock=1` in the page URL swaps the API transport for an in-browser mock
 * (resources/js/dev/mock-api.js, loaded lazily so it never weighs on the main bundle).
 * @module app
 */
import { api, setTransport, onAuthRequired } from './api.js';
import { appStore, bus, storage } from './store.js';
import { loadSaved } from './sql/saved-queries.js';
import { sanitizeNext, serverHint } from './share.js';
import { setLocale, detectLocale, t, LOCALES, locale } from './i18n/index.js';
import { h } from './ui/dom.js';
import { initTheme, cycleTheme } from './ui/theme.js';
import { initPalette, registerPaletteProvider } from './ui/palette.js';
import { errorState } from './ui/feedback.js';
import { toast } from './ui/toast.js';
import { startRouter, navigate, refresh, href, currentRoute } from './router.js';
import { mountShell } from './layout/shell.js';
import { getCaps, getDatabases, getObjects, clearCache } from './data.js';
import { setAuth, logout, changeLocale, isReadOnly } from './session.js';

let shell = null;
let root = null;

const authed = () => {
  const a = appStore.get().auth;
  return !!(a && a.authenticated && (a.connections || []).length);
};
const firstConn = () => appStore.get().auth?.connections?.[0]?.id;

/**
 * Deep link (or reload) into a connection that isn't open in this session → login screen with the
 * link as `next` (and the non-secret server hint `srv`); after login the same path is opened with
 * the new connection id (login.js).
 */
function reconnectTarget(route) {
  const next = sanitizeNext(location.hash);
  if (!next) return null;
  return href.login({ next, srv: route.params.srv || undefined, add: authed() ? 1 : undefined });
}

/** Connection that must enroll in 2FA first (`twofa_required` policy), if any. */
const enrollConn = () => (appStore.get().auth?.connections || []).find((c) => c.twofa_enroll);

function guard(route) {
  if (route.name === 'login') {
    return authed() && !route.params.add ? href.server(firstConn()) : null;
  }
  if (!authed()) return (route.conn && reconnectTarget(route)) || href.login();
  const enroll = enrollConn();
  // The server refuses everything else (twofa_enroll_required) until 2FA is set up.
  if (enroll && !(route.name === 'security' && String(route.conn) === String(enroll.id))) return href.security(enroll.id);
  const conns = appStore.get().auth.connections;
  const known = conns.find((c) => String(c.id) === String(route.conn));
  // A shared link carries `srv` (preset id / driver): ids are per session, so a mismatch means another server.
  if (!known || (route.params.srv && serverHint(known) !== route.params.srv)) return reconnectTarget(route) || href.server(firstConn());
  return null;
}

async function mount(route) {
  appStore.set({ route });
  if (!route.layout || enrollConn()) {
    // No shell (sidebar, overview requests) while 2FA enrollment is enforced.
    if (shell) { shell.destroy(); shell = null; }
    return root;
  }
  if (!shell) shell = mountShell(root);
  await shell.update(route);
  return shell.main;
}

async function context(route) {
  return { caps: route.conn && !enrollConn() ? await getCaps(route.conn) : {}, readOnly: route.conn ? isReadOnly(route.conn) : false };
}

/** Palette: actions, databases and tables of the current connection. */
async function paletteItems() {
  const route = currentRoute();
  const conn = route?.conn;
  const items = [];
  const A = t('palette.group.actions');
  if (conn) {
    const ro = isReadOnly(conn);
    items.push(
      { id: 'a:sql', group: A, icon: 'terminal', label: t('palette.action.newQuery'), run: () => navigate(href.sql(conn, { db: route.db, schema: route.schema, new: 1 })) },
      { id: 'a:server', group: A, icon: 'server', label: t('palette.action.server'), run: () => navigate(href.server(conn)) },
      ro ? null : { id: 'a:import', group: A, icon: 'upload', label: t('nav.import'), run: () => navigate(href.import(conn, { db: route.db, schema: route.schema })) },
      { id: 'a:export', group: A, icon: 'download', label: t('nav.export'), run: () => navigate(href.export(conn, { db: route.db, schema: route.schema })) },
      ro ? null : {
        id: 'a:importInto', group: A, icon: 'upload', label: t('palette.action.importInto'), keywords: 'import upload csv sql',
        children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `ii:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.import(conn, { db: d.name })) }))),
      },
      {
        id: 'a:exportOf', group: A, icon: 'download', label: t('palette.action.exportOf'), keywords: 'export download dump backup',
        children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `eo:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.export(conn, { db: d.name })) }))),
      },
    );
    if (route.table && route.db) {
      items.push({ id: 'a:exportTable', group: A, icon: 'download', label: t('palette.action.exportTable', { name: route.table }), run: () => navigate(href.export(conn, { db: route.db, schema: route.schema, tables: route.table })) });
    }
    if (route.db) {
      items.push(
        { id: 'a:erd', group: A, icon: 'network', label: `${t('nav.erd')} · ${route.db}`, keywords: 'erd diagram relations schema', run: () => navigate(href.erd(conn, route.db, route.schema)) },
        { id: 'a:dbSearch', group: A, icon: 'text-search', label: `${t('dbSearch.title')} · ${route.db}`, keywords: 'find search', run: () => navigate(href.dbSearch(conn, route.db, route.schema)) },
        { id: 'a:dbSettings', group: A, icon: 'settings', label: t('dbOps.palette', { db: route.db }), keywords: 'database settings collation charset rename copy owner pragma', run: () => navigate(href.dbSettings(conn, route.db, route.schema)) },
        { id: 'a:diff', group: A, icon: 'git-compare', label: t('diff.palette', { db: route.db }), keywords: 'compare diff schema migration sync', run: () => navigate(href.diff(conn, route.db, route.schema)) },
      );
      if (route.table) items.push({ id: 'a:tableSearch', group: A, icon: 'text-search', label: t('table.title.search', { table: route.table }), keywords: 'query by example filter', run: () => navigate(href.table(conn, route.db, route.schema, route.table, 'search')) });
    }
    items.push({
      id: 'a:dbSettingsOf', group: A, icon: 'settings', label: t('dbOps.paletteIn'), keywords: 'database settings collation charset rename copy',
      children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `ds:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.dbSettings(conn, d.name)) }))),
    });
    items.push({
      id: 'a:diffOf', group: A, icon: 'git-compare', label: t('diff.paletteIn'), keywords: 'compare diff schema migration sync',
      children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `df:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.diff(conn, d.name)) }))),
    });
    if (route.db && !ro) items.push({ id: 'a:createTable', group: A, icon: 'plus', label: t('createTable.paletteCreate', { db: route.db }), keywords: 'new create table', run: () => navigate(href.createTable(conn, route.db, route.schema)) });
    if (!ro) items.push({
      id: 'a:createTableIn', group: A, icon: 'plus', label: t('createTable.paletteCreateIn'), keywords: 'new create table',
      children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `ct:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.createTable(conn, d.name)) }))),
    });
    const caps = await getCaps(conn);
    if (caps.routines && route.db) {
      items.push({
        id: 'a:editRoutine', group: A, icon: 'function', label: t('routines.paletteEdit', { db: route.db }), keywords: 'routine procedure function trigger event edit',
        children: () => getObjects(conn, route.db, route.schema).then((o) => [
          ...(o.routines || []).map((r) => ({ id: `er:${r.type}:${r.name}:${r.arguments ?? ''}`, icon: 'function', label: r.arguments !== undefined ? `${r.name}(${r.arguments})` : r.name, hint: r.type, dir: 'auto', run: () => navigate(href.dbObject(conn, route.db, route.schema, 'routine', r.name, { type: String(r.type).toLowerCase(), sig: (o.routines || []).filter((x) => x.name === r.name).length > 1 ? r.arguments : undefined })) })),
          ...(o.triggers || []).map((r) => ({ id: `et:${r.table}:${r.name}`, icon: 'zap', label: r.name, hint: r.table, dir: 'auto', run: () => navigate(href.dbObject(conn, route.db, route.schema, 'trigger', r.name, { table: r.table })) })),
          ...(o.events || []).map((r) => ({ id: `ee:${r.name}`, icon: 'calendar', label: r.name, hint: r.schedule, dir: 'auto', run: () => navigate(href.dbObject(conn, route.db, route.schema, 'event', r.name)) })),
        ]),
      });
    }
    if (caps.routines && !ro) {
      items.push({
        id: 'a:newProcIn', group: A, icon: 'plus', label: t('routines.paletteNewProcedure'), keywords: 'new create procedure routine',
        children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `np:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.dbObject(conn, d.name, d.name === route.db ? route.schema : undefined, 'routine', null, { new: 'procedure' })) }))),
      }, {
        id: 'a:newFuncIn', group: A, icon: 'plus', label: t('routines.paletteNewFunction'), keywords: 'new create function routine',
        children: () => getDatabases(conn).then((dbs) => dbs.map((d) => ({ id: `nf:${d.name}`, icon: 'database', label: d.name, dir: 'auto', run: () => navigate(href.dbObject(conn, d.name, d.name === route.db ? route.schema : undefined, 'routine', null, { new: 'function' })) }))),
      });
    }
    if (caps.monitor) items.push({ id: 'a:srv:monitor', group: A, icon: 'chart-line', label: t('monitor.palette'), keywords: 'server monitor metrics dashboard performance slow queries', run: () => navigate(href.serverTab(conn, 'monitor')) });
    const admin = [['processes', 'activity', 'palette.action.processes'], ['users', 'users', 'palette.action.users'], ['variables', 'sliders', 'palette.action.variables'], ['status', 'gauge', 'palette.action.status']];
    for (const [tab, ic, key] of admin) {
      if (caps[tab]) items.push({ id: `a:srv:${tab}`, group: A, icon: ic, label: t(key), keywords: 'server admin', run: () => navigate(href.serverTab(conn, tab)) });
    }
  }
  items.push(
    { id: 'a:theme', group: A, icon: 'moon', label: t('palette.action.toggleTheme'), keywords: 'dark light', run: () => cycleTheme() },
    {
      id: 'a:lang', group: A, icon: 'languages', label: t('palette.action.language'), keywords: 'language locale',
      children: () => LOCALES.map((l) => ({ id: `l:${l.code}`, label: l.name, lang: l.code, icon: l.code === locale() ? 'check' : 'languages', run: () => changeLocale(l.code) })),
    },
    { id: 'a:add', group: A, icon: 'plug', label: t('topbar.addConnection'), run: () => navigate(href.login({ add: 1 })) },
    { id: 'a:logout', group: A, icon: 'log-out', label: t('topbar.logout'), run: () => logout() },
  );
  for (let i = items.length - 1; i >= 0; i--) if (!items[i]) items.splice(i, 1);
  if (conn) {
    const S = t('savedQueries.palette');
    loadSaved(storage).slice(0, 200).forEach((e) => items.push({
      id: `sq:${e.id}`, group: S, icon: 'bookmark', label: e.name, hint: e.db || undefined, dir: 'auto', keywords: `sql query ${e.sql.slice(0, 200)}`,
      run: () => { appStore.set({ pendingSql: { sql: e.sql, db: e.db || undefined } }); navigate(href.sql(conn, { db: e.db || undefined })); },
    }));
  }
  if (!conn) return items;
  try {
    const dbs = await getDatabases(conn);
    const D = t('palette.group.databases');
    dbs.forEach((d) => items.push({ id: `d:${d.name}`, group: D, icon: 'database', label: d.name, run: () => navigate(href.db(conn, d.name)) }));
    const T = t('palette.group.tables');
    const wanted = [...new Set([route.db, ...dbs.map((d) => d.name)].filter(Boolean))].slice(0, 12);
    const objs = await Promise.all(wanted.map((db) => getObjects(conn, db, db === route.db ? route.schema : undefined).then((o) => [db, o], () => [db, null])));
    for (const [db, o] of objs) {
      if (!o) continue;
      const seen = new Set();
      for (const tb of [...(o.tables || []), ...(o.views || [])]) {
        if (seen.has(tb.name)) continue;
        seen.add(tb.name);
        const schema = db === route.db ? route.schema : undefined;
        items.push({ id: `t:${db}.${tb.name}`, group: T, icon: tb.type === 'view' ? 'eye' : 'table', label: tb.name, hint: db, run: () => navigate(href.table(conn, db, schema, tb.name, 'browse')) });
        if (db === route.db) items.push({ id: `s:${db}.${tb.name}`, group: T, icon: 'columns', label: t('structure.paletteStructure', { name: tb.name }), hint: db, keywords: 'structure columns alter', run: () => navigate(href.table(conn, db, schema, tb.name, 'structure')) });
      }
    }
  } catch { /* palette still shows actions */ }
  return items;
}

async function boot() {
  root = document.getElementById('app');
  if (!root) {
    root = h('div', { id: 'app' });
    document.body.appendChild(root);
  }
  if (location.search.includes('mock=1')) {
    const mock = await import('./dev/mock-api.js');
    setTransport(mock.transport);
  }
  await setLocale(detectLocale(), { persist: false });
  initTheme();
  initPalette();
  registerPaletteProvider(paletteItems);

  let status;
  try {
    status = await api('auth.status', {}, { silentAuth: true });
  } catch (e) {
    root.replaceChildren(h('div', { class: 'flex h-dvh items-center justify-center' }, errorState(e, { onRetry: () => location.reload() })));
    return;
  }
  setAuth(status);

  onAuthRequired(() => {
    const a = appStore.get().auth || {};
    setAuth({ ...a, authenticated: false, connections: [] });
    clearCache();
    if (currentRoute()?.name !== 'login') {
      toast.warning(t('auth.sessionExpired'));
      navigate(href.login());
    }
  });

  bus.on('locale:changed', () => {
    if (shell) { shell.destroy(); shell = null; }
    refresh();
  });

  startRouter({
    guard,
    mount,
    context,
    fallback: () => (authed() ? href.server(firstConn()) : href.login()),
  });
}

boot();
