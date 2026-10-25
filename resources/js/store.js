/**
 * Tiny reactive store, event bus and safe localStorage wrapper.
 * @module store
 */

/**
 * Create a reactive store holding a plain object.
 * @template T
 * @param {T} initial
 * @returns {{get: () => T, set: (patch: Partial<T>|((s: T) => Partial<T>)) => void,
 *   subscribe: (fn: (state: T, prev: T) => void) => () => void,
 *   select: <K extends keyof T>(key: K, fn: (value: T[K], prev: T[K]) => void) => () => void}}
 */
export function createStore(initial) {
  let state = { ...initial };
  const subs = new Set();
  return {
    get: () => state,
    set(patch) {
      const prev = state;
      const next = typeof patch === 'function' ? patch(state) : patch;
      state = { ...state, ...next };
      subs.forEach((fn) => fn(state, prev));
    },
    subscribe(fn) {
      subs.add(fn);
      return () => subs.delete(fn);
    },
    select(key, fn) {
      const wrapped = (s, p) => { if (s[key] !== p[key]) fn(s[key], p[key]); };
      subs.add(wrapped);
      return () => subs.delete(wrapped);
    },
  };
}

/**
 * Minimal pub/sub bus.
 * @returns {{on: (ev: string, fn: Function) => () => void, emit: (ev: string, payload?: any) => void}}
 */
export function createBus() {
  const map = new Map();
  return {
    on(ev, fn) {
      if (!map.has(ev)) map.set(ev, new Set());
      map.get(ev).add(fn);
      return () => map.get(ev)?.delete(fn);
    },
    emit(ev, payload) {
      map.get(ev)?.forEach((fn) => {
        try { fn(payload); } catch (e) { console.error(e); }
      });
    },
  };
}

const PREFIX = 'ldb.';

/**
 * localStorage wrapper: every access is wrapped in try/catch (private mode, quota, disabled).
 * Strings are stored raw (theme.js reads `ldb.theme` / `ldb.locale` directly),
 * everything else as JSON.
 */
export const storage = {
  /** @param {string} key without prefix @param {any} [fallback] */
  get(key, fallback = null) {
    try {
      const raw = localStorage.getItem(PREFIX + key);
      if (raw === null) return fallback;
      try { return JSON.parse(raw); } catch { return raw; }
    } catch {
      return fallback;
    }
  },
  /** @param {string} key @param {any} value */
  set(key, value) {
    try {
      localStorage.setItem(PREFIX + key, typeof value === 'string' ? value : JSON.stringify(value));
      return true;
    } catch {
      return false;
    }
  },
  /** @param {string} key */
  remove(key) {
    try { localStorage.removeItem(PREFIX + key); } catch { /* ignore */ }
  },
};

/** Global application state. */
export const appStore = createStore({
  /** auth.status payload: {authenticated, csrf, connections, presets, drivers, version} */
  auth: null,
  /** current route context ({conn, db, schema, table, tab, params, name}) */
  route: null,
  /** theme preference: 'light' | 'dark' | 'system' */
  theme: storage.get('theme', document.documentElement.getAttribute('data-theme-default') || 'system'),
  /** mobile drawer open state */
  sidebarOpen: false,
  /** SQL handed over to the SQL editor (e.g. "open in SQL editor"): {sql, db} | null */
  pendingSql: null,
});

/**
 * App-wide events:
 *  - 'data:changed' {conn, db?}  structure changed (tables created/dropped) → sidebar refresh
 *  - 'locale:changed' code
 *  - 'palette:open'
 */
export const bus = createBus();
