/**
 * JSON API client for public/api.php (contract in ARCHITECTURE.md → HTTP API).
 * @module api
 */

/** Error thrown for every failed API call. */
export class ApiError extends Error {
  /**
   * @param {string} message  human readable (from the server when available)
   * @param {string} code     auth_required | csrf | bad_request | not_found | db_error |
   *                          unsupported | rate_limited | internal | network | aborted
   * @param {string|null} [sql]  offending SQL for db_error
   * @param {number} [status] HTTP status
   * `details` (optional) carries extra server fields, e.g. {index, op} from table.applyChanges.
   */
  constructor(message, code = 'internal', sql = null, status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.sql = sql;
    this.status = status;
  }
}

const ENDPOINT = 'api.php';
let csrfToken = null;
let authRequiredHandler = null;

/**
 * Pluggable transport (the dev mock replaces it). Receives (action, params, signal) and
 * resolves to the raw envelope `{ok, data}` / `{ok:false, error}` plus an HTTP status.
 * @type {(action: string, params: object, signal?: AbortSignal) => Promise<{status:number, body:any}>}
 */
const httpTransport = async (action, params, signal) => {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const res = await fetch(`${ENDPOINT}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers,
    credentials: 'same-origin',
    body: JSON.stringify(params ?? {}),
    signal,
  });
  let body = null;
  try { body = await res.json(); } catch { body = null; }
  return { status: res.status, body };
};

let transport = httpTransport;

/** Replace the transport (used by the dev mock only). */
export function setTransport(fn) { transport = fn; }

/** True while the dev mock transport is active (?mock=1): navigation downloads / uploads can't reach a server. */
export const usingMock = () => transport !== httpTransport;

/** Set the CSRF token returned by auth.status / auth.login. */
export function setCsrf(token) { csrfToken = token || null; }

/** Current CSRF token (needed for form-POST downloads). */
export const getCsrf = () => csrfToken;

/** Register the callback invoked when the server answers `auth_required`. */
export function onAuthRequired(fn) { authRequiredHandler = fn; }

/**
 * Call an API action.
 * @param {string} action  e.g. "table.browse"
 * @param {object} [params]
 * @param {{signal?: AbortSignal, silentAuth?: boolean}} [opts]
 * @returns {Promise<any>} the `data` member of the envelope
 * @throws {ApiError}
 */
export async function api(action, params = {}, opts = {}) {
  let res;
  try {
    res = await transport(action, params, opts.signal);
  } catch (e) {
    if (e && e.name === 'AbortError') throw new ApiError('Aborted', 'aborted');
    throw new ApiError(e?.message || 'Network error', 'network');
  }
  const body = res.body;
  if (body && body.ok) {
    if (body.data && typeof body.data === 'object' && typeof body.data.csrf === 'string') setCsrf(body.data.csrf);
    return body.data;
  }
  const err = body?.error || {};
  const error = new ApiError(err.message || `HTTP ${res.status}`, err.code || (res.status >= 500 ? 'internal' : 'bad_request'), err.sql || null, res.status);
  if (err.details) error.details = err.details; // e.g. {index, op} of a failed table.applyChanges change
  if (error.code === 'auth_required' && !opts.silentAuth && authRequiredHandler) authRequiredHandler(error);
  throw error;
}

/**
 * Trigger a file download through a regular form POST (export.download). A navigation is
 * used instead of fetch so the browser streams the file; the CSRF token goes in a field.
 * @param {string} action
 * @param {Record<string, string|string[]>} fields  arrays become repeated `name[]` fields
 */
export function downloadPost(action, fields) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = `${ENDPOINT}?action=${encodeURIComponent(action)}`;
  form.hidden = true;
  const add = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value);
    form.appendChild(input);
  };
  add('csrf', csrfToken || '');
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) v.forEach((x) => add(`${k}[]`, x));
    else if (v !== undefined && v !== null) add(k, v);
  }
  document.body.appendChild(form);
  form.submit();
  form.remove();
}

/**
 * Multipart upload with progress (XMLHttpRequest: fetch has no upload progress events).
 * Adds the CSRF token as header and `csrf` field. With the dev mock transport active the
 * fields are handed to the mock instead (files become {name, size, type, file}).
 * @param {string} action  e.g. "import.upload"
 * @param {Record<string, string|Blob>} fields  objects/arrays are JSON-encoded
 * @param {{onProgress?: (loaded: number, total: number) => void, signal?: AbortSignal}} [opts]
 * @returns {Promise<any>} the `data` member of the envelope
 * @throws {ApiError}
 */
export function uploadForm(action, fields, opts = {}) {
  const enc = (v) => (v !== null && typeof v === 'object' && !(v instanceof Blob) ? JSON.stringify(v) : v);
  if (transport !== httpTransport) {
    const params = {};
    let size = 0;
    for (const [k, v] of Object.entries(fields)) {
      if (v instanceof Blob) { params[k] = { name: v.name || k, size: v.size, type: v.type }; size += v.size; } else params[k] = v;
    }
    let loaded = 0;
    const step = Math.max(1, Math.ceil(size / 8));
    const tick = () => new Promise((r) => setTimeout(r, 60));
    return (async () => {
      while (loaded < size) { await tick(); loaded = Math.min(size, loaded + step); opts.onProgress?.(loaded, size); }
      return api(action, params, { signal: opts.signal });
    })();
  }
  return new Promise((resolve, reject) => {
    const form = new FormData();
    form.append('csrf', csrfToken || '');
    for (const [k, v] of Object.entries(fields)) {
      if (v === undefined || v === null) continue;
      if (v instanceof Blob) form.append(k, v, v.name || k);
      else form.append(k, String(enc(v)));
    }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${ENDPOINT}?action=${encodeURIComponent(action)}`);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) opts.onProgress?.(e.loaded, e.total); };
    const onAbort = () => xhr.abort();
    opts.signal?.addEventListener('abort', onAbort);
    xhr.onabort = () => reject(new ApiError('Aborted', 'aborted'));
    xhr.onerror = () => reject(new ApiError('Network error', 'network'));
    xhr.onload = () => {
      opts.signal?.removeEventListener('abort', onAbort);
      let body = null;
      try { body = JSON.parse(xhr.responseText); } catch { body = null; }
      if (body && body.ok) { resolve(body.data); return; }
      const err = body?.error || {};
      const error = new ApiError(err.message || `HTTP ${xhr.status}`, err.code || (xhr.status >= 500 ? 'internal' : 'bad_request'), err.sql || null, xhr.status);
      if (error.code === 'auth_required' && authRequiredHandler) authRequiredHandler(error);
      reject(error);
    };
    xhr.send(form);
  });
}
