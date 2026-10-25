/**
 * Internationalisation: translation lookup, plurals and Intl formatting.
 *
 * Locale files live in ./locales/<code>.js and `export default { "ns.key": "Text {param}" }`.
 * `en` is the source locale and is bundled statically as the fallback for missing keys;
 * every other locale is code-split and fetched with import() only when selected.
 * @module i18n
 */
import en from './locales/en.js';
import { storage } from '../store.js';

/** Supported UI locales with their native names (shown in the language picker). */
export const LOCALES = [
  { code: 'en', name: 'English' },
  { code: 'de', name: 'Deutsch' },
  { code: 'es', name: 'Español' },
  { code: 'fr', name: 'Français' },
  { code: 'it', name: 'Italiano' },
  { code: 'pt-BR', name: 'Português (Brasil)' },
  { code: 'pl', name: 'Polski' },
  { code: 'tr', name: 'Türkçe' },
  { code: 'ru', name: 'Русский' },
  { code: 'ar', name: 'العربية' },
  { code: 'he', name: 'עברית' },
  { code: 'ur', name: 'اردو' },
  { code: 'hi', name: 'हिन्दी' },
  { code: 'bn', name: 'বাংলা' },
  { code: 'id', name: 'Bahasa Indonesia' },
  { code: 'vi', name: 'Tiếng Việt' },
  { code: 'ja', name: '日本語' },
  { code: 'ko', name: '한국어' },
  { code: 'zh-CN', name: '简体中文' },
  { code: 'zh-TW', name: '繁體中文' },
];
const RTL = new Set(['ar', 'he', 'ur']);

const loaders = {
  de: () => import('./locales/de.js'),
  ja: () => import('./locales/ja.js'),
  'zh-CN': () => import('./locales/zh-CN.js'),
  'zh-TW': () => import('./locales/zh-TW.js'),
  ko: () => import('./locales/ko.js'),
  vi: () => import('./locales/vi.js'),
  ru: () => import('./locales/ru.js'),
  ar: () => import('./locales/ar.js'),
  he: () => import('./locales/he.js'),
  es: () => import('./locales/es.js'),
  fr: () => import('./locales/fr.js'),
  it: () => import('./locales/it.js'),
  'pt-BR': () => import('./locales/pt-BR.js'),
  pl: () => import('./locales/pl.js'),
  tr: () => import('./locales/tr.js'),
  ur: () => import('./locales/ur.js'),
  hi: () => import('./locales/hi.js'),
  bn: () => import('./locales/bn.js'),
  id: () => import('./locales/id.js'),
};

let current = 'en';
let messages = en;
let pluralRules = new Intl.PluralRules('en');
const numberFormats = new Map();
const warned = new Set();

/** Detect the preferred locale: localStorage → navigator.languages → en. */
export function detectLocale() {
  const saved = storage.get('locale');
  if (typeof saved === 'string' && LOCALES.some((l) => l.code === saved)) return saved;
  const langs = navigator.languages || [navigator.language || 'en'];
  for (const raw of langs) {
    const l = String(raw);
    if (/^zh/i.test(l)) return /TW|HK|MO|Hant/i.test(l) ? 'zh-TW' : 'zh-CN';
    if (/^pt/i.test(l)) return 'pt-BR';
    if (/^(id|in)\b/i.test(l)) return 'id'; // "in" is the legacy code for Indonesian
    const base = l.split('-')[0].toLowerCase();
    if (LOCALES.some((x) => x.code === base)) return base;
  }
  return 'en';
}

/**
 * Activate a locale: loads its messages, sets <html lang dir>, persists the choice.
 * @param {string} code
 * @param {{persist?: boolean}} [opts]
 */
export async function setLocale(code, { persist = true } = {}) {
  if (!LOCALES.some((l) => l.code === code)) code = 'en';
  let msgs = en;
  if (code !== 'en' && loaders[code]) {
    try {
      msgs = (await loaders[code]()).default || {};
    } catch (e) {
      console.error('Failed to load locale', code, e);
      code = 'en';
    }
  }
  current = code;
  messages = msgs;
  pluralRules = new Intl.PluralRules(code);
  numberFormats.clear();
  const html = document.documentElement;
  html.lang = code;
  html.dir = dir();
  if (persist) storage.set('locale', code);
}

