/**
 * Server administration (route #/c/{conn}/server/{tab}): tabs Variables (searchable, grouped by
 * prefix, copy), Status (KPIs + searchable list), Processes (auto-refresh, hide sleeping, long
 * query highlight, kill), Users (list, grants, create / change password / drop), Charsets,
 * Collations, Engines. Tabs the driver does not support (capabilities) are hidden.
 */
import { h, cls, debounce, setChildren, copyText, uid, isCompact } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { createTabs } from '../ui/tabs.js';
import { openModal, confirmDialog, viewerDialog, formDialog } from '../ui/modal.js';
import { bindMenu } from '../ui/menu.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, callout, statCard, refreshButton } from '../ui/feedback.js';
import { t, plural, fmtNumber, fmtBytes, fmtUptime } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { getOverview, getDatabases } from '../data.js';
import { highlightSql } from '../sql/highlight.js';

const TABS = [
  { id: 'variables', icon: 'sliders', cap: 'variables' },
  { id: 'status', icon: 'gauge', cap: 'status' },
  { id: 'monitor', icon: 'chart-line', cap: 'monitor' }, // own route/view: views/server-monitor.js
  { id: 'processes', icon: 'activity', cap: 'processes' },
  { id: 'users', icon: 'users', cap: 'users' },
  { id: 'charsets', icon: 'languages', cap: 'charsets' },
  { id: 'collations', icon: 'arrow-up-down', cap: 'collations' },
  { id: 'engines', icon: 'layers', cap: 'engines' },
];
const LONG_QUERY_SEC = 10;
const REFRESH_MS = 3000;

let cleanup = [];

/** "1 day 02:03:04" / "00:00:05" / "12" → seconds */
function toSeconds(v) {
  if (v === null || v === undefined || v === '') return 0;
  if (typeof v === 'number') return v;
  const s = String(v);
  if (/^\d+(\.\d+)?$/.test(s)) return Number(s);
  let total = 0;
  const days = /(\d+)\s+days?/.exec(s);
  if (days) total += Number(days[1]) * 86400;
  const hms = /(-?\d+):(\d+):(\d+)/.exec(s);
  if (hms) total += Number(hms[1]) * 3600 + Number(hms[2]) * 60 + Number(hms[3]);
  return total;
}

/** Cryptographically random password. */
function generatePassword(len = 20) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!@#%+=';
  const buf = new Uint32Array(len);
  crypto.getRandomValues(buf);
  return Array.from(buf, (n) => chars[n % chars.length]).join('');
}

const searchInput = (placeholder, onInput) => {
  const el = h('input', { type: 'search', class: 'input input-sm w-full sm:w-64', placeholder, 'aria-label': placeholder });
  el.addEventListener('input', debounce(() => onInput(el.value.trim().toLowerCase()), 120));
  return el;
};

const copyBtn = (text, label) => h('button', {
  type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': label, title: label,
  onClick: () => copyText(text).then((ok) => (ok ? toast.success(t('common.copied')) : toast.error(t('grid.copyFailed')))),
}, icon('copy'));

/* ====================================================================== name/value lists */

function prefixOf(name) {
  const m = /^([A-Za-z0-9]+)[_.]/.exec(name);
  return m ? m[1].toLowerCase() : name.toLowerCase();
}

function nameValueTable(rows, emptyTitle) {
  if (!rows.length) return emptyState({ icon: 'search', title: emptyTitle });
  return h('table', { class: 'table-grid table-fixed' },
    h('colgroup', {}, h('col', { class: 'w-[42%]' }), h('col'), h('col', { class: 'w-12' })),
    h('tbody', {}, rows.map((r) => {
      const v = r.value === null || r.value === undefined ? null : String(r.value);
      return h('tr', {},
        h('td', { class: 'break-all font-mono text-[12.5px]', dir: 'ltr' }, r.name),
        h('td', { class: 'break-all font-mono text-[12.5px] text-muted', dir: 'ltr' }, v === null ? h('span', { class: 'badge-null' }, 'NULL') : v === '' ? h('span', { class: 'text-subtle' }, t('admin.empty')) : v),
        h('td', { class: 'text-end' }, copyBtn(`${r.name} = ${v ?? 'NULL'}`, t('admin.copyNamed', { name: r.name }))));
    })));
}

