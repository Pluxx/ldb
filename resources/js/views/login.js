/**
 * Login view: driver picker cards (with availability), presets, host/port/user/password/db,
 * SQLite file path, remembers last driver/host/user/preset (never the password).
 * Honors auth.status settings: allow_arbitrary_server=false / only_presets → preset choice
 * only (no host/port), sqlite_enabled → SQLite option.
 * Two-factor logins: auth.login answers {twofa_required} → code step (auth.verify2fa with a
 * TOTP or recovery code); a reload while a login is pending (status.twofa_pending) resumes it.
 */
import { h, cls, uid } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { bindMenu } from '../ui/menu.js';
import { callout } from '../ui/feedback.js';
import { t, LOCALES, locale } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore, storage } from '../store.js';
import { setAuth, changeLocale } from '../session.js';
import { sanitizeNext, swapConn, serverHint } from '../share.js';
import { navigate, href } from '../router.js';
import { setTheme, themeIcon } from '../ui/theme.js';
import { codeField, codeErrorMessage } from './_twofa-code.js';

const DEFAULT_PORTS = { mysql: 3306, pgsql: 5432, sqlsrv: 1433 };
const DRIVER_ICON = { mysql: 'database', pgsql: 'database', sqlite: 'hard-drive', sqlsrv: 'server' };

