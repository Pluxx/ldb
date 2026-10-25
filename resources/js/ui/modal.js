/**
 * Modal dialogs built on <dialog> (top layer, inert background) with a focus trap,
 * Esc/backdrop dismissal, focus restoration, and a full-screen sheet layout on phones.
 * @module ui/modal
 */
import { h, focusables, uid, cls, copyText } from './dom.js';
import { toast } from './toast.js';
import { icon } from './icons.js';
import { t } from '../i18n/index.js';
import { highlightSql, highlightJson } from '../sql/highlight.js';

const SIZES = { sm: 'sm:max-w-sm', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl', full: 'sm:max-w-[min(1200px,95vw)]' };

/**
 * @typedef {object} ModalHandle
 * @property {HTMLDialogElement} dialog
 * @property {HTMLElement} body    scrollable content area
 * @property {HTMLElement} footer  action bar (empty → hidden)
 * @property {(result?: any) => void} close
 * @property {Promise<any>} closed resolves with the value passed to close() (undefined on dismiss)
 */

/**
 * Open a generic modal.
 * @param {object} o
 * @param {string} o.title
 * @param {string} [o.description]
 * @param {Node|Node[]} [o.body]
 * @param {Node[]} [o.actions]  footer buttons
 * @param {'sm'|'md'|'lg'|'xl'|'full'} [o.size='md']
 * @param {boolean} [o.sheet=true]  full-screen sheet below the sm breakpoint
 * @param {boolean} [o.dismissible=true]  Esc / backdrop / close button allowed
 * @param {HTMLElement|string} [o.initialFocus]  element or selector to focus first
 * @param {(result: any) => void} [o.onClose]
 * @returns {ModalHandle}
 */
export function openModal(o) {
  const titleId = uid('dlg');
  const descId = o.description ? uid('dlgd') : null;
  const previous = document.activeElement;
  let resolveClosed;
  let result;
  const closed = new Promise((r) => { resolveClosed = r; });
  const dismissible = o.dismissible !== false;

  const body = h('div', { class: 'min-h-0 flex-1 overflow-auto px-5 py-4' });
  [].concat(o.body || []).forEach((n) => n && body.appendChild(n));
  const footer = h('div', { class: 'flex flex-wrap items-center justify-end gap-2 border-t border-border bg-surface-2/50 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:pb-3' });
  (o.actions || []).forEach((n) => footer.appendChild(n));
  if (!o.actions || !o.actions.length) footer.hidden = true;

  const dialog = h('dialog', {
    class: cls('modal', o.sheet !== false && 'modal-sheet', SIZES[o.size || 'md']),
    'aria-labelledby': titleId,
    'aria-describedby': descId,
  },
  h('div', { class: 'flex items-start gap-3 border-b border-border px-5 py-3.5' },
    h('div', { class: 'min-w-0 flex-1' },
      h('h2', { id: titleId, class: 'truncate text-[15px] font-semibold leading-6', dir: 'auto' }, o.title),
      o.description ? h('p', { id: descId, class: 'mt-0.5 text-[13px] text-muted', dir: 'auto' }, o.description) : null),
    dismissible ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon -me-2', 'aria-label': t('common.close'), onClick: () => close() }, icon('x')) : null),
  body, footer);

  function close(value) {
    result = value;
    if (dialog.open) dialog.close();
  }

  dialog.addEventListener('cancel', (e) => {
    if (!dismissible) e.preventDefault();
  });
  dialog.addEventListener('close', () => {
    dialog.remove();
    o.onClose?.(result);
    resolveClosed(result);
    if (previous && previous.isConnected && typeof previous.focus === 'function') previous.focus();
  });
  // backdrop click: the click target is the <dialog> itself outside the panel content
  dialog.addEventListener('pointerdown', (e) => {
    if (e.target !== dialog || !dismissible) return;
    const r = dialog.getBoundingClientRect();
    const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
    if (!inside) close();
  });
  // focus trap (the dialog is modal, but keep Tab cycling inside instead of leaving to browser UI)
  dialog.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;
    const items = focusables(dialog);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  document.body.appendChild(dialog);
  dialog.showModal();
  const target = typeof o.initialFocus === 'string' ? dialog.querySelector(o.initialFocus) : o.initialFocus;
  if (target) target.focus();
  else {
    const first = focusables(body)[0] || focusables(footer).find((b) => b.classList.contains('btn-primary')) || focusables(dialog)[0];
    first?.focus();
  }
  return { dialog, body, footer, close, closed };
}

function button(label, className, onClick, extra = {}) {
  return h('button', { type: 'button', class: `btn ${className}`, onClick, ...extra }, label);
}

/**
 * Ask for confirmation.
 * @param {object} o
 * @param {string} o.title
 * @param {string|Node} [o.message]
 * @param {string} [o.confirmLabel]
 * @param {string} [o.cancelLabel]
 * @param {boolean} [o.danger]  red confirm button
 * @param {string} [o.requireText]  user must type this exact text (e.g. a database name)
 * @param {string} [o.sql]  optional SQL preview shown in a code block
 * @returns {Promise<boolean>}
 */
export async function confirmDialog(o) {
  const confirmBtn = button(o.confirmLabel || t('common.confirm'), o.danger ? 'btn-danger' : 'btn-primary', () => m.close(true));
  const nodes = [];
  if (o.message) nodes.push(typeof o.message === 'string' ? h('p', { class: 'text-[13.5px] leading-relaxed', dir: 'auto' }, o.message) : o.message);
  if (o.sql) nodes.push(h('pre', { class: 'mt-3 max-h-48 overflow-auto rounded-lg border border-border bg-surface-2 p-3 font-mono text-xs', dir: 'ltr' }, highlightSql(o.sql)));
  if (o.requireText) {
    const id = uid('cf');
    const input = h('input', {
      id, class: 'input font-mono', autocomplete: 'off', spellcheck: 'false', dir: 'auto',
      onInput: () => { confirmBtn.disabled = input.value !== o.requireText; },
      onKeydown: (e) => { if (e.key === 'Enter' && !confirmBtn.disabled) m.close(true); },
    });
    confirmBtn.disabled = true;
    nodes.push(h('div', { class: 'mt-4' },
      h('label', { class: 'label', for: id }, t('modal.typeToConfirm', { text: o.requireText })), input));
  }
  const m = openModal({
    title: o.title,
    body: nodes,
    size: 'sm',
    sheet: false,
    actions: [button(o.cancelLabel || t('common.cancel'), 'btn-ghost', () => m.close(false)), confirmBtn],
    initialFocus: o.requireText ? 'input' : confirmBtn,
  });
  return (await m.closed) === true;
}

/**
 * Ask for a single text value.
 * @param {object} o
 * @param {string} o.title
 * @param {string} [o.label]
 * @param {string} [o.value]
 * @param {string} [o.placeholder]
 * @param {string} [o.confirmLabel]
 * @param {boolean} [o.mono]  monospace input (identifiers)
 * @param {(v: string) => string|null} [o.validate]  return an error message to block submit
 * @returns {Promise<string|null>} null when cancelled
 */
export async function promptDialog(o) {
  const values = await formDialog({
    title: o.title,
    submitLabel: o.confirmLabel,
    size: 'sm',
    fields: [{ name: 'value', label: o.label || o.title, value: o.value ?? '', placeholder: o.placeholder, mono: o.mono, required: true, validate: o.validate }],
  });
  return values ? values.value : null;
}

/**
 * @typedef {object} FormField
 * @property {string} name
 * @property {string} label
 * @property {'text'|'number'|'password'|'select'|'textarea'|'checkbox'|'email'} [type='text']
 * @property {any} [value]
 * @property {{value: string, label: string}[]} [options]  for select
 * @property {string} [placeholder]
 * @property {string} [help]
 * @property {boolean} [required]
 * @property {boolean} [mono]
 * @property {string} [autocomplete]
 * @property {(v: any, all: object) => string|null} [validate]
 */

/**
 * Generic form dialog. Resolves with the values object, or null when cancelled.
 * If `onSubmit` throws, the error message is shown inside the dialog and it stays open.
 * @param {object} o
 * @param {string} o.title
 * @param {string} [o.description]
 * @param {FormField[]} o.fields
 * @param {string} [o.submitLabel]
 * @param {boolean} [o.danger]
 * @param {'sm'|'md'|'lg'} [o.size]
 * @param {(values: object) => Promise<any>|any} [o.onSubmit]  return value becomes the result
 * @returns {Promise<object|null>}
 */
export async function formDialog(o) {
  const inputs = {};
  const errorBox = h('div', { id: uid('fe'), class: 'mt-3 rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-[13px] text-danger', role: 'alert', hidden: true, dir: 'auto' });
  const form = h('form', { class: 'space-y-4', novalidate: true, onSubmit: (e) => { e.preventDefault(); submit(); } });

  for (const f of o.fields) {
    const id = uid('f');
    let input;
    const common = { id, name: f.name, required: f.required, autocomplete: f.autocomplete || 'off', 'aria-describedby': f.help ? `${id}-h` : null };
    if (f.type === 'select') {
      input = h('select', { ...common, class: 'select' },
        (f.options || []).map((op) => h('option', { value: op.value, selected: String(op.value) === String(f.value ?? '') }, op.label)));
    } else if (f.type === 'textarea') {
      input = h('textarea', { ...common, class: cls('textarea', f.mono && 'font-mono text-[13px]'), placeholder: f.placeholder, rows: 4, dir: f.mono ? 'ltr' : 'auto', spellcheck: 'false' });
      input.value = f.value ?? '';
    } else if (f.type === 'checkbox') {
      input = h('input', { ...common, type: 'checkbox', class: 'checkbox', checked: !!f.value });
    } else {
      input = h('input', { ...common, type: f.type || 'text', class: cls('input', f.mono && 'font-mono'), placeholder: f.placeholder, value: f.value ?? '', dir: f.mono ? 'ltr' : 'auto', spellcheck: 'false' });
    }
    inputs[f.name] = input;
    const help = f.help ? h('p', { id: `${id}-h`, class: 'help' }, f.help) : null;
    form.appendChild(f.type === 'checkbox'
      ? h('label', { class: 'flex items-center gap-2 text-[13px]', for: id }, input, f.label, help)
      : h('div', {}, h('label', { class: 'label', for: id }, f.label, f.required ? h('span', { class: 'text-danger', 'aria-hidden': 'true' }, ' *') : null), input, help));
  }
  form.appendChild(errorBox);
  // hidden submit so Enter in a text input submits
  form.appendChild(h('button', { type: 'submit', hidden: true, tabindex: '-1' }));

  const submitBtn = button(o.submitLabel || t('common.save'), o.danger ? 'btn-danger' : 'btn-primary', () => submit());
  const m = openModal({
    title: o.title,
    description: o.description,
    body: form,
    size: o.size || 'md',
    actions: [button(t('common.cancel'), 'btn-ghost', () => m.close(null)), submitBtn],
  });

  const read = () => {
    const values = {};
    for (const f of o.fields) {
      const el = inputs[f.name];
      values[f.name] = f.type === 'checkbox' ? el.checked : f.type === 'number' ? (el.value === '' ? null : Number(el.value)) : el.value;
    }
    return values;
  };

  async function submit() {
    const values = read();
    errorBox.hidden = true;
    for (const f of o.fields) {
      const el = inputs[f.name];
      el.removeAttribute('aria-invalid');
      const described = (el.getAttribute('aria-describedby') || '').split(' ').filter((x) => x && x !== errorBox.id);
      if (described.length) el.setAttribute('aria-describedby', described.join(' ')); else el.removeAttribute('aria-describedby');
      let err = null;
      if (f.required && (values[f.name] === '' || values[f.name] === null)) err = t('modal.required', { field: f.label });
      else if (f.validate) err = f.validate(values[f.name], values);
      if (err) {
        el.setAttribute('aria-invalid', 'true');
        el.setAttribute('aria-describedby', [...described, errorBox.id].join(' '));
        errorBox.textContent = err;
        errorBox.hidden = false;
        el.focus();
        return;
      }
    }
    if (!o.onSubmit) { m.close(values); return; }
    submitBtn.disabled = true;
    try {
      const res = await o.onSubmit(values);
      m.close(res === undefined ? values : res);
    } catch (e) {
      errorBox.textContent = e?.message || String(e);
      errorBox.hidden = false;
    } finally {
      submitBtn.disabled = false;
    }
  }

  return (await m.closed) ?? null;
}

/**
 * Read-only value viewer (long text, JSON pretty view, SQL) with copy button.
 * @param {object} o
 * @param {string} o.title
 * @param {string} o.text
 * @param {'text'|'json'|'sql'} [o.language='text']
 * @param {(text: string) => void} [o.onCopy]
 */
export function viewerDialog(o) {
  let text = o.text;
  let content;
  if (o.language === 'json') {
    try { text = JSON.stringify(JSON.parse(o.text), null, 2); } catch { /* keep raw */ }
    content = highlightJson(text);
  } else if (o.language === 'sql') {
    content = highlightSql(text);
  } else {
    content = document.createTextNode(text);
  }
  const pre = h('pre', {
    class: cls('max-h-[65dvh] overflow-auto rounded-lg border border-border bg-surface-2 p-3 text-[12.5px] leading-relaxed whitespace-pre-wrap break-words', o.language !== 'text' && 'font-mono'),
    dir: o.language === 'text' || !o.language ? 'auto' : 'ltr',
    tabindex: '0',
  }, content);
  const m = openModal({
    title: o.title,
    size: 'lg',
    body: pre,
    actions: [
      button([icon('copy'), t('common.copy')], 'btn-ghost', () => (o.onCopy ? o.onCopy(text) : copyText(text).then(() => toast.success(t('common.copied'))))),
      button(t('common.close'), 'btn-primary', () => m.close()),
    ],
  });
  return m;
}