/** Searchable name/value list grouped by name prefix (variables, status). */
function groupedList(rows, { grouped = true } = {}) {
  let filter = '';
  const box = h('div', { class: 'space-y-4' });
  const count = h('span', { class: 'text-[12.5px] text-muted tabular-nums', 'aria-live': 'polite' });
  const draw = () => {
    const list = rows.filter((r) => !filter || r.name.toLowerCase().includes(filter) || String(r.value ?? '').toLowerCase().includes(filter));
    count.textContent = plural('admin.items', list.length);
    if (!grouped || filter) { setChildren(box, h('div', { class: 'card overflow-hidden' }, nameValueTable(list, t('sidebar.noMatches')))); return; }
    const groups = new Map();
    for (const r of list) {
      const p = prefixOf(r.name);
      if (!groups.has(p)) groups.set(p, []);
      groups.get(p).push(r);
    }
    const other = [];
    const sections = [];
    for (const [p, items] of groups) {
      if (items.length < 3) other.push(...items);
      else sections.push([p, items]);
    }
    if (other.length) sections.push([t('admin.otherGroup'), other]);
    setChildren(box, sections.map(([p, items]) => h('details', { class: 'group card overflow-hidden', open: sections.length <= 12 },
      h('summary', { class: 'flex min-h-10 cursor-pointer list-none items-center [&::-webkit-details-marker]:hidden gap-2 bg-surface-2/40 px-3 py-2 text-[13px] font-medium' },
        icon('chevron-right', 'size-3.5 text-muted transition-transform group-open:rotate-90 rtl:group-open:-rotate-90'),
        h('span', { class: 'font-mono', dir: 'ltr' }, p), h('span', { class: 'badge' }, fmtNumber(items.length))),
      nameValueTable(items, ''))));
  };
  const search = searchInput(t('admin.search'), (v) => { filter = v; draw(); });
  draw();
  return { search, count, box };
}

async function variablesTab(body, ctx) {
  const rows = await api('server.variables', { conn: ctx.conn });
  const g = groupedList(rows);
  setChildren(body,
    h('div', { class: 'flex flex-wrap items-center gap-2' }, g.search, g.count, h('div', { class: 'flex-1' }),
      refreshButton(async () => { try { await variablesTab(body, ctx); } catch (e) { toastError(e); } })),
    g.box);
}

/** KPI cards derived from status values (MySQL / MariaDB / PostgreSQL); generic fallback otherwise. */
function kpis(rows, driver) {
  const m = new Map(rows.map((r) => [r.name.toLowerCase(), r.value]));
  const num = (k) => (m.has(k) && m.get(k) !== null && m.get(k) !== '' && !Number.isNaN(Number(m.get(k))) ? Number(m.get(k)) : null);
  const out = [];
  if (driver === 'mysql') {
    const up = num('uptime');
    out.push({ label: t('admin.kpi.uptime'), value: fmtUptime(up), icon: 'clock' });
    const thr = num('threads_connected');
    if (thr !== null) out.push({ label: t('admin.kpi.connections'), value: fmtNumber(thr), hint: num('max_used_connections') !== null ? t('admin.kpi.maxUsed', { n: fmtNumber(num('max_used_connections')) }) : null, icon: 'plug' });
    const q = num('questions') ?? num('queries');
    if (q !== null && up) out.push({ label: t('admin.kpi.qps'), value: fmtNumber(q / up, { maximumFractionDigits: 2 }), hint: t('admin.kpi.total', { n: fmtNumber(q) }), icon: 'zap' });
    const rx = num('bytes_received');
    const tx = num('bytes_sent');
    if (rx !== null && tx !== null) out.push({ label: t('admin.kpi.traffic'), value: fmtBytes(rx + tx), hint: t('admin.kpi.rxtx', { rx: fmtBytes(rx), tx: fmtBytes(tx) }), icon: 'arrow-up-down' });
  } else if (driver === 'pgsql') {
    const up = num('uptime');
    if (up !== null) out.push({ label: t('admin.kpi.uptime'), value: fmtUptime(up), icon: 'clock' });
    const c = num('connections');
    if (c !== null) out.push({ label: t('admin.kpi.connections'), value: fmtNumber(c), hint: num('max_connections') !== null ? t('admin.kpi.ofMax', { n: fmtNumber(num('max_connections')) }) : null, icon: 'plug' });
    const commits = num('total_commits');
    if (commits !== null && up) out.push({ label: t('admin.kpi.tps'), value: fmtNumber(commits / up, { maximumFractionDigits: 2 }), hint: t('admin.kpi.total', { n: fmtNumber(commits) }), icon: 'zap' });
    if (m.has('db.cache_hit_ratio')) out.push({ label: t('admin.kpi.cacheHit'), value: String(m.get('db.cache_hit_ratio')), hint: num('database_size') !== null ? t('admin.kpi.dbSize', { size: fmtBytes(num('database_size')) }) : null, icon: 'gauge' });
  } else {
    rows.slice(0, 4).forEach((r) => out.push({ label: r.name, value: String(r.value ?? '—'), icon: 'gauge' }));
  }
  return out;
}

