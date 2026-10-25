<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Auth\LoginService;
use Ldb\Auth\Totp;
use Ldb\Auth\TwoFactor;
use Ldb\Http\ApiException;

/**
 * Self-service two-factor management for the login behind a connection (`twofa.*`).
 * Enrollment: enrollStart creates a secret kept (encrypted) in the session for ENROLL_TTL
 * seconds, enrollConfirm checks a code from the app and stores the record. Disabling and
 * regenerating recovery codes need a current code (or a recovery code); their failures count
 * against the per-identity limiter.
 */
final class TwoFactorController extends AbstractController
{
    private const ENROLL_TTL = 600;
    private const MAX_ENROLL_ATTEMPTS = 5;

    /** @return array<string,mixed> */
    public function status(): array
    {
        $params = $this->params();
        $tf = new TwoFactor($this->config);
        $identity = TwoFactor::identity($params);
        $enabled = $tf->available() && $tf->enabled($identity);
        return [
            'available' => $tf->available(),
            'enabled' => $enabled,
            'required' => $tf->required($params),
            'enroll_required' => $this->enrollFlag(),
            'recovery_remaining' => $enabled ? $tf->recoveryRemaining($identity) : null,
            'account' => TwoFactor::accountName($params),
        ];
    }

    /** @return array<string,mixed> {secret, uri, account, issuer, expires_in} */
    public function enrollStart(): array
    {
        $params = $this->params();
        $tf = $this->twoFactor();
        if ($tf->enabled(TwoFactor::identity($params))) {
            throw new ApiException('Two-factor authentication is already enabled for this login', 'conflict', 409);
        }
        $secret = Totp::generateSecret();
        $this->session->putTransient($this->enrollSlot(), ['secret' => $secret], self::ENROLL_TTL);
        $account = TwoFactor::accountName($params);
        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, $account, TwoFactor::ISSUER),
            'account' => $account,
            'issuer' => TwoFactor::ISSUER,
            'expires_in' => self::ENROLL_TTL,
        ];
    }

    /** @return array<string,mixed> {recovery_codes:[…]} (shown once) */
    public function enrollConfirm(): array
    {
        $params = $this->params();
        $tf = $this->twoFactor();
        $slot = $this->enrollSlot();
        $pending = $this->session->transient($slot);
        $secret = $pending['data']['secret'] ?? null;
        if (!is_string($secret) || $pending['attempts'] >= self::MAX_ENROLL_ATTEMPTS) {
            $this->session->forgetTransient($slot);
            throw new ApiException('The setup has expired. Please start again.', 'twofa_expired', 400);
        }
        $identity = TwoFactor::identity($params);
        $codes = $tf->enroll($identity, $secret, $this->request->string('code'), TwoFactor::accountName($params));
        if ($codes === null) {
            $remaining = self::MAX_ENROLL_ATTEMPTS - $this->session->bumpTransientAttempts($slot);
            if ($remaining <= 0) {
                $this->session->forgetTransient($slot);
                throw new ApiException('Too many wrong codes. Please start again.', 'twofa_expired', 400);
            }
            throw (new ApiException('Invalid authentication code', 'twofa_invalid', 400))->withDetails(['remaining' => $remaining]);
        }
        $this->session->forgetTransient($slot);
        error_log('[ldb] 2FA enabled for ' . TwoFactor::describe($identity));
        // Every connection of this session with the same login has now satisfied the policy.
        foreach ($this->session->connectionIds() as $id) {
            $p = $this->session->connectionParams($id);
            if ($p !== null && TwoFactor::identity($p) === $identity) {
                $this->session->updateConnectionMeta($id, ['twofa_enroll' => null]);
            }
        }
        return ['recovery_codes' => $codes];
    }

    public function disable(): bool
    {
        $params = $this->params();
        $tf = $this->twoFactor();
        if ($tf->required($params)) {
            throw new ApiException('Two-factor authentication is required for this login by the administrator', 'twofa_policy', 403);
        }
        $identity = $this->verifyCurrent($tf, $params);
        $tf->disable($identity);
        error_log('[ldb] 2FA disabled for ' . TwoFactor::describe($identity));
        return true;
    }

    /** @return array<string,mixed> {recovery_codes:[…]} */
    public function recoveryRegenerate(): array
    {
        $params = $this->params();
        $tf = $this->twoFactor();
        $identity = $this->verifyCurrent($tf, $params);
        return ['recovery_codes' => $tf->regenerateRecovery($identity)];
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function params(): array
    {
        $params = $this->session->connectionParams($this->connId());
        if ($params === null) {
            throw ApiException::authRequired('Unknown or expired connection');
        }
        return $params;
    }

    private function twoFactor(): TwoFactor
    {
        $tf = new TwoFactor($this->config);
        if (!$tf->available()) {
            throw new ApiException('Two-factor authentication needs secret_key in config/config.php', 'twofa_unavailable', 503);
        }
        return $tf;
    }

    private function enrollSlot(): string
    {
        return 'enroll|' . $this->connId();
    }

    private function enrollFlag(): bool
    {
        $id = $this->connId();
        foreach ($this->session->connections() as $meta) {
            if ((string) ($meta['id'] ?? '') === $id) {
                return !empty($meta['twofa_enroll']);
            }
        }
        return false;
    }

    /**
     * Require a current TOTP code (`code`) or a recovery code (`recovery_code`).
     * @param array<string,mixed> $params
     * @return string identity
     */
    private function verifyCurrent(TwoFactor $tf, array $params): string
    {
        $identity = TwoFactor::identity($params);
        if (!$tf->enabled($identity)) {
            throw new ApiException('Two-factor authentication is not enabled for this login', 'bad_request', 400);
        }
        $limiter = LoginService::identityLimiter($this->config);
        $key = 'twofa|' . hash('sha256', $identity);
        if ($limiter->tooManyAttempts($key)) {
            throw ApiException::rateLimited();
        }
        $code = $this->request->optString('code');
        $recovery = $code === null ? $this->request->optString('recovery_code') : null;
        if (($code === null && $recovery === null) || $tf->verify($identity, $code, $recovery) === null) {
            $limiter->hit($key);
            throw new ApiException('Invalid authentication code', 'twofa_invalid', 400);
        }
        $limiter->clear($key);
        return $identity;
    }
}
