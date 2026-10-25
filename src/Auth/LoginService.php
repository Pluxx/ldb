<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Http\ApiException;

/**
 * The one code path that turns login input into a stored connection — used by `auth.login`
 * and by SSO links (public/sso.php), so both apply exactly the same policy: rate limit,
 * DriverFactory::resolveLogin() (presets, allow_arbitrary_server, host checks, SQLite
 * password/paths, forced read-only), a real connection test, the two-factor check, then
 * session-id regeneration.
 */
final class LoginService
{
    private Config $config;
    private SessionManager $session;
    private DriverFactory $factory;
    private RateLimiter $limiter;
    private RateLimiter $identityLimiter;
    private TwoFactor $twoFactor;

    /** Session slot of the pending (second factor missing) login. */
    public const PENDING = 'pending_2fa';
    public const PENDING_TTL = 300;
    public const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        Config $config,
        SessionManager $session,
        DriverFactory $factory,
        ?RateLimiter $limiter = null,
        ?TwoFactor $twoFactor = null,
        ?RateLimiter $identityLimiter = null
    ) {
        $this->config = $config;
        $this->session = $session;
        $this->factory = $factory;
        $this->limiter = $limiter ?? self::rateLimiter($config);
        $this->twoFactor = $twoFactor ?? new TwoFactor($config);
        $this->identityLimiter = $identityLimiter ?? self::identityLimiter($config);
    }

    /** Login rate limiter configured from `login_max_attempts`, `login_window`, `rate_limit_dir`. */
    public static function rateLimiter(Config $config): RateLimiter
    {
        $dir = $config->get('rate_limit_dir');
        return new RateLimiter(
            $config->int('login_max_attempts', 5),
            $config->int('login_window', 300),
            is_string($dir) && $dir !== '' ? $dir : null
        );
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter;
    }

    /**
     * Validate, connect and store a connection. Failures count against $rateKey.
     *
     * Two-factor authentication (TwoFactor): when the login has 2FA enabled, nothing is stored
     * yet — the verified parameters are kept as an encrypted, short-lived pending login and
     * ['twofa_required' => true] is returned; verifySecondFactor() completes it. SSO logins
     * ($sso) of 2FA-enabled identities are refused unless `sso_bypass_2fa` is set.
     * @param array<string,mixed> $input auth.login parameters
     * @param bool|null $allowArbitrary override for free host entry (null = login-page policy)
     * @return array{conn?:string,twofa_required?:bool,twofa_enroll?:bool}
     * @throws ApiException
     */
    public function login(array $input, string $rateKey, ?bool $allowArbitrary = null, bool $sso = false): array
    {
        if ($this->limiter->tooManyAttempts($rateKey)) {
            throw ApiException::rateLimited();
        }
        try {
            // Inside the try: policy failures (wrong SQLite password, bad preset …) count as attempts too.
            $params = $this->factory->resolveLogin($input, $allowArbitrary);
            $db = $this->factory->connect($params);
            $info = $db->serverInfo();
            $state = ($sso && $this->config->bool('sso_bypass_2fa')) ? TwoFactor::OFF : $this->twoFactor->loginState($params);
            if ($sso && $state === TwoFactor::ENABLED) {
                error_log('[ldb] SSO login of ' . TwoFactor::describe(TwoFactor::identity($params))
                    . ' rejected: two-factor authentication is enabled for it (set sso_bypass_2fa to let the panel vouch for the user)');
                throw new ApiException('Two-factor authentication is enabled for this login; SSO is not allowed', 'twofa_required', 403);
            }
        } catch (\PDOException $e) {
            $this->limiter->hit($rateKey);
            throw new ApiException('Login failed: ' . self::preview($e->getMessage(), 300), 'db_error', 401, null, $e);
        } catch (ApiException $e) {
            $this->limiter->hit($rateKey);
            throw $e;
        }
        $meta = $this->meta($input, $params, $info);

        if ($state === TwoFactor::ENABLED) {
            // Password was right, but the login limiter is only cleared after the second factor.
            $this->session->putTransient(self::PENDING, ['params' => $params, 'meta' => $meta], self::PENDING_TTL);
            return ['twofa_required' => true];
        }
        $this->limiter->clear($rateKey);
        if ($state === TwoFactor::ENROLL) {
            $meta['twofa_enroll'] = true;
        }
        $conn = $this->session->addConnection($params, $meta);
        $this->session->csrf()->regenerate();
        return ['conn' => $conn] + ($state === TwoFactor::ENROLL ? ['twofa_enroll' => true] : []);
    }

    /**
     * Second step of a 2FA login: check a TOTP code or a recovery code against the pending
     * login and store the connection (session id regenerated). Every failure counts against
     * the login limiter ($rateKey, per IP) and a per-identity limiter; the pending login is
     * discarded after MAX_CODE_ATTEMPTS failures or PENDING_TTL seconds.
     * @return string new connection id
     * @throws ApiException codes: twofa_expired, twofa_invalid (details.remaining), rate_limited
     */
    public function verifySecondFactor(?string $code, ?string $recoveryCode, string $rateKey): string
    {
        $pending = $this->session->transient(self::PENDING);
        if ($pending === null || !is_array($pending['data']['params'] ?? null) || $pending['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            $this->session->forgetTransient(self::PENDING);
            throw new ApiException('The sign-in has expired. Please log in again.', 'twofa_expired', 401);
        }
        $params = $pending['data']['params'];
        $meta = is_array($pending['data']['meta'] ?? null) ? $pending['data']['meta'] : [];
        $identity = TwoFactor::identity($params);
        $idKey = 'twofa|' . hash('sha256', $identity);
        if ($this->limiter->tooManyAttempts($rateKey) || $this->identityLimiter->tooManyAttempts($idKey)) {
            throw ApiException::rateLimited();
        }
        $method = (($code ?? '') === '' && ($recoveryCode ?? '') === '')
            ? null : $this->twoFactor->verify($identity, $code, $recoveryCode);
        if ($method === null) {
            $this->limiter->hit($rateKey);
            $this->identityLimiter->hit($idKey);
            $attempts = $this->session->bumpTransientAttempts(self::PENDING);
            $remaining = max(0, self::MAX_CODE_ATTEMPTS - $attempts);
            if ($remaining === 0) {
                $this->session->forgetTransient(self::PENDING);
                error_log('[ldb] 2FA: too many wrong codes for ' . TwoFactor::describe($identity) . ', pending login discarded');
                throw (new ApiException('Too many wrong codes. Please log in again.', 'twofa_expired', 401))->withDetails(['remaining' => 0]);
            }
            throw (new ApiException('Invalid authentication code', 'twofa_invalid', 401))->withDetails(['remaining' => $remaining]);
        }
        $this->session->forgetTransient(self::PENDING);
        $this->limiter->clear($rateKey);
        $this->identityLimiter->clear($idKey);
        if ($method === 'recovery') {
            error_log('[ldb] 2FA: recovery code used for ' . TwoFactor::describe($identity));
        }
        $conn = $this->session->addConnection($params, $meta);
        $this->session->csrf()->regenerate();
        return $conn;
    }

    /**
     * Per-identity limiter for second-factor failures (login, disable, regenerate):
     * `twofa_max_failures` per `twofa_window` seconds.
     */
    public static function identityLimiter(Config $config): RateLimiter
    {
        $dir = $config->get('rate_limit_dir');
        return new RateLimiter(
            $config->int('twofa_max_failures', 10),
            $config->int('twofa_window', 900),
            is_string($dir) && $dir !== '' ? $dir : null
        );
    }

    /**
     * Public connection metadata.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $params
     * @param array<string,mixed> $info
     * @return array<string,mixed>
     */
    private function meta(array $input, array $params, array $info): array
    {
        $label = isset($input['label']) && is_scalar($input['label']) && (string) $input['label'] !== ''
            ? (string) $input['label'] : null;
        return [
            'driver' => $params['driver'],
            'host' => $params['driver'] === 'sqlite' ? null : $params['host'],
            'user' => $params['driver'] === 'sqlite' ? null : $params['user'],
            'server' => $params['server'],
            'label' => $label !== null ? self::preview($label, 80)
                : ($params['driver'] === 'sqlite' ? $params['server'] : $params['user'] . '@' . $params['server']),
            'version' => isset($info['version']) ? (string) $info['version'] : null,
            // Support policy (Ldb\Db\ServerVersion): older servers connect, the UI shows a warning.
            'version_supported' => !isset($info['version_supported']) || (bool) $info['version_supported'],
            'min_version' => isset($info['min_version']) ? (string) $info['min_version'] : null,
            'version_flavor' => isset($info['version_flavor']) ? (string) $info['version_flavor'] : null,
            'version_number' => isset($info['version_number']) ? (string) $info['version_number'] : null,
            'database' => $params['database'],
            'preset' => $params['preset'],
            'read_only' => !empty($params['read_only']),
        ];
    }

    private static function preview(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') <= $max ? $s : mb_substr($s, 0, $max, 'UTF-8') . '…';
    }
}
