/**
 * Application shell: top bar (drawer toggle, logo, connection switcher, breadcrumbs, palette
 * trigger, theme and language pickers, logout) + sidebar + main outlet.
 * @module layout/shell
 */
import { h, cls, isMac } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { bindMenu } from '../ui/menu.js';
import { openPalette } from '../ui/palette.js';
import { toastError } from '../ui/toast.js';
import { t, LOCALES, locale } from '../i18n/index.js';
import { appStore, storage } from '../store.js';
import { href, navigate } from '../router.js';
import { setTheme, themeIcon } from '../ui/theme.js';
import { createSidebar } from './sidebar.js';
import { changeLocale, logout, isReadOnly } from '../session.js';
import { getOverview } from '../data.js';

/** Display label of a connection. */
export function connectionLabel(c) {
  if (!c) return '';
  if (c.label) return c.label;
  if (c.driver === 'sqlite') return c.database || 'SQLite';
  return `${c.user || ''}@${c.host || ''}`;
}

const PRODUCTS = { mysql: 'MySQL', mariadb: 'MariaDB', pgsql: 'PostgreSQL', sqlsrv: 'SQL Server', sqlite: 'SQLite' };

/**
 * Version support of a connection: from the login metadata, else (sessions from before the field
 * existed) from server.overview. Resolves to null when the server version is supported.
 * @returns {Promise<{product: string, version: string, min: string, key: string}|null>}
 */
async function unsupportedVersion(c) {
  if (!c) return null;
  let info = c;
  if (c.version_supported === undefined) {
    const ov = await getOverview(c.id).catch(() => null);
    if (!ov?.info) return null;
    info = ov.info;
  }
  if (info.version_supported !== false) return null;
  const flavor = info.version_flavor || c.driver;
  return {
    product: PRODUCTS[flavor] || flavor || '',
    version: String(info.version_number || info.version || c.version || ''),
    min: String(info.min_version ?? ''),
    // dismissal is remembered per server and version (connection ids change per session)
    key: `versionWarn.${[c.driver, c.server || c.host || c.database || '', c.version || info.version || ''].join('|')}`,
  };
}

/**
 * Mount the shell into a root element.
 * @param {HTMLElement} root
 * @returns {{main: HTMLElement, update: (route: object) => Promise<void>, destroy: () => void}}
 */
