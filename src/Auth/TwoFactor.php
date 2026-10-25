<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;
use Ldb\Http\ApiException;

/**
 * Two-factor authentication (TOTP + single-use recovery codes) for database logins.
 *
 * LDB has no user accounts of its own: the identity is the database login, i.e. the server
 * (driver + host + port, or the SQLite file) plus the DB user name. The server address is used
 * even for presets, so a preset login and a free-host login to the same host:port are the same
 * identity (free host entry cannot be used to skip the second factor). Records live in
 * TwoFactorStore. Policy:
 *  - `secret_key` missing → 2FA unavailable; logins of identities that still have a record are
 *    refused (fail closed) and `twofa_required` refuses every affected login.
 *  - `twofa_required` (true, or a list of preset ids) → logins without a record must enroll
 *    before using anything (Kernel answers `twofa_enroll_required`).
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 10;
    public const ISSUER = 'LDB';
    /** Recovery code alphabet: base32 without easily confused characters. */
    private const RC_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const RC_LENGTH = 10;
    private const DEFAULT_PORTS = ['mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433];

    /** Login states returned by loginState(). */
    public const OFF = 'off';
    public const ENABLED = 'enabled';
    public const ENROLL = 'enroll';

    private Config $config;
    private ?TwoFactorStore $store;
    /** @var callable():int */
    private $clock;

    public function __construct(Config $config, ?TwoFactorStore $store = null, ?callable $clock = null)
    {
        $this->config = $config;
        $this->store = $store ?? TwoFactorStore::fromConfig($config);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function available(): bool
    {
        return $this->store !== null;
    }

    public function store(): ?TwoFactorStore
    {
        return $this->store;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Stable identity of a login from resolved connection params (DriverFactory::resolveLogin()
     * output or stored connection params). JSON-encoded so no two logins can collide.
     * @param array<string,mixed> $params
     */
    public static function identity(array $params): string
    {
        $driver = (string) ($params['driver'] ?? '');
        if ($driver === 'sqlite') {
            return (string) json_encode(['sqlite', (string) ($params['file'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        [, $host, $port] = self::server($params);
        $parts = ['server', $driver, $host, $port, (string) ($params['user'] ?? '')];
        return (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Canonical [driver, host (lower case), port] of connection params or a `servers` preset
     * entry (missing host = localhost, missing port = the driver's default port).
     * @param array<string,mixed> $p
     * @return array{0:string,1:string,2:int}
     */
    public static function server(array $p): array
    {
        $driver = (string) ($p['driver'] ?? 'mysql');
        $host = strtolower(trim((string) ($p['host'] ?? '')));
        $port = isset($p['port']) && is_numeric($p['port']) && (int) $p['port'] > 0 ? (int) $p['port'] : (self::DEFAULT_PORTS[$driver] ?? 0);
        return [$driver, $host === '' ? 'localhost' : $host, $port];
    }

    /** Human readable form of an identity, e.g. "root @ mysql://mariadb:3306". */
    public static function describe(string $identity): string
    {
        $p = json_decode($identity, true);
        if (!is_array($p) || !isset($p[0])) {
            return $identity;
        }
        switch ($p[0]) {
            case 'sqlite':
                return 'sqlite ' . ($p[1] ?? '');
            default:
                return ($p[4] ?? '') . ' @ ' . ($p[1] ?? '') . '://' . ($p[2] ?? '') . ':' . ($p[3] ?? '');
        }
    }

    /** Account name shown in authenticator apps. @param array<string,mixed> $params */
    public static function accountName(array $params): string
    {
        if (($params['driver'] ?? '') === 'sqlite') {
            return basename((string) ($params['file'] ?? 'sqlite'));
        }
        $server = isset($params['preset']) && $params['preset'] !== null && $params['preset'] !== ''
            ? (string) $params['preset'] : (string) ($params['server'] ?? ($params['host'] ?? ''));
        return (string) ($params['user'] ?? '') . '@' . $server;
    }

    /** Whether `twofa_required` applies to this login. @param array<string,mixed> $params */
    public function required(array $params): bool
    {
        $req = $this->config->get('twofa_required', false);
        if (is_array($req)) {
            if (($params['driver'] ?? '') === 'sqlite') {
                return false;
            }
            // By preset id, or by the server address of a listed preset (free host entry to
            // the same host:port does not escape the policy).
            $ids = array_map('strval', $req);
            $preset = isset($params['preset']) && $params['preset'] !== null ? (string) $params['preset'] : null;
            if ($preset !== null && in_array($preset, $ids, true)) {
                return true;
            }
            $target = self::server($params);
            foreach ($this->config->arr('servers') as $i => $p) {
                if (is_array($p) && in_array((string) ($p['id'] ?? $i), $ids, true) && self::server($p) === $target) {
                    return true;
                }
            }
            return false;
        }
        return (bool) $req;
    }

    /**
     * What the login of $params needs: OFF (no second factor), ENABLED (code required) or
     * ENROLL (policy requires 2FA but none is set up yet).
     * @param array<string,mixed> $params
     * @throws ApiException when 2FA state cannot be determined safely (fail closed)
     */
    public function loginState(array $params): string
    {
        $identity = self::identity($params);
        try {
            $exists = $this->store !== null
                ? $this->store->exists($identity)
                : TwoFactorStore::recordExists(TwoFactorStore::recordDir($this->config), $identity);
        } catch (\RuntimeException $e) {
            error_log('[ldb] 2FA: ' . $e->getMessage());
            throw self::unavailable();
        }
        if ($exists) {
            if ($this->store === null) {
                error_log('[ldb] 2FA: login of ' . self::describe($identity) . ' refused: a 2FA record exists but secret_key/data_dir is not configured');
                throw self::unavailable();
            }
            return self::ENABLED;
        }
        if ($this->required($params)) {
            if ($this->store === null) {
                error_log('[ldb] 2FA: twofa_required is set but secret_key or data_dir is missing; login refused');
                throw self::unavailable();
            }
            return self::ENROLL;
        }
        return self::OFF;
    }

    public static function unavailable(): ApiException
    {
        return new ApiException('Two-factor authentication is not available on this server (see the server log)', 'twofa_unavailable', 503);
    }

    public function enabled(string $identity): bool
    {
        return $this->store !== null && $this->store->exists($identity);
    }

    /**
     * Verify a TOTP code or a recovery code for $identity and record its use (time step for
     * replay protection, recovery code consumed). Returns 'totp', 'recovery' or null.
     */
    public function verify(string $identity, ?string $code, ?string $recoveryCode): ?string
    {
        $store = $this->requireStore();
        $now = $this->now();
        $method = null;
        try {
            $store->update($identity, $this->verifier($code, $recoveryCode, $now, $method));
        } catch (ApiException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            error_log('[ldb] 2FA: ' . $e->getMessage() . ' (' . self::describe($identity) . ')');
            throw self::unavailable();
        }
        return $method;
    }

    /** @param string|null $method set to the method that matched */
    private function verifier(?string $code, ?string $recoveryCode, int $now, ?string &$method): callable
    {
        return function (?array $record) use ($code, $recoveryCode, $now, &$method) {
            if ($record === null || !is_string($record['secret'] ?? null)) {
                return false;
            }
            if ($code !== null && $code !== '') {
                $last = isset($record['last_step']) ? (int) $record['last_step'] : null;
                $step = Totp::verify($record['secret'], $code, $now, $last);
                if ($step === null) {
                    return false;
                }
                $record['last_step'] = $step;
                $method = 'totp';
            } elseif ($recoveryCode !== null && $recoveryCode !== '') {
                $idx = self::matchRecovery((array) ($record['recovery'] ?? []), $recoveryCode);
                if ($idx === null) {
                    return false;
                }
                array_splice($record['recovery'], $idx, 1);
                $method = 'recovery';
            } else {
                return false;
            }
            $record['last_used'] = $now;
            return $record;
        };
    }

    /** Remaining recovery codes (null when not enrolled). */
    public function recoveryRemaining(string $identity): ?int
    {
        $record = $this->requireStore()->load($identity);
        return $record === null ? null : count((array) ($record['recovery'] ?? []));
    }

    /**
     * Complete an enrollment: $secret (from enrollment start) must produce $code now.
     * @return string[]|null new recovery codes (plain, shown once) or null on a wrong code
     */
    public function enroll(string $identity, string $secret, string $code, string $label): ?array
    {
        $store = $this->requireStore();
        $now = $this->now();
        $step = Totp::verify($secret, $code, $now);
        if ($step === null) {
            return null;
        }
        [$plain, $hashes] = self::newRecoveryCodes();
        $created = false;
        $store->update($identity, function (?array $record) use ($secret, $step, $hashes, $now, $label, &$created) {
            if ($record !== null) {
                return false; // already enrolled (e.g. a second tab): keep the existing secret
            }
            $created = true;
            return ['secret' => $secret, 'last_step' => $step, 'recovery' => $hashes, 'created' => $now, 'label' => $label];
        });
        if (!$created) {
            throw new ApiException('Two-factor authentication is already enabled for this login', 'conflict', 409);
        }
        return $plain;
    }

    /** Replace all recovery codes. @return string[] */
    public function regenerateRecovery(string $identity): array
    {
        [$plain, $hashes] = self::newRecoveryCodes();
        $result = $this->requireStore()->update($identity, function (?array $record) use ($hashes) {
            if ($record === null) {
                return false;
            }
            $record['recovery'] = $hashes;
            return $record;
        });
        if (!is_array($result)) {
            throw new ApiException('Two-factor authentication is not enabled for this login', 'bad_request', 400);
        }
        return $plain;
    }

    public function disable(string $identity): bool
    {
        return $this->requireStore()->delete($identity);
    }

    /** Normalise a typed recovery code: lower case, without spaces and dashes. */
    public static function normalizeRecovery(string $code): string
    {
        return strtolower((string) preg_replace('/[\s\-]+/', '', $code));
    }

    /**
     * @param array<mixed> $hashes
     * Every hash is checked (no early exit) so timing does not reveal which code matched.
     */
    private static function matchRecovery(array $hashes, string $code): ?int
    {
        $code = self::normalizeRecovery($code);
        if (strlen($code) !== self::RC_LENGTH || strspn($code, self::RC_ALPHABET) !== self::RC_LENGTH) {
            return null;
        }
        $match = null;
        foreach (array_values($hashes) as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash) && $match === null) {
                $match = $i;
            }
        }
        return $match;
    }

    /** @return array{0:string[],1:string[]} [plain codes "xxxxx-xxxxx", password_hash()es] */
    public static function newRecoveryCodes(): array
    {
        $plain = [];
        $hashes = [];
        $n = strlen(self::RC_ALPHABET);
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = '';
            for ($j = 0; $j < self::RC_LENGTH; $j++) {
                $c .= self::RC_ALPHABET[random_int(0, $n - 1)];
            }
            $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        return [$plain, $hashes];
    }

    private function requireStore(): TwoFactorStore
    {
        if ($this->store === null) {
            throw new ApiException('Two-factor authentication needs secret_key and a persistent data_dir in config/config.php', 'twofa_unavailable', 503);
        }
        return $this->store;
    }
}
