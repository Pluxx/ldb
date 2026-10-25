/**
 * Routine / trigger / event editor (#/c/{conn}/db/{db}[@schema]/{routine|trigger|event}[/{name}]).
 * One component for the three kinds: a kind-specific header form (name, parameters, return type,
 * characteristics / timing + events / schedule) next to the body in the SQL editor component.
 * Actions: Save (routine.save … preview → review dialog → execute), Execute (routines: parameter
 * dialog → result sets + OUT values via the shared result view), Copy as SQL, Open in SQL editor,
 * Drop (typed confirmation). Read-only connections: view only, functions can still be executed.
 * Query: ?new=procedure|function (routine) / ?new=1 (trigger, event), ?type=, ?sig= (PG overload),
 * ?table= (trigger: preset / disambiguation), ?returns=trigger (new PG trigger function).
 */
import { h, cls, uid, copyText, on, isMobile, setChildren } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openModal, confirmDialog } from '../ui/modal.js';
import { bindMenu } from '../ui/menu.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { skeleton, errorState, callout, spinner } from '../ui/feedback.js';
import { createSqlEditor } from '../ui/sqleditor.js';
import { createResultView } from '../ui/resultview.js';
import { t, plural, fmtDuration } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore } from '../store.js';
import { href, navigate, setLeaveGuard } from '../router.js';
import { getObjects, getOverview, getCompletions, getTableInfo, invalidate } from '../data.js';
import { loadMeta, createTypePicker } from './_table-editor.js';

/* ------------------------------------------------------------------ driver profiles */

const DRIVERS = {
  mysql: {
    modes: { PROCEDURE: ['IN', 'OUT', 'INOUT'], FUNCTION: ['IN'] }, defaults: false, paramNamesOptional: false,
    timings: ['BEFORE', 'AFTER'], events: ['INSERT', 'UPDATE', 'DELETE'], multiEvent: false, triggerBody: true,
  },
  pgsql: {
    modes: { PROCEDURE: ['IN', 'OUT', 'INOUT', 'VARIADIC'], FUNCTION: ['IN', 'OUT', 'INOUT', 'VARIADIC'] }, defaults: true, paramNamesOptional: true,
    timings: ['BEFORE', 'AFTER', 'INSTEAD OF'], events: ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'], multiEvent: true, triggerBody: false, levels: true, when: true, updateOf: true,
  },
  sqlsrv: {
    modes: { PROCEDURE: ['IN', 'OUT'], FUNCTION: ['IN'] }, defaults: true, paramNamesOptional: false,
    timings: ['AFTER', 'INSTEAD OF'], events: ['INSERT', 'UPDATE', 'DELETE'], multiEvent: true, triggerBody: true,
  },
  sqlite: {
    modes: { PROCEDURE: [], FUNCTION: [] }, defaults: false,
    timings: ['BEFORE', 'AFTER', 'INSTEAD OF'], events: ['INSERT', 'UPDATE', 'DELETE'], multiEvent: false, triggerBody: true, when: true, updateOf: true,
  },
};
const DATA_ACCESS = ['CONTAINS SQL', 'NO SQL', 'READS SQL DATA', 'MODIFIES SQL DATA'];
const VOLATILITY = ['VOLATILE', 'STABLE', 'IMMUTABLE'];
const PARALLEL = ['UNSAFE', 'RESTRICTED', 'SAFE'];
const PG_LANGS = ['plpgsql', 'sql'];
const INTERVAL_UNITS = ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR',
  'MINUTE_SECOND', 'HOUR_SECOND', 'HOUR_MINUTE', 'DAY_SECOND', 'DAY_MINUTE', 'DAY_HOUR', 'YEAR_MONTH'];
const EVENT_STATUS = ['ENABLE', 'DISABLE', 'DISABLE ON SLAVE'];
const INT_RX = /^(tiny|small|medium|big)?int(eger)?\b|^int[248]\b|^(small|big)?serial/i;
const NUM_RX = /^(decimal|numeric|float|double|real|money)/i;

let cleanup = [];

/** Blank definition for a new object. */
function blankDef(kind, driver, ctx) {
  const p = ctx.params || {};
  if (kind === 'trigger') {
    const d = { name: '', table: p.table || '', timing: DRIVERS[driver].timings[0], events: ['INSERT'], level: 'ROW', columns: [], when: '', body: '' };
    if (driver === 'mysql') d.body = 'BEGIN\n  \nEND';
    if (driver === 'sqlsrv') { d.timing = 'AFTER'; d.body = 'BEGIN\n  SET NOCOUNT ON;\n  \nEND'; }
    if (driver === 'pgsql') { d.function = ''; d.function_schema = ''; d.arguments = []; }
    return d;
  }
  if (kind === 'event') {
    return { name: '', schedule: { type: 'every', interval_value: '1', interval_field: 'DAY', starts: '', ends: '', at: '' }, preserve: false, status: 'ENABLE', comment: '', definer: '', body: 'BEGIN\n  \nEND' };
  }
  const type = String(p.new || p.type || 'procedure').toUpperCase() === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
  const d = { name: '', type, parameters: [], returns: '', body: '', comment: '' };
  if (driver === 'mysql') Object.assign(d, { deterministic: false, data_access: 'CONTAINS SQL', security: 'DEFINER', definer: '', body: type === 'FUNCTION' ? 'BEGIN\n  RETURN NULL;\nEND' : 'BEGIN\n  \nEND', returns: type === 'FUNCTION' ? 'INT' : '' });
  if (driver === 'pgsql') {
    Object.assign(d, { language: 'plpgsql', volatility: 'VOLATILE', strict: false, security: 'INVOKER', parallel: 'UNSAFE', config: [], definer: '', returns: type === 'FUNCTION' ? (p.returns === 'trigger' ? 'trigger' : 'integer') : '' });
    d.body = p.returns === 'trigger' ? 'BEGIN\n  RETURN NEW;\nEND;' : (type === 'FUNCTION' ? 'BEGIN\n  RETURN NULL;\nEND;' : 'BEGIN\n  \nEND;');
  }
  if (driver === 'sqlsrv') Object.assign(d, { execute_as: '', schemabinding: false, recompile: false, returns: type === 'FUNCTION' ? 'int' : '', body: type === 'FUNCTION' ? 'BEGIN\n  RETURN NULL;\nEND' : 'BEGIN\n  SET NOCOUNT ON;\n  \nEND' });
  return d;
}

