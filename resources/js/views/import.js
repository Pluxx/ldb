/**
 * Import view: drag & drop / file picker, size check against the server limit (`import.options`),
 * format auto-detection by extension, SQL options (stop on error, resume from statement) and
 * CSV options (target table, delimiter auto-detect, enclosure, header row, encoding, NULL
 * handling, truncate first) with a client-side preview of the first rows decoded through
 * TextDecoder in the chosen encoding and a file column → table column mapping.
 * Uploads through XMLHttpRequest (api.uploadForm) for progress; shows the result (counts, time,
 * errors) and offers "resume" after an aborted SQL import. Route params: ?db=&schema=&table=.
 */
import { h, cls, setChildren, uid } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { formDialog } from '../ui/modal.js';
import { toast, toastError } from '../ui/toast.js';
import { skeleton, errorState, callout, readOnlyState } from '../ui/feedback.js';
import { t, plural, fmtNumber, fmtBytes, fmtDuration, locale } from '../i18n/index.js';
import { api, uploadForm } from '../api.js';
import { href } from '../router.js';
import { getDatabases, getObjects, getSchemas, getTableInfo, invalidate } from '../data.js';
import { highlightSql } from '../sql/highlight.js';

const ACCEPT = '.sql,.gz,.zip,.bz2,.csv,.tsv,.txt';
const PREVIEW_BYTES = 256 * 1024;
const PREVIEW_ROWS = 10;
const DELIMS = [',', ';', '\t', '|'];

/** Backend encoding id → WHATWG TextDecoder label. */
const DECODER = {
  'UTF-8': 'utf-8', 'UTF-16': 'utf-16le', 'UTF-16LE': 'utf-16le', 'UTF-16BE': 'utf-16be',
  'SJIS-win': 'shift_jis', 'eucJP-win': 'euc-jp', GB18030: 'gb18030', 'BIG-5': 'big5',
  'EUC-KR': 'euc-kr', UHC: 'euc-kr', 'ISO-8859-1': 'windows-1252', 'ISO-8859-9': 'windows-1254', 'ISO-8859-11': 'windows-874',
};
const decoderLabel = (id) => DECODER[id] || String(id).toLowerCase();

/** Encodings to try (in order) when a file is not valid UTF-8, by UI locale. */
const GUESS = {
  ja: ['SJIS-win', 'eucJP-win'], 'zh-CN': ['GB18030'], 'zh-TW': ['BIG-5'], ko: ['UHC'], ru: ['Windows-1251', 'KOI8-R'],
  vi: ['Windows-1258'], ar: ['Windows-1256'], he: ['Windows-1255'],
};

function decode(bytes, enc, fatal = false) {
  try { return new TextDecoder(decoderLabel(enc), { fatal }).decode(bytes); } catch { return null; }
}

/** Detect format from a file name. */
function detectFormat(name) {
  const n = name.toLowerCase().replace(/\.(gz|zip|bz2)$/, '');
  return /\.(csv|tsv|txt)$/.test(n) ? 'csv' : 'sql';
}
const isCompressed = (name) => /\.(gz|zip|bz2)$/i.test(name);

/** Count delimiter candidates outside quotes over the first lines and pick the most consistent one. */
function detectDelimiter(text, enclosure = '"') {
  const lines = [];
  let cur = '';
  let q = false;
  for (let i = 0; i < text.length && lines.length < 20; i++) {
    const c = text[i];
    if (c === enclosure) q = !q;
    if (!q && (c === '\n' || c === '\r')) { if (cur) lines.push(cur); cur = ''; continue; }
    cur += c;
  }
  if (cur && lines.length < 2) lines.push(cur);
  let best = ',';
  let bestScore = 0;
  for (const d of DELIMS) {
    const counts = lines.map((l) => {
      let n = 0;
      let inq = false;
      for (const c of l) { if (c === enclosure) inq = !inq; else if (!inq && c === d) n++; }
      return n;
    });
    if (!counts.length || !counts[0]) continue;
    const consistent = counts.filter((n) => n === counts[0]).length / counts.length;
    const score = consistent * 100 + counts[0];
    if (score > bestScore) { bestScore = score; best = d; }
  }
  return best;
}