export function mountShell(root) {
  const offs = [];
  const sidebar = createSidebar();

  // ----- top bar pieces
  const menuBtn = h('button', {
    type: 'button', class: 'btn btn-ghost btn-icon lg:hidden', 'aria-label': t('topbar.toggleSidebar'),
    'aria-controls': 'sidebar', 'aria-expanded': 'false',
    onClick: () => appStore.set({ sidebarOpen: !appStore.get().sidebarOpen }),
  }, icon('menu'));
  offs.push(appStore.select('sidebarOpen', (open) => menuBtn.setAttribute('aria-expanded', String(open))));

  const logo = h('a', { href: '#/', class: 'flex shrink-0 items-center gap-2 rounded-md px-1 font-semibold tracking-tight', 'aria-label': 'LDB' },
    h('span', { class: 'flex size-7 items-center justify-center rounded-lg bg-accent text-accent-fg' }, icon('logo', 'size-4')),
    h('span', { class: 'hidden text-[15px] sm:inline' }, 'LDB'));

  const connLabel = h('span', { class: 'max-w-[9rem] truncate sm:max-w-[14rem]', dir: 'auto' });
  // Lock badge for read-only connections (shown/hidden in update()).
  const roBadge = h('span', { class: 'badge badge-warning', title: t('readonly.badgeTitle'), hidden: true },
    icon('lock'), h('span', { class: 'hidden sm:inline' }, t('readonly.badge')));
  const connBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm min-w-0 gap-1.5 text-fg', 'aria-label': t('topbar.connection') },
    icon('server', 'text-muted'), connLabel, roBadge, icon('chevron-down', 'size-3.5 text-muted'));
  bindMenu(connBtn, () => {
    const { auth, route } = appStore.get();
    const items = [{ header: t('topbar.connections') }];
    for (const c of auth?.connections || []) {
      items.push({
        label: connectionLabel(c), icon: isReadOnly(c) ? 'lock' : (c.driver === 'sqlite' ? 'hard-drive' : 'server'), dir: 'auto',
        hint: [c.server || c.version || c.driver, isReadOnly(c) ? t('readonly.badge') : null].filter(Boolean).join(' · '), checked: String(c.id) === String(route?.conn),
        onSelect: () => navigate(href.server(c.id)),
      });
    }
    items.push({ separator: true },
      ...(route?.conn ? [{ label: t('twofa.menu'), icon: 'shield-check', onSelect: () => navigate(href.security(route.conn)) }] : []),
      { label: t('topbar.addConnection'), icon: 'plug', onSelect: () => navigate(href.login({ add: 1 })) },
      { label: t('topbar.disconnect'), icon: 'unplug', danger: true, onSelect: () => logout(route?.conn) });
    return items;
  }, { minWidth: 260, label: t('topbar.connections') });

  const crumbs = h('ol', { class: 'flex min-w-0 items-center gap-1 text-[13px]' });
  const crumbNav = h('nav', { class: 'hidden min-w-0 md:flex', 'aria-label': t('topbar.breadcrumbs') }, crumbs);

  const mod = isMac() ? '⌘' : 'Ctrl';
  const paletteBtn = h('button', {
    type: 'button',
    class: 'flex h-9 min-w-0 items-center gap-2 rounded-lg border border-border bg-surface-2/60 px-2.5 text-[13px] text-muted hover:border-border-strong hover:text-fg max-md:w-9 max-md:justify-center max-md:px-0 md:w-64',
    'aria-label': t('palette.open'),
    'aria-keyshortcuts': isMac() ? 'Meta+K' : 'Control+K',
    onClick: () => openPalette(),
  }, icon('search'), h('span', { class: 'hidden flex-1 truncate text-start md:inline' }, t('palette.trigger')),
  h('span', { class: 'hidden items-center gap-0.5 md:flex' }, h('kbd', { class: 'kbd' }, mod), h('kbd', { class: 'kbd' }, 'K')));

  const themeBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-icon', 'aria-label': t('theme.label'), title: t('theme.label') });
  const renderThemeBtn = () => themeBtn.replaceChildren(icon(themeIcon(appStore.get().theme)));
  renderThemeBtn();
  offs.push(appStore.select('theme', renderThemeBtn));
  bindMenu(themeBtn, () => ['light', 'dark', 'system'].map((p) => ({
    label: t(`theme.${p}`), icon: themeIcon(p), checked: appStore.get().theme === p, onSelect: () => setTheme(p),
  })), { align: 'end', label: t('theme.label') });

  // Content width: capped at 1600px by default (see .view in app.css); "wide" uses the full window.
  const widthBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-icon max-lg:hidden' });
  const renderWidthBtn = () => {
    const wide = document.documentElement.getAttribute('data-width') === 'full';
    const label = t(wide ? 'layout.widthLimited' : 'layout.widthFull');
    widthBtn.replaceChildren(icon(wide ? 'fold-horizontal' : 'unfold-horizontal'));
    widthBtn.setAttribute('aria-label', label);
    widthBtn.setAttribute('aria-pressed', String(wide));
    widthBtn.title = label;
  };
  widthBtn.addEventListener('click', () => {
    const wide = document.documentElement.getAttribute('data-width') !== 'full';
    if (wide) document.documentElement.setAttribute('data-width', 'full');
    else document.documentElement.removeAttribute('data-width');
    storage.set('layout.wide', wide);
    renderWidthBtn();
  });
  renderWidthBtn();

  const langBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm h-9 gap-1.5 max-sm:w-9 max-sm:px-0', 'aria-label': t('lang.label'), title: t('lang.label') },
    icon('languages'), h('span', { class: 'hidden text-xs uppercase sm:inline' }, locale()));
  bindMenu(langBtn, () => LOCALES.map((l) => ({
    label: l.name, lang: l.code, checked: l.code === locale(), onSelect: () => changeLocale(l.code),
  })), { align: 'end', label: t('lang.label') });

  const logoutBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-icon', 'aria-label': t('topbar.logout'), title: t('topbar.logout'), onClick: () => logout().catch(toastError) }, icon('log-out'));

  const header = h('header', { class: 'safe-x flex h-12 shrink-0 items-center gap-1.5 border-b border-border bg-surface px-2 pt-[env(safe-area-inset-top)] sm:gap-2 sm:px-3' },
    menuBtn, logo,
    h('span', { class: 'mx-1 hidden h-5 w-px bg-border sm:block', 'aria-hidden': 'true' }),
    connBtn, crumbNav,
    h('div', { class: 'flex-1' }),
    paletteBtn, themeBtn, widthBtn, langBtn, logoutBtn);

  // Dismissible warning for servers older than the supported minimum (ServerVersion.php).
  const versionBanner = h('div', { class: 'safe-x border-b border-warning/30 bg-warning/10 px-3 py-2 text-[13px] text-fg', role: 'status', hidden: true });
  let bannerSeq = 0;
  async function renderVersionBanner(c) {
    const seq = ++bannerSeq;
    const u = await unsupportedVersion(c).catch(() => null);
    if (seq !== bannerSeq) return;
    if (!u || storage.get(u.key)) { versionBanner.hidden = true; versionBanner.replaceChildren(); return; }
    const dismiss = h('button', {
      type: 'button', class: 'btn btn-ghost btn-sm btn-icon -my-1 shrink-0', 'aria-label': t('version.dismiss'), title: t('version.dismiss'),
      onClick: () => { storage.set(u.key, 1); versionBanner.hidden = true; versionBanner.replaceChildren(); },
    }, icon('x'));
    versionBanner.replaceChildren(h('div', { class: 'flex items-start gap-2' },
      icon('warning', 'mt-0.5 shrink-0 text-warning'),
      h('div', { class: 'min-w-0 flex-1', dir: 'auto' },
        h('span', { class: 'font-medium' }, t('version.unsupported')), ' ',
        h('span', { class: 'text-muted' }, t('version.unsupportedDetail', { product: u.product, version: u.version, min: u.min }))),
      dismiss));
    versionBanner.hidden = false;
  }

  const main = h('main', { id: 'main', tabindex: '-1', class: 'flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden outline-none' });
  const skip = h('a', { href: '#main', class: 'sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-[200] focus:rounded-md focus:bg-accent focus:px-3 focus:py-2 focus:text-accent-fg', onClick: (e) => { e.preventDefault(); main.focus(); } }, t('a11y.skipToContent'));

  const shell = h('div', { class: 'flex h-dvh flex-col bg-bg' },
    skip, header, versionBanner,
    h('div', { class: 'safe-x flex min-h-0 flex-1' }, sidebar.el, main));
  root.replaceChildren(shell);

  function renderCrumbs(route) {
    const items = [];
    const sep = () => h('li', { 'aria-hidden': 'true', class: 'text-subtle' }, icon('chevron-right', 'size-3.5'));
    const crumb = (label, link, ic, current) => h('li', { class: 'min-w-0' },
      h('a', {
        href: link, class: cls('flex min-w-0 items-center gap-1.5 rounded px-1.5 py-1 hover:bg-surface-2', current ? 'font-medium text-fg' : 'text-muted'),
        'aria-current': current ? 'page' : null,
      }, icon(ic, 'size-3.5'), h('span', { class: 'truncate max-w-[12rem]', dir: 'auto' }, label)));
    if (route.db) {
      items.push(crumb(route.schema ? `${route.db}.${route.schema}` : route.db, href.db(route.conn, route.db, route.schema), 'database', !route.table && route.name === 'db'));
      if (route.table) items.push(sep(), crumb(route.table, href.table(route.conn, route.db, route.schema, route.table, 'browse'), 'table', true));
      else if (route.name === 'dbObject') {
        const ic = { routine: 'function', trigger: 'zap', event: 'calendar' }[route.kind] || 'function';
        const fresh = route.kind === 'trigger' ? 'routines.newTrigger' : route.kind === 'event' ? 'routines.newEvent' : route.params?.new === 'function' ? 'routines.newFunction' : 'routines.newProcedure';
        items.push(sep(), crumb(route.object || t(fresh), location.hash, ic, true));
      } else if (route.name !== 'db') items.push(sep(), h('li', { class: 'px-1.5 text-fg' }, t(route.name === 'diff' ? 'diff.title' : `nav.${route.name}`)));
    } else if (route.name !== 'server') {
      items.push(h('li', { class: 'px-1.5 font-medium text-fg' }, route.name === 'security' ? t('twofa.title') : t(`nav.${route.name}`)));
    }
    crumbs.replaceChildren(...items);
  }

  return {
    main,
    async update(route) {
      const { auth } = appStore.get();
      const c = (auth?.connections || []).find((x) => String(x.id) === String(route.conn));
      connLabel.textContent = connectionLabel(c);
      roBadge.hidden = !isReadOnly(c || {});
      connBtn.title = roBadge.hidden ? '' : t('readonly.badgeTitle');
      renderCrumbs(route);
      renderVersionBanner(c);
      sidebar.update(route).catch((e) => console.error(e));
    },
    destroy() {
      offs.forEach((f) => f());
      sidebar.destroy();
    },
  };
}