/** "New procedure" / "New trigger" … */
export const newLabel = (kind, type) => t(kind === 'trigger' ? 'routines.newTrigger' : kind === 'event' ? 'routines.newEvent' : String(type).toUpperCase() === 'FUNCTION' ? 'routines.newFunction' : 'routines.newProcedure');

/** Datetime from the server ("2026-01-01 10:00:00") → datetime-local value and back. */
const toLocalInput = (v) => (v ? String(v).replace(' ', 'T').slice(0, 19) : '');
const fromLocalInput = (v) => (v ? String(v).replace('T', ' ') : '');

export default {
  title: (ctx) => ctx.object || newLabel(ctx.kind, ctx.params?.new || ctx.params?.type),

  async render(el, ctx) {
    const { conn, db, schema, caps } = ctx;
    const kind = ctx.kind;
    const isNew = !ctx.object;
    const ro = !!ctx.readOnly;
    const root = h('div', { class: 'flex min-h-0 flex-1 flex-col' }, skeleton({ rows: 8 }));
    el.replaceChildren(root);
    const capKey = { routine: 'routines', trigger: 'triggers', event: 'events' }[kind];
    if (!caps[capKey]) { root.replaceChildren(errorState(new Error(t('routines.unsupported')))); return; }

    let overview;
    let info = null;
    let meta = { types: {} };
    let objects = { tables: [], views: [], routines: [], triggers: [] };
    try {
      overview = await getOverview(conn);
      const driver0 = overview?.info?.driver || 'mysql';
      const loads = [
        loadMeta(conn, caps).catch(() => ({ types: {} })),
        getObjects(conn, db, schema).catch(() => objects),
      ];
      if (!isNew) {
        const base = { conn, db, schema, name: ctx.object };
        if (kind === 'routine') loads.push(api('routine.info', { ...base, type: ctx.params.type || undefined, ...(ctx.params.sig !== undefined ? { signature: ctx.params.sig } : {}) }));
        else if (kind === 'trigger') loads.push(api('trigger.info', { ...base, table: ctx.params.table || undefined }));
        else loads.push(api('event.info', base));
      }
      const res = await Promise.all(loads);
      [meta, objects] = res;
      info = res[2] || null;
      if (!DRIVERS[driver0]) throw new Error(t('routines.unsupported'));
    } catch (e) {
      root.replaceChildren(errorState(e, { onRetry: () => this.render(el, ctx) }));
      return;
    }
    const driver = overview.info.driver;
    const D = DRIVERS[driver];
    const def = info ? structuredClone(info) : blankDef(kind, driver, ctx);
    delete def.sql;
    const original = info ? { name: info.name, type: info.type, signature: info.signature, table: info.table } : null;
    const currentUser = overview?.info?.user || '';
    let dirty = false;
    const quote = driver === 'mysql' ? '`' : '"';

    // ----- leave guard
    const markDirty = () => {
      if (!dirty) { dirty = true; drawHeader(); }
    };
    setLeaveGuard(() => (!dirty ? true : confirmDialog({ title: t('routines.discardTitle'), message: t('routines.discardMessage'), danger: true, confirmLabel: t('routines.discard') })));
    const beforeUnload = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
    cleanup.push(on(window, 'beforeunload', beforeUnload));

    // ----- small form helpers
    const field = (label, control, help, extra) => {
      const id = control.id || uid('re');
      control.id = id;
      return h('div', { class: cls('min-w-0', extra) }, h('label', { class: 'label', for: id }, label), control, help ? h('p', { class: 'help' }, help) : null);
    };
    const input = (key, o = {}) => {
      const inp = h('input', {
        class: cls('input', o.mono && 'font-mono', o.small && 'input-sm'), value: def[key] ?? '', dir: o.mono ? 'ltr' : 'auto',
        autocomplete: 'off', spellcheck: 'false', placeholder: o.placeholder || '', type: o.type || 'text',
        onInput: () => { def[key] = inp.value; markDirty(); o.onInput?.(); },
      });
      return inp;
    };
    const select = (value, options, onChange, o = {}) => {
      const sel = h('select', { class: cls('select', o.small && 'input-sm'), 'aria-label': o.label, onChange: () => { onChange(sel.value); markDirty(); } },
        options.map((op) => (typeof op === 'string' ? { value: op, label: op } : op)).map((op) => h('option', { value: op.value, selected: String(op.value) === String(value ?? '') }, op.label)));
      return sel;
    };
    const checkbox = (key, label, help) => {
      const c = h('input', { type: 'checkbox', class: 'checkbox', checked: !!def[key], onChange: () => { def[key] = c.checked; markDirty(); } });
      return h('div', {}, h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, c, label), help ? h('p', { class: 'help' }, help) : null);
    };
    const section = (title, ...body) => h('section', { class: 'space-y-3' }, h('h2', { class: 'text-[13px] font-semibold text-muted' }, title), ...body);

    // ----- body editor
    const editor = createSqlEditor({
      value: def.body || '',
      quote,
      dialect: driver,
      ariaLabel: t('routines.body'),
      placeholder: t('routines.bodyPlaceholder'),
      className: 'min-h-0 flex-1',
      onChange: (v) => { def.body = v; markDirty(); },
      onFormat: async (sql) => (await api('sql.format', { sql })).sql,
      onRun: () => { if (kind === 'routine') openExecute(); },
    });
    if (ro) editor.textarea.readOnly = true;
    cleanup.push(() => editor.destroy());
    getCompletions(conn, db, schema).then((c) => editor.setCompletions(c)).catch(() => {});

    // ----- forms
    const form = h('div', { class: 'space-y-5' });
    const fieldset = h('fieldset', { class: 'min-w-0 space-y-5', disabled: ro }, form);

    function drawForm() {
      if (kind === 'routine') drawRoutineForm();
      else if (kind === 'trigger') drawTriggerForm();
      else drawEventForm();
    }

    // routine form
    function drawRoutineForm() {
      const nameIn = input('name', { mono: true, placeholder: t('routines.namePlaceholder') });
      nameIn.setAttribute('data-autofocus', '');
      const typeSel = select(def.type, [{ value: 'PROCEDURE', label: t('routines.type.procedure') }, { value: 'FUNCTION', label: t('routines.type.function') }], (v) => {
        def.type = v;
        const allowed = D.modes[v];
        def.parameters.forEach((p) => { if (!allowed.includes(p.mode)) p.mode = 'IN'; });
        drawForm();
      });
      const head = h('div', { class: 'grid gap-3 sm:grid-cols-2' }, field(t('routines.name'), nameIn), field(t('routines.typeLabel'), typeSel));
      const params = drawParams();
      const isFn = def.type === 'FUNCTION';
      let returns = null;
      if (isFn) {
        const picker = createTypePicker({ value: def.returns || '', groups: meta.types, label: t('routines.returns'), onChange: (v) => { if (v !== (def.returns || '')) { def.returns = v; markDirty(); } } });
        const help = driver === 'pgsql' ? t('routines.returnsHelpPg') : driver === 'sqlsrv' ? t('routines.returnsHelpSqlsrv') : null;
        returns = field(t('routines.returns'), picker.input, help);
      }
      const chars = [];
      if (driver === 'mysql') {
        chars.push(
          checkbox('deterministic', 'DETERMINISTIC', t('routines.deterministicHelp')),
          field(t('routines.dataAccess'), select(def.data_access, DATA_ACCESS, (v) => { def.data_access = v; })),
          field(t('routines.security'), select(def.security, [{ value: 'DEFINER', label: 'DEFINER' }, { value: 'INVOKER', label: 'INVOKER' }], (v) => { def.security = v; }), t('routines.securityHelp')),
          field(t('routines.definer'), input('definer', { mono: true, placeholder: currentUser || 'CURRENT_USER' }), t('routines.definerHelp')),
        );
      } else if (driver === 'pgsql') {
        const langs = [...new Set([...PG_LANGS, def.language].filter(Boolean))];
        chars.push(field(t('routines.language'), select(def.language, langs, (v) => { def.language = v; })));
        if (isFn) {
          chars.push(
            field(t('routines.volatility'), select(def.volatility, VOLATILITY, (v) => { def.volatility = v; }), t('routines.volatilityHelp')),
            field(t('routines.parallel'), select(def.parallel || 'UNSAFE', PARALLEL, (v) => { def.parallel = v; })),
            checkbox('strict', 'STRICT', t('routines.strictHelp')),
          );
        }
        const sec = h('input', { type: 'checkbox', class: 'checkbox', checked: def.security === 'DEFINER', onChange: () => { def.security = sec.checked ? 'DEFINER' : 'INVOKER'; markDirty(); } });
        chars.push(h('div', {}, h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, sec, 'SECURITY DEFINER'), h('p', { class: 'help' }, t('routines.securityHelp'))));
        chars.push(field(t('routines.owner'), input('definer', { mono: true, placeholder: currentUser })));
        const cfg = h('textarea', { class: 'textarea font-mono text-[12.5px]', rows: 2, dir: 'ltr', spellcheck: 'false', placeholder: 'search_path = public, pg_temp', onInput: () => { def.config = cfg.value.split('\n').map((x) => x.trim()).filter(Boolean); markDirty(); } });
        cfg.value = (def.config || []).join('\n');
        chars.push(field(t('routines.config'), cfg, t('routines.configHelp'), 'sm:col-span-2'));
      } else if (driver === 'sqlsrv') {
        chars.push(field('EXECUTE AS', select(def.execute_as || '', [{ value: '', label: 'CALLER' }, 'OWNER', 'SELF'], (v) => { def.execute_as = v; })));
        if (isFn) chars.push(checkbox('schemabinding', 'SCHEMABINDING'));
        else chars.push(checkbox('recompile', 'RECOMPILE'));
      }
      chars.push(field(t('routines.comment'), input('comment'), null, 'sm:col-span-2'));
      if (info?.overloads > 1) chars.unshift(h('div', { class: 'sm:col-span-2' }, callout('info', t('routines.overloadNote', { n: info.overloads, sig: `${info.name}(${info.signature})` }))));
      setChildren(form,
        head,
        section(t('routines.parameters'), params),
        returns,
        section(t('routines.characteristics'), h('div', { class: 'grid gap-3 sm:grid-cols-2' }, chars)));
    }

    function drawParams() {
      const modes = D.modes[def.type] || ['IN'];
      const list = h('div', { class: 'space-y-2' });
      const rows = def.parameters.map((p, i) => {
        const nameIn = h('input', { class: 'input input-sm font-mono', value: p.name || '', dir: 'ltr', spellcheck: 'false', autocomplete: 'off', 'aria-label': t('routines.param.nameN', { n: i + 1 }), placeholder: driver === 'sqlsrv' ? '@name' : t('routines.param.name'), onInput: () => { p.name = nameIn.value; markDirty(); } });
        const picker = createTypePicker({ value: p.type || '', groups: meta.types, small: true, label: t('routines.param.typeN', { n: i + 1 }), onChange: (v) => { if (v !== (p.type || '')) { p.type = v; markDirty(); } } });
        const modeSel = modes.length > 1 ? select(p.mode || 'IN', modes, (v) => { p.mode = v; }, { small: true, label: t('routines.param.modeN', { n: i + 1 }) }) : null;
        const defIn = D.defaults ? h('input', { class: 'input input-sm font-mono', value: p.default ?? '', dir: 'ltr', spellcheck: 'false', autocomplete: 'off', 'aria-label': t('routines.param.defaultN', { n: i + 1 }), placeholder: t('routines.param.default'), onInput: () => { p.default = defIn.value === '' ? null : defIn.value; markDirty(); } }) : null;
        const move = (d) => { const j = i + d; if (j < 0 || j >= def.parameters.length) return; [def.parameters[i], def.parameters[j]] = [def.parameters[j], def.parameters[i]]; markDirty(); drawForm(); };
        const iconBtn = (ic, label, fn, disabled) => h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': label, title: label, disabled, onClick: fn }, icon(ic));
        return h('div', { class: 'flex flex-wrap items-center gap-1.5 rounded-lg border border-border p-1.5 sm:flex-nowrap', role: 'group', 'aria-label': t('routines.param.groupN', { n: i + 1 }) },
          modeSel ? h('div', { class: 'w-24 shrink-0' }, modeSel) : null,
          h('div', { class: 'min-w-0 flex-1 basis-28' }, nameIn),
          h('div', { class: 'min-w-0 flex-1 basis-32' }, picker.input),
          defIn ? h('div', { class: 'min-w-0 flex-1 basis-24' }, defIn) : null,
          h('div', { class: 'flex shrink-0 items-center' },
            iconBtn('arrow-up', t('routines.param.up'), () => move(-1), i === 0),
            iconBtn('arrow-down', t('routines.param.down'), () => move(1), i === def.parameters.length - 1),
            iconBtn('x', t('routines.param.remove'), () => { def.parameters.splice(i, 1); markDirty(); drawForm(); })));
      });
      setChildren(list, rows.length ? rows : h('p', { class: 'text-[13px] text-muted' }, t('routines.param.none')));
      const add = h('button', { type: 'button', class: 'btn btn-sm', onClick: () => {
        def.parameters.push({ mode: 'IN', name: driver === 'sqlsrv' ? `@p${def.parameters.length + 1}` : `p${def.parameters.length + 1}`, type: driver === 'pgsql' ? 'integer' : driver === 'sqlsrv' ? 'int' : 'INT', default: null });
        markDirty();
        drawForm();
        const inputs = form.querySelectorAll('[role=group] input');
        inputs[inputs.length - (D.defaults ? 3 : 2)]?.focus();
      } }, icon('plus'), t('routines.param.add'));
      return h('div', { class: 'space-y-2' }, list, ro ? null : add);
    }

    // trigger form
    function drawTriggerForm() {
      const nameIn = input('name', { mono: true, placeholder: t('routines.namePlaceholder') });
      nameIn.setAttribute('data-autofocus', '');
      const tables = [...(objects.tables || []).filter((x) => x.type !== 'view'), ...(objects.views || []), ...(objects.tables || []).filter((x) => x.type === 'view')].map((x) => x.name);
      const uniq = [...new Set([def.table, ...tables].filter(Boolean))];
      const tableSel = select(def.table, [{ value: '', label: t('routines.trigger.chooseTable') }, ...uniq.map((n) => ({ value: n, label: n }))], (v) => { def.table = v; });
      const timingSel = select(def.timing, D.timings, (v) => { def.timing = v; });
      const evBox = h('div', { class: 'flex flex-wrap gap-x-4 gap-y-1', role: D.multiEvent ? 'group' : 'radiogroup', 'aria-label': t('routines.trigger.events') });
      const evName = uid('ev');
      D.events.forEach((ev) => {
        const c = h('input', {
          type: D.multiEvent ? 'checkbox' : 'radio', name: evName, class: 'checkbox', checked: (def.events || []).includes(ev),
          onChange: () => {
            if (D.multiEvent) def.events = D.events.filter((x) => (x === ev ? c.checked : def.events.includes(x)));
            else def.events = [ev];
            markDirty();
            drawForm();
          },
        });
        evBox.appendChild(h('label', { class: 'flex min-h-9 items-center gap-2 font-mono text-[13px]' }, c, ev));
      });
      const parts = [
        h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          field(t('routines.name'), nameIn),
          field(t('routines.trigger.table'), tableSel),
          field(t('routines.trigger.timing'), timingSel, driver === 'pgsql' || driver === 'sqlite' ? t('routines.trigger.insteadHelp') : null),
          h('div', {}, h('div', { class: 'label' }, t('routines.trigger.events')), evBox)),
      ];
      const more = [];
      if (D.levels) more.push(field(t('routines.trigger.level'), select(def.level || 'ROW', [{ value: 'ROW', label: 'FOR EACH ROW' }, { value: 'STATEMENT', label: 'FOR EACH STATEMENT' }], (v) => { def.level = v; })));
      if (D.updateOf && (def.events || []).includes('UPDATE')) {
        const cols = h('input', { class: 'input font-mono', dir: 'ltr', value: (def.columns || []).join(', '), placeholder: 'col1, col2', spellcheck: 'false', onInput: () => { def.columns = cols.value.split(',').map((x) => x.trim()).filter(Boolean); markDirty(); } });
        more.push(field(t('routines.trigger.updateOf'), cols, t('routines.trigger.updateOfHelp')));
        if (def.table) {
          getTableInfo(conn, db, schema, def.table).then((ti) => {
            const dl = h('datalist', { id: uid('cols') }, (ti.columns || []).map((c) => h('option', { value: c.name })));
            cols.setAttribute('list', dl.id);
            cols.after(dl);
          }).catch(() => {});
        }
      }
      if (D.when) {
        const w = h('input', { class: 'input font-mono', dir: 'ltr', value: def.when || '', spellcheck: 'false', placeholder: driver === 'pgsql' ? 'OLD.status IS DISTINCT FROM NEW.status' : 'NEW.qty > 0', onInput: () => { def.when = w.value; markDirty(); } });
        more.push(field(t('routines.trigger.when'), w, t('routines.trigger.whenHelp'), 'sm:col-span-2'));
      }
      if (driver === 'pgsql') {
        const fns = (objects.routines || []).filter((r) => r.type === 'FUNCTION' && /^trigger$/i.test(String(r.returns || ''))).map((r) => r.name);
        const opts = [...new Set([def.function, ...fns].filter(Boolean))];
        const fnSel = select(def.function, [{ value: '', label: t('routines.trigger.chooseFunction') }, ...opts.map((n) => ({ value: n, label: n }))], (v) => { def.function = v; def.function_schema = ''; drawForm(); });
        const args = h('input', { class: 'input font-mono', dir: 'ltr', value: (def.arguments || []).join(', '), placeholder: 'arg1, arg2', spellcheck: 'false', onInput: () => { def.arguments = args.value.split(',').map((x) => x.trim()).filter(Boolean); markDirty(); } });
        const links = h('div', { class: 'flex flex-wrap gap-2 pt-1' },
          def.function ? h('a', { class: 'btn btn-sm', href: href.dbObject(conn, db, def.function_schema || schema, 'routine', def.function, { type: 'function' }) }, icon('pencil'), t('routines.trigger.editFunction')) : null,
          ro ? null : h('a', { class: 'btn btn-sm', href: href.dbObject(conn, db, schema, 'routine', null, { new: 'function', returns: 'trigger' }) }, icon('plus'), t('routines.trigger.newFunction')));
        more.push(field(t('routines.trigger.function'), fnSel, t('routines.trigger.functionHelp')), field(t('routines.trigger.arguments'), args, t('routines.trigger.argumentsHelp')), h('div', { class: 'sm:col-span-2' }, links));
      }
      if (driver === 'mysql') {
        more.push(field(t('routines.definer'), input('definer', { mono: true, placeholder: currentUser || 'CURRENT_USER' }), t('routines.definerHelp')));
        const others = (objects.triggers || []).filter((x) => x.table === def.table && x.name !== (original?.name || def.name)).map((x) => x.name);
        if (others.length) {
          def.order = def.order || { kind: 'FOLLOWS', trigger: '' };
          more.push(h('div', { class: 'grid grid-cols-2 gap-2' },
            field(t('routines.trigger.order'), select(def.order.kind, ['FOLLOWS', 'PRECEDES'], (v) => { def.order.kind = v; })),
            field(t('routines.trigger.orderOf'), select(def.order.trigger, [{ value: '', label: '—' }, ...others.map((n) => ({ value: n, label: n }))], (v) => { def.order.trigger = v; }))));
        }
      }
      if (info?.constraint) parts.push(callout('warning', t('routines.trigger.constraint')));
      setChildren(form, ...parts, more.length ? section(t('routines.characteristics'), h('div', { class: 'grid gap-3 sm:grid-cols-2' }, more)) : null);
    }

    // event form
    function drawEventForm() {
      const s = def.schedule;
      const nameIn = input('name', { mono: true, placeholder: t('routines.namePlaceholder') });
      nameIn.setAttribute('data-autofocus', '');
      const typeName = uid('st');
      const radio = (v, label) => {
        const r = h('input', { type: 'radio', class: 'checkbox', name: typeName, checked: s.type === v, onChange: () => { s.type = v; markDirty(); drawForm(); } });
        return h('label', { class: 'flex min-h-9 items-center gap-2 text-[13px]' }, r, label);
      };
      const dt = (key, label) => {
        const inp = h('input', { type: 'datetime-local', step: '1', class: 'input font-mono', dir: 'ltr', value: toLocalInput(s[key]), onInput: () => { s[key] = fromLocalInput(inp.value); markDirty(); } });
        return field(label, inp);
      };
      const sched = [h('div', { class: 'flex flex-wrap gap-x-5', role: 'radiogroup', 'aria-label': t('routines.event.schedule') }, radio('at', t('routines.event.once')), radio('every', t('routines.event.every')))];
      if (s.type === 'at') {
        sched.push(h('div', { class: 'grid gap-3 sm:grid-cols-2' }, dt('at', t('routines.event.at'))));
      } else {
        const val = h('input', { class: 'input font-mono', dir: 'ltr', value: s.interval_value ?? '1', inputmode: /_/.test(s.interval_field || '') ? 'text' : 'numeric', onInput: () => { s.interval_value = val.value; markDirty(); } });
        sched.push(h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          field(t('routines.event.interval'), val, /_/.test(s.interval_field || '') ? t('routines.event.compoundHelp') : null),
          field(t('routines.event.unit'), select(s.interval_field || 'DAY', INTERVAL_UNITS, (v) => { s.interval_field = v; drawForm(); })),
          dt('starts', t('routines.event.starts')),
          dt('ends', t('routines.event.ends'))));
      }
      const status = String(def.status || 'ENABLE').replace('DISABLE ON REPLICA', 'DISABLE ON SLAVE');
      setChildren(form,
        h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          field(t('routines.name'), nameIn),
          field(t('routines.event.status'), select(status, EVENT_STATUS.map((x) => ({ value: x, label: x })), (v) => { def.status = v; }))),
        info?.scheduler && !/^(ON|1)$/i.test(info.scheduler) ? callout('warning', t('routines.event.schedulerOff', { value: info.scheduler })) : null,
        section(t('routines.event.schedule'), ...sched),
        section(t('routines.characteristics'), h('div', { class: 'grid gap-3 sm:grid-cols-2' },
          checkbox('preserve', 'ON COMPLETION PRESERVE', t('routines.event.preserveHelp')),
          field(t('routines.definer'), input('definer', { mono: true, placeholder: currentUser || 'CURRENT_USER' }), t('routines.definerHelp')),
          field(t('routines.comment'), input('comment'), null, 'sm:col-span-2'))));
    }

    // ----- definition sent to the server
    function definition() {
      const d = structuredClone(def);
      d.name = String(d.name || '').trim();
      if (kind === 'routine') {
        d.parameters = (d.parameters || []).map((p) => ({ ...p, name: String(p.name || '').trim(), type: String(p.type || '').trim() }));
        if (d.type !== 'FUNCTION') d.returns = null;
      }
      if (kind === 'trigger' && driver === 'mysql' && d.order && !d.order.trigger) delete d.order;
      if (kind === 'event') {
        const s = d.schedule;
        d.schedule = s.type === 'at' ? { type: 'at', at: s.at } : { type: 'every', interval_value: s.interval_value, interval_field: s.interval_field, starts: s.starts || null, ends: s.ends || null };
      }
      return d;
    }

    function clientError() {
      const d = definition();
      if (!d.name) return t('routines.err.name');
      if (kind === 'routine') {
        for (const [i, p] of d.parameters.entries()) {
          if (!p.name && !D.paramNamesOptional) return t('routines.err.paramName', { n: i + 1 });
          if (!p.type) return t('routines.err.paramType', { n: i + 1 });
        }
        if (d.type === 'FUNCTION' && !String(d.returns || '').trim() && !(driver === 'pgsql' && d.parameters.some((p) => p.mode === 'OUT' || p.mode === 'INOUT'))) return t('routines.err.returns');
      }
      if (kind === 'trigger') {
        if (!d.table) return t('routines.err.table');
        if (!(d.events || []).length) return t('routines.err.events');
        if (driver === 'pgsql' && !d.function) return t('routines.err.function');
      }
      if (D.triggerBody !== false || kind !== 'trigger') {
        if (!(kind === 'trigger' && driver === 'pgsql') && !String(d.body || '').trim()) return t('routines.err.body');
      }
      return null;
    }

    const base = { conn, db, schema };
    const saveAction = `${kind}.save`;
    const warningText = (w) => {
      const key = `routines.warn.${w}`;
      const txt = t(key);
      return txt === key ? w : txt;
    };

    async function save() {
      const err = clientError();
      if (err) { toast.error(err); return; }
      const params = { ...base, definition: definition(), original: original || undefined };
      let prev;
      try { prev = await api(saveAction, { ...params, preview: true }); } catch (e) { toastError(e, t('routines.saveFailed')); return; }
      const { reviewSqlDialog } = await import('./_table-editor.js');
      const res = await reviewSqlDialog({
        title: isNew ? t('routines.reviewCreate', { name: params.definition.name }) : t('routines.reviewSave', { name: params.definition.name }),
        sql: prev.sql || [],
        danger: (prev.warnings || []).length > 0 ? true : undefined,
        notes: (prev.warnings || []).map((w) => callout('warning', warningText(w))),
        confirmLabel: t('common.save'),
        execute: () => api(saveAction, params),
      });
      if (!res) return;
      dirty = false;
      toast.success(t('routines.saved', { name: params.definition.name }));
      invalidate(conn, db);
      const p = {};
      if (kind === 'routine') { p.type = params.definition.type.toLowerCase(); }
      if (kind === 'trigger' && driver === 'pgsql') p.table = params.definition.table;
      navigate(href.dbObject(conn, db, schema, kind, params.definition.name, p), { replace: true });
    }

    async function scriptText() {
      if (ro || !String(def.name || '').trim()) return info?.sql || '';
      try {
        const res = await api(saveAction, { ...base, definition: definition(), preview: true });
        return res.script || (res.sql || []).join(';\n\n');
      } catch (e) {
        if (info?.sql && !dirty) return info.sql;
        throw e;
      }
    }
    async function copyScript() {
      try { const s = await scriptText(); await copyText(s); toast.success(t('common.copied')); } catch (e) { toastError(e); }
    }
    async function openInEditor() {
      try {
        const s = await scriptText();
        appStore.set({ pendingSql: { sql: s, db, schema } });
        navigate(href.sql(conn, { db, schema }));
      } catch (e) { toastError(e); }
    }

    async function drop() {
      const name = info.name;
      const ok = await confirmDialog({
        title: t('routines.dropTitle', { name }), message: t('routines.dropMessage'), requireText: name, danger: true, confirmLabel: t('common.drop'),
      });
      if (!ok) return;
      try {
        await api(`${kind}.drop`, { ...base, name, type: info.type, ...(info.signature !== undefined ? { signature: info.signature } : {}), table: info.table || undefined });
        dirty = false;
        toast.success(t('routines.dropped', { name }));
        invalidate(conn, db);
        navigate(href.db(conn, db, schema));
      } catch (e) { toastError(e); }
    }

    // ----- execute
    const resultsBox = h('section', { class: 'flex min-h-0 flex-col border-t border-border bg-surface', hidden: true, 'aria-label': t('routines.exec.results') });
    let resultViews = [];
    const lastArgs = new Map();
    const canExecute = () => kind === 'routine' && !isNew && (!ro || info?.type === 'FUNCTION');

    function argControl(p, i) {
      const prev = lastArgs.get(i) || { value: '', null: false, default: p.default !== undefined && p.default !== null };
      const ty = String(p.type || '');
      const isBool = /^bool/i.test(ty) || /^bit$/i.test(ty);
      const valIn = isBool
        ? h('select', { class: 'select font-mono', 'aria-label': p.name || `#${i + 1}` }, ['', 'true', 'false'].map((v) => h('option', { value: v, selected: prev.value === v }, v || '—')))
        : h('input', { class: 'input font-mono', dir: 'ltr', value: prev.value, spellcheck: 'false', autocomplete: 'off', inputmode: INT_RX.test(ty) ? 'numeric' : NUM_RX.test(ty) ? 'decimal' : null, placeholder: ty, 'aria-label': p.name || `#${i + 1}` });
      const nullCb = h('input', { type: 'checkbox', class: 'checkbox', checked: prev.null });
      const hasDefault = p.default !== undefined && p.default !== null && D.defaults;
      const defCb = hasDefault ? h('input', { type: 'checkbox', class: 'checkbox', checked: prev.default }) : null;
      const sync = () => { valIn.disabled = nullCb.checked || !!defCb?.checked; nullCb.disabled = !!defCb?.checked; };
      nullCb.addEventListener('change', sync);
      defCb?.addEventListener('change', sync);
      sync();
      const id = uid('arg');
      valIn.id = id;
      const el2 = h('div', { class: 'space-y-1' },
        h('label', { class: 'label flex flex-wrap items-baseline gap-2', for: id },
          h('span', { class: 'font-mono', dir: 'ltr' }, p.name || `$${i + 1}`),
          h('span', { class: 'font-mono text-xs font-normal text-muted', dir: 'ltr' }, `${p.mode && p.mode !== 'IN' ? `${p.mode} ` : ''}${ty}`)),
        valIn,
        h('div', { class: 'flex flex-wrap gap-4' },
          h('label', { class: 'flex min-h-8 items-center gap-2 text-[13px]' }, nullCb, 'NULL'),
          defCb ? h('label', { class: 'flex min-h-8 items-center gap-2 text-[13px]' }, defCb, t('routines.exec.useDefault'), h('code', { class: 'text-xs text-muted', dir: 'ltr' }, p.default)) : null));
      return { el: el2, read: () => ({ value: nullCb.checked ? null : valIn.value, null: nullCb.checked, default: !!defCb?.checked }) };
    }

    async function openExecute() {
      if (!canExecute()) return;
      if (dirty) toast.info(t('routines.exec.savedVersion'));
      const params = info.parameters || [];
      const inputs = params.filter((p) => p.mode !== 'OUT');
      const controls = new Map();
      params.forEach((p, i) => { if (p.mode !== 'OUT') controls.set(i, argControl(p, i)); });
      const go = async () => {
        const args = params.map((p, i) => (controls.has(i) ? controls.get(i).read() : { value: null, null: true }));
        args.forEach((a, i) => lastArgs.set(i, a));
        m.close(args);
      };
      const runBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: go }, icon('play'), t('routines.exec.run'));
      const m = openModal({
        title: t('routines.exec.title', { name: info.name }),
        description: inputs.length ? t('routines.exec.description') : t('routines.exec.noParams'),
        size: 'md',
        body: h('form', { class: 'space-y-4', onSubmit: (e) => { e.preventDefault(); go(); } }, [...controls.values()].map((c) => c.el), h('button', { type: 'submit', hidden: true, tabindex: '-1' })),
        actions: [h('button', { type: 'button', class: 'btn btn-ghost', onClick: () => m.close(null) }, t('common.cancel')), runBtn],
        initialFocus: controls.size ? undefined : runBtn,
      });
      const args = await m.closed;
      if (!args) return;
      await execute(args);
    }

    async function execute(args) {
      resultsBox.hidden = false;
      resultViews.forEach((v) => v.destroy());
      resultViews = [];
      setChildren(resultsBox, h('div', { class: 'flex flex-1 items-center justify-center p-6' }, spinner({ label: t('routines.exec.running') })));
      const started = performance.now();
      try {
        const res = await api('routine.execute', { ...base, name: info.name, type: info.type, ...(info.signature !== undefined ? { signature: info.signature } : {}), args });
        drawResults(res, performance.now() - started);
      } catch (e) {
        setChildren(resultsBox, resultsHeader(null), h('div', { class: 'overflow-auto p-4' }, callout('danger', h('div', {},
          h('div', { class: 'font-medium', dir: 'auto' }, e.message),
          e.sql ? h('pre', { class: 'mt-2 overflow-auto font-mono text-xs', dir: 'ltr' }, e.sql) : null))));
        announce(e.message);
      }
    }

    function resultsHeader(res, ms) {
      return h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border px-3 py-1.5 text-xs' },
        h('h2', { class: 'font-semibold' }, t('routines.exec.results')),
        ms !== undefined ? h('span', { class: 'flex items-center gap-1 text-muted tabular-nums' }, icon('timer', 'size-3.5'), fmtDuration(ms)) : null,
        res ? h('code', { class: 'min-w-0 flex-1 truncate font-mono text-[11.5px] text-muted', dir: 'ltr', title: res.sql }, res.sql) : h('span', { class: 'flex-1' }),
        res ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => copyText(res.sql).then(() => toast.success(t('common.copied'))) }, icon('copy'), h('span', { class: 'max-sm:sr-only' }, t('routines.exec.copyCall'))) : null,
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('routines.exec.closeResults'), onClick: () => { resultsBox.hidden = true; resultViews.forEach((v) => v.destroy()); resultViews = []; } }, icon('x')));
    }

    function drawResults(res, ms) {
      const parts = [resultsHeader(res, ms)];
      if (res.out?.length) {
        parts.push(h('div', { class: 'border-b border-border px-3 py-2' },
          h('h3', { class: 'mb-1 text-xs font-semibold text-muted' }, t('routines.exec.outValues')),
          h('dl', { class: 'grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 text-[13px]' }, res.out.flatMap((o) => [
            h('dt', { class: 'font-mono text-muted', dir: 'ltr' }, o.name),
            h('dd', { class: 'min-w-0 truncate font-mono', dir: 'auto', 'data-out': o.name }, o.value === null ? h('span', { class: 'text-subtle' }, 'NULL') : typeof o.value === 'object' ? JSON.stringify(o.value) : String(o.value)),
          ]))));
      }
      const results = res.results || [];
      const body = h('div', { class: 'flex min-h-0 flex-1 flex-col overflow-auto' });
      results.forEach((q) => {
        const v = createResultView(q, { quote, ariaLabel: t('routines.exec.results'), setHeight: 'h-56' });
        resultViews.push(v);
        body.appendChild(q.is_result_set && !q.more_results?.length ? h('div', { class: 'flex h-56 shrink-0 flex-col' }, v.el) : v.el);
      });
      if (!results.length && !res.out?.length) body.appendChild(h('p', { class: 'p-4 text-[13px] text-muted' }, t('routines.exec.done')));
      parts.push(body);
      setChildren(resultsBox, ...parts);
      const sets = results.reduce((n, q) => n + (q.is_result_set ? 1 + (q.more_results?.length || 0) : 0), 0);
      announce(t('routines.exec.announce', { sets: plural('routines.exec.sets', sets), out: res.out?.length || 0 }));
    }

    // ----- body full-screen sheet (phones)
    const bodyHost = h('div', { class: 'flex min-h-0 flex-1 flex-col' }, editor.el);
    function bodyFullscreen() {
      const holder = h('div', { class: 'flex h-[calc(100dvh-8rem)] min-h-0 flex-col sm:h-[70dvh]' });
      holder.appendChild(editor.el);
      const m = openModal({ title: t('routines.body'), size: 'full', body: holder, actions: [h('button', { type: 'button', class: 'btn btn-primary', onClick: () => m.close() }, t('common.close'))] });
      editor.refresh();
      editor.focus();
      m.closed.then(() => { bodyHost.appendChild(editor.el); editor.refresh(); });
    }

    // ----- header / toolbar
    const headerBox = h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border bg-surface px-3 py-2 sm:px-4' });
    function drawHeader() {
      const kindLabel = kind === 'routine' ? (def.type === 'FUNCTION' ? t('routines.type.function') : t('routines.type.procedure')) : t(`routines.kind.${kind}`);
      const ic = kind === 'routine' ? 'function' : kind === 'trigger' ? 'zap' : 'calendar';
      const title = isNew ? newLabel(kind, def.type) : info.name;
      const btn = (ic2, label, fn, extra = '', o = {}) => h('button', { type: 'button', class: `btn btn-sm ${extra}`, onClick: fn, title: o.title || null, 'aria-label': o.iconOnly ? label : null }, icon(ic2), o.iconOnly ? null : h('span', { class: o.hideLabel ? 'max-md:sr-only' : '' }, label));
      const more = h('button', { type: 'button', class: 'btn btn-sm btn-icon', 'aria-label': t('common.more') }, icon('ellipsis'));
      bindMenu(more, () => [
        { label: t('routines.copySql'), icon: 'copy', onSelect: copyScript },
        { label: t('routines.openInEditor'), icon: 'external-link', onSelect: openInEditor },
        { label: t('routines.bodyFullscreen'), icon: 'maximize', onSelect: bodyFullscreen },
        ...(!isNew ? [{ label: t('table.tab.ddl'), icon: 'file-code', onSelect: () => navigate(href.table(conn, db, schema, info.name, 'ddl', { type: kind === 'routine' ? String(info.type).toLowerCase() : kind })) }] : []),
        ...(!isNew && !ro ? [{ separator: true }, { label: t('common.drop'), icon: 'trash', danger: true, onSelect: drop }] : []),
      ], { align: 'end' });
      setChildren(headerBox,
        h('h1', { class: 'flex min-w-0 items-center gap-2 text-[15px] font-semibold' }, icon(ic, 'text-muted'),
          h('span', { class: 'truncate font-mono', dir: 'auto' }, title),
          !isNew ? h('span', { class: 'badge' }, kindLabel) : null,
          dirty ? h('span', { class: 'badge badge-warning' }, t('routines.unsaved')) : null,
          ro ? h('span', { class: 'badge' }, icon('lock', 'size-3'), t('routines.readOnly')) : null),
        h('div', { class: 'flex-1' }),
        canExecute() ? btn('play', kind === 'routine' && def.type === 'FUNCTION' ? t('routines.execute') : t('routines.call'), openExecute, '', { hideLabel: true }) : null,
        btn('copy', t('routines.copySql'), copyScript, 'max-lg:hidden'),
        btn('external-link', t('routines.openInEditor'), openInEditor, 'max-lg:hidden'),
        !isNew && !ro ? btn('trash', t('common.drop'), drop, 'btn-ghost text-danger max-lg:hidden') : null,
        more,
        ro ? null : h('button', { type: 'button', class: 'btn btn-primary btn-sm', onClick: save, 'aria-keyshortcuts': 'Control+S Meta+S' }, icon('save'), t('common.save')));
    }

    // keyboard: Ctrl/Cmd+S saves
    cleanup.push(on(window, 'keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S') && !ro) { e.preventDefault(); save(); }
    }));

    // ----- layout
    const bodyLabel = kind === 'trigger' && driver === 'pgsql' ? null : h('div', { class: 'flex items-center gap-2 border-b border-border bg-surface-2/60 px-3 py-1.5' },
      h('span', { class: 'text-xs font-semibold text-muted' }, t('routines.body')),
      h('span', { class: 'min-w-0 flex-1 truncate text-xs text-subtle' }, kind === 'trigger' && driver === 'sqlite' ? t('routines.bodyHelpSqlite') : driver === 'pgsql' ? t('routines.bodyHelpPg') : t('routines.bodyHelp')),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': t('routines.bodyFullscreen'), title: t('routines.bodyFullscreen'), onClick: bodyFullscreen }, icon('maximize')));
    const formPane = h('div', { class: 'min-h-0 overflow-auto border-border p-3 sm:p-4 lg:w-[30rem] lg:shrink-0 lg:border-e xl:w-[34rem]' }, fieldset);
    let bodyPane;
    if (kind === 'trigger' && driver === 'pgsql') {
      bodyPane = h('div', { class: 'flex min-h-0 flex-1 flex-col p-4' }, callout('info', t('routines.trigger.pgBody')));
      editor.el.hidden = true;
    } else {
      bodyPane = h('div', { class: 'flex min-h-[55dvh] flex-1 flex-col lg:min-h-0' }, bodyLabel, bodyHost);
    }
    const mainCol = h('div', { class: 'flex min-h-0 min-w-0 flex-1 flex-col' }, bodyPane, resultsBox);
    resultsBox.classList.add('max-h-[60%]');
    root.className = 'flex min-h-0 flex-1 flex-col';
    root.replaceChildren(headerBox, h('div', { class: 'flex min-h-0 flex-1 flex-col overflow-auto lg:flex-row lg:overflow-hidden' }, formPane, mainCol));
    drawHeader();
    drawForm();
    if (isNew) form.querySelector('[data-autofocus]')?.focus();
    if (isMobile()) editor.refresh();
  },

  destroy() {
    cleanup.forEach((f) => { try { f(); } catch { /* ignore */ } });
    cleanup = [];
  },
};
