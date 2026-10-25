/**
 * Schema diff (#/c/{conn}/db/{db}[@schema]/diff): compare a SOURCE database (desired state) with a
 * TARGET (defaults to the route's connection/database) — on the same or another connection of
 * the same driver — review the changes (tree with include/exclude checkboxes, DDL line diff),
 * generate the migration script (diff.plan), copy / download / open it in the SQL editor and
 * apply it to the target (diff.apply, typed confirmation, atomic or auto-commit).
 *
 * Query params (kept in the URL with replaceState): sc/sdb/ss = source conn/db/schema,
 * tc/tdb/ts = target. sc=@path means "the route's connection" — used by "Open another
 * connection…", which goes through the share-link reconnect flow (login ?next=… swaps the
 * route connection for the new one).
 */
import { h, cls, uid, setChildren, copyText, downloadText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openModal } from '../ui/modal.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { skeleton, emptyState, callout, spinner } from '../ui/feedback.js';
import { t, fmtNumber } from '../i18n/index.js';
import { api } from '../api.js';
import { href, navigate } from '../router.js';
import { appStore, storage } from '../store.js';
import { connections, isReadOnly } from '../session.js';
import { getCaps, getDatabases, getSchemas, invalidate } from '../data.js';
import { highlightSql } from '../sql/highlight.js';
import { splitLines, diffLines, diffStats, sideBySide, withContext } from '../diff/linediff.js';
import { groupChanges, buildScript, scriptFilename, actionOf } from '../diff/plan.js';

const DEFAULT_OPTIONS = {
  include: { tables: true, views: true, routines: true, triggers: true, events: true },
  ignore: { comments: false, auto_increment: true, collation: false, column_order: false, definer: true },
  detect_renames: false,
};
/** Not offered as the default source database (still selectable). */
const SYSTEM_DBS = new Set(['information_schema', 'mysql', 'performance_schema', 'sys', 'postgres', 'master', 'tempdb', 'model', 'msdb']);
const GROUP_ICON = { table: 'table', view: 'eye', routine: 'function', trigger: 'zap', event: 'calendar' };
const ACTION_BADGE = { added: 'badge-success', removed: 'badge-danger', changed: 'badge-warning', renamed: 'badge-accent' };
const ACTION_MARK = { added: '+', removed: '−', changed: '~', renamed: '→' };
const WARN_KIND = { drop_table: 'danger', drop_column: 'danger', narrowing: 'danger', not_null_nulls: 'danger', not_null: 'warning', fk_dropped: 'warning', fk_missing_table: 'warning', generated_unsupported: 'warning', definition_missing: 'warning' };

const loadOptions = () => {
  const saved = storage.get('diff.options', null);
  const o = JSON.parse(JSON.stringify(DEFAULT_OPTIONS));
  if (saved && typeof saved === 'object') {
    for (const g of ['include', 'ignore']) for (const k of Object.keys(o[g])) if (typeof saved[g]?.[k] === 'boolean') o[g][k] = saved[g][k];
    if (typeof saved.detect_renames === 'boolean') o.detect_renames = saved.detect_renames;
  }
  return o;
};

const connLabel = (c) => `${c.label || `${c.user || ''}@${c.host || c.driver}`} · ${c.driver}`;

