/**
 * Account security (#/c/{conn}/security): two-factor authentication for the database login
 * behind the connection — set up (QR code + manual key, confirm with a code, recovery codes
 * shown once), regenerate recovery codes and disable (both need a current code).
 * When the `twofa_required` policy forces enrollment the app renders this view without the
 * shell (app.js) and the server refuses every other action until setup is complete.
 */
import { h, copyText, downloadText } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openModal } from '../ui/modal.js';
import { toast, toastError, announce } from '../ui/toast.js';
import { skeleton, errorState, callout } from '../ui/feedback.js';
import { qrSvg } from '../ui/qr.js';
import { t, plural } from '../i18n/index.js';
import { api } from '../api.js';
import { appStore } from '../store.js';
import { setAuth, logout } from '../session.js';
import { href, navigate } from '../router.js';
import { codeField, codeErrorMessage } from './_twofa-code.js';

/** "ABCD EFGH …" for reading the key aloud / typing it into an app. */
const groupKey = (s) => s.replace(/(.{4})/g, '$1 ').trim();

export default {
  title: () => t('twofa.title'),

  async render(el, ctx) {
    const { conn } = ctx;
    const standalone = !el.closest('#main'); // enforced enrollment: no shell around us
    const content = h('div', { class: 'mx-auto w-full max-w-2xl space-y-4 p-4 sm:p-6' }, skeleton({ rows: 5 }));
    const scroller = h('div', { class: 'min-h-0 flex-1 overflow-auto' }, content);
    if (standalone) {
      el.className = 'view flex min-h-dvh flex-col bg-bg';
      el.replaceChildren(
        h('header', { class: 'flex h-12 shrink-0 items-center gap-2 border-b border-border bg-surface px-3 pt-[env(safe-area-inset-top)]' },
          h('span', { class: 'flex size-7 items-center justify-center rounded-lg bg-accent text-accent-fg' }, icon('logo', 'size-4')),
          h('span', { class: 'font-semibold' }, 'LDB'),
          h('div', { class: 'flex-1' }),
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onClick: () => logout().catch(toastError) }, icon('log-out'), t('topbar.logout'))),
        scroller);
    } else {
      el.replaceChildren(scroller);
    }
    const rerender = () => this.render(el, ctx);

    let st;
    try {
      st = await api('twofa.status', { conn });
    } catch (e) {
      content.replaceChildren(errorState(e, { onRetry: rerender }));
      return;
    }

    const card = (id, title, ...body) => h('section', { class: 'card space-y-3 p-4 sm:p-5', 'aria-labelledby': id },
      h('h2', { id, class: 'text-[15px] font-semibold' }, title), ...body);
    const badge = st.enabled
      ? h('span', { class: 'badge badge-success' }, icon('shield-check'), t('twofa.status.enabled'))
      : h('span', { class: 'badge' }, t('twofa.status.disabled'));

    const head = h('div', { class: 'space-y-1' },
      h('h1', { class: 'flex flex-wrap items-center gap-2 text-lg font-semibold' }, icon('shield-check', 'size-5 text-accent'), t('twofa.heading'), badge),
      h('p', { class: 'text-[13px] text-muted' }, t('twofa.intro')),
      h('p', { class: 'text-[13px]' }, t('twofa.account', { account: st.account }), st.required ? h('span', { class: 'ms-2 text-muted' }, `· ${t('twofa.status.required')}`) : null));

    const parts = [head];
    if (st.enroll_required) parts.push(callout('warning', t('twofa.enrollRequired')));
    if (!st.available) {
      parts.push(callout('warning', t('twofa.unavailable')));
    } else if (!st.enabled) {
      const startBtn = h('button', { type: 'button', class: 'btn btn-primary' }, icon('key-round'), t('twofa.enable'));
      const area = h('div', {}, startBtn);
      startBtn.addEventListener('click', () => startEnrollment(area, startBtn));
      parts.push(card('twofa-setup', t('twofa.enable'), area));
      if (st.enroll_required) setTimeout(() => startBtn.click(), 0);
    } else {
      const remaining = h('p', { class: 'text-[13px] text-muted' }, plural('twofa.recovery.remaining', st.recovery_remaining ?? 0));
      const regenBtn = h('button', { type: 'button', class: 'btn' }, icon('refresh'), t('twofa.recovery.regenerate'));
      regenBtn.addEventListener('click', async () => {
        const res = await confirmWithCode(t('twofa.recovery.regenerate'), 'twofa.recoveryRegenerate', false);
        if (res) showRecovery(res.recovery_codes, rerender);
      });
      parts.push(card('twofa-recovery', t('twofa.recovery.title'), remaining,
        h('div', { class: 'flex flex-wrap items-center gap-3' }, regenBtn, h('span', { class: 'help mt-0' }, t('twofa.recovery.regenerateHelp')))));

      const disableBtn = h('button', { type: 'button', class: 'btn btn-danger', disabled: !!st.required }, icon('x'), t('twofa.disable'));
      disableBtn.addEventListener('click', async () => {
        const res = await confirmWithCode(t('twofa.disable'), 'twofa.disable', true);
        if (res) { toast.success(t('twofa.disabledToast')); rerender(); }
      });
      parts.push(card('twofa-disable', t('twofa.disable'), st.required ? h('p', { class: 'text-[13px] text-muted' }, t('twofa.disableBlocked')) : null, h('div', {}, disableBtn)));
    }
    parts.push(h('p', { class: 'text-xs text-muted' }, t('twofa.lostDevice')));
    content.replaceChildren(...parts);

    // ---------------------------------------------------------------- enrollment
    async function startEnrollment(area, startBtn) {
      startBtn.disabled = true;
      let data;
      try {
        data = await api('twofa.enrollStart', { conn });
      } catch (e) {
        startBtn.disabled = false;
        toastError(e);
        return;
      }
      const qr = h('div', { class: 'mx-auto w-full max-w-[240px] rounded-lg bg-white p-2 sm:mx-0' }, qrSvg(data.uri, { label: t('twofa.qrLabel', { account: data.account }) }));
      const keyText = h('code', { class: 'block select-all break-words rounded-md bg-surface-2 px-2 py-1.5 font-mono text-[13px]', dir: 'ltr' }, groupKey(data.secret));
      const copyBtn = h('button', { type: 'button', class: 'btn btn-sm', onClick: async () => { if (await copyText(data.secret)) toast.success(t('common.copied')); } }, icon('copy'), t('twofa.copySecret'));
      const field = codeField({ onComplete: () => form.requestSubmit() });
      const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, icon('check'), t('twofa.activate'));
      let busy = false;
      const form = h('form', {
        class: 'space-y-3', novalidate: true,
        onSubmit: async (e) => {
          e.preventDefault();
          if (busy) return;
          const p = field.params();
          if (!p) { field.error(t('twofa.invalid')); field.focus(); return; }
          busy = true;
          submit.disabled = true;
          try {
            const res = await api('twofa.enrollConfirm', { conn, code: p.code });
            // Connection flags changed (twofa_enroll cleared): refresh the auth state.
            setAuth(await api('auth.status').catch(() => appStore.get().auth));
            toast.success(t('twofa.enabledToast'));
            showRecovery(res.recovery_codes, () => (st.enroll_required ? navigate(href.server(conn)) : rerender()));
          } catch (err) {
            if (err.code === 'twofa_expired') { toast.error(t('twofa.setupExpired')); rerender(); return; }
            field.clear();
            field.error(codeErrorMessage(err));
            field.focus();
          } finally {
            busy = false;
            submit.disabled = false;
          }
        },
      }, field.el, submit);

      const step = (n, title, ...body) => h('li', { class: 'space-y-2' },
        h('h3', { class: 'flex items-center gap-2 text-[14px] font-medium' },
          h('span', { class: 'flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent', 'aria-hidden': 'true' }, String(n)), title),
        ...body);
      area.replaceChildren(h('ol', { class: 'space-y-5' },
        step(1, t('twofa.step1'), h('div', { class: 'flex flex-col gap-4 sm:flex-row sm:items-start' }, qr,
          h('div', { class: 'min-w-0 flex-1 space-y-2' }, h('p', { class: 'text-[13px] text-muted' }, t('twofa.manual')), keyText, copyBtn))),
        step(2, t('twofa.step2'), form)));
      announce(t('twofa.step1'));
      field.focus();
    }

    // ---------------------------------------------------------------- recovery codes (shown once)
    function showRecovery(codes, onDone) {
      const list = h('ol', { class: 'grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg bg-surface-2 p-3 font-mono text-[14px]', dir: 'ltr' },
        codes.map((c) => h('li', {}, c)));
      const text = `${t('twofa.recovery.fileHeader', { account: st.account })}\n\n${codes.join('\n')}\n`;
      const doneBtn = h('button', { type: 'button', class: 'btn btn-primary', onClick: () => m.close(true) }, icon('check'), t('twofa.recovery.done'));
      const m = openModal({
        title: t('twofa.recovery.title'),
        description: t('twofa.recovery.intro'),
        dismissible: false,
        body: [list],
        actions: [
          h('button', { type: 'button', class: 'btn', onClick: async () => { if (await copyText(codes.join('\n'))) toast.success(t('common.copied')); } }, icon('copy'), t('twofa.recovery.copy')),
          h('button', { type: 'button', class: 'btn', onClick: () => downloadText(`ldb-recovery-codes-${String(st.account).replace(/[^\w.@-]+/g, '_')}.txt`, text) }, icon('download'), t('twofa.recovery.download')),
          doneBtn,
        ],
        initialFocus: doneBtn,
      });
      m.closed.then(() => onDone());
    }

    // ---------------------------------------------------------------- code confirmation dialog
    function confirmWithCode(title, action, danger) {
      return new Promise((resolve) => {
        let result = null;
        const field = codeField({ recovery: true, autoSubmit: false });
        const okBtn = h('button', { type: 'submit', form: 'twofa-confirm', class: `btn ${danger ? 'btn-danger' : 'btn-primary'}` }, t('common.confirm'));
        const form = h('form', {
          id: 'twofa-confirm', novalidate: true,
          onSubmit: async (e) => {
            e.preventDefault();
            const p = field.params();
            if (!p) { field.error(t('twofa.invalid')); field.focus(); return; }
            okBtn.disabled = true;
            try {
              result = await api(action, { conn, ...p });
              m.close(true);
            } catch (err) {
              field.clear();
              field.error(codeErrorMessage(err));
              field.focus();
            } finally {
              okBtn.disabled = false;
            }
          },
        }, field.el);
        const m = openModal({
          title,
          description: t('twofa.confirmBody'),
          size: 'sm',
          body: [form],
          actions: [h('button', { type: 'button', class: 'btn', onClick: () => m.close() }, t('common.cancel')), okBtn],
          initialFocus: field.input,
        });
        m.closed.then(() => resolve(result));
      });
    }
  },
};