/** Minimal RFC 4180 parser for the preview; drops a trailing incomplete record when `partial`. */
function parseCsv(text, delim, enclosure, maxRecords, partial) {
  const out = [];
  let row = [];
  let field = '';
  let q = false;
  let i = 0;
  const enc = enclosure || '"';
  for (; i < text.length && out.length < maxRecords; i++) {
    const c = text[i];
    if (q) {
      if (c === enc) {
        if (text[i + 1] === enc) { field += enc; i++; } else q = false;
      } else field += c;
    } else if (c === enc && field === '') q = true;
    else if (c === delim) { row.push(field); field = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field); field = '';
      if (!(row.length === 1 && row[0] === '')) out.push(row);
      row = [];
    } else field += c;
  }
  if (out.length < maxRecords && !partial && (field !== '' || row.length)) { row.push(field); out.push(row); }
  return out;
}

export default {
  title: () => t('nav.import'),

  async render(el, ctx) {
    const { conn, caps } = ctx;
    const params = ctx.params || {};
    const body = h('div', { class: 'mx-auto w-full max-w-5xl space-y-5 p-4 sm:p-6' }, skeleton({ rows: 8 }));
    el.replaceChildren(h('div', { class: 'min-h-0 flex-1 overflow-auto' }, body));
    if (ctx.readOnly) { setChildren(body, readOnlyState()); return; }

    let opts;
    let dbs;
    try {
      [opts, dbs] = await Promise.all([api('import.options', { conn }).catch(() => ({ encodings: [{ id: 'UTF-8', label: 'Unicode (UTF-8)' }], upload_max: 0 })), getDatabases(conn)]);
    } catch (e) {
      setChildren(body, errorState(e, { onRetry: () => this.render(el, ctx) }));
      return;
    }
    const encodings = opts.encodings?.length ? opts.encodings : [{ id: 'UTF-8', label: 'UTF-8' }];
    const limit = Number(opts.upload_max) || 0;

    const state = {
      db: params.db || ctx.db || '',
      schema: params.schema || ctx.schema || '',
      file: null,
      bytes: null,       // Uint8Array preview slice
      format: 'sql',
      sql: { stop_on_error: true, skip_statements: 0 },
      csv: { table: params.table || '', delimiter: 'auto', enclosure: '"', header: true, encoding: 'UTF-8', null_as: '\\N', empty_as_null: false, truncate_first: false },
      tables: [],
      tableCols: [],
      mapping: [],       // per file column: table column name or '' (skip)
      preview: null,     // {rows, delim, error?}
      busy: null,        // AbortController while uploading
      result: null,
    };

    /* -------------------------------------------------------------- target */
    const targetBox = h('div', { class: 'flex flex-wrap items-end gap-3' });
    async function drawTarget() {
      const dbId = uid('imdb');
      const dbSel = h('select', { id: dbId, class: 'select', onChange: async () => { state.db = dbSel.value; state.schema = ''; state.csv.table = ''; await drawTarget(); await loadTables(); } },
        h('option', { value: '' }, t('import.chooseDb')),
        dbs.map((d) => h('option', { value: d.name, selected: d.name === state.db }, d.name)));
      let schemaField = null;
      if (caps.schemas && state.db) {
        const list = await getSchemas(conn, state.db).catch(() => []);
        if (!state.schema) state.schema = list.includes('public') ? 'public' : (list[0] || '');
        const scId = uid('imsc');
        const scSel = h('select', { id: scId, class: 'select', onChange: () => { state.schema = scSel.value; state.csv.table = ''; loadTables(); } },
          list.map((s) => h('option', { value: s, selected: s === state.schema }, s)));
        schemaField = h('div', { class: 'min-w-0 flex-1 sm:max-w-xs' }, h('label', { class: 'label', for: scId }, t('import.schema')), scSel);
      }
      setChildren(targetBox,
        h('div', { class: 'min-w-0 flex-1 sm:max-w-sm' }, h('label', { class: 'label', for: dbId }, t('import.database')), dbSel),
        schemaField,
        caps.create_database !== false ? h('button', { type: 'button', class: 'btn', onClick: createDb }, icon('plus'), t('import.newDb')) : null);
    }

    async function createDb() {
      const res = await formDialog({
        title: t('server.createDb'), submitLabel: t('common.create'),
        fields: [{ name: 'name', label: t('server.dbName'), required: true, mono: true }],
        onSubmit: async (v) => { await api('db.create', { conn, name: v.name }); return v; },
      });
      if (!res) return;
      toast.success(t('server.dbCreated', { name: res.name }));
      invalidate(conn);
      dbs = await getDatabases(conn, true).catch(() => dbs);
      state.db = res.name;
      state.schema = '';
      await drawTarget();
      await loadTables();
    }

    async function loadTables() {
      state.tables = [];
      if (state.db) {
        try {
          const o = await getObjects(conn, state.db, state.schema || undefined);
          state.tables = (o.tables || []).filter((x) => x.type !== 'view').map((x) => x.name);
        } catch (e) { toastError(e); }
      }
      if (state.csv.table && !state.tables.includes(state.csv.table)) state.csv.table = '';
      if (!state.csv.table && state.file && state.tables.length) {
        const base = state.file.name.replace(/\.(gz|zip|bz2)$/i, '').replace(/\.[^.]+$/, '').toLowerCase();
        state.csv.table = state.tables.find((n) => n.toLowerCase() === base) || '';
      }
      await loadTableCols();
    }

    async function loadTableCols() {
      state.tableCols = [];
      if (state.db && state.csv.table) {
        try { state.tableCols = (await getTableInfo(conn, state.db, state.schema || undefined, state.csv.table)).columns.map((c) => c.name); } catch (e) { toastError(e); }
      }
      autoMap();
      drawOptions();
    }

    /* -------------------------------------------------------------- file */
    const fileInput = h('input', { type: 'file', accept: ACCEPT, class: 'sr-only', id: uid('imf'), onChange: () => fileInput.files[0] && setFile(fileInput.files[0]) });
    const dropInfo = h('div', { class: 'min-w-0 text-center' });
    const drop = h('label', {
      for: fileInput.id,
      class: 'flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border bg-surface px-4 py-6 text-[13px] transition-colors hover:border-accent focus-within:border-accent',
      onDragover: (e) => { e.preventDefault(); drop.classList.add('border-accent', 'bg-accent-soft'); },
      onDragleave: () => drop.classList.remove('border-accent', 'bg-accent-soft'),
      onDrop: (e) => { e.preventDefault(); drop.classList.remove('border-accent', 'bg-accent-soft'); const f = e.dataTransfer?.files?.[0]; if (f) setFile(f); },
    }, fileInput, dropInfo);

    function drawDrop() {
      const f = state.file;
      if (!f) {
        setChildren(dropInfo,
          h('span', { class: 'mx-auto mb-1 flex size-10 items-center justify-center rounded-xl bg-surface-2 text-muted' }, icon('file-up', 'size-5')),
          h('div', { class: 'font-medium' }, t('import.dropHere')),
          h('div', { class: 'text-muted' }, t('import.orPick')),
          h('div', { class: 'mt-1 text-xs text-muted' }, t('import.accepted', { list: ACCEPT.replace(/,/g, ' ') }), limit ? ` · ${t('import.limit', { size: fmtBytes(limit) })}` : ''));
        return;
      }
      const tooBig = limit && f.size > limit;
      setChildren(dropInfo,
        h('span', { class: 'mx-auto mb-1 flex size-10 items-center justify-center rounded-xl bg-accent-soft text-accent' }, icon(state.format === 'csv' ? 'table-2' : 'file-code', 'size-5')),
        h('div', { class: 'max-w-full truncate font-medium', dir: 'auto', title: f.name }, f.name),
        h('div', { class: cls('tabular-nums', tooBig ? 'font-medium text-danger' : 'text-muted') },
          limit ? t('import.sizeOfLimit', { size: fmtBytes(f.size), limit: fmtBytes(limit) }) : fmtBytes(f.size)),
        h('div', { class: 'mt-1 text-xs text-accent' }, t('import.changeFile')));
    }

    async function setFile(f) {
      state.file = f;
      state.result = null;
      state.format = detectFormat(f.name);
      if (/\.tsv(\.(gz|zip|bz2))?$/i.test(f.name)) state.csv.delimiter = '\t';
      state.bytes = null;
      if (state.format === 'csv' && !isCompressed(f.name)) {
        try { state.bytes = new Uint8Array(await f.slice(0, PREVIEW_BYTES).arrayBuffer()); } catch { state.bytes = null; }
        if (state.bytes) guessEncoding();
      }
      state.sql.skip_statements = 0;
      drawDrop();
      drawFormat();
      if (state.format === 'csv') await loadTables(); else drawOptions();
      drawResult();
    }

    function guessEncoding() {
      const b = state.bytes;
      if (b[0] === 0xff && b[1] === 0xfe) { state.csv.encoding = encodings.some((e) => e.id === 'UTF-16LE') ? 'UTF-16LE' : 'UTF-16'; return; }
      if (b[0] === 0xfe && b[1] === 0xff) { state.csv.encoding = 'UTF-16BE'; return; }
      // cut at most 3 trailing bytes so a multibyte char split by the slice does not fail the check
      const probe = b.length === PREVIEW_BYTES ? b.subarray(0, b.length - 3) : b;
      if (decode(probe, 'UTF-8', true) !== null) { state.csv.encoding = 'UTF-8'; return; }
      const order = [...(GUESS[locale()] || []), 'SJIS-win', 'GB18030', 'Windows-1251', 'Windows-1252'];
      const hit = order.find((id) => encodings.some((e) => e.id === id) && decode(probe, id, true) !== null);
      if (hit) state.csv.encoding = hit;
    }

    /* -------------------------------------------------------------- format + options */
    const formatBox = h('div');
    function drawFormat() {
      setChildren(formatBox, state.file ? h('div', { class: 'flex flex-wrap items-center gap-3' },
        h('span', { class: 'label mb-0' }, t('import.format')),
        h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': t('import.format') },
          [['sql', 'SQL', 'file-code'], ['csv', 'CSV', 'table-2']].map(([id, label, ic]) => h('button', {
            type: 'button', role: 'radio', 'aria-checked': String(state.format === id),
            onClick: async () => { if (state.format === id) return; state.format = id; if (id === 'csv' && !state.bytes && !isCompressed(state.file.name)) { state.bytes = new Uint8Array(await state.file.slice(0, PREVIEW_BYTES).arrayBuffer()); guessEncoding(); } drawFormat(); drawDrop(); if (id === 'csv') await loadTables(); else drawOptions(); },
          }, icon(ic), label)))) : null);
    }

    const optionsBox = h('div', { class: 'space-y-4' });
    const field = (label, control, help) => {
      const id = control.id || uid('imo');
      control.id = id;
      return h('div', { class: 'min-w-0' }, h('label', { class: 'label', for: id }, label), control, help ? h('p', { class: 'help' }, help) : null);
    };
    const check = (label, value, onChange, help) => {
      const id = uid('imc');
      return h('div', {}, h('label', { for: id, class: 'flex min-h-9 items-center gap-2 text-[13px]' },
        h('input', { id, type: 'checkbox', class: 'checkbox', checked: value, onChange: (e) => onChange(e.target.checked) }), label),
      help ? h('p', { class: 'help ms-6' }, help) : null);
    };

    function drawOptions() {
      if (!state.file) { setChildren(optionsBox); updateSubmit(); return; }
      if (state.format === 'sql') {
        const skipIn = h('input', { type: 'number', min: '0', class: 'input w-40 tabular-nums', value: String(state.sql.skip_statements || 0), onInput: () => { state.sql.skip_statements = Math.max(0, Number(skipIn.value) || 0); } });
        setChildren(optionsBox, h('div', { class: 'card grid grid-cols-1 gap-4 p-4 sm:grid-cols-2' },
          check(t('import.stopOnError'), state.sql.stop_on_error, (v) => { state.sql.stop_on_error = v; }, t('import.stopOnErrorHelp')),
          field(t('import.skipStatements'), skipIn, t('import.skipStatementsHelp'))));
        updateSubmit();
        return;
      }
      // ----- CSV
      const c = state.csv;
      const tableSel = h('select', { class: 'select', onChange: () => { c.table = tableSel.value; loadTableCols(); } },
        h('option', { value: '' }, state.tables.length ? t('import.chooseTable') : t('import.noTables')),
        state.tables.map((n) => h('option', { value: n, selected: n === c.table }, n)));
      const delimSel = h('select', { class: 'select', onChange: () => { c.delimiter = delimSel.value; buildPreview(); drawOptions(); } },
        [['auto', t('import.delimAuto')], [',', t('export.choice.comma')], [';', t('export.choice.semicolon')], ['\t', t('export.choice.tab')], ['|', t('export.choice.pipe')]]
          .map(([v, l]) => h('option', { value: v, selected: v === c.delimiter }, l)));
      const encIn = h('input', { class: 'input w-20 font-mono', dir: 'ltr', maxlength: '1', value: c.enclosure, onChange: () => { c.enclosure = encIn.value || '"'; buildPreview(); drawOptions(); } });
      const encodingSel = h('select', { class: 'select', onChange: () => { c.encoding = encodingSel.value; buildPreview(); drawOptions(); } },
        encodings.map((e) => h('option', { value: e.id, selected: e.id === c.encoding }, e.label)));
      const nullIn = h('input', { class: 'input font-mono', dir: 'ltr', value: c.null_as, onInput: () => { c.null_as = nullIn.value; } });

      setChildren(optionsBox,
        h('div', { class: 'card grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3' },
          field(t('import.targetTable'), tableSel, t('import.targetTableHelp')),
          field(t('import.encoding'), encodingSel),
          field(t('import.delimiter'), delimSel, c.delimiter === 'auto' && state.preview?.delim ? t('import.delimDetected', { d: t({ ',': 'export.choice.comma', ';': 'export.choice.semicolon', '\t': 'export.choice.tab', '|': 'export.choice.pipe' }[state.preview.delim] || 'export.choice.comma') }) : null),
          field(t('import.enclosure'), encIn),
          field(t('import.nullAs'), nullIn, t('import.nullAsHelp')),
          h('div', { class: 'space-y-1' },
            check(t('import.header'), c.header, (v) => { c.header = v; autoMap(); drawOptions(); }),
            check(t('import.emptyAsNull'), c.empty_as_null, (v) => { c.empty_as_null = v; }),
            check(t('import.truncateFirst'), c.truncate_first, (v) => { c.truncate_first = v; }))),
        previewBlock());
      updateSubmit();
    }

    function buildPreview() {
      state.preview = null;
      if (state.format !== 'csv' || !state.bytes) return;
      const text = decode(state.bytes, state.csv.encoding);
      if (text === null) { state.preview = { rows: [], delim: ',', error: t('import.decoderMissing', { enc: state.csv.encoding }) }; return; }
      const clean = text.replace(/^﻿/, '');
      const delim = state.csv.delimiter === 'auto' ? detectDelimiter(clean, state.csv.enclosure) : state.csv.delimiter;
      const partial = state.bytes.length >= PREVIEW_BYTES;
      const rows = parseCsv(clean, delim, state.csv.enclosure, PREVIEW_ROWS + 1, partial);
      state.preview = { rows, delim };
    }

    /** Default mapping: header names (case-insensitive) or position. */
    function autoMap() {
      buildPreview();
      const rows = state.preview?.rows || [];
      const width = rows.reduce((m, r) => Math.max(m, r.length), 0);
      const lower = new Map(state.tableCols.map((n) => [n.toLowerCase(), n]));
      state.mapping = Array.from({ length: width }, (_, i) => {
        if (state.csv.header) return lower.get(String(rows[0]?.[i] ?? '').trim().toLowerCase()) || '';
        return state.tableCols[i] || '';
      });
    }

    function previewBlock() {
      if (!state.bytes) {
        return callout('info', isCompressed(state.file.name) ? t('import.noPreviewCompressed') : t('import.noPreview'));
      }
      const p = state.preview;
      if (!p) return null;
      if (p.error) return callout('warning', p.error);
      const header = state.csv.header ? p.rows[0] || [] : null;
      const data = (state.csv.header ? p.rows.slice(1) : p.rows).slice(0, PREVIEW_ROWS);
      const width = state.mapping.length;
      const used = state.mapping.filter(Boolean);
      const dup = used.filter((v, i) => used.indexOf(v) !== i);
      const mapSel = (i) => {
        const sel = h('select', {
          class: cls('select input-sm min-w-[8rem]', !state.mapping[i] && 'text-muted'), 'aria-label': t('import.mapColumn', { n: i + 1 }),
          onChange: () => { state.mapping[i] = sel.value; drawOptions(); },
        },
        h('option', { value: '' }, t('import.skipColumn')),
        state.tableCols.map((n) => h('option', { value: n, selected: n === state.mapping[i] }, n)));
        return sel;
      };
      const warnings = [];
      if (state.csv.table && !used.length) warnings.push(callout('warning', t('import.noMapping')));
      if (dup.length) warnings.push(callout('warning', t('import.duplicateMapping', { cols: [...new Set(dup)].join(', ') })));
      return h('section', { class: 'space-y-2', 'aria-label': t('import.preview') },
        h('div', { class: 'flex flex-wrap items-baseline gap-2' },
          h('h3', { class: 'text-[13.5px] font-semibold' }, t('import.preview')),
          h('span', { class: 'text-xs text-muted' }, t('import.previewHint', { n: data.length }))),
        ...warnings,
        h('div', { class: 'card overflow-x-auto' }, h('table', { class: 'table-grid' },
          h('thead', {},
            h('tr', {}, Array.from({ length: width }, (_, i) => h('th', { class: 'whitespace-nowrap' }, header ? h('span', { dir: 'auto' }, header[i] ?? '') : t('import.columnN', { n: i + 1 })))),
            state.tableCols.length ? h('tr', {}, Array.from({ length: width }, (_, i) => h('th', { class: 'font-normal' }, mapSel(i)))) : null),
          h('tbody', {}, data.map((r) => h('tr', {}, Array.from({ length: width }, (_, i) => {
            const v = r[i];
            const isNull = v === undefined || v === state.csv.null_as || (state.csv.empty_as_null && v === '');
            return h('td', { class: 'max-w-[16rem] truncate', dir: 'auto', title: v ?? '' }, isNull ? h('span', { class: 'badge-null' }, 'NULL') : v);
          })))))));
    }

    /* -------------------------------------------------------------- upload */
    const progressBar = h('div', { class: 'h-full rounded-full bg-accent transition-[width]', style: { width: '0%' } });
    const progressText = h('span', { class: 'text-xs text-muted tabular-nums' });
    const progress = h('div', { class: 'space-y-1.5', hidden: true },
      h('div', { class: 'h-2 overflow-hidden rounded-full bg-surface-2', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': t('import.uploading') }, progressBar),
      progressText);
    const submitBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => start() }, icon('upload'), t('import.submit'));
    const cancelBtn = h('button', { type: 'button', class: 'btn', hidden: true, onClick: () => state.busy?.abort() }, t('common.cancel'));
    const submitHint = h('span', { class: 'text-[12.5px] text-muted' });

    function blocker() {
      if (!state.db) return t('import.chooseDb');
      if (!state.file) return t('import.chooseFile');
      if (limit && state.file.size > limit) return t('import.tooLarge', { limit: fmtBytes(limit) });
      if (state.format === 'csv' && !state.csv.table) return t('import.chooseTable');
      if (state.format === 'csv' && state.bytes && state.tableCols.length && !state.mapping.some(Boolean)) return t('import.noMapping');
      return null;
    }
    function updateSubmit() {
      const b = blocker();
      submitBtn.disabled = !!b || !!state.busy;
      submitHint.textContent = state.busy ? '' : b || '';
    }

    async function start() {
      if (blocker() || state.busy) return;
      const f = state.file;
      const fields = { conn, db: state.db, schema: state.schema || undefined, format: state.format, file: f };
      if (state.format === 'sql') {
        fields.options = { stop_on_error: state.sql.stop_on_error, skip_statements: state.sql.skip_statements || 0 };
      } else {
        const c = state.csv;
        const delim = c.delimiter === 'auto' ? (state.preview?.delim || ',') : c.delimiter;
        const csvOpts = { delimiter: delim === '\t' ? 'tab' : delim, enclosure: c.enclosure, header: c.header, encoding: c.encoding, null_as: c.null_as, empty_as_null: c.empty_as_null, truncate_first: c.truncate_first };
        if (state.bytes && state.tableCols.length && state.mapping.length) csvOpts.columns = state.mapping.map((m) => m || null);
        fields.table = c.table;
        fields.csv_options = csvOpts;
      }
      state.busy = new AbortController();
      state.result = null;
      drawResult();
      progress.hidden = false;
      cancelBtn.hidden = false;
      updateSubmit();
      const setPct = (loaded, total) => {
        const pct = total ? Math.round((loaded / total) * 100) : 0;
        progressBar.style.width = `${pct}%`;
        progress.firstChild.setAttribute('aria-valuenow', String(pct));
        progressText.textContent = pct >= 100 ? t('import.processing') : t('import.uploadProgress', { pct, loaded: fmtBytes(loaded), total: fmtBytes(total) });
      };
      setPct(0, f.size);
      try {
        const res = await uploadForm('import.upload', fields, { onProgress: setPct, signal: state.busy.signal });
        state.result = res;
        invalidate(conn, state.db);
        const n = state.format === 'csv' ? plural('import.rowsImported', res.rows ?? res.statements ?? 0) : plural('import.statementsRun', res.statements ?? 0);
        if (res.errors?.length) toast.warning(t('import.doneWithErrors', { what: n, n: res.errors.length }));
        else toast.success(t('import.done', { what: n }));
      } catch (e) {
        if (e.code !== 'aborted') toastError(e, t('import.failed'));
        else toast.info(t('import.cancelled'));
      } finally {
        state.busy = null;
        progress.hidden = true;
        cancelBtn.hidden = true;
        updateSubmit();
        drawResult();
      }
    }

    /* -------------------------------------------------------------- result */
    const resultBox = h('div');
    function drawResult() {
      const r = state.result;
      if (!r) { setChildren(resultBox); return; }
      const csv = r.rows !== undefined && state.format === 'csv';
      const stat = (label, value) => h('div', { class: 'card min-w-0 p-3' }, h('div', { class: 'text-xs text-muted' }, label), h('div', { class: 'truncate text-[15px] font-semibold tabular-nums' }, value));
      const errs = r.errors || [];
      const resume = !csv && r.aborted && r.position ? h('div', { class: 'flex flex-wrap items-center gap-2' },
        h('button', { type: 'button', class: 'btn btn-sm', onClick: () => { state.sql.skip_statements = r.position; drawOptions(); toast.info(t('import.resumeSet', { n: fmtNumber(r.position) })); } }, icon('play'), t('import.resumeAfter', { n: fmtNumber(r.position) })),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => { state.sql.skip_statements = Math.max(0, r.position - 1); drawOptions(); toast.info(t('import.resumeSet', { n: fmtNumber(r.position - 1) })); } }, icon('refresh'), t('import.retryFailed'))) : null;
      setChildren(resultBox, h('section', { class: 'space-y-3', 'aria-live': 'polite' },
        h('h2', { class: 'flex items-center gap-2 text-[14px] font-semibold' },
          icon(errs.length ? 'warning' : 'circle-check', errs.length ? 'text-warning' : 'text-success'), t('import.result')),
        h('div', { class: 'grid grid-cols-2 gap-2 sm:grid-cols-4' },
          csv ? stat(t('import.stat.rows'), fmtNumber(r.rows)) : stat(t('import.stat.statements'), fmtNumber(r.statements)),
          !csv && r.skipped ? stat(t('import.stat.skipped'), fmtNumber(r.skipped)) : null,
          stat(t('import.stat.errors'), fmtNumber(errs.length)),
          stat(t('import.stat.time'), fmtDuration(r.time_ms ?? (r.time || 0) * 1000))),
        r.aborted ? callout('warning', csv ? t('import.abortedCsv') : t('import.aborted', { n: fmtNumber(r.position || 0) })) : null,
        resume,
        errs.length ? h('ol', { class: 'card divide-y divide-border' }, errs.map((e) => h('li', { class: 'space-y-1.5 p-3 text-[13px]' },
          h('div', { class: 'flex items-start gap-2 text-danger' }, icon('circle-alert', 'mt-0.5'),
            h('span', { class: 'min-w-0 break-words', dir: 'auto' }, e.line ? t('import.errorLine', { line: fmtNumber(e.line), message: e.message }) : e.message)),
          e.statement ? h('pre', { class: 'max-h-32 overflow-auto rounded-md bg-surface-2 p-2 font-mono text-xs whitespace-pre-wrap break-all', dir: 'ltr' }, highlightSql(e.statement)) : null))) : null,
        state.db ? h('div', { class: 'flex flex-wrap gap-2' },
          h('a', { class: 'btn btn-sm', href: href.db(conn, state.db, state.schema || undefined) }, icon('database'), t('import.openDb')),
          csv && state.csv.table ? h('a', { class: 'btn btn-sm', href: href.table(conn, state.db, state.schema || undefined, state.csv.table, 'browse') }, icon('table-2'), t('import.openTable')) : null) : null));
    }

    /* -------------------------------------------------------------- layout */
    const section = (title, ...children) => h('section', { class: 'space-y-3' }, h('h2', { class: 'text-[14px] font-semibold' }, title), ...children);
    setChildren(body,
      h('div', { class: 'flex flex-wrap items-end justify-between gap-3' },
        h('div', { class: 'min-w-0' },
          h('h1', { class: 'flex items-center gap-2 text-xl font-semibold tracking-tight' }, icon('upload', 'size-5 text-muted'), t('nav.import')),
          h('p', { class: 'mt-0.5 text-[13px] text-muted' }, t('import.subtitle'))),
        h('a', { href: href.export(conn, { db: state.db || undefined, schema: state.schema || undefined }), class: 'btn btn-sm' }, icon('download'), t('nav.export'))),
      section(t('import.target'), targetBox),
      section(t('import.file'), drop, formatBox),
      optionsBox,
      h('div', { class: 'flex flex-wrap items-center gap-3' }, submitBtn, cancelBtn, submitHint),
      progress,
      resultBox);

    drawDrop();
    await drawTarget();
    if (state.db) await loadTables();
    updateSubmit();
  },
};