export default {
  title: () => t('login.title'),

  render(el, ctx) {
    const status = appStore.get().auth || {};
    const settings = status.settings || {};
    const presets = status.presets || [];
    const presetOnly = settings.allow_arbitrary_server === false || settings.only_presets === true;
    const sqliteEnabled = settings.sqlite_enabled !== false;
    const drivers = (status.drivers || [
      { id: 'mysql', label: 'MySQL / MariaDB', available: true },
      { id: 'pgsql', label: 'PostgreSQL', available: true },
      { id: 'sqlite', label: 'SQLite', available: true },
      { id: 'sqlsrv', label: 'SQL Server', available: true },
    ]).filter((d) => d.id !== 'sqlite' || sqliteEnabled);
    const adding = !!ctx.params.add && (status.connections || []).length > 0;
    // Opened from a share link / deep link whose connection isn't open: connect, then continue there.
    const next = sanitizeNext(ctx.params.next);
    const srv = next ? String(ctx.params.srv || '') : '';

    const last = storage.get('login.last', {}) || {};
    // Preset-only login (no free host) still offers a plain SQLite file when SQLite is usable
    // (sqlite_paths configured, not only_presets) and no SQLite preset already provides it.
    const sqliteFree = presetOnly && drivers.some((d) => d.id === 'sqlite' && d.available) && !presets.some((p) => p.driver === 'sqlite');
    const state = {
      driver: last.driver && drivers.some((d) => d.id === last.driver && d.available) ? last.driver : (drivers.find((d) => d.available)?.id || 'mysql'),
      preset: presets.some((p) => p.id === last.preset) ? last.preset : (presetOnly && !(sqliteFree && (last.driver === 'sqlite' || !presets.length)) ? presets[0]?.id || '' : ''),
    };
    if (sqliteFree && !state.preset) state.driver = 'sqlite';
    const hintPreset = srv ? presets.find((p) => p.id === srv) : null;
    const hintDriver = srv && !hintPreset ? drivers.find((d) => d.id === srv && d.available) : null;
    if (hintPreset) state.preset = hintPreset.id;
    else if (hintDriver) { state.driver = hintDriver.id; state.preset = presetOnly ? state.preset : ''; }

    // ----- fields
    const field = (label, input, extra) => {
      const id = input.id || uid('lg');
      input.id = id;
      return h('div', { class: cls('min-w-0', extra) }, h('label', { class: 'label', for: id }, label), input);
    };
    const hostIn = h('input', { class: 'input', name: 'host', autocomplete: 'off', spellcheck: 'false', dir: 'ltr', value: last.host || 'localhost' });
    const portIn = h('input', { class: 'input', name: 'port', inputmode: 'numeric', dir: 'ltr', placeholder: String(DEFAULT_PORTS[state.driver] || ''), value: last.port || '' });
    const userIn = h('input', { class: 'input', name: 'username', autocomplete: 'username', spellcheck: 'false', dir: 'auto', value: last.user || '' });
    const passIn = h('input', { class: 'input pe-10', name: 'password', type: 'password', autocomplete: 'current-password', dir: 'ltr' });
    const showPass = h('button', {
      type: 'button', class: 'absolute end-1 top-1/2 -translate-y-1/2 btn btn-ghost btn-sm btn-icon', 'aria-label': t('login.showPassword'), 'aria-pressed': 'false',
      onClick: () => {
        const show = passIn.type === 'password';
        passIn.type = show ? 'text' : 'password';
        showPass.setAttribute('aria-pressed', String(show));
        showPass.replaceChildren(icon(show ? 'eye-off' : 'eye'));
      },
    }, icon('eye'));
    const dbIn = h('input', { class: 'input', name: 'database', spellcheck: 'false', dir: 'auto', placeholder: t('login.databaseOptional') });
    const fileIn = h('input', { class: 'input font-mono', name: 'file', spellcheck: 'false', dir: 'ltr', placeholder: '/var/lib/ldb/app.sqlite', value: last.file || '' });
    const labelIn = h('input', { class: 'input', name: 'label', dir: 'auto', placeholder: t('login.labelPlaceholder') });

    const SQLITE_OPT = '__sqlite';
    const presetSel = presets.length || sqliteFree ? h('select', {
      class: 'select', name: 'preset',
      onChange: () => {
        if (presetSel.value === SQLITE_OPT) { state.preset = ''; state.driver = 'sqlite'; } else { state.preset = presetSel.value; applyPreset(); }
        sync();
      },
    },
    presetOnly ? null : h('option', { value: '' }, t('login.customServer')),
    presets.map((p) => h('option', { value: p.id, selected: p.id === state.preset }, p.label || `${p.driver} · ${p.host}`)),
    sqliteFree ? h('option', { value: SQLITE_OPT, selected: !state.preset && state.driver === 'sqlite' }, t('login.sqliteOption')) : null) : null;

    const driverCards = h('div', { class: 'grid grid-cols-2 gap-2', role: 'radiogroup', 'aria-label': t('login.driver') });
    function renderDrivers() {
      driverCards.replaceChildren(...drivers.map((d) => {
        const checked = d.id === state.driver;
        return h('button', {
          type: 'button', role: 'radio', 'aria-checked': String(checked), disabled: !d.available,
          tabindex: checked ? '0' : '-1',
          class: cls('flex min-h-14 items-center gap-2.5 rounded-lg border p-2.5 text-start transition-colors',
            checked ? 'border-accent bg-accent-soft ring-1 ring-accent' : 'border-border bg-surface hover:border-border-strong',
            !d.available && 'opacity-55'),
          onClick: () => { state.driver = d.id; state.preset = ''; if (presetSel) presetSel.value = ''; sync(); },
          onKeydown: (e) => {
            const avail = drivers.filter((x) => x.available);
            const i = avail.findIndex((x) => x.id === state.driver);
            const fwd = ['ArrowRight', 'ArrowDown'];
            const back = ['ArrowLeft', 'ArrowUp'];
            if (![...fwd, ...back].includes(e.key)) return;
            e.preventDefault();
            const rtl = document.documentElement.dir === 'rtl';
            let dirn = fwd.includes(e.key) ? 1 : -1;
            if (rtl && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) dirn = -dirn;
            state.driver = avail[(i + dirn + avail.length) % avail.length].id;
            sync();
            driverCards.querySelector('[aria-checked="true"]')?.focus();
          },
        },
        h('span', { class: cls('flex size-8 shrink-0 items-center justify-center rounded-md', checked ? 'bg-accent text-accent-fg' : 'bg-surface-2 text-muted') }, icon(DRIVER_ICON[d.id] || 'database')),
        h('span', { class: 'min-w-0' },
          h('span', { class: 'block truncate text-[13px] font-medium' }, d.label),
          !d.available ? h('span', { class: 'block truncate text-[11px] text-warning' }, t('login.unavailable')) : null));
      }));
    }

    function applyPreset() {
      const p = presets.find((x) => x.id === state.preset);
      if (!p) return;
      state.driver = p.driver;
      hostIn.value = p.host || '';
      portIn.value = p.port ? String(p.port) : '';
      if (p.database) dbIn.value = p.database;
    }

    const serverRow = h('div', { class: 'grid grid-cols-[1fr_6.5rem] gap-3' }, field(t('login.host'), hostIn), field(t('login.port'), portIn));
    const userRow = field(t('login.user'), userIn);
    const credRows = h('div', { class: 'grid gap-3' },
      userRow,
      h('div', {}, h('label', { class: 'label', for: passIn.id || (passIn.id = uid('lg')) }, t('login.password')), h('div', { class: 'relative' }, passIn, showPass)));
    const dbRow = field(t('login.database'), dbIn);
    const fileRow = field(t('login.sqliteFile'), fileIn);
    const fileHelp = h('p', { class: 'help' }, t('login.sqliteHelp'));
    fileRow.appendChild(fileHelp);
    // Read-only: user may opt in; a read_only preset or the global config forces it (checked + disabled).
    const roIn = h('input', { type: 'checkbox', class: 'checkbox mt-0.5', name: 'read_only', checked: !!last.readOnly });
    const roHelp = h('span', { class: 'help mt-0.5 block' });
    const roRow = h('label', { class: 'flex cursor-pointer items-start gap-2' }, roIn,
      h('span', { class: 'min-w-0' }, h('span', { class: 'inline-flex items-center gap-1 font-medium' }, icon('lock', 'size-3.5 text-muted'), t('readonly.loginOption')), roHelp));
    let roUserChoice = !!last.readOnly;
    roIn.addEventListener('change', () => { if (!roIn.disabled) roUserChoice = roIn.checked; });
    const more = h('details', { class: 'group text-[13px]' },
      h('summary', { class: 'cursor-pointer list-none text-muted hover:text-fg [&::-webkit-details-marker]:hidden' },
        h('span', { class: 'inline-flex items-center gap-1' }, icon('chevron-right', 'size-3.5 transition-transform group-open:rotate-90 rtl:group-open:-rotate-90'), t('login.moreOptions'))),
      h('div', { class: 'mt-3 grid gap-3' }, field(t('login.label'), labelIn), roRow));

    const errorBox = h('div', { hidden: true });
    const cardBody = h('div', {});
    const submitBtn = h('button', { type: 'submit', class: 'btn btn-primary h-10 w-full' }, icon('plug'), t('login.connect'));

    function sync() {
      const p = presets.find((x) => x.id === state.preset);
      if (p) state.driver = p.driver;
      renderDrivers();
      const sqlite = state.driver === 'sqlite';
      driverCards.hidden = presetOnly;
      if (sqliteFree && presetSel) presetSel.value = state.preset || (state.driver === 'sqlite' ? SQLITE_OPT : '');
      serverRow.hidden = sqlite || presetOnly || !!p;
      // SQLite has no users; the password is the server-wide sqlite_password_hash (if required)
      credRows.hidden = sqlite && settings.sqlite_password === false;
      userRow.hidden = sqlite;
      fileRow.hidden = !sqlite;
      dbRow.hidden = sqlite;
      portIn.placeholder = String(DEFAULT_PORTS[state.driver] || '');
      const forced = settings.read_only === true || !!p?.read_only;
      roIn.disabled = forced;
      roIn.checked = forced || roUserChoice;
      roHelp.textContent = forced ? t('readonly.loginForced') : t('readonly.loginHelp');
      if (forced) more.open = true;
    }

    const form = h('form', {
      class: 'grid gap-4', novalidate: true,
      onSubmit: async (e) => {
        e.preventDefault();
        errorBox.hidden = true;
        const sqlite = state.driver === 'sqlite';
        const params = { driver: state.driver };
        if (state.preset) params.preset = state.preset;
        if (sqlite) {
          params.file = fileIn.value.trim();
          if (settings.sqlite_password !== false) params.password = passIn.value;
        } else {
          if (!state.preset) {
            params.host = hostIn.value.trim();
            if (portIn.value.trim()) params.port = Number(portIn.value.trim());
          }
          params.user = userIn.value;
          params.password = passIn.value;
          if (dbIn.value.trim()) params.database = dbIn.value.trim();
        }
        if (labelIn.value.trim()) params.label = labelIn.value.trim();
        if (roIn.checked) params.read_only = true;
        submitBtn.disabled = true;
        submitBtn.replaceChildren(icon('loader', 'spin'), t('login.connecting'));
        try {
          const res = await api('auth.login', params, { silentAuth: true });
          storage.set('login.last', { driver: state.driver, preset: state.preset, host: hostIn.value.trim(), port: portIn.value.trim(), user: userIn.value, file: sqlite ? params.file : last.file, readOnly: roUserChoice });
          if (res.twofa_required) {
            setAuth(res);
            showCodeStep();
            return;
          }
          finish(res);
        } catch (err) {
          const msg = err.code === 'rate_limited' ? t('login.rateLimited') : err.code === 'network' ? t('login.networkError') : err.message;
          errorBox.replaceChildren(callout('danger', msg));
          errorBox.hidden = false;
          passIn.value = '';
          (sqlite ? fileIn : passIn).focus();
        } finally {
          submitBtn.disabled = false;
          submitBtn.replaceChildren(icon('plug'), t('login.connect'));
        }
      },
    },
    presetSel ? field(presetOnly ? t('login.server') : t('login.preset'), presetSel) : null,
    driverCards, serverRow, credRows, dbRow, fileRow, more, errorBox, submitBtn);

    function finish(res) {
      setAuth(res);
      const newConn = res.conn ?? res.connections?.[res.connections.length - 1]?.id;
      if (res.twofa_enroll) navigate(href.security(newConn));
      else navigate((next && swapConn(next, newConn)) || href.server(newConn));
    }

    // ----- second factor (TOTP / recovery code)
    function showCodeStep() {
      const intro = h('p', { class: 'text-[13px] text-muted' });
      const field = codeField({ recovery: true, onComplete: () => stepForm.requestSubmit() });
      const verifyBtn = h('button', { type: 'submit', class: 'btn btn-primary h-10 w-full' }, icon('shield-check'), t('twofa.verify'));
      const syncIntro = () => { intro.textContent = field.isRecovery() ? t('twofa.login.bodyRecovery') : t('twofa.login.body'); };
      field.el.querySelector('button')?.addEventListener('click', syncIntro);
      syncIntro();
      let busy = false;
      const back = (msg) => {
        cardBody.replaceChildren(form);
        if (msg) { errorBox.replaceChildren(callout('danger', msg)); errorBox.hidden = false; }
        setTimeout(() => passIn.focus(), 0);
      };
      const stepForm = h('form', {
        class: 'grid gap-4', novalidate: true, 'aria-labelledby': 'twofa-step-title',
        onSubmit: async (e) => {
          e.preventDefault();
          if (busy) return;
          const params = field.params();
          if (!params) { field.error(t('twofa.invalid')); field.focus(); return; }
          busy = true;
          verifyBtn.disabled = true;
          verifyBtn.replaceChildren(icon('loader', 'spin'), t('twofa.verifying'));
          try {
            finish(await api('auth.verify2fa', params, { silentAuth: true }));
          } catch (err) {
            if (err.code === 'twofa_expired') { back(codeErrorMessage(err)); return; }
            field.clear();
            field.error(codeErrorMessage(err));
            field.focus();
          } finally {
            busy = false;
            verifyBtn.disabled = false;
            verifyBtn.replaceChildren(icon('shield-check'), t('twofa.verify'));
          }
        },
      },
      h('div', { class: 'flex items-start gap-3' },
        h('span', { class: 'flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent' }, icon('shield-check', 'size-5')),
        h('div', { class: 'min-w-0' }, h('h2', { id: 'twofa-step-title', class: 'text-[15px] font-semibold' }, t('twofa.heading')), intro)),
      field.el, verifyBtn,
      h('button', { type: 'button', class: 'inline-flex items-center justify-center gap-1 text-[13px] text-muted hover:text-fg', onClick: () => back() }, icon('arrow-left', 'size-3.5'), t('twofa.login.back')));
      cardBody.replaceChildren(stepForm);
      setTimeout(() => field.focus(), 0);
    }

    // ----- reconnect notice (share links)
    let reconnectBox = null;
    if (next) {
      const serverName = hintPreset ? (hintPreset.label || `${hintPreset.driver} · ${hintPreset.host}`) : hintDriver?.label || null;
      const existing = (status.connections || []).filter((c) => srv && serverHint(c) === srv);
      reconnectBox = callout('info', h('div', { class: 'space-y-2' },
        h('div', { class: 'font-medium' }, t('share.reconnect.title')),
        h('p', {}, serverName ? t('share.reconnect.body', { server: serverName }) : t('share.reconnect.bodyGeneric')),
        existing.length ? h('div', { class: 'flex flex-wrap gap-2' }, existing.map((c) => h('a', { class: 'btn btn-sm', href: swapConn(next, c.id) || href.server(c.id) }, icon('plug'), t('share.reconnect.useExisting', { name: c.label || c.id })))) : null,
        existing.length ? h('p', { class: 'text-xs text-muted' }, t('share.reconnect.orConnect')) : null));
    }

    // ----- corner controls (language / theme)
    const langBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm gap-1.5', 'aria-label': t('lang.label') }, icon('languages'), LOCALES.find((l) => l.code === locale())?.name);
    bindMenu(langBtn, () => LOCALES.map((l) => ({ label: l.name, lang: l.code, checked: l.code === locale(), onSelect: () => changeLocale(l.code) })), { align: 'end' });
    const themeBtn = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('theme.label') }, icon(themeIcon(appStore.get().theme)));
    bindMenu(themeBtn, () => ['light', 'dark', 'system'].map((p) => ({ label: t(`theme.${p}`), icon: themeIcon(p), checked: appStore.get().theme === p, onSelect: () => { setTheme(p); themeBtn.replaceChildren(icon(themeIcon(p))); } })), { align: 'end' });

    el.className = 'view min-h-dvh overflow-auto bg-bg';
    el.replaceChildren(
      h('div', { class: 'flex justify-end gap-1 p-3 pt-[max(0.75rem,env(safe-area-inset-top))]' }, langBtn, themeBtn),
      h('main', { class: 'mx-auto flex w-full max-w-[420px] flex-col px-4 pb-16 pt-2 sm:pt-8' },
        h('div', { class: 'mb-6 flex flex-col items-center text-center' },
          h('span', { class: 'mb-3 flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-fg shadow-pop' }, icon('logo', 'size-7')),
          h('h1', { class: 'text-xl font-semibold tracking-tight' }, adding ? t('login.addTitle') : t('login.heading')),
          h('p', { class: 'mt-1 text-[13px] text-muted' }, t('login.subtitle'))),
        h('div', { class: 'card p-5 shadow-pop sm:p-6' },
          adding ? h('a', { href: href.server(status.connections[0].id), class: 'mb-4 inline-flex items-center gap-1 text-[13px] text-muted hover:text-fg' }, icon('arrow-left', 'size-3.5'), t('common.back')) : null,
          reconnectBox ? h('div', { class: 'mb-4' }, reconnectBox) : null,
          cardBody),
        h('p', { class: 'mt-6 text-center text-xs text-subtle' }, status.version ? `LDB ${status.version}` : 'LDB')),
    );
    if (state.preset) applyPreset();
    sync();
    if (status.twofa_pending && !status.authenticated) { showCodeStep(); return; }
    cardBody.replaceChildren(form);
    const focusTarget = state.driver === 'sqlite' ? fileIn : (userIn.value ? passIn : (serverRow.hidden ? userIn : hostIn));
    setTimeout(() => focusTarget.focus(), 0);
  },
};