async function statusTab(body, { conn, driver }) {
  const load = () => api('server.status', { conn });
  let rows = await load();
  const kpiBox = h('section', { class: 'grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4', 'aria-label': t('admin.kpis') });
  const listBox = h('div');
  const head = h('div', { class: 'flex flex-wrap items-center gap-2' });
  const draw = () => {
    setChildren(kpiBox, kpis(rows, driver).map(statCard));
    const g = groupedList(rows, { grouped: driver === 'mysql' });
    setChildren(head, g.search, g.count, h('div', { class: 'flex-1' }),
      refreshButton(async () => { try { rows = await load(); draw(); } catch (e) { toastError(e); } }));
    setChildren(listBox, g.box);
  };
  draw();
  setChildren(body, kpiBox, head, listBox);
}

/* ====================================================================== processes */

async function processesTab(body, { conn, caps, driver }) {
  let data = await api('server.processes', { conn });
  let hideSleeping = true;
  let auto = false;
  let timer = null;
  let filter = '';
  const box = h('div', { class: 'card overflow-x-auto' });
  const count = h('span', { class: 'text-[12.5px] text-muted tabular-nums', 'aria-live': 'polite' });

  const colIdx = (...names) => {
    const lower = data.columns.map((c) => String(typeof c === 'string' ? c : c.name).toLowerCase());
    for (const n of names) { const i = lower.indexOf(n); if (i >= 0) return i; }
    return -1;
  };

  const draw = () => {
    const cols = data.columns.map((c) => (typeof c === 'string' ? c : c.name));
    const iId = colIdx('id', 'pid');
    const iCmd = colIdx('command');
    const iState = colIdx('state');
    const iTime = colIdx('time', 'duration');
    const iQuery = colIdx('info', 'query');
    const sleeping = (r) => (driver === 'pgsql' ? r[iState] === 'idle' || r[iState] === null : iCmd >= 0 && /^(sleep|daemon)$/i.test(String(r[iCmd] ?? '')));
    const rows = data.rows.filter((r) => (!hideSleeping || !sleeping(r)) && (!filter || r.some((v) => String(v ?? '').toLowerCase().includes(filter))));
    count.textContent = t('admin.proc.count', { n: fmtNumber(rows.length), total: fmtNumber(data.rows.length) });
    if (!rows.length) { setChildren(box, emptyState({ icon: 'activity', title: t('admin.proc.none') })); return; }
    setChildren(box, h('table', { class: 'table-grid' },
      h('thead', {}, h('tr', {},
        cols.map((c, i) => h('th', { class: cls(i === iTime && 'num', 'whitespace-nowrap') }, c)),
        caps.kill ? h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))) : null)),
      h('tbody', {}, rows.map((r) => {
        const long = iTime >= 0 && !sleeping(r) && toSeconds(r[iTime]) >= LONG_QUERY_SEC && r[iQuery];
        return h('tr', { class: cls(long && '[&>td]:bg-warning/10'), title: long ? t('admin.proc.long') : null },
          r.map((v, i) => {
            if (i === iQuery && v) {
              return h('td', { class: 'max-w-[24rem]' }, h('button', {
                type: 'button', class: 'block max-w-full truncate text-start font-mono text-[12px] hover:text-accent', dir: 'ltr', title: String(v),
                onClick: () => viewerDialog({ title: t('admin.proc.query', { id: r[iId] }), text: String(v), language: 'sql' }),
              }, String(v)));
            }
            return h('td', { class: cls('whitespace-nowrap', i === iTime && 'num', i === iTime && long && 'font-semibold text-warning'), dir: 'auto' },
              v === null || v === undefined ? h('span', { class: 'text-subtle' }, '—') : String(v));
          }),
          caps.kill ? h('td', { class: 'w-10 text-end' }, h('button', {
            type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('admin.proc.killNamed', { id: r[iId] }), title: t('admin.proc.kill'),
            onClick: () => kill(r[iId]),
          }, icon('circle-x'))) : null);
      }))));
  };

  const reload = async () => {
    try { data = await api('server.processes', { conn }); draw(); } catch (e) { toastError(e); setAuto(false); }
  };
  async function kill(id) {
    const ok = await confirmDialog({
      title: t('admin.proc.killTitle', { id }), message: t('admin.proc.killMessage'), danger: true, confirmLabel: t('admin.proc.kill'),
      sql: driver === 'pgsql' ? `SELECT pg_terminate_backend(${Number(id)});` : `KILL ${Number(id)};`,
    });
    if (!ok) return;
    try { await api('server.kill', { conn, id: String(id) }); toast.success(t('admin.proc.killed', { id })); reload(); } catch (e) { toastError(e); }
  }
  const autoBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', onClick: () => setAuto(!auto) }, icon('timer'), t('admin.proc.auto'));
  function setAuto(v) {
    auto = v;
    autoBtn.setAttribute('aria-pressed', String(v));
    autoBtn.classList.toggle('btn-primary', v);
    clearInterval(timer);
    timer = v ? setInterval(() => { if (document.visibilityState === 'visible') reload(); }, REFRESH_MS) : null;
  }
  cleanup.push(() => clearInterval(timer));
  const sleepId = uid('psl');
  setChildren(body,
    h('div', { class: 'flex flex-wrap items-center gap-2' },
      searchInput(t('admin.search'), (v) => { filter = v; draw(); }),
      h('label', { for: sleepId, class: 'flex min-h-9 items-center gap-2 text-[13px]' },
        h('input', { id: sleepId, type: 'checkbox', class: 'checkbox', checked: hideSleeping, onChange: (e) => { hideSleeping = e.target.checked; draw(); } }), t('admin.proc.hideSleeping')),
      count, h('div', { class: 'flex-1' }),
      autoBtn,
      refreshButton(reload)),
    box);
  draw();
}

