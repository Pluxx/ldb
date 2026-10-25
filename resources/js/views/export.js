/**
 * Export view. Sources: a whole database, selected tables (checklist with search, select
 * all / none, row counts) or a SQL query. Format cards come from `export.formats`; their
 * options are rendered by a generic bool/select/text form. The download is a form POST to
 * `export.download` aimed at a hidden iframe, so the SPA stays loaded (an error response is
 * rendered into the iframe and reported as a toast).
 *
 * Route params: ?db=&schema= (source), ?tables=a,b (preselection), ?table=x (single table),
 * ?query=<sql> (export a query result, e.g. from the SQL editor), ?format=.
 * Last used options are remembered per format in localStorage (`export.opts.<format>`).
 */
import { h, cls, debounce, setChildren, uid, downloadText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, emptyState, callout } from '../ui/feedback.js';
import { t, plural, fmtNumber } from '../i18n/index.js';
import { api, getCsrf, usingMock } from '../api.js';
import { storage } from '../store.js';
import { href } from '../router.js';
import { getDatabases, getObjects, getSchemas } from '../data.js';

const FRAME = 'oph-export-frame';
const DELIMITER_LABEL = { ',': 'export.choice.comma', ';': 'export.choice.semicolon', '\t': 'export.choice.tab', '|': 'export.choice.pipe' };

/** Translated option label, falling back to the server's English label. */
function optLabel(format, o) {
  const key = `export.opt.${format}.${o.name}`;
  const s = t(key);
  return s === key ? o.label : s;
}