export default {
  title: (ctx) => t('diff.titleFor', { db: ctx.db }),

  async render(el, ctx) {
    const p = ctx.params || {};
    const known = (id) => connections().some((c) => String(c.id) === String(id));
    const resolveConn = (v) => (v === '@path' ? ctx.conn : v);
    const target = { conn: known(resolveConn(p.tc)) ? resolveConn(p.tc) : ctx.conn, db: p.tdb || ctx.db, schema: p.ts || (p.tdb ? '' : ctx.schema || '') };
    const source = { conn: known(resolveConn(p.sc)) ? resolveConn(p.sc) : target.conn, db: p.sdb || '', schema: p.ss || '' };
    const state = {
      source,
      target,
      options: loadOptions(),
      result: null,
      request: null,
      selected: new Set(),
      collapsed: new Set(),
      focus: null,
      mode: storage.get('diff.mode', 'split') === 'unified' ? 'unified' : 'split',
      plan: null,
      seq: 0,
    };

    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' });
    const content = h('div', { class: 'mx-auto w-full max-w-[1400px] space-y-4 p-4 sm:p-6' });
    scroller.appendChild(content);
    el.replaceChildren(scroller);

    const setupBox = h('section', { class: 'card space-y-4 p-4', 'aria-labelledby': 'diff-setup-h' });
    const resultBox = h('div', { class: 'space-y-4', 'aria-live': 'polite', 'aria-busy': 'false' });
    const scriptBox = h('section', { class: 'card space-y-3 p-4', hidden: true, tabindex: '-1', 'aria-labelledby': 'diff-script-h' });

    setChildren(content,
      h('div', { class: 'flex flex-wrap items-end justify-between gap-3' },
        h('div', { class: 'min-w-0' },
          h('h1', { class: 'flex items-center gap-2 text-xl font-semibold tracking-tight' }, icon('git-compare', 'size-5 text-muted'), t('diff.title')),
          h('p', { class: 'mt-0.5 max-w-3xl text-[13px] text-muted' }, t('diff.intro')))),
      setupBox, resultBox, scriptBox);

    // ------------------------------------------------------------------ URL state
    const syncUrl = () => {
      const params = {
        sc: state.source.conn, sdb: state.source.db, ss: state.source.schema || undefined,
        tc: state.target.conn !== ctx.conn ? state.target.conn : undefined,
        tdb: state.target.conn !== ctx.conn || state.target.db !== ctx.db ? state.target.db : undefined,
        ts: state.target.schema && (state.target.schema !== (ctx.schema || '') || state.target.db !== ctx.db) ? state.target.schema : undefined,
      };
      try { history.replaceState(history.state, '', href.diff(ctx.conn, ctx.db, ctx.schema, params)); } catch { /* ignore */ }
    };

    // ------------------------------------------------------------------ setup (source / target pickers)
    const sidePicker = (role) => {
      const side = state[role];
      const ids = { conn: uid('dc'), db: uid('dd'), schema: uid('ds') };
      const connSel = h('select', { id: ids.conn, class: 'select', onChange: () => { side.conn = connSel.value; side.db = ''; side.schema = ''; fillDbs(); onSetupChange(); } },
        connections().map((c) => h('option', { value: String(c.id), selected: String(c.id) === String(side.conn) }, connLabel(c))));
      const dbSel = h('select', { id: ids.db, class: 'select', onChange: () => { side.db = dbSel.value; side.schema = ''; fillSchemas(); onSetupChange(); } });
      const schemaSel = h('select', { id: ids.schema, class: 'select', onChange: () => { side.schema = schemaSel.value; onSetupChange(); } });
      const schemaField = h('div', { class: 'min-w-0', hidden: true }, h('label', { class: 'label', for: ids.schema }, t('diff.schema')), schemaSel);
      const roNote = h('p', { class: 'help flex items-center gap-1', hidden: true }, icon('lock', 'size-3.5'), t('diff.targetReadOnly'));

      async function fillSchemas() {
        schemaField.hidden = true;
        const caps = await getCaps(side.conn);
        if (!caps.schemas || !side.db) return;
        try {
          const list = await getSchemas(side.conn, side.db);
          if (!side.schema || !list.includes(side.schema)) side.schema = list.includes('public') ? 'public' : (list[0] || '');
          setChildren(schemaSel, list.map((s) => h('option', { value: s, selected: s === side.schema }, s)));
          schemaField.hidden = !list.length;
        } catch { /* schema list optional */ }
        onSetupChange(false);
      }
      async function fillDbs() {
        setChildren(dbSel, h('option', { value: '' }, t('common.loading')));
        dbSel.disabled = true;
        roNote.hidden = !(role === 'target' && isReadOnly(side.conn));
        try {
          const dbs = await getDatabases(side.conn);
          const names = dbs.map((d) => d.name);
          if (!side.db || !names.includes(side.db)) {
            // source defaults: same name as the target on another connection, else another database
            const other = state.target.db;
            const candidates = names.filter((n) => !SYSTEM_DBS.has(n.toLowerCase()));
            side.db = role === 'source' && names.includes(other) && side.conn !== state.target.conn ? other
              : (candidates.find((n) => !(role === 'source' && side.conn === state.target.conn && n === other)) || candidates[0] || names[0] || '');
          }
          setChildren(dbSel, names.map((n) => h('option', { value: n, selected: n === side.db }, n)));
        } catch (e) {
          setChildren(dbSel, h('option', { value: '' }, '—'));
          toastError(e);
        } finally {
          dbSel.disabled = false;
        }
        await fillSchemas();
        onSetupChange(false);
      }
      const title = role === 'source' ? t('diff.source') : t('diff.target');
      const help = role === 'source' ? t('diff.sourceHelp') : t('diff.targetHelp');
      const box = h('fieldset', { class: 'min-w-0 space-y-3 rounded-lg border border-border p-3' },
        h('legend', { class: 'px-1 text-[13px] font-semibold' }, h('span', { class: cls('me-1.5 badge', role === 'source' ? 'badge-accent' : 'badge-warning') }, role === 'source' ? 'A' : 'B'), title),
        h('p', { class: 'text-xs text-muted' }, help),
        h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: ids.conn }, t('diff.connection')), connSel),
        h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: ids.db }, t('diff.database')), dbSel),
          schemaField),
        roNote);
      return { box, fillDbs, connSel };
    };

    const driverError = h('div', { hidden: true });
    const compareBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => compare() }, icon('git-compare'), t('diff.compare'));

    function sameDriver() {
      const a = connections().find((c) => String(c.id) === String(state.source.conn));
      const b = connections().find((c) => String(c.id) === String(state.target.conn));
      return !a || !b || a.driver === b.driver ? null : [a.driver, b.driver];
    }
    function onSetupChange(clear = true) {
      const mismatch = sameDriver();
      driverError.hidden = !mismatch;
      if (mismatch) setChildren(driverError, callout('danger', t('diff.driverMismatch', { source: mismatch[0], target: mismatch[1] })));
      const same = state.source.conn === state.target.conn && state.source.db === state.target.db && (state.source.schema || '') === (state.target.schema || '');
      compareBtn.disabled = !!mismatch || !state.source.db || !state.target.db || same;
      compareBtn.title = same ? t('diff.sameDatabase') : '';
      if (clear && state.result) { state.result = null; state.plan = null; drawResult(); }
      syncUrl();
    }

    const swapBtn = h('button', {
      type: 'button', class: 'btn btn-sm self-center', 'aria-label': t('diff.swap'), title: t('diff.swap'),
      onClick: () => {
        const s = { ...state.source };
        Object.assign(state.source, state.target);
        Object.assign(state.target, s);
        drawSetup();
      },
    }, icon('arrow-left-right', 'max-lg:rotate-90'));

    const otherConnHref = () => {
      const next = href.diff(ctx.conn, ctx.db, ctx.schema, {
        sc: '@path', tc: state.target.conn, tdb: state.target.db, ts: state.target.schema || undefined,
      }).replace(/^#/, '');
      return href.login({ add: 1, next });
    };

    const optionsBox = () => {
      const o = state.options;
      const save = () => { storage.set('diff.options', o); if (state.result) { state.result = null; drawResult(); } };
      const box = (group, key, label, help) => {
        const id = uid('do');
        const cb = h('input', {
          id, type: 'checkbox', class: 'checkbox mt-0.5', checked: group ? o[group][key] : o[key],
          onChange: () => { if (group) o[group][key] = cb.checked; else o[key] = cb.checked; save(); },
        });
        return h('div', { class: 'flex items-start gap-2' }, cb, h('label', { for: id, class: 'text-[13px] leading-5' }, label,
          help ? h('span', { class: 'block text-xs text-muted' }, help) : null));
      };
      return h('details', { class: 'rounded-lg border border-border', open: storage.get('diff.optionsOpen', false) || undefined, onToggle: (e) => storage.set('diff.optionsOpen', e.currentTarget.open) },
        h('summary', { class: 'cursor-pointer select-none px-3 py-2 text-[13px] font-medium' }, t('diff.options')),
        h('div', { class: 'grid gap-4 border-t border-border p-3 md:grid-cols-3' },
          h('fieldset', { class: 'space-y-2' }, h('legend', { class: 'mb-1 text-xs font-semibold uppercase tracking-wide text-muted' }, t('diff.opt.include')),
            box('include', 'tables', t('diff.group.table')), box('include', 'views', t('diff.group.view')), box('include', 'routines', t('diff.group.routine')),
            box('include', 'triggers', t('diff.group.trigger')), box('include', 'events', t('diff.group.event'))),
          h('fieldset', { class: 'space-y-2' }, h('legend', { class: 'mb-1 text-xs font-semibold uppercase tracking-wide text-muted' }, t('diff.opt.ignore')),
            box('ignore', 'comments', t('diff.opt.comments')), box('ignore', 'auto_increment', t('diff.opt.autoIncrement')),
            box('ignore', 'collation', t('diff.opt.collation')), box('ignore', 'column_order', t('diff.opt.columnOrder')),
            box('ignore', 'definer', t('diff.opt.definer'))),
          h('fieldset', { class: 'space-y-2' }, h('legend', { class: 'mb-1 text-xs font-semibold uppercase tracking-wide text-muted' }, t('diff.opt.heuristics')),
            box(null, 'detect_renames', t('diff.opt.renames'), t('diff.opt.renamesHelp')))));
    };

    function drawSetup() {
      const s = sidePicker('source');
      const tg = sidePicker('target');
      setChildren(setupBox,
        h('h2', { id: 'diff-setup-h', class: 'sr-only' }, t('diff.setup')),
        h('div', { class: 'flex flex-col gap-3 lg:flex-row lg:items-stretch' },
          h('div', { class: 'min-w-0 flex-1' }, s.box), swapBtn, h('div', { class: 'min-w-0 flex-1' }, tg.box)),
        driverError,
        optionsBox(),
        h('div', { class: 'flex flex-wrap items-center gap-3' }, compareBtn,
          h('a', { href: otherConnHref(), class: 'inline-flex items-center gap-1 text-[13px] text-accent hover:underline', onClick: (e) => { e.currentTarget.href = otherConnHref(); } },
            icon('plug', 'size-3.5'), t('diff.otherConnection'))));
      s.fillDbs();
      tg.fillDbs();
    }

    // ------------------------------------------------------------------ compare
    const sideParams = (s) => ({ conn: s.conn, db: s.db, schema: s.schema || undefined });
    const request = () => ({ source: sideParams(state.source), target: sideParams(state.target), options: state.options });

    async function compare() {
      if (compareBtn.disabled) return;
      const seq = ++state.seq;
      state.plan = null;
      scriptBox.hidden = true;
      compareBtn.disabled = true;
      resultBox.setAttribute('aria-busy', 'true');
      setChildren(resultBox, h('div', { class: 'card p-4' }, h('div', { class: 'mb-3' }, spinner({ label: t('diff.comparing') })), skeleton({ rows: 6 })));
      const req = request();
      try {
        const res = await api('diff.compare', req);
        if (seq !== state.seq) return;
        state.result = res;
        state.request = req;
        state.selected = new Set(res.changes.map((c) => c.id));
        state.collapsed = new Set();
        const groups = groupChanges(res.changes);
        state.focus = groups[0] ? focusOf(groups[0]) : null;
        drawResult();
        announce(res.changes.length ? t('diff.foundChanges', { n: fmtNumber(res.changes.length) }) : t('diff.noDifferences'));
      } catch (e) {
        if (seq !== state.seq) return;
        state.result = null;
        setChildren(resultBox, callout('danger', e.message));
      } finally {
        if (seq === state.seq) { compareBtn.disabled = false; onSetupChange(false); resultBox.setAttribute('aria-busy', 'false'); }
      }
    }

    // ------------------------------------------------------------------ result
    const focusOf = (g) => (g.type === 'table' ? { type: 'table', key: g.key, group: g } : { type: 'object', id: g.changes[0].id, change: g.changes[0] });
    const kindLabel = (c) => t(`diff.kind.${c.kind}`);
    const describe = (c) => {
      const a = c.after || {};
      const b = c.before || {};
      const cols = (x) => (x.columns || []).join(', ');
      switch (c.kind) {
        case 'column_added': return a.definition;
        case 'column_removed': return b.definition;
        case 'column_renamed': return `${b.name} → ${a.name}`;
        case 'column_changed': return `${b.definition} → ${a.definition}`;
        case 'index_added': case 'index_removed': { const x = c.kind === 'index_added' ? a : b; return `${x.unique || x.primary ? 'UNIQUE ' : ''}(${cols(x)})`; }
        case 'index_changed': return `(${cols(b)}) → (${cols(a)})${c.fields?.includes('name') ? ` · ${b.name} → ${a.name}` : ''}`;
        case 'fk_added': case 'fk_removed': case 'fk_changed': {
          const fk = (x) => `(${cols(x)}) → ${x.ref_table} (${(x.ref_columns || []).join(', ')})${x.on_delete ? ` ON DELETE ${x.on_delete}` : ''}`;
          return c.kind === 'fk_changed' ? `${fk(b)}  ⇒  ${fk(a)}` : fk(c.kind === 'fk_added' ? a : b);
        }
        case 'check_added': return a.definition;
        case 'check_removed': return b.definition;
        case 'check_changed': return `${b.definition} → ${a.definition}`;
        case 'option_changed': return (c.fields || []).map((f) => `${t(`diff.field.${f}`)}: ${b[f] ?? '—'} → ${a[f] ?? '—'}`).join(' · ');
        case 'table_added': return t('diff.tableColumns', { n: a.columns ?? 0 });
        default: return '';
      }
    };

    function summaryChips(summary) {
      const chips = [];
      for (const type of ['table', 'view', 'routine', 'trigger', 'event']) {
        const s = summary[`${type}s`];
        if (!s || !(s.added + s.removed + s.changed)) continue;
        const parts = [];
        if (s.added) parts.push(h('span', { class: 'text-success', dir: 'ltr' }, `+${fmtNumber(s.added)}`, h('span', { class: 'sr-only' }, ` ${t('diff.action.added')}`)));
        if (s.removed) parts.push(h('span', { class: 'text-danger', dir: 'ltr' }, `−${fmtNumber(s.removed)}`, h('span', { class: 'sr-only' }, ` ${t('diff.action.removed')}`)));
        if (s.changed) parts.push(h('span', { class: 'text-warning', dir: 'ltr' }, `~${fmtNumber(s.changed)}`, h('span', { class: 'sr-only' }, ` ${t('diff.action.changed')}`)));
        chips.push(h('li', { class: 'inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-[12.5px]' },
          icon(GROUP_ICON[type], 'size-3.5 text-muted'), h('span', { class: 'font-medium' }, t(`diff.group.${type}`)), ...parts));
      }
      if (summary.destructive) {
        chips.push(h('li', { class: 'inline-flex items-center gap-1.5 rounded-full border border-danger/40 bg-danger/10 px-3 py-1 text-[12.5px] text-danger' },
          icon('warning', 'size-3.5'), t('diff.destructiveCount', { n: fmtNumber(summary.destructive) })));
      }
      return h('ul', { class: 'flex flex-wrap gap-2', 'aria-label': t('diff.summary') }, chips);
    }

    const tree = h('div', { class: 'card min-w-0 overflow-hidden' });
    const detail = h('section', { class: 'card min-w-0 overflow-hidden', 'aria-labelledby': 'diff-detail-h' });
    const selCount = h('span', { class: 'text-[13px] text-muted', 'aria-live': 'polite' });

    function updateCount() {
      const total = state.result?.changes.length || 0;
      selCount.textContent = t('diff.selectedCount', { n: fmtNumber(state.selected.size), total: fmtNumber(total) });
    }

    function drawResult() {
      const res = state.result;
      if (!res) { resultBox.replaceChildren(); scriptBox.hidden = true; return; }
      if (!res.changes.length) {
        setChildren(resultBox, h('div', { class: 'card' }, emptyState({
          icon: 'circle-check', title: t('diff.noDifferences'),
          message: t('diff.noDifferencesHelp', { source: `${res.source.db}${res.source.schema ? `.${res.source.schema}` : ''}`, target: `${res.target.db}${res.target.schema ? `.${res.target.schema}` : ''}` }),
        })));
        return;
      }
      const destructive = res.changes.filter((c) => c.destructive);
      const toolbar = h('div', { class: 'flex flex-wrap items-center gap-2' },
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { res.changes.forEach((c) => state.selected.add(c.id)); drawTree(); } }, t('diff.selectAll')),
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { state.selected.clear(); drawTree(); } }, t('diff.selectNone')),
        destructive.length ? h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { destructive.forEach((c) => state.selected.delete(c.id)); drawTree(); } }, icon('warning'), t('diff.skipDestructive')) : null,
        selCount,
        h('div', { class: 'flex-1' }),
        h('button', { type: 'button', class: 'btn btn-primary', onClick: (e) => generate(e.currentTarget) }, icon('file-code'), t('diff.generate')));
      setChildren(resultBox,
        summaryChips(res.summary),
        toolbar,
        h('div', { class: 'grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]' }, tree, detail));
      drawTree();
      drawDetail();
    }

    function drawTree() {
      const groups = groupChanges(state.result.changes);
      updateCount();
      const list = h('ul', { class: 'divide-y divide-border', 'aria-label': t('diff.changes') });
      for (const g of groups) {
        const ids = g.changes.map((c) => c.id);
        const n = ids.filter((id) => state.selected.has(id)).length;
        const gcb = h('input', {
          type: 'checkbox', class: 'checkbox', checked: n === ids.length,
          'aria-label': t('diff.includeGroup', { name: g.type === 'table' ? g.name : t(`diff.group.${g.type}`) }),
          onChange: () => { ids.forEach((id) => (gcb.checked ? state.selected.add(id) : state.selected.delete(id))); drawTree(); },
        });
        gcb.indeterminate = n > 0 && n < ids.length;
        const listId = uid('dg');
        const open = !state.collapsed.has(g.key);
        const isTable = g.type === 'table';
        const focused = isTable && state.focus?.type === 'table' && state.focus.key === g.key;
        const hasDestructive = g.changes.some((c) => c.destructive);
        const header = h('div', { class: cls('flex min-h-10 items-center gap-2 px-3 py-1.5', focused && 'bg-accent-soft') },
          h('button', {
            type: 'button', class: 'btn btn-ghost btn-sm btn-icon -ms-1', 'aria-expanded': String(open), 'aria-controls': listId,
            'aria-label': open ? t('diff.collapse') : t('diff.expand'),
            onClick: () => { if (open) state.collapsed.add(g.key); else state.collapsed.delete(g.key); drawTree(); },
          }, icon(open ? 'chevron-down' : 'chevron-right')),
          gcb,
          isTable
            ? h('button', {
              type: 'button', class: 'flex min-w-0 flex-1 items-center gap-2 text-start text-[13px] font-medium hover:text-accent', 'aria-current': focused ? 'true' : null,
              onClick: () => { state.focus = focusOf(g); drawTree(); drawDetail(); },
            }, icon('table', 'text-muted'), h('span', { class: 'truncate', dir: 'auto' }, g.name))
            : h('span', { class: 'flex min-w-0 flex-1 items-center gap-2 text-[13px] font-semibold' }, icon(GROUP_ICON[g.type], 'text-muted'), t(`diff.group.${g.type}`)),
          hasDestructive ? h('span', { class: 'text-danger', title: t('diff.destructive') }, icon('warning', 'size-3.5'), h('span', { class: 'sr-only' }, t('diff.destructive'))) : null,
          isTable && g.own ? h('span', { class: cls('badge', ACTION_BADGE[g.action]) }, t(`diff.action.${g.action}`)) : h('span', { class: 'badge' }, fmtNumber(g.changes.length)));
        const children = h('ul', { id: listId, class: 'pb-1', hidden: !open });
        for (const c of g.changes) {
          if (isTable && c === g.own && g.changes.length === 1) continue;
          const id = uid('dch');
          const action = actionOf(c.kind);
          const objFocused = state.focus?.type === 'object' && state.focus.id === c.id;
          const cb = h('input', {
            id, type: 'checkbox', class: 'checkbox mt-0.5', checked: state.selected.has(c.id),
            onChange: () => { if (cb.checked) state.selected.add(c.id); else state.selected.delete(c.id); drawTree(); },
          });
          const text = describe(c);
          children.appendChild(h('li', { class: cls('flex items-start gap-2 py-1.5 pe-3 ps-12', objFocused && 'bg-accent-soft', !state.selected.has(c.id) && 'opacity-60') },
            cb,
            h('div', { class: 'min-w-0 flex-1' },
              h('label', { for: id, class: 'flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]' },
                h('span', { class: cls('font-mono text-xs', action === 'added' ? 'text-success' : action === 'removed' ? 'text-danger' : 'text-warning'), 'aria-hidden': 'true' }, ACTION_MARK[action] || '~'),
                h('span', {}, kindLabel(c)),
                h('span', { class: 'font-mono text-[12.5px] font-medium', dir: 'ltr' }, c.object),
                c.destructive ? h('span', { class: 'badge badge-danger' }, icon('warning'), t('diff.destructive')) : null,
                c.null_rows ? h('span', { class: 'badge badge-danger' }, t('diff.nullRows', { n: fmtNumber(c.null_rows) })) : null),
              text ? h('div', { class: 'mt-0.5 font-mono text-[11.5px] text-muted [overflow-wrap:anywhere]', dir: 'ltr' }, text) : null,
              c.fields?.length && c.kind === 'column_changed' ? h('div', { class: 'mt-0.5 text-[11.5px] text-muted' }, c.fields.map((f) => t(`diff.field.${f}`)).join(', ')) : null,
              !isTable ? h('button', {
                type: 'button', class: 'mt-1 inline-flex items-center gap-1 text-xs text-accent hover:underline', 'aria-current': objFocused ? 'true' : null,
                onClick: () => { state.focus = { type: 'object', id: c.id, change: c }; drawTree(); drawDetail(); },
              }, icon('file-code', 'size-3.5'), t('diff.showDdl')) : null)));
        }
        list.appendChild(h('li', {}, header, children));
      }
      setChildren(tree, h('div', { class: 'flex items-center justify-between border-b border-border px-3 py-2' },
        h('h2', { class: 'text-[13px] font-semibold' }, t('diff.changes'))), list);
    }

    // ------------------------------------------------------------------ DDL diff
    const GUTTER = 'w-10 select-none border-e border-border px-2 text-end align-top tabular-nums text-subtle';
    const CODE = 'whitespace-pre-wrap px-2 align-top [overflow-wrap:anywhere]';
    const TH = 'sticky top-0 z-[1] border-b border-border bg-surface-2 px-2 py-1 text-start font-sans text-xs font-medium text-muted';
    const lineCell = (o, side) => {
      if (!o) return [h('td', { class: GUTTER }), h('td', { class: cls(CODE, 'bg-surface-2/60') })];
      const n = side === 'a' ? o.a : o.b;
      const mark = o.op === 'del' ? '−' : o.op === 'add' ? '+' : ' ';
      const sr = o.op === 'del' ? t('diff.line.removed') : o.op === 'add' ? t('diff.line.added') : '';
      return [
        h('td', { class: cls(GUTTER, o.op === 'del' && 'bg-danger/15', o.op === 'add' && 'bg-success/15') }, n === null ? '' : String(n + 1)),
        h('td', { class: cls(CODE, o.op === 'del' && 'bg-danger/10', o.op === 'add' && 'bg-success/10') },
          h('span', { class: cls('inline-block w-3 select-none', o.op === 'del' && 'text-danger', o.op === 'add' && 'text-success'), 'aria-hidden': 'true' }, mark),
          sr ? h('span', { class: 'sr-only' }, `${sr}: `) : null,
          highlightSql(o.text)),
      ];
    };

    function ddlDiff(before, after) {
      const a = splitLines(before);
      const b = splitLines(after);
      const ops = diffLines(a, b);
      const st = diffStats(ops);
      const caption = h('caption', { class: 'sr-only' }, t('diff.ddlCaption', { added: st.added, removed: st.removed }));
      let table;
      if (state.mode === 'split') {
        table = h('table', { class: 'w-full table-fixed border-collapse', dir: 'ltr' }, caption,
          h('colgroup', {}, h('col', { class: 'w-10' }), h('col', { class: 'w-1/2' }), h('col', { class: 'w-10' }), h('col', { class: 'w-1/2' })),
          h('thead', {}, h('tr', {},
            h('th', { class: TH, scope: 'col', colspan: '2' }, t('diff.targetCurrent')),
            h('th', { class: TH, scope: 'col', colspan: '2' }, t('diff.sourceDesired')))),
          h('tbody', {}, sideBySide(ops).map((r) => h('tr', {}, ...lineCell(r.left, 'a'), ...lineCell(r.right, 'b')))));
      } else {
        table = h('table', { class: 'w-full table-fixed border-collapse', dir: 'ltr' }, caption,
          h('colgroup', {}, h('col', { class: 'w-10' }), h('col', { class: 'w-10' }), h('col', {})),
          h('thead', {}, h('tr', {}, h('th', { class: TH, scope: 'col' }, 'B'), h('th', { class: TH, scope: 'col' }, 'A'), h('th', { class: TH, scope: 'col' }, t('diff.unifiedHeader')))),
          h('tbody', {}, withContext(ops, 3).map((o) => (o.skip
            ? h('tr', {}, h('td', { class: GUTTER }, '⋯'), h('td', { class: GUTTER }, '⋯'), h('td', { class: cls(CODE, 'text-muted') }, t('diff.unchangedLines', { n: o.skip })))
            : h('tr', {}, h('td', { class: GUTTER }, o.a === null ? '' : String(o.a + 1)), ...lineCell(o, 'b'))))));
      }
      return h('div', { class: 'max-h-[65vh] overflow-auto rounded-lg border border-border bg-surface font-mono text-[12px]', dir: 'ltr', tabindex: '0', role: 'region', 'aria-label': t('diff.ddlDiff') },
        ops.length ? table : h('p', { class: 'p-3 text-muted' }, t('diff.noDdl')));
    }

    async function drawDetail() {
      const f = state.focus;
      const res = state.result;
      if (!f || !res) { setChildren(detail, h('p', { class: 'p-4 text-[13px] text-muted' }, t('diff.pickObject'))); return; }
      const token = ++state.detailSeq;
      const modeBtns = h('div', { class: 'flex gap-1', role: 'group', 'aria-label': t('diff.viewMode') },
        ['split', 'unified'].map((m) => h('button', {
          type: 'button', class: 'btn btn-ghost btn-sm', 'aria-pressed': String(state.mode === m),
          onClick: () => { state.mode = m; storage.set('diff.mode', m); drawDetail(); },
        }, icon(m === 'split' ? 'columns' : 'rows'), t(`diff.mode.${m}`))));
      let title;
      let sub = null;
      let before = '';
      let after = '';
      const body = h('div', { class: 'space-y-3 p-3' }, spinner({ label: t('common.loading') }));
      if (f.type === 'table') {
        const g = f.group;
        title = g.name;
        sub = g.own ? t(`diff.kind.${g.own.kind}`) : t('diff.tableChanges', { n: g.changes.length });
      } else {
        const c = f.change;
        title = c.object;
        sub = `${t(`diff.type.${c.object_type}`)} · ${kindLabel(c)}`;
      }
      setChildren(detail,
        h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border px-3 py-2' },
          h('div', { class: 'min-w-0 flex-1' },
            h('h2', { id: 'diff-detail-h', class: 'truncate font-mono text-[13px] font-semibold', dir: 'ltr' }, title),
            h('p', { class: 'text-xs text-muted' }, sub)),
          modeBtns),
        body);
      try {
        if (f.type === 'table') {
          const g = f.group;
          const ddl = (side) => api('table.ddl', { conn: side.conn, db: side.db, schema: side.schema || undefined, table: g.name }).then((r) => r.sql, () => '');
          [before, after] = await Promise.all([
            g.action === 'added' ? '' : ddl(state.request.target),
            g.action === 'removed' ? '' : ddl(state.request.source),
          ]);
        } else {
          before = f.change.before?.ddl || '';
          after = f.change.after?.ddl || '';
        }
      } catch { /* shown as empty */ }
      if (token !== state.detailSeq) return;
      const warn = [];
      if (f.type === 'table' && f.group.changes.some((c) => c.destructive)) warn.push(callout('danger', t('diff.destructiveHelp')));
      setChildren(body, ...warn, ddlDiff(before, after));
    }
    state.detailSeq = 0;

    // ------------------------------------------------------------------ script
    const phaseLabel = (ph) => t(`diff.phase.${ph}`);
    const warnText = (w) => t(`diff.warn.${w.code}`, w.params || {});

    async function generate(btn) {
      if (!state.selected.size) { toast.info(t('diff.nothingSelected')); return; }
      btn.disabled = true;
      try {
        const plan = await api('diff.plan', { ...state.request, selected: [...state.selected] });
        state.plan = plan;
        drawScript();
        scriptBox.hidden = false;
        scriptBox.scrollIntoView({ block: 'start', behavior: 'smooth' });
        scriptBox.focus({ preventScroll: true });
      } catch (e) {
        toastError(e);
      } finally {
        if (btn.isConnected) btn.disabled = false;
      }
    }

    const scriptText = () => {
      const r = state.result;
      return buildScript(state.plan, {
        driver: r.driver,
        header: [
          t('diff.script.header'),
          t('diff.script.source', { db: `${r.source.db}${r.source.schema ? `.${r.source.schema}` : ''}` }),
          t('diff.script.target', { db: `${r.target.db}${r.target.schema ? `.${r.target.schema}` : ''}` }),
          t('diff.script.generated', { date: new Date().toISOString() }),
        ],
        phaseLabel,
        use: r.driver === 'mysql' ? r.target.db : null,
      });
    };

    function drawScript(applied) {
      const plan = state.plan;
      const r = state.result;
      const targetName = r.target.db;
      const ro = isReadOnly(state.request.target.conn);
      const n = plan.statements.filter((s) => !s.tx_control).length;
      const pre = h('pre', { class: 'max-h-[60vh] overflow-auto rounded-lg border border-border bg-surface-2 p-3 font-mono text-[12px] leading-relaxed', dir: 'ltr', tabindex: '0', 'aria-label': t('diff.script.title') });
      pre.appendChild(highlightSql(scriptText()));
      const warnings = (plan.warnings || []).map((w) => callout(WARN_KIND[w.code] || 'info', warnText(w)));
      const notes = [];
      if (r.driver === 'mysql') notes.push(callout('info', t('diff.apply.mysqlNote')));
      const applyBtn = ro ? null : h('button', { type: 'button', class: 'btn btn-danger btn-sm', disabled: !n || applied, onClick: () => applyDialog() }, icon('play'), t('diff.apply.button'));
      setChildren(scriptBox,
        h('div', { class: 'flex flex-wrap items-center gap-2' },
          h('div', { class: 'me-auto min-w-0' },
            h('h2', { id: 'diff-script-h', class: 'text-[14px] font-semibold' }, t('diff.script.title')),
            h('p', { class: 'text-xs text-muted' }, t('diff.script.count', { n: fmtNumber(n), db: targetName }))),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: async () => { await copyText(scriptText()); toast.success(t('common.copied')); } }, icon('copy'), t('common.copy')),
          h('button', { type: 'button', class: 'btn btn-sm', onClick: () => downloadText(scriptFilename(r.source.db, r.target.db), scriptText(), 'application/sql;charset=utf-8') }, icon('download'), t('diff.script.download')),
          h('button', {
            type: 'button', class: 'btn btn-sm',
            onClick: () => {
              const tg = state.request.target;
              appStore.set({ pendingSql: { sql: scriptText(), db: tg.db, schema: tg.schema } });
              navigate(href.sql(tg.conn, { db: tg.db, schema: tg.schema }));
            },
          }, icon('terminal'), t('diff.script.openEditor')),
          applyBtn),
        ro ? callout('info', t('diff.targetReadOnly')) : null,
        ...warnings, ...notes,
        n ? pre : callout('info', t('diff.script.empty')),
        applied || null);
    }

    function applyDialog() {
      const r = state.result;
      const driver = r.driver;
      const targetName = r.target.db;
      let tx = driver === 'mysql' ? 'none' : 'atomic';
      const inputId = uid('dac');
      const radio = (value, label, help) => {
        const id = uid('dtx');
        return h('div', { class: 'flex items-start gap-2' },
          h('input', { id, type: 'radio', name: 'diff-tx', class: 'mt-1', value, checked: tx === value, onChange: () => { tx = value; } }),
          h('label', { for: id, class: 'text-[13px]' }, label, h('span', { class: 'block text-xs text-muted' }, help)));
      };
      const applyBtn = h('button', { type: 'button', class: 'btn btn-danger', disabled: true }, icon('play'), t('diff.apply.confirm'));
      const input = h('input', {
        id: inputId, class: 'input font-mono', autocomplete: 'off', spellcheck: 'false', dir: 'auto',
        onInput: () => { applyBtn.disabled = input.value !== targetName; },
        onKeydown: (e) => { if (e.key === 'Enter' && !applyBtn.disabled) applyBtn.click(); },
      });
      const destructive = r.changes.filter((c) => c.destructive && state.selected.has(c.id)).length;
      const m = openModal({
        title: t('diff.apply.title', { db: targetName }),
        description: t('diff.apply.description'),
        size: 'md',
        body: [
          destructive ? callout('danger', t('diff.apply.destructive', { n: fmtNumber(destructive) })) : null,
          h('fieldset', { class: 'mt-3 space-y-2' }, h('legend', { class: 'label' }, t('diff.apply.tx')),
            radio('atomic', t('diff.apply.atomic'), driver === 'mysql' ? t('diff.apply.atomicMysql') : t('diff.apply.atomicHelp')),
            radio('none', t('diff.apply.none'), t('diff.apply.noneHelp'))),
          h('div', { class: 'mt-4' }, h('label', { class: 'label', for: inputId }, t('modal.typeToConfirm', { text: targetName })), input),
        ].filter(Boolean),
        actions: [h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close() }, t('common.cancel')), applyBtn],
        initialFocus: input,
      });
      applyBtn.addEventListener('click', async () => {
        applyBtn.disabled = true;
        applyBtn.replaceChildren(icon('loader', 'spin'), t('diff.apply.running'));
        try {
          const res = await api('diff.apply', { ...state.request, conn: state.request.target.conn, selected: [...state.selected], hash: state.plan.hash, tx });
          m.close();
          invalidate(state.request.target.conn, state.request.target.db);
          showApplyResult(res);
        } catch (e) {
          m.close();
          if (e.code === 'stale') {
            drawScript(callout('warning', h('div', { class: 'space-y-2' }, h('p', {}, t('diff.apply.stale')),
              h('button', { type: 'button', class: 'btn btn-sm', onClick: () => compare() }, icon('refresh'), t('diff.compareAgain')))));
          } else {
            toastError(e);
          }
        }
      });
    }

    function showApplyResult(res) {
      const failed = res.failed;
      const okCount = res.results.filter((x) => !x.error).length;
      const head = failed
        ? callout('danger', res.rolled_back ? t('diff.apply.failedRolledBack') : t('diff.apply.failedPartial', { n: fmtNumber(okCount) }))
        : callout('success', t('diff.apply.done', { n: fmtNumber(okCount) }));
      const list = h('ol', { class: 'divide-y divide-border rounded-lg border border-border text-[12.5px]', 'aria-label': t('diff.apply.results') },
        res.results.map((x, i) => h('li', { class: 'flex items-start gap-2 px-3 py-1.5' },
          x.error ? icon('circle-x', 'mt-0.5 text-danger') : icon('circle-check', 'mt-0.5 text-success'),
          h('span', { class: 'sr-only' }, x.error ? t('diff.apply.statementFailed') : t('diff.apply.statementOk')),
          h('div', { class: 'min-w-0 flex-1' },
            h('div', { class: 'truncate font-mono', dir: 'ltr', title: x.sql }, `${i + 1}. ${String(x.sql).split('\n')[0]}`),
            x.error ? h('div', { class: 'mt-0.5 break-words text-danger', dir: 'auto' }, x.error) : null),
          h('span', { class: 'shrink-0 text-xs tabular-nums text-muted' }, `${fmtNumber(x.time_ms)} ms`))));
      const extra = (res.warnings || []).map((w) => callout('warning', w));
      drawScript(h('div', { class: 'space-y-2', role: 'status' }, head, ...extra, list,
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: () => compare() }, icon('refresh'), t('diff.compareAgain'))));
      announce(failed ? t('diff.apply.failedShort') : t('diff.apply.done', { n: fmtNumber(okCount) }));
      if (!failed) toast.success(t('diff.apply.done', { n: fmtNumber(okCount) }));
    }

    drawSetup();
    if (p.auto === '1') setTimeout(() => compare(), 0);
  },
};