/* ====================================================================== users */

function grantSql(driver, preset, user, host, db) {
  if (!preset || !db) return [];
  if (driver === 'pgsql') {
    const qi = (s) => `"${String(s).replace(/"/g, '""')}"`;
    if (preset === 'all') return [`GRANT ALL PRIVILEGES ON DATABASE ${qi(db)} TO ${qi(user)};`];
    return [`GRANT CONNECT ON DATABASE ${qi(db)} TO ${qi(user)};`, `GRANT USAGE ON SCHEMA public TO ${qi(user)};`, `GRANT SELECT ON ALL TABLES IN SCHEMA public TO ${qi(user)};`];
  }
  const ql = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
  const acct = `${ql(user)}@${ql(host || '%')}`;
  const ident = `\`${String(db).replace(/`/g, '``')}\``;
  if (preset === 'all') return [`GRANT ALL PRIVILEGES ON ${ident}.* TO ${acct};`];
  return [`GRANT SELECT, SHOW VIEW ON ${ident}.* TO ${acct};`];
}

function passwordField(id, onChange) {
  const input = h('input', { id, type: 'password', class: 'input font-mono', dir: 'ltr', autocomplete: 'new-password', spellcheck: 'false', onInput: () => onChange?.(input.value) });
  const reveal = h('button', {
    type: 'button', class: 'btn btn-icon shrink-0', 'aria-label': t('login.showPassword'), title: t('login.showPassword'), 'aria-pressed': 'false',
    onClick: () => { const show = input.type === 'password'; input.type = show ? 'text' : 'password'; reveal.setAttribute('aria-pressed', String(show)); reveal.replaceChildren(icon(show ? 'eye-off' : 'eye')); },
  }, icon('eye'));
  const gen = h('button', {
    type: 'button', class: 'btn shrink-0', title: t('admin.user.generate'),
    onClick: () => { input.value = generatePassword(); input.type = 'text'; reveal.setAttribute('aria-pressed', 'true'); reveal.replaceChildren(icon('eye-off')); onChange?.(input.value); copyText(input.value).then((ok) => ok && toast.info(t('admin.user.generatedCopied'))); },
  }, icon('wand'), h('span', { class: 'max-sm:sr-only' }, t('admin.user.generate')));
  return { input, el: h('div', { class: 'flex gap-2' }, input, reveal, gen) };
}

