/**
 * Two-factor code input shared by the login code step and the account security page:
 * one field (numeric keyboard, `autocomplete="one-time-code"` so phones offer SMS/app codes,
 * pasting "123 456" works), optional switch to a recovery code, inline error wired through
 * aria-invalid/aria-describedby and a polite live region.
 * @module views/_twofa-code
 */
import { h, uid } from '../ui/dom.js';
import { t, plural } from '../i18n/index.js';

/**
 * @param {{recovery?: boolean, onComplete?: () => void, autoSubmit?: boolean}} [o]
 *   recovery: offer "use a recovery code"; onComplete: called when 6 digits were entered (autoSubmit)
 * @returns {{el: HTMLElement, input: HTMLInputElement, params: () => object|null, error: (msg: string|null) => void,
 *   clear: () => void, focus: () => void, isRecovery: () => boolean}}
 */
export function codeField(o = {}) {
  const id = uid('otp');
  const helpId = `${id}-help`;
  const errId = `${id}-err`;
  let recovery = false;
  const label = h('label', { class: 'label', for: id });
  const help = h('p', { id: helpId, class: 'help' });
  const err = h('p', { id: errId, class: 'mt-1 text-[13px] text-danger', 'aria-live': 'polite' });
  const input = h('input', {
    id, class: 'input h-11 text-center font-mono text-lg tracking-[0.3em]', dir: 'ltr', spellcheck: 'false',
    autocapitalize: 'none', autocorrect: 'off', 'aria-describedby': `${helpId} ${errId}`,
  });
  input.addEventListener('input', () => {
    error(null);
    if (recovery) return;
    const digits = input.value.replace(/\D+/g, '').slice(0, 6);
    if (digits !== input.value) input.value = digits;
    if (digits.length === 6 && o.autoSubmit !== false) o.onComplete?.();
  });

  const toggle = o.recovery ? h('button', { type: 'button', class: 'mt-2 text-[13px] text-accent hover:underline', onClick: () => { setMode(!recovery); input.focus(); } }) : null;

  function setMode(rec) {
    recovery = rec;
    input.value = '';
    error(null);
    label.textContent = rec ? t('twofa.recoveryCode') : t('twofa.code');
    help.textContent = rec ? t('twofa.recoveryHelp') : t('twofa.codeHelp');
    input.setAttribute('inputmode', rec ? 'text' : 'numeric');
    input.setAttribute('autocomplete', rec ? 'off' : 'one-time-code');
    // No maxlength: pasting "123 456" must not be cut off before the digits are extracted.
    if (rec) input.setAttribute('maxlength', '32'); else input.removeAttribute('maxlength');
    input.setAttribute('placeholder', rec ? 'xxxxx-xxxxx' : '000000');
    if (rec) input.removeAttribute('pattern'); else input.setAttribute('pattern', '[0-9]{6}');
    input.classList.toggle('tracking-[0.3em]', !rec);
    if (toggle) toggle.textContent = rec ? t('twofa.useCode') : t('twofa.useRecovery');
  }

  function error(msg) {
    err.textContent = msg || '';
    if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
  }

  setMode(false);
  return {
    el: h('div', {}, label, input, help, err, toggle),
    input,
    params() {
      const v = input.value.trim();
      if (recovery) return v ? { recovery_code: v } : null;
      return /^\d{6}$/.test(v) ? { code: v } : null;
    },
    error,
    clear() { input.value = ''; },
    focus() { input.focus(); },
    isRecovery: () => recovery,
  };
}

/**
 * User-facing message for a failed code check (ApiError from auth.verify2fa / twofa.*).
 * @param {import('../api.js').ApiError} err
 * @returns {string}
 */
export function codeErrorMessage(err) {
  switch (err.code) {
    case 'twofa_invalid': {
      const n = err.details?.remaining;
      return typeof n === 'number' ? plural('twofa.invalidRemaining', n) : t('twofa.invalid');
    }
    case 'twofa_expired': return t('twofa.expired');
    case 'rate_limited': return t('twofa.rateLimited');
    case 'twofa_unavailable': return t('twofa.serverUnavailable');
    case 'network': return t('login.networkError');
    default: return err.message;
  }
}
