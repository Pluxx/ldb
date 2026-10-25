/**
 * Theme handling: 'light' | 'dark' | 'system' preference → data-theme on <html>.
 * (theme.js, loaded before paint, applies the stored preference initially.)
 * @module ui/theme
 */
import { appStore, storage } from '../store.js';

const media = window.matchMedia('(prefers-color-scheme: dark)');

/** Resolve a preference to the concrete theme. */
export const resolveTheme = (pref) => (pref === 'light' || pref === 'dark' ? pref : media.matches ? 'dark' : 'light');

function apply(pref) {
  document.documentElement.setAttribute('data-theme', resolveTheme(pref));
}

/**
 * Set and persist the theme preference.
 * @param {'light'|'dark'|'system'} pref
 */
export function setTheme(pref) {
  storage.set('theme', pref);
  appStore.set({ theme: pref });
  apply(pref);
}

/** Cycle light → dark → system. */
export function cycleTheme() {
  const order = ['light', 'dark', 'system'];
  const cur = appStore.get().theme;
  setTheme(order[(order.indexOf(cur) + 1) % order.length]);
}

/** Apply the current preference and follow OS changes while on 'system'. */
export function initTheme() {
  apply(appStore.get().theme);
  media.addEventListener?.('change', () => { if (appStore.get().theme === 'system') apply('system'); });
}

/** Icon name for a theme preference. */
export const themeIcon = (pref) => (pref === 'light' ? 'sun' : pref === 'dark' ? 'moon' : 'monitor');
