/**
 * Share-link helpers (pure, no DOM): SQL ⇄ base64url, size cap, connection hint, hash rewriting.
 * Links never contain credentials or result data. The only server information is a non-secret
 * hint (`srv` = preset id, else driver id) so a fresh session can offer the right connect form.
 * @module share
 */

/** Maximum SQL size (UTF-8 bytes) that goes into a link. */
export const MAX_LINK_SQL_BYTES = 8192;

const encoder = new TextEncoder();

/** Byte length of a string in UTF-8. */
export const utf8Length = (s) => encoder.encode(String(s)).length;

/** UTF-8 text → base64url (no padding). */
export function b64urlEncode(text) {
  const bytes = encoder.encode(String(text));
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** base64url → UTF-8 text, or null when it is not valid base64url / valid UTF-8. */
export function b64urlDecode(s) {
  if (typeof s !== 'string' || !/^[A-Za-z0-9_-]*$/.test(s) || s.length % 4 === 1) return null;
  try {
    const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return null;
  }
}

/**
 * Encode SQL for the `q` parameter.
 * @returns {{ok: true, value: string}|{ok: false, reason: 'empty'|'too_large', bytes?: number}}
 */
export function encodeSqlParam(sql, max = MAX_LINK_SQL_BYTES) {
  if (!String(sql ?? '').trim()) return { ok: false, reason: 'empty' };
  const bytes = utf8Length(sql);
  if (bytes > max) return { ok: false, reason: 'too_large', bytes };
  return { ok: true, value: b64urlEncode(sql) };
}

/** Decode a `q` parameter; null when invalid, empty or over the size cap. */
export function decodeSqlParam(q, max = MAX_LINK_SQL_BYTES) {
  if (typeof q !== 'string' || !q || q.length > Math.ceil(max * 4 / 3) + 4) return null;
  const sql = b64urlDecode(q);
  if (sql === null || !sql.trim() || utf8Length(sql) > max) return null;
  return sql;
}

/** Non-secret server hint of a connection meta ({preset, driver}): preset id, else driver id. */
export const serverHint = (conn) => (conn ? String(conn.preset || conn.driver || '') : '');

/** Full URL (origin + path + search) with the given hash (leading "#" optional). */
export function absoluteUrl(hash, loc = globalThis.location) {
  return `${loc.origin}${loc.pathname}${loc.search}${hash.startsWith('#') ? hash : `#${hash}`}`;
}

/** Add/replace query params on a route hash ("#/c/1/sql?db=x"); empty values are dropped. */
export function withParams(hash, params) {
  const raw = hash.replace(/^#/, '');
  const qi = raw.indexOf('?');
  const path = qi >= 0 ? raw.slice(0, qi) : raw;
  const q = new URLSearchParams(qi >= 0 ? raw.slice(qi + 1) : '');
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') q.delete(k); else q.set(k, String(v));
  }
  const s = q.toString();
  return `#${path}${s ? `?${s}` : ''}`;
}

/** Route path (+query) without "#": only in-app `/c/{conn}…` routes are accepted, else null. */
export function sanitizeNext(next) {
  if (typeof next !== 'string') return null;
  const n = next.replace(/^#/, '');
  return /^\/c\/[^/?#]+(?:[/?]|$)/.test(n) && !n.split('?')[0].includes('//') ? n : null;
}

/** Swap the connection id in a `/c/{conn}…` route path and drop the `srv` hint. */
export function swapConn(next, newConn) {
  const n = sanitizeNext(next);
  if (!n) return null;
  const swapped = n.replace(/^\/c\/[^/?#]+/, `/c/${encodeURIComponent(String(newConn))}`);
  return withParams(`#${swapped}`, { srv: null });
}