/** Current locale code. */
export const locale = () => current;

/** Text direction of the current locale: 'ltr' | 'rtl'. */
export const dir = () => (RTL.has(current) ? 'rtl' : 'ltr');

function interpolate(str, params) {
  if (!params) return str;
  return str.replace(/\{(\w+)\}/g, (m, k) => (params[k] === undefined || params[k] === null ? m : String(params[k])));
}

/**
 * Translate a key. Missing keys fall back to English, then to the key itself.
 * @param {string} key  namespaced key, e.g. "browse.toolbar.refresh"
 * @param {Record<string, string|number>} [params]  values for {placeholders}
 * @returns {string}
 */
export function t(key, params) {
  const str = messages[key] ?? en[key];
  if (str === undefined) {
    if (!warned.has(key)) { warned.add(key); console.warn('Missing i18n key', key); }
    return key;
  }
  return interpolate(str, params);
}

/**
 * Pluralised translation using Intl.PluralRules. Looks up `key.<category>` (zero/one/two/few/
 * many/other) and falls back to `key.other`. `{n}` is replaced with the formatted number.
 * @param {string} key
 * @param {number} n
 * @param {Record<string, string|number>} [params]
 */
export function plural(key, n, params = {}) {
  const cat = pluralRules.select(n);
  const k = `${key}.${cat}`;
  const str = messages[k] ?? messages[`${key}.other`] ?? en[`${key}.${new Intl.PluralRules('en').select(n)}`] ?? en[`${key}.other`];
  if (str === undefined) return key;
  return interpolate(str, { n: fmtNumber(n), ...params });
}

/**
 * Locale-aware number formatting.
 * @param {number|null|undefined} n
 * @param {Intl.NumberFormatOptions} [opts]
 */
export function fmtNumber(n, opts) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  const k = opts ? JSON.stringify(opts) : '';
  if (!numberFormats.has(k)) numberFormats.set(k, new Intl.NumberFormat(current, opts));
  return numberFormats.get(k).format(Number(n));
}

/**
 * Human readable byte size (1024 based, locale-formatted).
 * @param {number|null|undefined} bytes
 */
export function fmtBytes(bytes) {
  if (bytes === null || bytes === undefined || Number.isNaN(Number(bytes))) return '—';
  const units = ['byte', 'kilobyte', 'megabyte', 'gigabyte', 'terabyte'];
  let v = Number(bytes);
  let i = 0;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return fmtNumber(v, { style: 'unit', unit: units[i], unitDisplay: 'short', maximumFractionDigits: v < 10 && i > 0 ? 1 : 0 });
}

/**
 * Locale-aware date/time formatting. Accepts Date, timestamp or ISO/SQL datetime string.
 * @param {Date|number|string} value
 * @param {Intl.DateTimeFormatOptions} [opts]
 */
export function fmtDate(value, opts = { dateStyle: 'medium', timeStyle: 'short' }) {
  if (value === null || value === undefined || value === '') return '—';
  const d = value instanceof Date ? value : new Date(typeof value === 'string' ? value.replace(' ', 'T') : value);
  if (Number.isNaN(d.getTime())) return String(value);
  return new Intl.DateTimeFormat(current, opts).format(d);
}

/**
 * Format a duration in milliseconds ("12 ms", "1.4 s").
 * @param {number} ms
 */
export function fmtDuration(ms) {
  if (ms === null || ms === undefined) return '—';
  if (ms < 1000) return fmtNumber(ms, { style: 'unit', unit: 'millisecond', unitDisplay: 'short', maximumFractionDigits: ms < 10 ? 1 : 0 });
  return fmtNumber(ms / 1000, { style: 'unit', unit: 'second', unitDisplay: 'short', maximumFractionDigits: 2 });
}

/**
 * Uptime in seconds → "3 d 4 h" / "4 h 12 min" (keys server.uptimeDays / server.uptimeHours).
 * @param {number|null|undefined} sec
 */
export function fmtUptime(sec) {
  if (sec === null || sec === undefined || Number.isNaN(sec)) return '—';
  const d = Math.floor(sec / 86400);
  const hr = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return d ? t('server.uptimeDays', { d, h: hr }) : t('server.uptimeHours', { h: hr, m });
}