async function usersTab(body, { conn, driver, readOnly }) {
  const isPg = driver === 'pgsql';
  let users = await api('server.users', { conn });
  let filter = '';
  const box = h('div', { class: 'card overflow-x-auto' });
  const acctLabel = (u) => (u.host === null || u.host === undefined ? u.user : `${u.user}@${u.host}`);
  const sameAcct = (a, b) => a && b && a.user === b.user && (a.host ?? null) === (b.host ?? null);
  // Privileges editor: detail panel beside the list (lg+) or a full-screen sheet (smaller screens)
  const privsSupported = ['mysql', 'pgsql', 'sqlsrv'].includes(driver);
  const overview = await getOverview(conn).catch(() => null);
  const ver = String(overview?.info?.version || '');
  const canCreateRole = !readOnly && (isPg || (driver === 'mysql' && (/mariadb/i.test(ver) || overview?.info?.name === 'MariaDB' || parseInt(ver, 10) >= 8)));
  const detail = h('div', { class: 'card hidden min-h-[28rem] overflow-hidden lg:sticky lg:top-0 lg:flex lg:h-[calc(100dvh-12rem)] lg:flex-col' });
  let current = null; // {u, panel, modal?}
  const emptyDetail = () => setChildren(detail, emptyState({ icon: 'key', title: t('privs.selectTitle'), message: t('privs.selectMessage') }));
  emptyDetail();
  const confirmLeave = async () => !current?.panel.dirty() || confirmDialog({ title: t('privs.pending.discardTitle'), message: t('privs.leaveMessage'), confirmLabel: t('privs.pending.discard'), danger: true });
  const closeDetail = () => {
    if (!current) return;
    current.panel.destroy();
    current.modal?.close();
    current = null;
    emptyDetail();
  };

  async function openDetail(u) {
    if (!privsSupported) { showGrants(u); return; }
    if (current && !(await confirmLeave())) return;
    closeDetail();
    const { mountPrivileges } = await import('./_privileges.js');
    const opts = {
      conn, readOnly, account: u, accounts: users,
      onChanged: (acct) => reload().then(() => { if (acct) openDetail(users.find((x) => sameAcct(x, acct)) || acct); }),
      onDrop: () => dropUser(u),
    };
    if (isCompact()) {
      const host = h('div', { class: '-mx-5 -my-4 flex h-[calc(100dvh-3.25rem)] flex-col sm:h-[75dvh]' });
      const m = openModal({ title: t('privs.title'), size: 'full', body: host, dismissible: false });
      const panel = mountPrivileges(host, { ...opts, onClose: async () => { if (await confirmLeave()) closeDetail(); } });
      current = { u, panel, modal: m };
      m.closed.then(() => { if (current?.panel === panel) { panel.destroy(); current = null; } });
    } else {
      const panel = mountPrivileges(detail, { ...opts, onClose: async () => { if (await confirmLeave()) { closeDetail(); draw(); } } });
      current = { u, panel };
    }
    draw();
  }

  const draw = () => {
    const list = users.filter((u) => !filter || acctLabel(u).toLowerCase().includes(filter));
    if (!list.length) { setChildren(box, emptyState({ icon: 'users', title: filter ? t('sidebar.noMatches') : t('admin.user.none') })); return; }
    const attrs = (u) => {
      const b = [];
      if (u.is_role) b.push(h('span', { class: 'badge badge-accent' }, t('privs.badge.role')));
      if (isPg) {
        if (u.superuser) b.push(h('span', { class: 'badge badge-warning' }, t('admin.user.superuser')));
        if (!u.login && !u.is_role) b.push(h('span', { class: 'badge' }, t('admin.user.noLogin')));
        if (u.create_db) b.push(h('span', { class: 'badge' }, 'CREATEDB'));
        if (u.create_role) b.push(h('span', { class: 'badge' }, 'CREATEROLE'));
        if (u.member_of?.length) b.push(h('span', { class: 'badge', dir: 'auto' }, t('admin.user.memberOf', { roles: u.member_of.join(', ') })));
      } else {
        if (u.global_privileges) b.push(h('span', { class: cls('badge', u.global_privileges === 'ALL' && 'badge-warning') }, u.global_privileges));
        if (u.plugin && !u.is_role) b.push(h('span', { class: 'badge font-mono' }, u.plugin));
        if (u.account_locked && !u.is_role) b.push(h('span', { class: 'badge badge-danger' }, icon('lock'), t('admin.user.locked')));
        if (u.password_expired && !u.is_role) b.push(h('span', { class: 'badge badge-warning' }, t('admin.user.expired')));
        if (u.disabled) b.push(h('span', { class: 'badge badge-danger' }, icon('lock'), t('admin.user.locked')));
        if (u.type) b.push(h('span', { class: 'badge' }, u.type));
      }
      return b;
    };
    setChildren(box, h('table', { class: 'table-grid' },
      h('thead', {}, h('tr', {},
        h('th', {}, t('admin.user.user')),
        h('th', {}, h('span', { class: 'sr-only' }, t('common.actions'))))),
      h('tbody', {}, list.map((u) => {
        const more = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.moreActionsFor', { name: acctLabel(u) }) }, icon('ellipsis'));
        const mariaRole = u.is_role && (u.host === null || u.host === undefined) && !isPg;
        bindMenu(more, () => [
          ...(privsSupported ? [{ label: readOnly ? t('privs.view') : t('privs.edit'), icon: 'key', onSelect: () => openDetail(u) }] : []),
          ...(mariaRole ? [] : [{ label: t('admin.user.grants'), icon: 'scroll', onSelect: () => showGrants(u) }]),
          ...(readOnly ? [] : [
            ...(u.is_role ? [] : [{ label: t('admin.user.changePassword'), icon: 'key-round', onSelect: () => changePassword(u) }]),
            { separator: true },
            { label: u.is_role ? t('privs.dropRole') : t('admin.user.drop'), icon: 'trash', danger: true, onSelect: () => dropUser(u) },
          ]),
        ], { align: 'end' });
        const selected = sameAcct(current?.u, u);
        return h('tr', { 'aria-selected': selected ? 'true' : null },
          h('td', {}, h('button', { type: 'button', class: 'flex w-full min-w-0 flex-col items-start gap-1 text-start hover:text-accent', 'aria-current': selected ? 'true' : null, onClick: () => openDetail(u) },
            h('span', { class: 'flex min-w-0 max-w-full items-center gap-2 font-medium' },
              icon(u.is_role ? 'users' : 'key', 'text-muted'),
              h('span', { class: 'truncate font-mono text-[12.5px]', dir: 'ltr' }, u.user === '' ? t('admin.user.anonymous') : u.user),
              u.host !== null && u.host !== undefined ? h('span', { class: 'truncate font-mono text-[12px] font-normal text-muted', dir: 'ltr' }, `@${u.host}`) : null),
            h('span', { class: 'flex flex-wrap gap-1' }, attrs(u)))),
          h('td', { class: 'w-10 text-end' }, more));
      }))));
  };
  const reload = async () => { try { users = await api('server.users', { conn }); draw(); } catch (e) { toastError(e); } };

  async function showGrants(u) {
    const pre = h('pre', { class: 'max-h-[60dvh] overflow-auto rounded-lg border border-border bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap break-words', dir: 'ltr', tabindex: '0' }, skeleton({ rows: 3 }));
    let text = '';
    const m = openModal({
      title: t('admin.user.grantsFor', { name: acctLabel(u) }), size: 'lg', body: pre,
      actions: [
        h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => copyText(text).then(() => toast.success(t('common.copied'))) }, icon('copy'), t('common.copy')),
        h('button', { type: 'button', class: 'btn btn-primary', onClick: () => m.close() }, t('common.close')),
      ],
    });
    try {
      const grants = await api('server.userGrants', { conn, user: u.user, host: u.host ?? undefined });
      text = grants.map((g) => (/;\s*$/.test(g) ? g : `${g};`)).join('\n');
      pre.replaceChildren(highlightSql(text || '-- '));
    } catch (e) {
      pre.replaceChildren(errorState(e));
    }
  }

  async function changePassword(u) {
    const id = uid('pw');
    const pw = passwordField(id);
    const err = h('div', { class: 'mt-3', hidden: true });
    const save = h('button', { type: 'button', class: 'btn btn-primary', onClick: async () => {
      if (!pw.input.value) { pw.input.focus(); return; }
      save.disabled = true;
      try {
        await api('server.userSave', { conn, user: u.user, host: u.host ?? undefined, password: pw.input.value, grants: [], is_new: false });
        toast.success(t('admin.user.passwordChanged', { name: acctLabel(u) }));
        m.close(true);
      } catch (e) { err.hidden = false; setChildren(err, callout('danger', e.message)); } finally { save.disabled = false; }
    } }, t('common.save'));
    const m = openModal({
      title: t('admin.user.changePasswordFor', { name: acctLabel(u) }), size: 'md',
      body: [h('label', { class: 'label', for: id }, t('login.password')), pw.el, err],
      actions: [h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close() }, t('common.cancel')), save],
      initialFocus: pw.input,
    });
  }

  async function dropUser(u) {
    const ok = await confirmDialog({
      title: t('admin.user.dropTitle', { name: acctLabel(u) }), message: t('admin.user.dropMessage'), danger: true,
      requireText: u.user || acctLabel(u), confirmLabel: t('admin.user.drop'),
    });
    if (!ok) return;
    try {
      if (u.is_role && !isPg && driver === 'mysql') await api('server.roleDrop', { conn, name: u.user, host: u.host ?? undefined });
      else await api('server.userDrop', { conn, user: u.user, host: u.host ?? undefined });
      toast.success(t('admin.user.dropped', { name: acctLabel(u) }));
      if (sameAcct(current?.u, u)) closeDetail();
      reload();
    } catch (e) { toastError(e); }
  }

  async function createUser() {
    const dbs = await getDatabases(conn).catch(() => []);
    const ids = { user: uid('nu'), host: uid('nh'), pw: uid('np'), preset: uid('npr'), db: uid('ndb'), grants: uid('ng') };
    const userIn = h('input', { id: ids.user, class: 'input font-mono', dir: 'ltr', autocomplete: 'off', spellcheck: 'false', onInput: () => sync() });
    const hostIn = h('input', { id: ids.host, class: 'input font-mono', dir: 'ltr', value: '%', spellcheck: 'false', onInput: () => sync() });
    const pw = passwordField(ids.pw);
    const presetSel = h('select', { id: ids.preset, class: 'select', onChange: () => sync() },
      [['', t('admin.user.preset.none')], ['all', t('admin.user.preset.all')], ['read', t('admin.user.preset.read')]].map(([v, l]) => h('option', { value: v }, l)));
    const dbSel = h('select', { id: ids.db, class: 'select', onChange: () => sync() }, dbs.map((d) => h('option', { value: d.name }, d.name)));
    const grantsTa = h('textarea', { id: ids.grants, class: 'textarea font-mono text-[12.5px]', rows: 3, dir: 'ltr', spellcheck: 'false', placeholder: 'GRANT … ;' });
    let touched = false;
    grantsTa.addEventListener('input', () => { touched = true; });
    const dbField = h('div', { class: 'min-w-0 flex-1' }, h('label', { class: 'label', for: ids.db }, t('admin.user.onDatabase')), dbSel);
    function sync() {
      dbField.hidden = !presetSel.value;
      if (!touched) grantsTa.value = grantSql(driver, presetSel.value, userIn.value || 'user', hostIn.value, dbSel.value).join('\n');
    }
    sync();
    const err = h('div', { hidden: true });
    const save = h('button', { type: 'button', class: 'btn btn-primary', onClick: async () => {
      const user = userIn.value.trim();
      if (!user) { userIn.setAttribute('aria-invalid', 'true'); userIn.focus(); return; }
      if (!touched) grantsTa.value = grantSql(driver, presetSel.value, user, hostIn.value, dbSel.value).join('\n');
      const grants = grantsTa.value.split('\n').map((s) => s.trim().replace(/;+$/, '')).filter(Boolean);
      save.disabled = true;
      try {
        await api('server.userSave', { conn, user, host: isPg ? undefined : (hostIn.value.trim() || '%'), password: pw.input.value || undefined, grants, is_new: true });
        toast.success(t('admin.user.created', { name: user }));
        m.close(true);
        reload();
      } catch (e) { err.hidden = false; setChildren(err, callout('danger', h('div', {}, e.message, e.sql ? h('pre', { class: 'mt-1 font-mono text-xs', dir: 'ltr' }, e.sql) : null))); } finally { save.disabled = false; }
    } }, t('common.create'));
    const lbl = (id, text) => h('label', { class: 'label', for: id }, text);
    const m = openModal({
      title: isPg ? t('admin.user.createRole') : t('admin.user.create'), size: 'lg',
      body: h('div', { class: 'space-y-4' },
        h('div', { class: 'flex flex-wrap gap-3' },
          h('div', { class: 'min-w-0 flex-1 basis-48' }, lbl(ids.user, t('admin.user.user')), userIn),
          isPg ? null : h('div', { class: 'min-w-0 flex-1 basis-32' }, lbl(ids.host, t('admin.user.host')), hostIn, h('p', { class: 'help' }, t('admin.user.hostHelp')))),
        h('div', {}, lbl(ids.pw, t('login.password')), pw.el),
        h('div', { class: 'flex flex-wrap gap-3' },
          h('div', { class: 'min-w-0 flex-1 basis-48' }, lbl(ids.preset, t('admin.user.privileges')), presetSel),
          dbField),
        h('div', {}, lbl(ids.grants, t('admin.user.grantSql')), grantsTa, h('p', { class: 'help' }, isPg ? t('admin.user.grantHelpPg') : t('admin.user.grantHelp'))),
        err),
      actions: [h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close() }, t('common.cancel')), save],
      initialFocus: userIn,
    });
  }

  async function createRole() {
    const fields = [{ name: 'name', label: t('privs.role.name'), mono: true, required: true }];
    const mysqlHost = driver === 'mysql' && !(/mariadb/i.test(ver) || overview?.info?.name === 'MariaDB');
    if (mysqlHost) fields.push({ name: 'host', label: t('admin.user.host'), value: '%', mono: true, required: true });
    const v = await formDialog({
      title: t('privs.role.createTitle'), description: isPg ? t('privs.role.helpPg') : t('privs.role.help'), fields, submitLabel: t('common.create'), size: 'sm',
      onSubmit: async (vals) => { await api('server.roleCreate', { conn, name: vals.name.trim(), host: mysqlHost ? vals.host.trim() || '%' : undefined }); return vals; },
    });
    if (!v) return;
    toast.success(t('privs.role.created', { name: v.name }));
    await reload();
    const created = users.find((u) => u.user === v.name.trim() && (!mysqlHost || u.host === (v.host.trim() || '%')));
    if (created) openDetail(created);
  }

  setChildren(body,
    h('div', { class: 'flex flex-wrap items-center gap-2' },
      searchInput(t('admin.user.search'), (v) => { filter = v; draw(); }),
      h('div', { class: 'flex-1' }),
      canCreateRole ? h('button', { type: 'button', class: 'btn btn-sm', onClick: createRole }, icon('users'), isPg ? t('privs.role.createGroup') : t('privs.role.create')) : null,
      readOnly ? null : h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: createUser }, icon('plus'), isPg ? t('admin.user.createRole') : t('admin.user.create')),
      refreshButton(reload)),
    privsSupported ? h('div', { class: 'grid items-start gap-4 lg:grid-cols-[minmax(16rem,24rem)_minmax(0,1fr)]' }, box, detail) : box);
  cleanup.push(() => { current?.panel.destroy(); current?.modal?.close(); current = null; });
  draw();
}

