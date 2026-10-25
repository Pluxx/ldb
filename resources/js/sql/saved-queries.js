/**
 * Saved queries (browser-local): pure helpers for the SQL editor's "Saved queries" panel.
 * No DOM / no direct localStorage access, so it runs under node:test; callers pass a
 * storage-like object ({get(key, fallback), set(key, value), remove(key)}) where needed.
 *
 * Entry: {id, name, sql, conn?, db?, created, updated}
 *   conn = connection label the query was saved on (display only), db = database it was saved for.
 * Export file: {format: 'ldb.savedQueries', version: 1, exported, queries: Entry[]}.
 * @module sql/saved-queries
 */

export const SAVED_KEY = 'sql.saved';
export const LEGACY_SNIPPETS_KEY = 'sql.snippets';
export const MAX_SAVED = 500;
export const MAX_NAME = 120;
export const MAX_SQL_CHARS = 200_000;
export const MAX_IMPORT_BYTES = 5 * 1024 * 1024;
export const EXPORT_FORMAT = 'ldb.savedQueries';

/** Persistent unique id (not the per-page counter of ui/dom.js uid). */
export function newId() {
  return `sq-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

const str = (v, max) => (typeof v === 'string' ? v.slice(0, max) : '');
const ts = (v, fallback) => (Number.isFinite(v) && v > 0 ? Math.floor(v) : fallback);

/**
 * Normalise one raw entry (stored, legacy or imported). Returns null when it has no usable
 * name or SQL.
 * @param {any} raw
 * @param {{now?: number, newId?: () => string}} [o]
 */
export function normalizeEntry(raw, o = {}) {
  if (!raw || typeof raw !== 'object') return null;
  const now = o.now ?? Date.now();
  const name = str(raw.name, MAX_NAME).trim();
  const sql = typeof raw.sql === 'string' ? raw.sql.slice(0, MAX_SQL_CHARS) : '';
  if (!name || !sql.trim()) return null;
  const created = ts(raw.created ?? raw.at, now);
  const entry = {
    id: str(raw.id, 64) || (o.newId || newId)(),
    name,
    sql,
    created,
    updated: ts(raw.updated ?? raw.at, created),
  };
  const conn = str(raw.conn, 120).trim();
  const db = str(raw.db, 200).trim();
  if (conn) entry.conn = conn;
  if (db) entry.db = db;
  return entry;
}

/** "name", "name (2)", "name (3)" … — first variant not in `taken` (case-insensitive). */
export function uniqueName(name, taken) {
  const lower = new Set([...taken].map((x) => x.toLowerCase()));
  if (!lower.has(name.toLowerCase())) return name;
  const base = name.replace(/ \(\d+\)$/, '');
  for (let i = 2; ; i++) {
    const cand = `${base.slice(0, MAX_NAME - 8)} (${i})`;
    if (!lower.has(cand.toLowerCase())) return cand;
  }
}

/** Normalise a stored list; drops invalid entries and duplicate ids; caps at MAX_SAVED. */
export function normalizeList(raw) {
  if (!Array.isArray(raw)) return [];
  const seen = new Set();
  const out = [];
  for (const r of raw) {
    const e = normalizeEntry(r);
    if (!e || seen.has(e.id)) continue;
    seen.add(e.id);
    out.push(e);
    if (out.length >= MAX_SAVED) break;
  }
  return out;
}

/**
 * Load the list; migrates the old `sql.snippets` key ({id,name,sql,at}) on first use.
 * @param {{get: Function, set: Function, remove: Function}} storage
 */
export function loadSaved(storage) {
  const cur = storage.get(SAVED_KEY, null);
  if (Array.isArray(cur)) return normalizeList(cur);
  const legacy = storage.get(LEGACY_SNIPPETS_KEY, null);
  if (Array.isArray(legacy)) {
    const list = normalizeList(legacy);
    if (storage.set(SAVED_KEY, list)) storage.remove(LEGACY_SNIPPETS_KEY);
    return list;
  }
  return [];
}

/** @returns {boolean} false when the browser refused to store it (quota / private mode) */
export function persistSaved(storage, list) {
  return !!storage.set(SAVED_KEY, list);
}

/**
 * Add a new entry at the top. Name is made unique. Returns null when the cap is reached.
 * @returns {{list: object[], entry: object}|null}
 */
export function addEntry(list, input, now = Date.now()) {
  if (list.length >= MAX_SAVED) return null;
  const entry = normalizeEntry({ ...input, name: uniqueName(String(input.name || '').trim().slice(0, MAX_NAME), list.map((x) => x.name)), created: now, updated: now, id: undefined }, { now });
  if (!entry) return null;
  return { list: [entry, ...list], entry };
}

/** Patch one entry (name made unique among the others); keeps `created`. */
export function updateEntry(list, id, patch, now = Date.now()) {
  return list.map((e) => {
    if (e.id !== id) return e;
    const next = { ...e, ...patch, updated: now };
    if (patch.name !== undefined) next.name = uniqueName(String(patch.name).trim().slice(0, MAX_NAME) || e.name, list.filter((x) => x.id !== id).map((x) => x.name));
    return normalizeEntry(next, { now }) || e;
  });
}

/** Copy of an entry named "<name> (2)". Returns null at the cap / unknown id. */
export function duplicateEntry(list, id, now = Date.now()) {
  const src = list.find((e) => e.id === id);
  if (!src) return null;
  return addEntry(list, { ...src, name: src.name }, now);
}

export const removeEntry = (list, id) => list.filter((e) => e.id !== id);

/** Case-insensitive search over name, SQL, database and connection label. */
export function filterEntries(list, term) {
  const q = String(term || '').trim().toLowerCase();
  if (!q) return list;
  return list.filter((e) => [e.name, e.sql, e.db, e.conn].some((v) => v && v.toLowerCase().includes(q)));
}

/** JSON text of the export file. */
export function serializeExport(list, now = Date.now()) {
  return JSON.stringify({ format: EXPORT_FORMAT, version: 1, exported: new Date(now).toISOString(), queries: list }, null, 2);
}

/**
 * Parse and validate an import file. Accepts the export envelope or a bare array.
 * @param {string} text
 * @returns {{entries: object[], skipped: number}}  throws Error(code) 'too_large' | 'bad_json' | 'bad_shape' | 'empty'
 */
export function parseImport(text, now = Date.now()) {
  if (typeof text !== 'string' || text.length > MAX_IMPORT_BYTES) throw Object.assign(new Error('too_large'), { code: 'too_large' });
  let data;
  try { data = JSON.parse(text.replace(/^﻿/, '')); } catch { throw Object.assign(new Error('bad_json'), { code: 'bad_json' }); }
  const rows = Array.isArray(data) ? data : (data && typeof data === 'object' && data.format === EXPORT_FORMAT ? data.queries : null);
  if (!Array.isArray(rows)) throw Object.assign(new Error('bad_shape'), { code: 'bad_shape' });
  const entries = [];
  let skipped = 0;
  for (const r of rows) {
    const e = normalizeEntry({ ...r, id: undefined }, { now });
    if (e) entries.push(e); else skipped++;
  }
  if (!entries.length) throw Object.assign(new Error('empty'), { code: 'empty' });
  return { entries, skipped };
}

/**
 * Merge imported entries into the list by name: identical name + identical SQL is skipped
 * (already there), same name with different SQL is kept as "name (2)". New entries get fresh ids.
 * Stops at MAX_SAVED (rest counted as `dropped`).
 * @returns {{list: object[], added: number, renamed: number, duplicates: number, dropped: number}}
 */
export function mergeImport(list, incoming, o = {}) {
  const out = [...list];
  const names = new Set(out.map((e) => e.name));
  const bySql = new Map();
  out.forEach((e) => bySql.set(`${e.name.toLowerCase()}\u0000${e.sql}`, true));
  let added = 0; let renamed = 0; let duplicates = 0; let dropped = 0;
  for (const raw of incoming) {
    const e = normalizeEntry({ ...raw, id: undefined }, { newId: o.newId });
    if (!e) continue;
    if (bySql.has(`${e.name.toLowerCase()}\u0000${e.sql}`)) { duplicates++; continue; }
    if (out.length >= MAX_SAVED) { dropped++; continue; }
    const name = uniqueName(e.name, names);
    if (name !== e.name) renamed++;
    e.name = name;
    names.add(name);
    bySql.set(`${name.toLowerCase()}\u0000${e.sql}`, true);
    out.push(e);
    added++;
  }
  return { list: out, added, renamed, duplicates, dropped };
}
