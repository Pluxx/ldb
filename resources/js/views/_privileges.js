/**
 * Privileges editor of one account (Users tab detail panel / sheet): Overview (account options,
 * limits, lock, PostgreSQL role attributes), Global privileges, Databases, Tables & columns,
 * Roles and Raw grants. Every change is staged in a pending bar; "Review & apply" asks the server
 * for the plan (server.privilegesPlan), shows the SQL in the review dialog and applies it
 * (server.privilegesApply re-plans on the server — the client never sends SQL).
 * Lazily loaded by views/server-admin.js.
 * @module views/_privileges
 */
import { h, cls, uid, copyText, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { createTabs } from '../ui/tabs.js';
import { openModal, confirmDialog, formDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, callout } from '../ui/feedback.js';
import { t, plural } from '../i18n/index.js';
import { api } from '../api.js';
import { getDatabases } from '../data.js';
import { highlightSql } from '../sql/highlight.js';
import { reviewSqlDialog } from './_table-editor.js';

/** Privileges with a translated description (privs.desc.<slug>); others use the server's text. */
const DESCRIBED = new Set(['select', 'insert', 'update', 'delete', 'file', 'create', 'alter', 'index', 'drop', 'create_temporary_tables',
  'show_view', 'create_routine', 'alter_routine', 'execute', 'create_view', 'event', 'trigger', 'create_tablespace', 'grant_option',
  'super', 'process', 'reload', 'shutdown', 'show_databases', 'lock_tables', 'references', 'replication_client', 'replication_slave',
  'create_user', 'create_role', 'drop_role', 'delete_history', 'connect', 'temporary', 'usage', 'truncate', 'maintain', 'control',
  'view_definition', 'take_ownership']);
const GROUPS = ['data', 'structure', 'admin'];
const MAX_ROWS = 200;

const slug = (p) => String(p).toLowerCase().replace(/[^a-z0-9]+/g, '_');
const K = (...parts) => JSON.stringify(parts);
// MySQL database-level grants are patterns: a wildcard pattern ('p') is a different target than the literal name
const dbKey = (db, pattern) => (pattern ? K('database', db, null, null, 'p') : K('database', db));
const roleKey = (r) => K(r.name, r.host ?? null);
const parseRoleKey = (k) => { const [name, host] = JSON.parse(k); return host === null ? { name } : { name, host }; };
export const acctLabel = (u) => (u.host === null || u.host === undefined || u.host === '' ? (u.user === '' ? t('admin.user.anonymous') : u.user) : `${u.user}@${u.host}`);

/**
 * Mount the editor.
 * @param {HTMLElement} el  container (filled completely)
 * @param {object} o
 * @param {string} o.conn
 * @param {boolean} o.readOnly
 * @param {{user: string, host?: string|null, is_role?: boolean}} o.account
 * @param {Array<{user: string, host?: string|null}>} [o.accounts]  for "copy privileges from"
 * @param {(acct?: {user: string, host?: string|null}) => void} [o.onChanged]  after apply / rename (new identity)
 * @param {() => void} [o.onDrop]
 * @param {() => void} [o.onClose]  shows a close button
 * @returns {{dirty: () => boolean, destroy: () => void}}
 */
export function mountPrivileges(el, o) {
  const { conn } = o;
  const acct = { user: o.account.user, host: o.account.host ?? null };
  const ro = !!o.readOnly;
  let S = null; // PrivilegeState
  let F = {};   // features
  let tab = 'overview';
  let objDb = null;
  const objCache = new Map();
  const loadedDbs = new Set();
  let listOpen = false;
  let destroyed = false;

  const orig = { global: new Set(), objs: new Map(), roles: new Set(), defaults: new Set(), dbRoles: new Map(), account: {}, attrs: {} };
  let want = null;
  const createDbUser = new Set();

  const head = h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3' });
  const tabsBox = h('div', { class: 'border-b border-border px-2' });
  const content = h('div', { class: 'min-h-0 flex-1 space-y-4 overflow-auto p-4' }, skeleton({ rows: 6 }));
  const bar = h('div', { class: 'sticky bottom-0 z-10 border-t border-accent/30 bg-surface/95 px-3 py-2 backdrop-blur pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:px-4', role: 'region', 'aria-label': t('privs.pending.label'), hidden: true });
  el.replaceChildren(h('div', { class: 'flex h-full min-h-0 flex-col' }, head, tabsBox, content, bar));
  let tabs = null;

  /* ------------------------------------------------------------------ state */

  function mapPrivs(row, field = 'privileges', denied = 'denied') {
    const m = new Map();
    (row[field] || []).forEach((p) => m.set(String(p).toUpperCase(), 'G'));
    (row[denied] || []).forEach((p) => m.set(String(p).toUpperCase(), 'D'));
    return m;
  }

  /** Merge object-level rows of a state into orig (and want, for keys not staged yet). */
  function mergeObjects(st, withDatabases) {
    const add = (key, m) => {
      orig.objs.set(key, m);
      if (want && !want.objs.has(key)) want.objs.set(key, new Map(m));
    };
    if (withDatabases) (st.databases || []).forEach((r) => add(dbKey(r.db, r.pattern), mapPrivs(r)));
    (st.schemas || []).forEach((r) => add(K('schema', r.db, r.schema), mapPrivs(r)));
    (st.tables || []).forEach((r) => add(K('table', r.db, r.schema ?? null, r.table), mapPrivs(r)));
    (st.columns || []).forEach((r) => add(K('column', r.db, r.schema ?? null, r.table, r.column), mapPrivs(r)));
    Object.entries(st.db_roles || {}).forEach(([db, r]) => {
      const set = new Set((r.granted || []).map(roleKey));
      orig.dbRoles.set(db, set);
      if (want && !want.dbRoles.has(db)) want.dbRoles.set(db, new Set(set));
    });
    if (st.current_database) loadedDbs.add(st.current_database);
    if (S && st !== S) {
      S.db_users = { ...(S.db_users || {}), ...(st.db_users || {}) };
      S.db_roles = { ...(S.db_roles || {}), ...(st.db_roles || {}) };
      ['schemas', 'tables', 'columns'].forEach((k) => { S[k] = [...(S[k] || []).filter((r) => r.db !== st.current_database), ...(st[k] || [])]; });
    }
  }

  function initFrom(st) {
    S = st;
    F = st.features || {};
    orig.global = new Set((st.global || []).map((p) => p.toUpperCase()));
    orig.objs = new Map();
    orig.dbRoles = new Map();
    loadedDbs.clear();
    mergeObjects(st, true);
    orig.roles = new Set((st.roles?.granted || []).map(roleKey));
    orig.defaults = new Set((st.roles?.default || []).map(roleKey));
    const a = st.account || {};
    orig.account = {
      locked: !!a.locked, require_ssl: a.require_ssl || 'NONE', expire: 'keep', expire_days: '',
      limits: { ...(a.limits || {}) },
    };
    orig.attrs = { ...(a.attributes || {}) };
    if (orig.attrs.valid_until === null || orig.attrs.valid_until === undefined) orig.attrs.valid_until = '';
    reset();
    objDb = objDb || st.current_database || null;
  }

  function reset() {
    want = {
      global: new Set(orig.global),
      objs: new Map([...orig.objs].map(([k, v]) => [k, new Map(v)])),
      roles: new Set(orig.roles),
      defaults: new Set(orig.defaults),
      dbRoles: new Map([...orig.dbRoles].map(([k, v]) => [k, new Set(v)])),
      account: { ...orig.account, limits: { ...orig.account.limits } },
      attrs: { ...orig.attrs },
    };
    createDbUser.clear();
  }

  async function load() {
    try {
      const st = await api('server.privileges', { conn, user: acct.user, host: acct.host ?? undefined, db: objDb || undefined });
      if (destroyed) return;
      initFrom(st);
      drawHead();
      drawTabs();
      draw();
    } catch (e) {
      if (!destroyed) setChildren(content, errorState(e, { onRetry: load }));
    }
  }

  /** Object-level state of another database (PostgreSQL / SQL Server). */
  async function ensureDb(db) {
    if (!db || !F.object_db || loadedDbs.has(db)) return;
    const st = await api('server.privileges', { conn, user: acct.user, host: acct.host ?? undefined, db });
    // PostgreSQL database ACLs are cluster-wide (already loaded); SQL Server's are per database
    mergeObjects(st, S.driver !== 'pgsql');
  }

  async function objectsOf(db) {
    if (!objCache.has(db)) objCache.set(db, api('server.privilegeObjects', { conn, db }).catch((e) => { objCache.delete(db); throw e; }));
    return objCache.get(db);
  }

  /* ------------------------------------------------------------------ diff → PrivilegeChanges */

  function diffSet(a, b) {
    return { add: [...b].filter((x) => !a.has(x)), remove: [...a].filter((x) => !b.has(x)) };
  }

  function objDiff(o0, o1) {
    const out = { add: [], remove: [], deny: [] };
    const keys = new Set([...o0.keys(), ...o1.keys()]);
    keys.forEach((p) => {
      const a = o0.get(p); const b = o1.get(p);
      if (a === b) return;
      if (!b) out.remove.push(p);
      else if (b === 'D') out.deny.push(p);
      else out.add.push(p);
    });
    return out;
  }

  /** @returns {{changes: object, items: Array<[string, string]>}} */
  function compute() {
    const c = {};
    const items = [];
    const push = (ic, text) => items.push([ic, text]);
    if (!want) return { changes: c, items };
    if (F.global) {
      const d = diffSet(orig.global, want.global);
      if (d.add.length || d.remove.length) {
        c.global = d;
        d.add.forEach((p) => push('plus', t('privs.pending.grant', { priv: p, object: '*.*' })));
        d.remove.forEach((p) => push('minus', t('privs.pending.revoke', { priv: p, object: '*.*' })));
      }
    }
    const keys = new Set([...orig.objs.keys(), ...want.objs.keys()]);
    keys.forEach((k) => {
      const d = objDiff(orig.objs.get(k) || new Map(), want.objs.get(k) || new Map());
      if (!d.add.length && !d.remove.length && !d.deny.length) return;
      const [level, db, a, b, col] = JSON.parse(k);
      const entry = { db };
      if (level === 'database' && col === 'p') entry.pattern = true;
      let list = 'databases';
      if (level === 'schema') { entry.schema = a; list = 'schemas'; }
      if (level === 'table') { if (a !== null) entry.schema = a; entry.table = b; list = 'tables'; const kind = tableKind(db, a, b); if (kind === 'sequence') entry.kind = 'sequence'; }
      if (level === 'column') { if (a !== null) entry.schema = a; entry.table = b; entry.column = col; list = 'columns'; }
      if (d.add.length) entry.add = d.add;
      if (d.remove.length) entry.remove = d.remove;
      if (d.deny.length) entry.deny = d.deny;
      (c[list] = c[list] || []).push(entry);
      const obj = objLabel(level, db, a, b, col);
      d.add.forEach((p) => push('plus', t('privs.pending.grant', { priv: p, object: obj })));
      d.remove.forEach((p) => push('minus', t('privs.pending.revoke', { priv: p, object: obj })));
      d.deny.forEach((p) => push('x', t('privs.pending.deny', { priv: p, object: obj })));
    });
    const rd = diffSet(orig.roles, want.roles);
    if (rd.add.length || rd.remove.length) {
      const spec = { add: rd.add.map(parseRoleKey), remove: rd.remove.map(parseRoleKey) };
      if (F.server_roles) c.server_roles = spec; else c.roles = spec;
      rd.add.forEach((r) => push('plus', t('privs.pending.roleAdd', { role: roleLabel(parseRoleKey(r)) })));
      rd.remove.forEach((r) => push('minus', t('privs.pending.roleRemove', { role: roleLabel(parseRoleKey(r)) })));
    }
    if (F.default_roles) {
      const dd = diffSet(orig.defaults, want.defaults);
      if (dd.add.length || dd.remove.length) {
        c.roles = c.roles || {};
        c.roles.default = [...want.defaults].map(parseRoleKey);
        push('check', t('privs.pending.defaultRoles', { roles: c.roles.default.map(roleLabel).join(', ') || t('privs.roles.none') }));
      }
    }
    want.dbRoles.forEach((set, db) => {
      const d = diffSet(orig.dbRoles.get(db) || new Set(), set);
      if (!d.add.length && !d.remove.length) return;
      (c.db_roles = c.db_roles || []).push({ db, add: d.add.map(parseRoleKey), remove: d.remove.map(parseRoleKey) });
      d.add.forEach((r) => push('plus', t('privs.pending.dbRoleAdd', { role: parseRoleKey(r).name, db })));
      d.remove.forEach((r) => push('minus', t('privs.pending.dbRoleRemove', { role: parseRoleKey(r).name, db })));
    });
    if (createDbUser.size) {
      c.create_db_user = [...createDbUser].map((db) => ({ db }));
      createDbUser.forEach((db) => push('plus', t('privs.pending.dbUser', { db })));
    }
    // account options
    const A0 = orig.account; const A1 = want.account;
    const acc = {};
    if (F.lock && A0.locked !== A1.locked && S.driver !== 'pgsql') { acc.lock = A1.locked; push('lock', A1.locked ? t('privs.pending.lock') : t('privs.pending.unlock')); }
    if (F.require_ssl && A0.require_ssl !== A1.require_ssl) { acc.require_ssl = A1.require_ssl; push('lock', t('privs.pending.requireSsl', { value: A1.require_ssl })); }
    if (F.password_expire && A1.expire !== 'keep') {
      acc.password_expire = A1.expire === 'interval' ? Number(A1.expire_days) : (A1.expire === 'now' ? true : A1.expire);
      push('clock', t(`privs.pending.expire.${A1.expire}`, { days: A1.expire_days }));
    }
    if (Object.keys(acc).length) c.account = acc;
    const lim = {};
    (F.limits || []).forEach((k) => {
      const a = A0.limits[k] ?? 0; const b = A1.limits[k];
      if (b === '' || b === undefined || b === null || Number(b) === Number(a)) return;
      lim[k] = k === 'max_statement_time' ? Number(b) : Math.trunc(Number(b));
      push('gauge', t('privs.pending.limit', { name: t(`privs.limit.${k}`), value: String(b) }));
    });
    if (Object.keys(lim).length) c.limits = lim;
    if ((F.attributes || []).length) {
      const at = {};
      F.attributes.forEach((k) => {
        const a = orig.attrs[k]; const b = want.attrs[k];
        if (k === 'connection_limit') { if (b !== '' && b !== undefined && Number(b) !== Number(a)) at[k] = Math.trunc(Number(b)); return; }
        if (k === 'valid_until') { if ((b || '') !== (a || '')) at[k] = b || null; return; }
        if (!!a !== !!b) at[k] = !!b;
      });
      Object.entries(at).forEach(([k, v]) => push('settings', t('privs.pending.attr', { name: attrLabel(k), value: v === null ? '∞' : String(v) })));
      if (Object.keys(at).length) c.attributes = at;
    }
    return { changes: c, items };
  }

  const dirty = () => compute().items.length > 0;

  /* ------------------------------------------------------------------ labels */

  function roleLabel(r) { return r.host === undefined || r.host === null ? r.name : `${r.name}@${r.host}`; }
  function attrLabel(k) { return t(`privs.attr.${k}`); }
  function objLabel(level, db, schema, table, col) {
    if (level === 'database') return S.driver === 'mysql' ? `${db}.*` : db;
    if (level === 'schema') return `${db}: ${schema}`;
    const tbl = schema ? `${schema}.${table}` : `${db}.${table}`;
    return level === 'column' ? `${tbl}.${col}` : tbl;
  }
  function tableKind(db, schema, table) {
    const row = (S.tables || []).find((r) => r.db === db && (r.schema ?? null) === schema && r.table === table);
    return row?.kind || null;
  }
  function describe(p) {
    const s = slug(p);
    if (DESCRIBED.has(s)) return t(`privs.desc.${s}`);
    return S?.descriptions?.[p] || '';
  }

  /* ------------------------------------------------------------------ head, tabs, bar */

  function drawHead() {
    const a = S.account || {};
    const badges = [];
    badges.push(h('span', { class: cls('badge', a.is_role && 'badge-accent') }, a.is_role ? t('privs.badge.role') : t('privs.badge.user')));
    if (S.self) badges.push(h('span', { class: 'badge badge-warning' }, t('privs.badge.you')));
    if (a.locked && S.driver !== 'pgsql') badges.push(h('span', { class: 'badge badge-danger' }, icon('lock'), t('admin.user.locked')));
    if (a.password_expired) badges.push(h('span', { class: 'badge badge-warning' }, t('admin.user.expired')));
    if (a.attributes?.superuser) badges.push(h('span', { class: 'badge badge-warning' }, t('admin.user.superuser')));
    if (a.plugin) badges.push(h('span', { class: 'badge font-mono' }, a.plugin));
    setChildren(head,
      h('div', { class: 'min-w-0 flex-1' },
        h('h2', { class: 'truncate font-mono text-[14px] font-semibold', dir: 'ltr' }, acctLabel(acct)),
        h('div', { class: 'mt-1 flex flex-wrap gap-1' }, badges)),
      ro ? h('span', { class: 'badge' }, icon('lock'), t('privs.readOnly')) : null,
      o.onClose ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('common.close'), title: t('common.close'), onClick: () => o.onClose() }, icon('x')) : null);
  }

  function tabItems() {
    const list = [{ id: 'overview', label: t('privs.tab.overview'), icon: 'info' }];
    if (F.global) list.push({ id: 'global', label: t('privs.tab.global'), icon: 'server' });
    if (F.databases) list.push({ id: 'databases', label: t('privs.tab.databases'), icon: 'database' });
    if (F.tables) list.push({ id: 'tables', label: t('privs.tab.tables'), icon: 'table' });
    if (F.roles || F.server_roles) list.push({ id: 'roles', label: t('privs.tab.roles'), icon: 'users' });
    list.push({ id: 'raw', label: t('privs.tab.raw'), icon: 'code' });
    return list;
  }

  function drawTabs() {
    const items = tabItems();
    if (!items.some((x) => x.id === tab)) tab = 'overview';
    tabs = createTabs({ items, active: tab, ariaLabel: t('privs.tabsLabel'), onSelect: (id) => { tab = id; draw(); } });
    tabs.el.classList.remove('border-b');
    tabsBox.replaceChildren(tabs.el);
    tabs.panel(content);
  }

  function drawBar() {
    const { items } = compute();
    bar.hidden = ro || !items.length;
    if (bar.hidden) { bar.replaceChildren(); return; }
    const listId = uid('pp');
    const toggle = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': String(listOpen), 'aria-controls': listId, onClick: () => { listOpen = !listOpen; drawBar(); } },
      icon(listOpen ? 'chevron-down' : 'chevron-up'), h('span', { class: 'font-medium text-fg' }, plural('privs.pending.count', items.length)));
    setChildren(bar,
      listOpen ? h('ul', { id: listId, class: 'mb-2 max-h-40 space-y-0.5 overflow-auto text-[12.5px]' }, items.map(([ic, text]) => h('li', { class: 'flex items-center gap-2', dir: 'auto' }, icon(ic, 'size-3.5 text-muted'), text))) : null,
      h('div', { class: 'flex flex-wrap items-center gap-2' },
        toggle,
        h('div', { class: 'flex-1' }),
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => discard() }, icon('x'), t('privs.pending.discard')),
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: () => review() }, icon('play'), t('privs.pending.review'))));
  }

  /** Re-render after a staged change (keeps scroll position). */
  function changed() { drawBar(); }

  async function discard() {
    const n = compute().items.length;
    const ok = await confirmDialog({ title: t('privs.pending.discardTitle'), message: plural('privs.pending.discardMessage', n), confirmLabel: t('privs.pending.discard'), danger: true });
    if (!ok) return;
    reset();
    draw();
  }

  /* ------------------------------------------------------------------ review / apply */

  const shown = (s) => (s.db ? `-- ${t('privs.review.inDb', { db: s.db })}\n${s.sql}` : s.sql);

  async function planAndApply(changes, title, after) {
    let plan;
    try {
      plan = await api('server.privilegesPlan', { conn, user: acct.user, host: acct.host ?? undefined, changes });
    } catch (e) { toastError(e); return null; }
    if (!plan.statements?.length) { toast.info(t('privs.review.nothing')); if (after) after(null); return null; }
    const notes = (plan.warnings || []).map((w) => callout('warning', t(`privs.warn.${w}`)));
    let confirmSelf = null;
    if (plan.self_lockout?.length) {
      const id = uid('cs');
      confirmSelf = h('input', { id, type: 'checkbox', class: 'checkbox mt-0.5' });
      notes.push(callout('danger', h('div', {},
        h('div', { class: 'font-medium' }, t('privs.self.title')),
        h('ul', { class: 'mt-1 list-disc ps-5' }, plan.self_lockout.map((r) => h('li', {}, t(`privs.self.${r}`)))),
        h('label', { class: 'mt-2 flex items-start gap-2', for: id }, confirmSelf, t('privs.self.confirm')))));
    }
    const res = await reviewSqlDialog({
      title, sql: plan.statements.map(shown), notes,
      danger: !!plan.self_lockout?.length || plan.statements.some((s) => /^(REVOKE|DENY|DROP)\b/.test(s.sql)),
      confirmLabel: t('privs.review.apply'),
      execute: () => {
        if (confirmSelf && !confirmSelf.checked) { confirmSelf.focus(); return Promise.reject(new Error(t('privs.self.confirmRequired'))); }
        return api('server.privilegesApply', { conn, user: acct.user, host: acct.host ?? undefined, changes, confirm_self: !!confirmSelf?.checked });
      },
    });
    if (!res) return null;
    if (res.failed) showResults(res);
    else toast.success(plural('privs.review.applied', res.executed));
    if (after) after(res);
    return res;
  }

  function showResults(res) {
    const m = openModal({
      title: t('privs.results.title'), description: t('privs.results.summary', { ok: res.executed, failed: res.failed }), size: 'xl',
      body: h('ul', { class: 'space-y-2' }, res.results.map((r) => h('li', { class: cls('rounded-lg border p-2', r.ok ? 'border-border' : 'border-danger/40 bg-danger/5') },
        h('div', { class: 'flex items-start gap-2' }, icon(r.ok ? 'circle-check' : 'circle-x', r.ok ? 'mt-0.5 text-success' : 'mt-0.5 text-danger'),
          h('pre', { class: 'min-w-0 flex-1 overflow-auto font-mono text-[12px] whitespace-pre-wrap break-words', dir: 'ltr' }, highlightSql(shown(r)))),
        r.error ? h('div', { class: 'mt-1 ps-6 text-[12.5px] text-danger', dir: 'auto' }, r.error) : null))),
      actions: [h('button', { type: 'button', class: 'btn btn-primary', onClick: () => m.close() }, t('common.close'))],
    });
  }

  async function review() {
    const { changes } = compute();
    await planAndApply(changes, t('privs.review.title', { name: acctLabel(acct) }), (res) => {
      if (!res) return;
      objCache.clear();
      load();
      o.onChanged?.();
    });
  }

  /* ------------------------------------------------------------------ generic widgets */

  function cbox(checked, label, onChange, extra = {}) {
    return h('input', { type: 'checkbox', class: 'checkbox', checked, disabled: ro || extra.disabled, 'aria-label': label, title: extra.title || label, dataset: { fk: extra.fk }, onChange: (e) => onChange(e.target.checked) });
  }

  /** Grouped checkbox list (fieldset/legend per group, "select all" per group). */
  function groupedChecks(privs, getSet, onToggle) {
    const box = h('div', { class: 'grid gap-4 xl:grid-cols-3' });
    GROUPS.forEach((g) => {
      const list = privs.filter((p) => p.group === g);
      if (!list.length) return;
      const set = getSet();
      const n = list.filter((p) => set.has(p.name)).length;
      const allId = uid('all');
      const all = h('input', { id: allId, type: 'checkbox', class: 'checkbox', checked: n === list.length, indeterminate: n > 0 && n < list.length, disabled: ro, dataset: { fk: `ga|${g}` },
        onChange: (e) => { list.forEach((p) => onToggle(p.name, e.target.checked)); draw(); } });
      box.appendChild(h('fieldset', { class: 'card min-w-0 p-3' },
        h('legend', { class: 'px-1 text-[13px] font-semibold' }, t(`privs.group.${g}`)),
        h('label', { class: 'mb-2 flex items-center gap-2 border-b border-border pb-2 text-[12.5px] text-muted', for: allId }, all, t('privs.selectAllGroup', { group: t(`privs.group.${g}`) })),
        h('div', { class: 'space-y-1' }, list.map((p) => {
          const id = uid('pv');
          const d = describe(p.name);
          const descId = d ? `${id}-d` : null;
          return h('div', { class: 'flex min-h-8 items-start gap-2' },
            h('input', { id, type: 'checkbox', class: 'checkbox mt-0.5', checked: set.has(p.name), disabled: ro, 'aria-describedby': descId, dataset: { fk: `g|${p.name}` },
              onChange: (e) => { onToggle(p.name, e.target.checked); draw(); } }),
            h('label', { for: id, class: 'min-w-0 cursor-pointer' },
              h('span', { class: 'font-mono text-[12.5px] font-medium', dir: 'ltr' }, p.name),
              d ? h('span', { id: descId, class: 'block text-[12px] leading-snug text-muted' }, d) : null));
        }))));
    });
    return box;
  }

  /**
   * Matrix: rows = objects, columns = privileges. Cells are checkboxes, or GRANT/DENY selects
   * when the server supports DENY (SQL Server).
   * rows: [{key, label, sub?, expand?: {open, toggle, label}, children?: Node[] }]
   */
  function matrix(rows, privs, caption) {
    const tri = !!F.deny;
    const names = privs.map((p) => p.name);
    const cell = (row, p) => {
      const m = want.objs.get(row.key) || new Map();
      const label = t('privs.cell', { priv: p, object: row.label });
      if (tri) {
        return h('select', { class: 'select h-7 min-h-0 w-auto px-1 text-[11.5px]', 'aria-label': label, title: label, disabled: ro, dataset: { fk: `${row.key}|${p}` },
          onChange: (e) => { setPriv(row.key, p, e.target.value || null); draw(); } },
          [['', '—'], ['G', 'GRANT'], ['D', 'DENY']].map(([v, l]) => h('option', { value: v, selected: (m.get(p) || '') === v }, l)));
      }
      return cbox(m.has(p), label, (on) => { setPriv(row.key, p, on ? 'G' : null); draw(); }, { fk: `${row.key}|${p}` });
    };
    const rowAll = (row) => {
      const m = want.objs.get(row.key) || new Map();
      const n = names.filter((p) => m.has(p)).length;
      return h('input', { type: 'checkbox', class: 'checkbox', checked: n === names.length, indeterminate: n > 0 && n < names.length, disabled: ro, dataset: { fk: `${row.key}|*` },
        'aria-label': t('privs.rowAll', { object: row.label }), title: t('privs.rowAll', { object: row.label }),
        onChange: (e) => { names.forEach((p) => setPriv(row.key, p, e.target.checked ? 'G' : null)); draw(); } });
    };
    const body = [];
    rows.forEach((row) => {
      const changedRow = (() => { const d = objDiff(orig.objs.get(row.key) || new Map(), want.objs.get(row.key) || new Map()); return d.add.length + d.remove.length + d.deny.length > 0; })();
      body.push(h('tr', { class: cls(changedRow && 'bg-accent/5') },
        h('th', { scope: 'row', class: 'sticky start-0 z-[1] bg-surface px-2 py-1 text-start font-normal' },
          h('div', { class: 'flex min-w-0 max-w-[16rem] items-center gap-1.5' },
            row.expand ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon size-7 min-h-0 min-w-0 shrink-0', 'aria-expanded': String(row.expand.open), 'aria-label': row.expand.label, dataset: { fk: `${row.key}|exp` }, title: row.expand.label, onClick: () => row.expand.toggle() },
              icon(row.expand.open ? 'chevron-down' : 'chevron-right')) : null,
            rowAll(row),
            h('span', { class: cls('truncate font-mono text-[12.5px]', row.indent && 'ps-4 text-muted'), dir: 'ltr', title: row.label }, row.short || row.label),
            row.badge ? h('span', { class: 'badge shrink-0', title: row.badge.hint }, row.badge.text) : null,
            row.sub ? h('span', { class: 'badge shrink-0 max-sm:hidden', title: row.sub }, row.sub) : null)),
        privs.map((p) => h('td', { class: 'px-1 py-1 text-center' }, cell(row, p.name)))));
      if (row.children) body.push(...row.children);
    });
    return h('div', { class: 'card overflow-auto', dataset: { sk: caption || 'm' } },
      h('table', { class: 'w-max min-w-full border-separate border-spacing-0 text-[12.5px]' },
        caption ? h('caption', { class: 'sr-only' }, caption) : null,
        h('thead', {}, h('tr', {},
          h('th', { scope: 'col', class: 'sticky start-0 top-0 z-[2] border-b border-border bg-surface-2 px-2 py-1.5 text-start text-[12px] font-medium text-muted' }, t('privs.object')),
          privs.map((p) => h('th', { scope: 'col', class: 'sticky top-0 z-[1] border-b border-border bg-surface-2 px-1.5 py-1.5 text-center font-mono text-[10.5px] font-medium whitespace-nowrap text-muted', title: describe(p.name) || p.name, dir: 'ltr' }, p.name)))),
        h('tbody', { class: '[&_td]:border-b [&_td]:border-border [&_th]:border-b [&_th]:border-border' }, body)));
  }

  function setPriv(key, p, v) {
    if (!want.objs.has(key)) want.objs.set(key, new Map());
    const m = want.objs.get(key);
    if (v) m.set(p, v); else m.delete(p);
  }

  async function dbPicker(onPick, { includeAll = false } = {}) {
    const id = uid('db');
    const dbs = await getDatabases(conn).catch(() => []);
    const names = dbs.map((d) => d.name);
    if (objDb === null && names.length) {
      const sys = /^(information_schema|mysql|performance_schema|sys|master|model|msdb|tempdb)$/i;
      objDb = names.includes(S.current_database) ? S.current_database : (names.find((n) => !sys.test(n)) || names[0]);
    }
    const sel = h('select', { id, class: 'select max-w-xs', onChange: () => onPick(sel.value) },
      includeAll ? h('option', { value: '' }, '—') : null,
      names.map((n) => h('option', { value: n, selected: n === objDb }, n)));
    return h('div', { class: 'flex flex-wrap items-center gap-2' }, h('label', { class: 'label mb-0', for: id }, t('privs.pickDatabase')), sel);
  }

  /* ------------------------------------------------------------------ tabs */

  let drawSeq = 0;
  async function draw() {
    if (!S || destroyed) return;
    const seq = ++drawSeq;
    const scroll = content.scrollTop;
    const fk = document.activeElement?.dataset?.fk;
    const sx = {};
    content.querySelectorAll('[data-sk]').forEach((n) => { sx[n.dataset.sk] = n.scrollLeft; });
    let node;
    try {
      if (tab === 'overview') node = overviewTab();
      else if (tab === 'global') node = globalTab();
      else if (tab === 'databases') node = await databasesTab();
      else if (tab === 'tables') node = await tablesTab();
      else if (tab === 'roles') node = await rolesTab();
      else node = rawTab();
    } catch (e) { node = errorState(e, { onRetry: () => draw() }); }
    if (seq !== drawSeq || destroyed) return;
    setChildren(content, node);
    content.scrollTop = scroll;
    content.querySelectorAll('[data-sk]').forEach((n) => { if (sx[n.dataset.sk]) n.scrollLeft = sx[n.dataset.sk]; });
    if (fk) content.querySelector(`[data-fk="${CSS.escape(fk)}"]`)?.focus();
    drawBar();
  }

  function section(title, ...children) {
    return h('section', { class: 'space-y-3' }, h('h3', { class: 'text-[13px] font-semibold' }, title), ...children);
  }

  function infoList(pairs) {
    return h('dl', { class: 'grid gap-x-4 gap-y-1.5 text-[13px] sm:grid-cols-[max-content_1fr]' },
      pairs.filter(([, v]) => v !== null && v !== undefined && v !== '').flatMap(([k, v]) => [h('dt', { class: 'text-muted' }, k), h('dd', { class: 'min-w-0 break-words font-mono text-[12.5px]', dir: 'auto' }, String(v))]));
  }

  function overviewTab() {
    const a = S.account || {};
    const A = want.account;
    const parts = [];
    parts.push(section(t('privs.overview.account'), infoList([
      [t('admin.user.user'), acct.user === '' ? t('admin.user.anonymous') : acct.user],
      [t('admin.user.host'), acct.host],
      [t('privs.overview.type'), a.type || (a.is_role ? t('privs.badge.role') : t('privs.badge.user'))],
      [t('privs.overview.plugin'), a.plugin],
      [t('privs.overview.passwordChanged'), a.password_last_changed],
      [t('privs.overview.passwordLifetime'), a.password_lifetime === null || a.password_lifetime === undefined ? null : (a.password_lifetime === 0 ? t('privs.expire.never') : plural('privs.days', a.password_lifetime))],
      [t('privs.overview.defaultDb'), a.default_database],
      [t('privs.overview.created'), a.created],
      [t('privs.overview.dbUser'), S.driver === 'sqlsrv' ? (S.db_users?.[S.current_database] ?? t('privs.db.noUser')) : null],
    ])));

    const isMariaRole = S.flavor === 'mariadb' && a.is_role;
    if (S.driver === 'mysql' && !isMariaRole) {
      const lockId = uid('lk'); const sslId = uid('ssl'); const expId = uid('ex'); const daysId = uid('dy');
      const days = h('input', { id: daysId, type: 'number', min: '1', max: '65535', class: 'input w-24 shrink-0', value: A.expire_days, disabled: ro || A.expire !== 'interval', 'aria-label': t('privs.expire.days'),
        onInput: (e) => { A.expire_days = e.target.value; changed(); } });
      // F.lock / F.password_expire are false on servers without these ALTER USER options (MariaDB < 10.4).
      parts.push(section(t('privs.overview.security'),
        F.lock ? h('label', { class: 'flex items-center gap-2 text-[13px]', for: lockId },
          h('input', { id: lockId, type: 'checkbox', class: 'checkbox', checked: A.locked, disabled: ro, onChange: (e) => { A.locked = e.target.checked; changed(); } }),
          t('privs.overview.locked')) : null,
        h('div', { class: 'flex flex-wrap gap-3' },
          h('div', { class: 'min-w-0 flex-1 basis-48' }, h('label', { class: 'label', for: sslId }, t('privs.overview.requireSsl')),
            h('select', { id: sslId, class: 'select', disabled: ro, onChange: (e) => { A.require_ssl = e.target.value; changed(); } },
              ['NONE', 'SSL', 'X509'].concat(['NONE', 'SSL', 'X509'].includes(A.require_ssl) ? [] : [A.require_ssl]).map((v) => h('option', { value: v, selected: v === A.require_ssl }, t(`privs.ssl.${slug(v)}`))))),
          F.password_expire ? h('div', { class: 'min-w-0 flex-1 basis-72' }, h('label', { class: 'label', for: expId }, t('privs.overview.passwordExpire')),
            h('div', { class: 'flex gap-2' },
              h('select', { id: expId, class: 'select min-w-0 flex-1', disabled: ro, onChange: (e) => { A.expire = e.target.value; days.disabled = ro || A.expire !== 'interval'; changed(); } },
                ['keep', 'now', 'never', 'default', 'interval'].map((v) => h('option', { value: v, selected: v === A.expire }, t(`privs.expire.${v}`)))),
              days)) : null)));
      const lim = (F.limits || []).map((k) => {
        const id = uid('lim');
        return h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: id }, t(`privs.limit.${k}`)),
          h('input', { id, type: 'number', min: '0', step: k === 'max_statement_time' ? 'any' : '1', class: 'input', value: String(A.limits[k] ?? 0), disabled: ro, 'aria-describedby': `${id}-h`,
            onInput: (e) => { A.limits[k] = e.target.value; changed(); } }),
          h('p', { id: `${id}-h`, class: 'help' }, t('privs.limit.zero')));
      });
      if (lim.length) parts.push(section(t('privs.overview.limits'), h('div', { class: 'grid gap-3 sm:grid-cols-2' }, lim)));
    }
    if (S.driver === 'pgsql') {
      const W = want.attrs;
      const bools = (F.attributes || []).filter((k) => !['connection_limit', 'valid_until'].includes(k));
      const clId = uid('cl'); const vuId = uid('vu');
      parts.push(h('fieldset', { class: 'card space-y-2 p-3' }, h('legend', { class: 'px-1 text-[13px] font-semibold' }, t('privs.overview.attributes')),
        h('div', { class: 'grid gap-1 sm:grid-cols-2' }, bools.map((k) => {
          const id = uid('at');
          return h('div', { class: 'flex items-start gap-2' },
            h('input', { id, type: 'checkbox', class: 'checkbox mt-0.5', checked: !!W[k], disabled: ro, 'aria-describedby': `${id}-d`, onChange: (e) => { W[k] = e.target.checked; changed(); } }),
            h('label', { for: id }, h('span', { class: 'font-mono text-[12.5px] font-medium', dir: 'ltr' }, k.toUpperCase()),
              h('span', { id: `${id}-d`, class: 'block text-[12px] text-muted' }, t(`privs.attrDesc.${k}`))));
        })),
        h('div', { class: 'grid gap-3 pt-2 sm:grid-cols-2' },
          h('div', {}, h('label', { class: 'label', for: clId }, attrLabel('connection_limit')),
            h('input', { id: clId, type: 'number', min: '-1', class: 'input', value: String(W.connection_limit ?? -1), disabled: ro, 'aria-describedby': `${clId}-h`, onInput: (e) => { W.connection_limit = e.target.value; changed(); } }),
            h('p', { id: `${clId}-h`, class: 'help' }, t('privs.attr.connectionLimitHelp'))),
          h('div', {}, h('label', { class: 'label', for: vuId }, attrLabel('valid_until')),
            h('input', { id: vuId, class: 'input font-mono', dir: 'ltr', placeholder: 'YYYY-MM-DD HH:MM', value: W.valid_until || '', disabled: ro, 'aria-describedby': `${vuId}-h`, onInput: (e) => { W.valid_until = e.target.value.trim(); changed(); } }),
            h('p', { id: `${vuId}-h`, class: 'help' }, t('privs.attr.validUntilHelp'))))));
    }
    if (S.driver === 'sqlsrv') {
      const id = uid('en');
      parts.push(section(t('privs.overview.security'), h('label', { class: 'flex items-center gap-2 text-[13px]', for: id },
        h('input', { id, type: 'checkbox', class: 'checkbox', checked: want.account.locked, disabled: ro, onChange: (e) => { want.account.locked = e.target.checked; changed(); } }),
        t('privs.overview.disabled'))));
      if (S.server_permissions?.length) {
        parts.push(section(t('privs.overview.serverPermissions'), h('ul', { class: 'flex flex-wrap gap-1' },
          S.server_permissions.map((p) => h('li', { class: cls('badge font-mono', p.state === 'DENY' && 'badge-danger') }, `${p.state} ${p.permission}`)))));
      }
    }
    if (S.default_privileges?.length) {
      parts.push(section(t('privs.overview.defaultPrivileges'), h('ul', { class: 'space-y-1 text-[12.5px]' },
        S.default_privileges.map((d) => h('li', { class: 'font-mono', dir: 'ltr' }, `${d.owner}${d.schema ? ` / ${d.schema}` : ''}: ${d.object_type} — ${d.privileges.join(', ')}`)))));
    }
    if (!ro) {
      parts.push(section(t('privs.overview.actions'), h('div', { class: 'flex flex-wrap gap-2' },
        F.rename ? h('button', { type: 'button', class: 'btn btn-sm', onClick: () => rename() }, icon('pencil'), t('privs.rename.button')) : null,
        F.clone ? h('button', { type: 'button', class: 'btn btn-sm', onClick: () => clone() }, icon('copy-plus'), t('privs.clone.button')) : null,
        o.onDrop ? h('button', { type: 'button', class: 'btn btn-sm text-danger', onClick: () => o.onDrop() }, icon('trash'), a.is_role ? t('privs.dropRole') : t('admin.user.drop')) : null)));
    }
    return h('div', { class: 'max-w-3xl space-y-6' }, ...parts);
  }

  function globalTab() {
    return h('div', { class: 'space-y-3' },
      h('p', { class: 'text-[13px] text-muted' }, t('privs.global.help')),
      groupedChecks(S.catalog.global || [], () => want.global, (p, on) => { if (on) want.global.add(p); else want.global.delete(p); }));
  }

  async function databasesTab() {
    const privs = S.catalog.database || [];
    if (S.driver === 'sqlsrv') {
      const picker = await dbPicker(async (db) => { objDb = db; await ensureDb(db); draw(); });
      await ensureDb(objDb);
      const parts = [picker];
      const hasUser = S.db_users?.[objDb] || createDbUser.has(objDb);
      if (!hasUser) {
        parts.push(callout('info', h('div', { class: 'flex flex-wrap items-center gap-2' }, t('privs.db.noUserIn', { db: objDb }),
          ro ? null : h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { createDbUser.add(objDb); draw(); } }, icon('plus'), t('privs.db.createUser')))));
      }
      if (hasUser) {
        parts.push(matrix([{ key: K('database', objDb), label: objDb }], privs, t('privs.tab.databases')));
        const avail = S.db_roles?.[objDb]?.available || [];
        if (!want.dbRoles.has(objDb)) want.dbRoles.set(objDb, new Set());
        const set = want.dbRoles.get(objDb);
        parts.push(roleChecks(t('privs.roles.dbRoles', { db: objDb }), avail, set, () => changed()));
      }
      return h('div', { class: 'space-y-4' }, ...parts);
    }
    const rows = [];
    const seen = new Set();
    const keys = [...want.objs.keys()].filter((k) => JSON.parse(k)[0] === 'database');
    if (S.driver === 'pgsql') {
      (S.databases || []).forEach((d) => { const key = K('database', d.db); seen.add(key); rows.push({ key, label: d.db, sub: d.is_owner ? t('privs.owner') : (d.public?.length ? `PUBLIC: ${d.public.join(', ')}` : null) }); });
    } else {
      keys.sort().forEach((k) => {
        const [, db, , , flag] = JSON.parse(k);
        seen.add(k);
        rows.push({ key: k, label: `${db}.*`, short: db, ...(flag === 'p' ? { badge: { text: t('privs.patternBadge'), hint: t('privs.patternHint') } } : {}) });
      });
    }
    const addBox = [];
    if (S.driver === 'mysql' && !ro) {
      const dbs = await getDatabases(conn).catch(() => []);
      const id = uid('adb'); const listId = uid('dbl');
      const input = h('input', { id, class: 'input max-w-xs font-mono', dir: 'ltr', list: listId, placeholder: t('privs.db.addPlaceholder'), autocomplete: 'off', spellcheck: 'false' });
      const add = () => {
        const v = input.value.trim();
        if (!v) { input.focus(); return; }
        // a name that is not an existing database but has wildcards/escapes is a pattern, used as typed
        const key = dbKey(v, !dbs.some((d) => d.name === v) && /[\\_%]/.test(v));
        if (!want.objs.has(key)) want.objs.set(key, new Map());
        draw();
      };
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
      addBox.push(h('div', { class: 'flex flex-wrap items-end gap-2' },
        h('div', {}, h('label', { class: 'label', for: id }, t('privs.db.add')), input,
          h('datalist', { id: listId }, dbs.map((d) => h('option', { value: d.name })))),
        h('button', { type: 'button', class: 'btn', onClick: add }, icon('plus'), t('privs.db.addButton')),
        h('p', { class: 'help basis-full' }, t('privs.db.wildcardHelp'))));
    }
    return h('div', { class: 'space-y-4' },
      h('p', { class: 'text-[13px] text-muted' }, S.driver === 'pgsql' ? t('privs.db.helpPg') : t('privs.db.help')),
      ...addBox,
      rows.length ? matrix(rows, privs, t('privs.tab.databases')) : emptyState({ icon: 'database', title: t('privs.db.none') }));
  }

  const expanded = new Set();
  let tableFilter = '';
  let filterTimer = 0;
  function debounceFilter(e) {
    tableFilter = e.target.value;
    clearTimeout(filterTimer);
    filterTimer = setTimeout(() => draw().then(() => { const f = content.querySelector('[data-fk="tfilter"]'); if (f && document.activeElement === f) f.setSelectionRange(f.value.length, f.value.length); }), 200);
  }
  async function tablesTab() {
    const picker = await dbPicker(async (db) => { objDb = db; tableFilter = ''; expanded.clear(); await ensureDb(db); draw(); });
    if (!objDb) return emptyState({ icon: 'database', title: t('privs.db.none') });
    await ensureDb(objDb);
    const objs = await objectsOf(objDb);
    const db = objDb;
    const parts = [picker];
    const filter = h('input', { type: 'search', class: 'input max-w-xs', placeholder: t('privs.tables.filter'), value: tableFilter, 'aria-label': t('privs.tables.filter'), dataset: { fk: 'tfilter' },
      onInput: debounceFilter });
    parts.push(filter);
    if (F.schemas && (S.catalog.schema || []).length) {
      const rows = (objs.schemas || []).map((s) => ({ key: K('schema', db, s), label: s }));
      if (rows.length) parts.push(section(t('privs.tables.schemas'), matrix(rows, S.catalog.schema, t('privs.tables.schemas'))));
    }
    const f = tableFilter.toLowerCase();
    // tables known to the server + tables only present in grants (e.g. dropped tables)
    const list = [...(objs.tables || [])];
    (S.tables || []).filter((r) => r.db === db).forEach((r) => { if (!list.some((x) => x.name === r.table && (x.schema ?? null) === (r.schema ?? null))) list.push({ name: r.table, schema: r.schema ?? null, kind: r.kind || 'table', columns: [] }); });
    const matching = list.filter((x) => !f || `${x.schema ? `${x.schema}.` : ''}${x.name}`.toLowerCase().includes(f));
    const tablesOnly = matching.filter((x) => x.kind !== 'sequence');
    const seqs = matching.filter((x) => x.kind === 'sequence');
    const mkRows = (items) => items.slice(0, MAX_ROWS).map((x) => {
      const schema = x.schema ?? null;
      const key = K('table', db, schema, x.name);
      const label = schema ? `${schema}.${x.name}` : `${db}.${x.name}`;
      const ek = key;
      const open = expanded.has(ek);
      const row = { key, label, short: schema && schema !== 'public' && schema !== 'dbo' ? `${schema}.${x.name}` : x.name, sub: x.kind === 'view' ? t('privs.tables.view') : null };
      if (x.kind !== 'sequence' && x.columns?.length) {
        row.expand = { open, label: open ? t('privs.tables.hideColumns', { table: x.name }) : t('privs.tables.showColumns', { table: x.name }), toggle: () => { if (open) expanded.delete(ek); else expanded.add(ek); draw(); } };
        if (open) row.columnsOf = x.columns;
      }
      return row;
    });
    const tableRows = mkRows(tablesOnly);
    const tPrivs = S.catalog.table || [];
    const cPrivs = (S.catalog.column || []).map((p) => p.name);
    // column rows are rendered under their table (same matrix, only column-level privileges enabled)
    tableRows.forEach((row) => {
      if (!row.columnsOf) return;
      const [, , schema, table] = JSON.parse(row.key);
      row.children = row.columnsOf.map((c) => {
        const key = K('column', db, schema, table, c);
        const m = want.objs.get(key) || new Map();
        const label = `${row.label}.${c}`;
        return h('tr', { class: 'bg-surface-2/40' },
          h('th', { scope: 'row', class: 'sticky start-0 z-[1] bg-surface-2 px-2 py-1 text-start font-normal' },
            h('span', { class: 'block max-w-[16rem] truncate ps-9 font-mono text-[12px] text-muted', dir: 'ltr', title: label }, c)),
          tPrivs.map((p) => h('td', { class: 'px-1 py-1 text-center' }, cPrivs.includes(p.name)
            ? (F.deny
              ? h('select', { class: 'select h-7 min-h-0 w-auto px-1 text-[11.5px]', 'aria-label': t('privs.cell', { priv: p.name, object: label }), disabled: ro, dataset: { fk: `${key}|${p.name}` }, onChange: (e) => { setPriv(key, p.name, e.target.value || null); draw(); } },
                [['', '—'], ['G', 'GRANT'], ['D', 'DENY']].map(([v, l]) => h('option', { value: v, selected: (m.get(p.name) || '') === v }, l)))
              : cbox(m.has(p.name), t('privs.cell', { priv: p.name, object: label }), (on) => { setPriv(key, p.name, on ? 'G' : null); draw(); }, { fk: `${key}|${p.name}` }))
            : null)));
      });
    });
    parts.push(section(t('privs.tables.tables'),
      h('p', { class: 'text-[12.5px] text-muted' }, t('privs.tables.help')),
      tableRows.length ? matrix(tableRows, tPrivs, t('privs.tables.tables')) : emptyState({ icon: 'table', title: tableFilter ? t('sidebar.noMatches') : t('privs.tables.none') }),
      tablesOnly.length > MAX_ROWS ? h('p', { class: 'help' }, t('privs.tables.truncated', { shown: MAX_ROWS, total: tablesOnly.length })) : null));
    if (seqs.length && S.catalog.sequence) parts.push(section(t('privs.tables.sequences'), matrix(mkRows(seqs), S.catalog.sequence, t('privs.tables.sequences'))));
    const routines = (S.routines || []).filter((r) => !r.db || r.db === db);
    if (routines.length) {
      parts.push(section(t('privs.tables.routines'), h('ul', { class: 'space-y-1 text-[12.5px]' },
        routines.map((r) => h('li', { class: 'font-mono', dir: 'ltr' }, `${r.kind} ${r.schema ? `${r.schema}.` : ''}${r.name}: ${r.privileges.join(', ')}`)))));
    }
    return h('div', { class: 'space-y-4' }, ...parts);
  }

  function roleChecks(title, avail, set, onChange, defaults) {
    const single = F.default_roles === 'single';
    const legendId = uid('rl');
    if (!avail.length) return section(title, h('p', { class: 'text-[13px] text-muted' }, t('privs.roles.noneAvailable')));
    const radioName = uid('dr');
    return h('fieldset', { class: 'card p-3', 'aria-labelledby': legendId },
      h('legend', { id: legendId, class: 'px-1 text-[13px] font-semibold' }, title),
      h('ul', { class: 'divide-y divide-border' }, avail.map((r) => {
        const k = roleKey(r);
        const id = uid('ro');
        const label = roleLabel(r);
        const granted = set.has(k);
        return h('li', { class: 'flex min-h-10 flex-wrap items-center gap-2 py-1' },
          h('input', { id, type: 'checkbox', class: 'checkbox', checked: granted, disabled: ro, dataset: { fk: `r|${title}|${k}` },
            onChange: (e) => { if (e.target.checked) set.add(k); else { set.delete(k); defaults?.delete(k); } onChange(); draw(); } }),
          h('label', { for: id, class: 'min-w-0 flex-1 truncate font-mono text-[12.5px]', dir: 'ltr' }, label),
          r.predefined ? h('span', { class: 'badge' }, t('privs.roles.builtin')) : null,
          defaults ? h('label', { class: 'flex items-center gap-1.5 text-[12px] text-muted' },
            h('input', { type: single ? 'radio' : 'checkbox', name: single ? radioName : null, class: 'checkbox', checked: defaults.has(k), disabled: ro || !granted, dataset: { fk: `rd|${k}` },
              'aria-label': t('privs.roles.defaultFor', { role: label }),
              onChange: (e) => { if (single) defaults.clear(); if (e.target.checked) defaults.add(k); else defaults.delete(k); onChange(); draw(); } }),
            t('privs.roles.default')) : null);
      })),
      defaults && single ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm mt-2', disabled: ro || !defaults.size, onClick: () => { defaults.clear(); onChange(); draw(); } }, t('privs.roles.noDefault')) : null);
  }

  async function rolesTab() {
    const parts = [];
    const avail = S.roles?.available || [];
    if (F.server_roles) {
      parts.push(roleChecks(t('privs.roles.serverRoles'), avail, want.roles, changed));
      const picker = await dbPicker(async (db) => { objDb = db; await ensureDb(db); draw(); });
      await ensureDb(objDb);
      parts.push(picker);
      if (S.db_users?.[objDb] || createDbUser.has(objDb)) {
        if (!want.dbRoles.has(objDb)) want.dbRoles.set(objDb, new Set());
        parts.push(roleChecks(t('privs.roles.dbRoles', { db: objDb }), S.db_roles?.[objDb]?.available || [], want.dbRoles.get(objDb), changed));
      } else parts.push(callout('info', t('privs.db.noUserIn', { db: objDb })));
      return h('div', { class: 'space-y-4' }, ...parts);
    }
    parts.push(h('p', { class: 'text-[13px] text-muted' }, S.driver === 'pgsql' ? t('privs.roles.helpPg') : t('privs.roles.help')));
    const user = avail.filter((r) => !r.predefined);
    const builtin = avail.filter((r) => r.predefined);
    parts.push(roleChecks(t('privs.roles.granted'), user, want.roles, changed, F.default_roles ? want.defaults : null));
    if (builtin.length) parts.push(roleChecks(t('privs.roles.predefined'), builtin, want.roles, changed));
    // granted roles that are not in the available list (e.g. MySQL accounts granted as roles)
    const extra = [...want.roles].map(parseRoleKey).filter((r) => !avail.some((a) => roleKey(a) === roleKey(r)));
    if (extra.length) parts.push(roleChecks(t('privs.roles.other'), extra, want.roles, changed, F.default_roles ? want.defaults : null));
    if (S.roles?.members?.length) {
      parts.push(section(t('privs.roles.members'), h('ul', { class: 'flex flex-wrap gap-1' }, S.roles.members.map((m) => h('li', { class: 'badge font-mono' }, m)))));
    }
    return h('div', { class: 'space-y-4' }, ...parts);
  }

  function rawTab() {
    const text = (S.grants || []).map((g) => (/;\s*$/.test(g) ? g : `${g};`)).join('\n');
    return h('div', { class: 'space-y-3' },
      h('div', { class: 'flex flex-wrap items-center gap-2' },
        h('p', { class: 'flex-1 text-[13px] text-muted' }, S.driver === 'mysql' ? t('privs.raw.helpMysql') : t('privs.raw.help')),
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => copyText(text).then(() => toast.success(t('common.copied'))) }, icon('copy'), t('common.copy'))),
      h('pre', { class: 'max-h-[60dvh] overflow-auto rounded-lg border border-border bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap break-words', dir: 'ltr', tabindex: '0', 'aria-label': t('privs.tab.raw') }, highlightSql(text || '-- ')));
  }

  /* ------------------------------------------------------------------ rename / clone */

  async function rename() {
    if (dirty()) { toast.info(t('privs.applyFirst')); return; }
    const fields = [{ name: 'user', label: t('admin.user.user'), value: acct.user, mono: true, required: true }];
    if (F.host && !(S.account?.is_role && S.flavor === 'mariadb')) fields.push({ name: 'host', label: t('admin.user.host'), value: acct.host || '%', mono: true, required: true });
    const v = await formDialog({ title: t('privs.rename.title', { name: acctLabel(acct) }), fields, submitLabel: t('privs.rename.submit'), size: 'sm' });
    if (!v) return;
    const target = { user: v.user, host: F.host ? (v.host || acct.host) : null };
    await planAndApply({ rename: { user: target.user, host: target.host ?? undefined } }, t('privs.rename.title', { name: acctLabel(acct) }), (res) => {
      if (!res || res.failed) return;
      o.onChanged?.(target);
    });
  }

  async function clone() {
    if (dirty()) { toast.info(t('privs.applyFirst')); return; }
    const others = (o.accounts || []).filter((u) => !(u.user === acct.user && (u.host ?? null) === (acct.host ?? null)));
    if (!others.length) { toast.info(t('privs.clone.none')); return; }
    const v = await formDialog({
      title: t('privs.clone.title', { name: acctLabel(acct) }), size: 'md',
      description: S.driver === 'pgsql' ? t('privs.clone.helpPg', { db: objDb || S.current_database }) : t('privs.clone.help'),
      fields: [{ name: 'src', type: 'select', label: t('privs.clone.source'), value: '0', options: others.map((u, i) => ({ value: String(i), label: acctLabel(u) })) }],
      submitLabel: t('privs.clone.submit'),
    });
    if (!v) return;
    const src = others[Number(v.src)];
    await planAndApply({ clone_from: { user: src.user, host: src.host ?? undefined, db: S.driver === 'pgsql' ? (objDb || S.current_database) : undefined } },
      t('privs.clone.title', { name: acctLabel(acct) }), (res) => { if (res) { load(); o.onChanged?.(); } });
  }

  load();
  return {
    dirty,
    destroy() { destroyed = true; },
  };
}