/* ====================================================================== simple lists */

async function simpleList(body, action, conn, columns) {
  const rows = await api(action, { conn });
  let filter = '';
  const box = h('div', { class: 'card overflow-x-auto' });
  const count = h('span', { class: 'text-[12.5px] text-muted tabular-nums' });
  const keys = columns.filter((c) => rows.some((r) => r[c.key] !== undefined && r[c.key] !== null));
  const draw = () => {
    const list = rows.filter((r) => !filter || keys.some((c) => String(r[c.key] ?? '').toLowerCase().includes(filter)));
    count.textContent = plural('admin.items', list.length);
    if (!list.length) { setChildren(box, emptyState({ icon: 'search', title: t('sidebar.noMatches') })); return; }
    setChildren(box, h('table', { class: 'table-grid' },
      h('thead', {}, h('tr', {}, keys.map((c) => h('th', {}, t(c.label))))),
      h('tbody', {}, list.map((r) => h('tr', {}, keys.map((c) => {
        const v = r[c.key];
        if (typeof v === 'boolean') return h('td', {}, v ? icon('check', 'text-success') : '');
        return h('td', { class: cls(c.mono && 'font-mono text-[12.5px]', c.muted && 'text-muted'), dir: c.mono ? 'ltr' : 'auto' }, v === null || v === undefined ? '' : String(v));
      }))))));
  };
  setChildren(body, h('div', { class: 'flex flex-wrap items-center gap-2' }, searchInput(t('admin.search'), (v) => { filter = v; draw(); }), count), box);
  draw();
}

