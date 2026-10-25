/*
 * LDB pre-paint bootstrap (classic script, NOT a module). Copied verbatim to
 * public/assets/dist/theme.js and loaded in <head> so theme, language and text direction
 * are correct before the first paint (no flash of the wrong theme / direction).
 * Keep it tiny and dependency-free. Must stay in sync with i18n/index.js (SUPPORTED, RTL).
 */
(function () {
  var d = document.documentElement;
  var supported = ['en', 'de', 'es', 'fr', 'it', 'pt-BR', 'pl', 'tr', 'ru', 'ar', 'he', 'ur', 'hi', 'bn', 'id', 'vi', 'ja', 'ko', 'zh-CN', 'zh-TW'];
  var theme = d.getAttribute('data-theme-default') || 'system';
  var loc = '';
  try {
    theme = localStorage.getItem('ldb.theme') || d.getAttribute('data-theme-default') || 'system';
    loc = localStorage.getItem('ldb.locale') || '';
    if (localStorage.getItem('ldb.layout.wide') === 'true') d.setAttribute('data-width', 'full');
  } catch (e) { /* storage blocked */ }
  if (theme !== 'light' && theme !== 'dark') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  d.setAttribute('data-theme', theme);
  if (supported.indexOf(loc) < 0) {
    loc = 'en';
    var langs = navigator.languages || [navigator.language || 'en'];
    for (var i = 0; i < langs.length; i++) {
      var l = String(langs[i]);
      if (/^zh/i.test(l)) { loc = /TW|HK|MO|Hant/i.test(l) ? 'zh-TW' : 'zh-CN'; break; }
      if (/^pt/i.test(l)) { loc = 'pt-BR'; break; }
      if (/^(id|in)\b/i.test(l)) { loc = 'id'; break; }
      var base = l.split('-')[0].toLowerCase();
      if (supported.indexOf(base) >= 0) { loc = base; break; }
    }
  }
  d.setAttribute('lang', loc);
  d.setAttribute('dir', loc === 'ar' || loc === 'he' || loc === 'ur' ? 'rtl' : 'ltr');
})();
