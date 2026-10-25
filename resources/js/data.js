/**
 * Cached read-only API lookups shared by sidebar, palette and views. Promises are cached per
 * key and evicted on failure; call invalidate() after structural changes.
 * @module data
 */
import { api } from './api.js';
import { bus } from './store.js';

const cache = new Map();

function cached(key, loader, force = false) {
  if (!force && cache.has(key)) return cache.get(key);
  const p = loader().catch((e) => { cache.delete(key); throw e; });
  cache.set(key, p);
  return p;
}

const k = (...parts) => parts.map((p) => (p === undefined || p === null ? '' : String(p))).join('\u0001');

/** server.overview → {info, capabilities, databases} */
export const getOverview = (conn, force) => cached(k('ov', conn), () => api('server.overview', { conn }), force);
/** Capabilities of a connection ({} on failure). */
export const getCaps = (conn) => getOverview(conn).then((o) => o.capabilities || {}, () => ({}));
/** db.list → [{name, collation, tables, size}] */
export const getDatabases = (conn, force) => cached(k('dbs', conn), () => api('db.list', { conn }), force);
/** db.schemas → [name] */
export const getSchemas = (conn, db, force) => cached(k('sch', conn, db), () => api('db.schemas', { conn, db }), force);
/** db.objects → {tables, views, routines, triggers, events} */
export const getObjects = (conn, db, schema, force) => cached(k('obj', conn, db, schema), () => api('db.objects', { conn, db, schema }), force);
/** table.info → TableInfo */
export const getTableInfo = (conn, db, schema, table, force) => cached(k('ti', conn, db, schema, table), () => api('table.info', { conn, db, schema, table }), force);
/** sql.completions → {tables:{name:[cols]}, functions, keywords} */
export const getCompletions = (conn, db, schema, force) => cached(k('cmp', conn, db, schema), () => api('sql.completions', { conn, db, schema }), force);

/**
 * Drop cached entries for a connection (optionally only one database) and notify listeners
 * (sidebar refresh) through bus 'data:changed'.
 * @param {string} conn
 * @param {string} [db]
 */
export function invalidate(conn, db) {
  for (const key of [...cache.keys()]) {
    const parts = key.split('\u0001');
    if (parts[1] !== String(conn)) continue;
    if (db === undefined || parts[0] === 'dbs' || parts[0] === 'ov' || parts[2] === String(db)) cache.delete(key);
  }
  bus.emit('data:changed', { conn, db });
}

/** Forget everything (logout). */
export const clearCache = () => cache.clear();