const LISTS = {
  charsets: ['server.charsets', [{ key: 'name', label: 'admin.col.name', mono: true }, { key: 'description', label: 'admin.col.description', muted: true }, { key: 'default_collation', label: 'admin.col.defaultCollation', mono: true }, { key: 'maxlen', label: 'admin.col.maxlen' }]],
  collations: ['server.collations', [{ key: 'name', label: 'admin.col.name', mono: true }, { key: 'charset', label: 'admin.col.charset', mono: true }, { key: 'default', label: 'admin.col.default' }]],
  engines: ['server.engines', [{ key: 'name', label: 'admin.col.engine', mono: true }, { key: 'support', label: 'admin.col.support', mono: true }, { key: 'comment', label: 'admin.col.comment', muted: true }]],
};

/* ====================================================================== view */

export default {
  title: (ctx) => (TABS.some((x) => x.id === ctx.tab) ? t(`nav.${ctx.tab}`) : t('nav.serverAdmin')),

  async render(el, ctx) {
    cleanup.forEach((f) => f());
    cleanup = [];
    const { conn, caps } = ctx;
    const available = TABS.filter((x) => caps[x.cap]);
    if (!available.length) {
      el.replaceChildren(emptyState({ icon: 'server', title: t('admin.unsupported') }));
      return;
    }
    if (!available.some((x) => x.id === ctx.tab)) { navigate(href.serverTab(conn, available[0].id), { replace: true }); return; }
    const overview = await getOverview(conn).catch(() => null);
    const driver = overview?.info?.driver || '';

    const tabs = createTabs({
      items: available.map((x) => ({ id: x.id, label: t(`nav.${x.id}`), icon: x.icon, href: href.serverTab(conn, x.id) })),
      active: ctx.tab,
      ariaLabel: t('nav.serverAdmin'),
      className: 'px-2 sm:px-4',
    });
    const body = h('div', { class: 'w-full space-y-4 p-4 sm:p-6' }, skeleton({ rows: 8 }));
    el.replaceChildren(
      h('div', { class: 'border-b border-border bg-surface' },
        h('div', { class: 'flex items-center gap-2 px-4 pt-3' },
          icon('server', 'text-muted'),
          h('h1', { class: 'truncate text-[15px] font-semibold' }, t('nav.serverAdmin')),
          h('a', { href: href.server(conn), class: 'btn btn-ghost btn-sm ms-auto' }, icon('arrow-left'), h('span', { class: 'max-sm:sr-only' }, t('nav.server')))),
        tabs.el),
      tabs.panel(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, body)));
    tabs.el.classList.remove('border-b');

    // Read-only connection: no kill / user changes (the server refuses them with 403 read_only).
    const c = { conn, caps: ctx.readOnly ? { ...caps, kill: false } : caps, driver, readOnly: !!ctx.readOnly };
    try {
      if (ctx.tab === 'variables') await variablesTab(body, c);
      else if (ctx.tab === 'status') await statusTab(body, c);
      else if (ctx.tab === 'processes') await processesTab(body, c);
      else if (ctx.tab === 'users') await usersTab(body, c);
      else { const [action, cols] = LISTS[ctx.tab]; await simpleList(body, action, conn, cols); }
    } catch (e) {
      setChildren(body, errorState(e, { onRetry: () => this.render(el, ctx) }));
    }
  },

  destroy() {
    cleanup.forEach((f) => f());
    cleanup = [];
  },
};