/** {db}[_{table}]_{Ymd_His} — mirrors ExportController::fileBase(). */
function fileName(db, single, ext, compression) {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  const stamp = `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
  const base = [db || 'export', single, stamp].filter(Boolean).join('_').replace(/[\x00-\x1F\x7F/\\:*?"<>|]+/g, '_');
  if (compression === 'zip') return `${base}.zip`;
  return `${base}.${ext}${compression === 'gzip' ? '.gz' : ''}`;
}

/**
 * Submit a hidden form POST into a hidden iframe (downloads keep the SPA; an error page
 * fires the iframe's load event, which successful attachments do not).
 */
function postDownload(fields, onError) {
  let frame = document.getElementById(FRAME);
  if (!frame) {
    frame = h('iframe', { id: FRAME, name: FRAME, hidden: true, tabindex: '-1', 'aria-hidden': 'true', title: '' });
    document.body.appendChild(frame);
  }
  frame.onload = () => {
    let msg = '';
    try {
      const txt = frame.contentDocument?.body?.textContent || '';
      msg = JSON.parse(txt)?.error?.message || '';
    } catch { msg = ''; }
    onError(msg);
  };
  const form = h('form', { method: 'POST', action: 'api.php?action=export.download', target: FRAME, hidden: true });
  const add = (name, value) => form.appendChild(h('input', { type: 'hidden', name, value: String(value) }));
  add('csrf', getCsrf() || '');
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) v.forEach((x) => add(`${k}[]`, x));
    else if (v !== undefined && v !== null) add(k, v);
  }
  document.body.appendChild(form);
  form.submit();
  form.remove();
}

export default {
  title: () => t('nav.export'),

  async render(el, ctx) {
    const { conn, caps } = ctx;
    const params = ctx.params || {};
    const body = h('div', { class: 'mx-auto w-full max-w-5xl space-y-5 p-4 sm:p-6' }, skeleton({ rows: 8 }));
    const footer = h('div', { class: 'flex flex-wrap items-center gap-3 border-t border-border bg-surface px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-6' });
    el.replaceChildren(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, body), footer);

    let meta;
    let dbs;
    try {
      [meta, dbs] = await Promise.all([api('export.formats', { conn }), getDatabases(conn)]);
    } catch (e) {
      setChildren(body, errorState(e, { onRetry: () => this.render(el, ctx) }));
      footer.hidden = true;
      return;
    }
    const formats = meta.formats || [];
    const compressions = (meta.compression || ['none']).map((c) => (typeof c === 'string' ? c : c.id));

    const preTables = (params.tables || params.table || '').split(',').map((s) => s.trim()).filter(Boolean);
    const state = {
      db: params.db || ctx.db || '',
      schema: params.schema || ctx.schema || '',
      mode: params.query ? 'query' : preTables.length ? 'tables' : 'database',
      selected: new Set(preTables),
      query: params.query || '',
      format: formats.some((f) => f.id === params.format) ? params.format : (formats.some((f) => f.id === storage.get('export.format')) ? storage.get('export.format') : formats[0]?.id),
      compression: compressions.includes(storage.get('export.compression')) ? storage.get('export.compression') : 'none',
      options: {},
      objects: null,
      filter: '',
    };
    if (!state.db && dbs.length === 1) state.db = dbs[0].name;

    const fmt = () => formats.find((f) => f.id === state.format);
    const loadOptions = () => {
      const f = fmt();
      const saved = storage.get(`export.opts.${state.format}`, {}) || {};
      state.options = {};
      for (const o of f?.options || []) {
        const v = saved[o.name];
        const valid = v !== undefined && (o.type !== 'select' || (o.choices || []).some((c) => c.value === v));
        state.options[o.name] = valid ? v : o.default;
      }
    };
    const saveOptions = () => {
      storage.set(`export.opts.${state.format}`, state.options);
      storage.set('export.format', state.format);
      storage.set('export.compression', state.compression);
    };
    loadOptions();

    /* -------------------------------------------------------------- source */
    const sourceBox = h('div', { class: 'space-y-3' });
    const tablesBox = h('div');
    const allObjects = () => {
      if (!state.objects) return [];
      const seen = new Set();
      return [...(state.objects.tables || []), ...(state.objects.views || []).map((v) => ({ ...v, type: 'view' }))]
        .filter((x) => (seen.has(x.name) ? false : seen.add(x.name)));
    };
    const chosenTables = () => (state.mode === 'tables' ? allObjects().filter((x) => state.selected.has(x.name)).map((x) => x.name) : state.mode === 'database' ? allObjects().map((x) => x.name) : []);

    async function loadObjects() {
      state.objects = null;
      if (!state.db) { drawTables(); refresh(); return; }
      tablesBox.replaceChildren(skeleton({ rows: 4 }));
      try {
        if (caps.schemas && !state.schema) {
          const schemas = await getSchemas(conn, state.db).catch(() => []);
          state.schema = schemas.includes('public') ? 'public' : (schemas[0] || '');
          drawSource();
        }
        state.objects = await getObjects(conn, state.db, state.schema || undefined);
        const names = new Set(allObjects().map((x) => x.name));
        for (const n of [...state.selected]) if (!names.has(n)) state.selected.delete(n);
      } catch (e) {
        tablesBox.replaceChildren(errorState(e, { onRetry: loadObjects }));
        return;
      }
      drawTables();
      refresh();
    }

    function segmented(label, items, value, onPick) {
      return h('div', { class: 'segmented max-w-full flex-wrap', role: 'radiogroup', 'aria-label': label },
        items.map((it) => h('button', {
          type: 'button', role: 'radio', 'aria-checked': String(it.id === value),
          onClick: () => onPick(it.id),
        }, it.icon ? icon(it.icon) : null, it.label)));
    }

    function drawSource() {
      const dbId = uid('exdb');
      const scId = uid('exsc');
      const dbSel = h('select', { id: dbId, class: 'select', onChange: () => { state.db = dbSel.value; state.schema = ''; state.selected.clear(); drawSource(); loadObjects(); } },
        h('option', { value: '' }, t('export.chooseDb')),
        dbs.map((d) => h('option', { value: d.name, selected: d.name === state.db }, d.name)));
      let schemaField = null;
      if (caps.schemas && state.db) {
        const scSel = h('select', { id: scId, class: 'select', onChange: () => { state.schema = scSel.value; state.selected.clear(); loadObjects(); } });
        getSchemas(conn, state.db).then((list) => {
          setChildren(scSel, list.map((s) => h('option', { value: s, selected: s === state.schema }, s)));
        }).catch(() => {});
        schemaField = h('div', { class: 'min-w-0 flex-1' }, h('label', { class: 'label', for: scId }, t('export.schema')), scSel);
      }
      const modes = [
        { id: 'database', label: t('export.mode.database'), icon: 'database' },
        { id: 'tables', label: t('export.mode.tables'), icon: 'table' },
        { id: 'query', label: t('export.mode.query'), icon: 'terminal' },
      ];
      const queryArea = h('textarea', {
        class: 'textarea font-mono text-[13px]', rows: 5, dir: 'ltr', spellcheck: 'false', placeholder: 'SELECT …', 'aria-label': t('export.mode.query'),
        onInput: debounce(() => { state.query = queryArea.value; refresh(); }, 150),
      });
      queryArea.value = state.query;
      setChildren(sourceBox,
        h('div', { class: 'flex flex-wrap gap-3' },
          h('div', { class: 'min-w-0 flex-1 sm:max-w-sm' }, h('label', { class: 'label', for: dbId }, t('export.database')), dbSel),
          schemaField),
        segmented(t('export.source'), modes, state.mode, (id) => { state.mode = id; drawSource(); drawTables(); refresh(); }),
        state.mode === 'query' ? h('div', {}, queryArea, h('p', { class: 'help' }, t('export.queryHelp'))) : null,
        state.mode !== 'query' ? tablesBox : null);
    }

    function drawTables() {
      if (state.mode === 'query') return;
      if (!state.db) { tablesBox.replaceChildren(callout('info', t('export.chooseDbHint'))); return; }
      if (!state.objects) return;
      const items = allObjects();
      if (state.mode === 'database') {
        tablesBox.replaceChildren(h('p', { class: 'text-[13px] text-muted' }, plural('export.allTables', items.length)));
        return;
      }
      if (!items.length) { tablesBox.replaceChildren(emptyState({ icon: 'table', title: t('db.noTables') })); return; }
      const visible = items.filter((x) => !state.filter || x.name.toLowerCase().includes(state.filter));
      const list = h('ul', { class: 'max-h-80 divide-y divide-border overflow-auto', role: 'list' },
        visible.map((x) => {
          const id = uid('ext');
          return h('li', {},
            h('label', { for: id, class: 'flex min-h-10 cursor-pointer items-center gap-3 px-3 py-1.5 text-[13px] hover:bg-surface-2' },
              h('input', {
                id, type: 'checkbox', class: 'checkbox', checked: state.selected.has(x.name),
                onChange: (e) => { if (e.target.checked) state.selected.add(x.name); else state.selected.delete(x.name); updateCount(); refresh(); },
              }),
              icon(x.type === 'view' ? 'eye' : 'table', 'text-muted'),
              h('span', { class: 'min-w-0 flex-1 truncate', dir: 'auto' }, x.name),
              x.type === 'view' ? h('span', { class: 'badge' }, t('db.view')) : null,
              h('span', { class: 'shrink-0 text-xs text-muted tabular-nums' }, x.rows === null || x.rows === undefined ? '' : plural('export.rows', Number(x.rows)))));
        }));
      if (!visible.length) list.replaceChildren(h('li', { class: 'px-3 py-4 text-center text-[13px] text-muted' }, t('sidebar.noMatches')));
      const count = h('span', { class: 'me-auto text-[12.5px] text-muted', 'aria-live': 'polite' });
      const updateCount = () => { count.textContent = t('export.selectedOf', { n: fmtNumber(state.selected.size), total: fmtNumber(items.length) }); };
      updateCount();
      const search = h('input', {
        type: 'search', class: 'input input-sm w-full sm:w-56', placeholder: t('db.filter'), 'aria-label': t('db.filter'), value: state.filter,
        onInput: debounce(() => { state.filter = search.value.trim().toLowerCase(); drawTables(); tablesBox.querySelector('input[type=search]')?.focus(); }, 120),
      });
      const setAll = (on) => { visible.forEach((x) => (on ? state.selected.add(x.name) : state.selected.delete(x.name))); drawTables(); refresh(); };
      tablesBox.replaceChildren(h('div', { class: 'card overflow-hidden' },
        h('div', { class: 'flex flex-wrap items-center gap-2 border-b border-border bg-surface-2/40 px-3 py-2' },
          count,
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => setAll(true) }, t('export.selectAll')),
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => setAll(false) }, t('export.selectNone')),
          search),
        list));
    }

    /* -------------------------------------------------------------- format + options */
    const formatBox = h('div', { class: 'grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6', role: 'radiogroup', 'aria-label': t('export.format') });
    const optionsBox = h('div', { class: 'grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2' });
    const compressionBox = h('div');

    function drawFormats() {
      setChildren(formatBox, formats.map((f) => {
        const on = f.id === state.format;
        return h('button', {
          type: 'button', role: 'radio', 'aria-checked': String(on),
          class: cls('card flex min-h-14 flex-col items-start gap-0.5 px-3 py-2 text-start transition-colors', on ? 'border-accent bg-accent-soft ring-1 ring-accent' : 'hover:border-border-strong'),
          onClick: () => { if (state.format === f.id) return; saveOptions(); state.format = f.id; loadOptions(); drawFormats(); drawOptions(); refresh(); },
        },
        h('span', { class: 'text-[13px] font-medium' }, t(`export.format.${f.id}`) === `export.format.${f.id}` ? f.label : t(`export.format.${f.id}`)),
        h('span', { class: 'font-mono text-[11px] text-muted', dir: 'ltr' }, `.${f.extension}`));
      }));
    }

    function drawOptions() {
      const f = fmt();
      const fields = (f?.options || []).map((o) => {
        const id = uid('exo');
        const val = state.options[o.name];
        const set = (v) => { state.options[o.name] = v; saveOptions(); refresh(); };
        if (o.type === 'bool') {
          return h('label', { for: id, class: 'flex min-h-9 items-center gap-2 text-[13px]' },
            h('input', { id, type: 'checkbox', class: 'checkbox', checked: !!val, onChange: (e) => set(e.target.checked) }), optLabel(f.id, o));
        }
        if (o.type === 'select') {
          return h('div', {}, h('label', { class: 'label', for: id }, optLabel(f.id, o)),
            h('select', { id, class: 'select', onChange: (e) => set(e.target.value) },
              (o.choices || []).map((c) => h('option', { value: c.value, selected: c.value === val }, o.name === 'delimiter' && DELIMITER_LABEL[c.value] ? t(DELIMITER_LABEL[c.value]) : c.label))));
        }
        return h('div', {}, h('label', { class: 'label', for: id }, optLabel(f.id, o)),
          h('input', { id, class: 'input font-mono', dir: 'ltr', spellcheck: 'false', value: val ?? '', onInput: (e) => set(e.target.value) }));
      });
      setChildren(optionsBox, fields.length ? fields : h('p', { class: 'text-[13px] text-muted' }, t('export.noOptions')));
      const reset = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { storage.remove(`export.opts.${state.format}`); loadOptions(); drawOptions(); refresh(); } }, icon('refresh'), t('export.resetOptions'));
      optionsBox.appendChild(h('div', { class: 'sm:col-span-2' }, reset));
    }

    function drawCompression() {
      setChildren(compressionBox, segmented(t('export.compression'), compressions.map((c) => ({ id: c, label: t(`export.compression.${c}`) })), state.compression,
        (id) => { state.compression = id; saveOptions(); drawCompression(); refresh(); }));
    }

    /* -------------------------------------------------------------- summary + submit */
    const fileLabel = h('code', { class: 'min-w-0 truncate font-mono text-[12.5px]', dir: 'ltr' });
    const warnBox = h('div');
    const exportBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => submit() }, icon('download'), t('export.submit'));
    // button on the start side: toasts appear in the bottom-end corner and would cover it
    setChildren(footer,
      exportBtn,
      h('div', { class: 'flex min-w-0 flex-1 items-center gap-2 text-muted' }, icon('file-down', 'shrink-0'), h('span', { class: 'sr-only' }, t('export.filename')), fileLabel));

    function refresh() {
      const f = fmt();
      const tables = chosenTables();
      const single = state.mode === 'query' ? 'query' : tables.length === 1 ? tables[0] : null;
      fileLabel.textContent = f ? fileName(state.db, single, f.extension, state.compression) : '';
      fileLabel.title = fileLabel.textContent;
      let reason = null;
      if (!f) reason = t('export.noFormat');
      else if (state.mode === 'query' && !state.query.trim()) reason = t('export.needQuery');
      else if (state.mode !== 'query' && !state.db) reason = t('export.chooseDbHint');
      else if (state.mode !== 'query' && state.objects && !tables.length) reason = t('export.needTables');
      exportBtn.disabled = !!reason || (state.mode !== 'query' && !state.objects);
      exportBtn.title = reason || '';
      const multiCsv = f?.id === 'csv' && state.mode !== 'query' && tables.length > 1;
      setChildren(warnBox, multiCsv && !state.options.multi ? callout('info', t('export.csvMulti')) : null);
    }

    async function submit() {
      const f = fmt();
      if (!f || exportBtn.disabled) return;
      if (state.mode === 'query' && caps.explain !== false) {
        // errors after the download started would only truncate the file: validate the query first
        exportBtn.disabled = true;
        try {
          await api('sql.explain', { conn, db: state.db || undefined, schema: state.schema || undefined, sql: state.query });
        } catch (e) {
          if (e.code === 'db_error' || e.code === 'bad_request') { toastError(e, t('export.failed')); refresh(); return; }
        }
        refresh();
      }
      saveOptions();
      const options = { ...state.options, compression: state.compression };
      let tables = chosenTables();
      if (state.mode === 'query') { options.query = state.query; tables = []; }
      if (f.id === 'csv' && tables.length > 1) options.multi = true;
      const fields = { conn, db: state.db || undefined, schema: state.schema || undefined, format: f.id, options: JSON.stringify(options) };
      // whole database: no table list, the server exports every table (also ones created meanwhile)
      if (state.mode === 'tables') fields.tables = JSON.stringify(tables);
      const name = fileLabel.textContent;
      if (usingMock()) {
        api('export.download', { ...fields, tables })
          .then((res) => { downloadText(res.filename || name, res.content || '', res.mime); toast.success(t('export.started', { name })); })
          .catch((e) => toastError(e, t('export.failed')));
        return;
      }
      let started = null;
      postDownload(fields, (msg) => { started?.close(); toast.error(t('export.failed'), { detail: msg || undefined }); });
      started = toast.success(t('export.started', { name }), { detail: t('export.startedHint') });
    }

    /* -------------------------------------------------------------- layout */
    const section = (title, ...children) => h('section', { class: 'space-y-3' }, h('h2', { class: 'text-[14px] font-semibold' }, title), ...children);
    setChildren(body,
      h('div', { class: 'flex flex-wrap items-end justify-between gap-3' },
        h('div', { class: 'min-w-0' },
          h('h1', { class: 'flex items-center gap-2 text-xl font-semibold tracking-tight' }, icon('download', 'size-5 text-muted'), t('nav.export')),
          h('p', { class: 'mt-0.5 text-[13px] text-muted' }, t('export.subtitle'))),
        h('a', { href: href.import(conn, { db: state.db || undefined, schema: state.schema || undefined }), class: 'btn btn-sm' }, icon('upload'), t('nav.import'))),
      section(t('export.source'), sourceBox),
      section(t('export.format'), formatBox),
      section(t('export.options'), h('div', { class: 'card space-y-4 p-4' }, optionsBox, warnBox)),
      section(t('export.compression'), compressionBox));

    drawSource();
    drawFormats();
    drawOptions();
    drawCompression();
    refresh();
    await loadObjects();
  },
};
