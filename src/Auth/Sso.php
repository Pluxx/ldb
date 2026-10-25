<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;

/**
 * Single sign-on links for control panels (see docs/sso.md for the token format).
 *
 *   token = base64url( version[1] || iv[12] || ciphertext || tag[16] )      (no padding)
 *   key   = HKDF-SHA256(ikm = sso_secret, salt = "", info = "ldb-sso v1 aes-256-gcm", 32 bytes)
 *   ciphertext/tag = AES-256-GCM(key, iv, plaintext = JSON claims, aad = version byte)
 *
 * GCM authenticates the whole token (the version byte via AAD), so only holders of the secret
 * can create tokens, and the credentials inside stay confidential. Freshness: `iat`/`exp` with a
 * short maximum lifetime (`sso_ttl`, at most MAX_TTL), plus a one-time `nonce` (SsoNonceStore).
 */
final class Sso
{
    public const VERSION = 1;
    public const MIN_SECRET_BYTES = 32;
    public const DEFAULT_TTL = 60;
    public const MAX_TTL = 300;
    /** Accepted clock difference between the panel and this server (seconds) for `iat`. */
    public const CLOCK_SKEW = 30;
    public const MAX_TOKEN_LENGTH = 8192;
    public const HKDF_INFO = 'ldb-sso v1 aes-256-gcm';
    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';
    public const CONN_PLACEHOLDER = '{conn}';

    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MAX_FRAGMENT = 2048;

    private const STRING_CLAIMS = ['preset', 'driver', 'host', 'user', 'password', 'database', 'schema', 'table', 'label'];

    /** Configured secret, or null when SSO is disabled (missing or shorter than MIN_SECRET_BYTES). */
    public static function secret(Config $config): ?string
    {
        $s = $config->get('sso_secret');
        return is_string($s) && strlen($s) >= self::MIN_SECRET_BYTES ? $s : null;
    }

    /** Maximum token lifetime (exp - iat) in seconds: `sso_ttl`, clamped to 1..MAX_TTL. */
    public static function ttl(Config $config): int
    {
        return max(1, min(self::MAX_TTL, $config->int('sso_ttl', self::DEFAULT_TTL)));
    }

    /**
     * Create a token. Missing iat/exp/nonce are filled in (now, now + DEFAULT_TTL, 16 random bytes).
     * @param array<string,mixed> $claims
     */
    public static function createToken(array $claims, string $secret): string
    {
        self::assertSecret($secret);
        $now = time();
        $claims += ['iat' => $now];
        $claims += ['exp' => (int) $claims['iat'] + self::DEFAULT_TTL];
        $claims += ['nonce' => self::b64urlEncode(random_bytes(16))];
        self::checkShape($claims);
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \InvalidArgumentException('Claims are not JSON-encodable');
        }
        $version = chr(self::VERSION);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($json, self::CIPHER, self::key($secret), OPENSSL_RAW_DATA, $iv, $tag, $version, self::TAG_BYTES);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::b64urlEncode($version . $iv . $cipher . $tag);
    }

    /**
     * Link to `<baseUrl>/sso.php?token=…`. $baseUrl is LDB's public URL (directory, or the
     * sso.php URL itself).
     * @param array<string,mixed> $claims
     */
    public static function createUrl(string $baseUrl, array $claims, string $secret): string
    {
        $base = preg_match('~/sso\.php$~', $baseUrl) ? $baseUrl : rtrim($baseUrl, '/') . '/sso.php';
        return $base . '?token=' . self::createToken($claims, $secret);
    }

    /**
     * Decrypt and authenticate a token, check its claims and freshness, then consume its nonce.
     * @return array<string,mixed> claims
     * @throws SsoException
     */
    public static function verify(string $token, string $secret, int $ttl, SsoNonceStore $nonces, ?int $now = null): array
    {
        $now = $now ?? time();
        $claims = self::decrypt($token, $secret);
        self::checkShape($claims);
        self::checkTimes($claims, $ttl, $now);
        // Last: a token that fails any other check must not burn its nonce.
        if (!$nonces->consume((string) $claims['nonce'], $now)) {
            throw new SsoException('token already used (replay)');
        }
        return $claims;
    }

    /**
     * @return array<string,mixed> claims (authenticated, NOT yet checked for shape/freshness)
     * @throws SsoException
     */
    public static function decrypt(string $token, string $secret): array
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new SsoException('SSO secret missing or too short');
        }
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new SsoException('malformed token (encoding/length)');
        }
        $raw = self::b64urlDecode($token);
        if ($raw === null || strlen($raw) < 1 + self::IV_BYTES + self::TAG_BYTES + 2) {
            throw new SsoException('malformed token (base64url)');
        }
        $version = $raw[0];
        if (ord($version) !== self::VERSION) {
            throw new SsoException('unsupported token version ' . ord($version));
        }
        $iv = substr($raw, 1, self::IV_BYTES);
        $tag = substr($raw, -self::TAG_BYTES);
        $cipher = substr($raw, 1 + self::IV_BYTES, -self::TAG_BYTES);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key($secret), OPENSSL_RAW_DATA, $iv, $tag, $version);
        if ($plain === false) {
            throw new SsoException('token authentication failed (tampered or wrong secret)');
        }
        $claims = json_decode($plain, true);
        if (!is_array($claims) || ($claims !== [] && array_keys($claims) === range(0, count($claims) - 1))) {
            throw new SsoException('token payload is not a JSON object');
        }
        return $claims;
    }

    /**
     * Turn verified claims into auth.login input, applying the SSO server policy
     * (`sso_allowed_presets`, `sso_allow_arbitrary_server`). Everything else — preset lookup,
     * driver availability, host validation, SQLite password/paths, forced read-only — is done
     * by the shared login path (LoginService → DriverFactory::resolveLogin()).
     * @param array<string,mixed> $claims
     * @return array{0:array<string,mixed>,1:bool} [login input, allow arbitrary server]
     * @throws SsoException
     */
    public static function loginInput(array $claims, Config $config): array
    {
        $preset = isset($claims['preset']) && $claims['preset'] !== '' ? (string) $claims['preset'] : null;
        $allowArbitrary = $config->bool('sso_allow_arbitrary_server') && !$config->bool('only_presets');
        $input = [
            'user' => (string) ($claims['user'] ?? ''),
            'password' => (string) ($claims['password'] ?? ''),
            'read_only' => !empty($claims['read_only']),
        ];
        foreach (['database', 'label'] as $k) {
            if (isset($claims[$k]) && $claims[$k] !== '') {
                $input[$k] = (string) $claims[$k];
            }
        }
        if ($preset !== null) {
            foreach (['driver', 'host', 'port'] as $k) {
                if (isset($claims[$k])) {
                    throw new SsoException('claims combine "preset" with "' . $k . '"');
                }
            }
            $allowed = $config->get('sso_allowed_presets');
            if (is_array($allowed) && $allowed !== [] && !in_array($preset, array_map('strval', $allowed), true)) {
                throw new SsoException('preset "' . $preset . '" is not in sso_allowed_presets');
            }
            $input['preset'] = $preset;
        } else {
            if (!$allowArbitrary) {
                throw new SsoException('no preset given and sso_allow_arbitrary_server is off');
            }
            if (!isset($claims['driver']) || $claims['driver'] === '') {
                throw new SsoException('claims need "preset" or "driver"');
            }
            $input['driver'] = (string) $claims['driver'];
            $input['host'] = (string) ($claims['host'] ?? '');
            if (isset($claims['port'])) {
                $input['port'] = (int) $claims['port'];
            }
        }
        return [$input, $allowArbitrary];
    }

    /**
     * App route to open after login, as a URL fragment ("#/c/…"): the `redirect` claim (string
     * route, "{conn}" is replaced by the new connection id, or an object {db, schema?, table?}),
     * else the `database`/`schema` claims, else the server overview.
     * @param array<string,mixed> $claims
     * @throws SsoException
     */
    public static function redirectFragment(array $claims, string $conn): string
    {
        $c = rawurlencode($conn);
        $r = $claims['redirect'] ?? null;
        if (is_string($r) && $r !== '') {
            return self::validateFragment(str_replace(self::CONN_PLACEHOLDER, $c, $r));
        }
        $target = is_array($r) ? $r : ['db' => $claims['database'] ?? null, 'schema' => $claims['schema'] ?? null];
        foreach (['db', 'schema', 'table'] as $k) {
            if (isset($target[$k]) && !is_string($target[$k])) {
                throw new SsoException('redirect.' . $k . ' must be a string');
            }
        }
        $enc = static fn (string $s): string => rawurlencode($s); // also encodes '@' (db@schema separator)
        $f = '#/c/' . $c;
        if (isset($target['db']) && $target['db'] !== '') {
            $f .= '/db/' . $enc($target['db']);
            if (isset($target['schema']) && $target['schema'] !== '') {
                $f .= '@' . $enc($target['schema']);
            }
            if (isset($target['table']) && $target['table'] !== '') {
                $f .= '/t/' . $enc($target['table']) . '/browse';
            }
        }
        return self::validateFragment($f);
    }

    /**
     * A redirect may only be an in-app hash route: "#/" followed by RFC 3986 fragment characters
     * (no whitespace/CR/LF, backslash, quotes or angle brackets) and well-formed %XX escapes.
     * Absolute or protocol-relative URLs and schemes like javascript: can therefore never pass.
     * @throws SsoException
     */
    public static function validateFragment(string $f): string
    {
        if (strlen($f) > self::MAX_FRAGMENT
            || !preg_match('~^#/(?!/)[A-Za-z0-9\-._\~!$&\'()*+,;=:@%/?]*$~D', $f)
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $f)
        ) {
            throw new SsoException('invalid redirect (only in-app routes like "#/c/{conn}/db/name")');
        }
        return $f;
    }

    /**
     * Claim types (strings for STRING_CLAIMS, int port 1..65535, bool read_only, int iat/exp,
     * nonce pattern, string/object redirect).
     * @param array<string,mixed> $claims
     * @throws SsoException
     */
    private static function checkShape(array $claims): void
    {
        foreach (self::STRING_CLAIMS as $k) {
            if (isset($claims[$k]) && !is_string($claims[$k])) {
                throw new SsoException('claim "' . $k . '" must be a string');
            }
        }
        if (isset($claims['port']) && (!is_int($claims['port']) || $claims['port'] < 1 || $claims['port'] > 65535)) {
            throw new SsoException('claim "port" must be an integer 1..65535');
        }
        if (isset($claims['read_only']) && !is_bool($claims['read_only'])) {
            throw new SsoException('claim "read_only" must be a boolean');
        }
        if (!isset($claims['iat'], $claims['exp']) || !is_int($claims['iat']) || !is_int($claims['exp'])) {
            throw new SsoException('claims "iat" and "exp" must be integers');
        }
        if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !preg_match(self::NONCE_PATTERN, $claims['nonce'])) {
            throw new SsoException('claim "nonce" must be a base64url string of 16-128 characters');
        }
        if (isset($claims['redirect']) && !is_string($claims['redirect']) && !is_array($claims['redirect'])) {
            throw new SsoException('claim "redirect" must be a string or object');
        }
        if (empty($claims['preset']) && empty($claims['driver'])) {
            throw new SsoException('claims need "preset" or "driver"');
        }
        // Fail early on bad redirects (before the nonce is used or a connection is made).
        self::redirectFragment($claims, 'conn');
    }

    /** @param array<string,mixed> $claims @throws SsoException */
    private static function checkTimes(array $claims, int $ttl, int $now): void
    {
        $iat = (int) $claims['iat'];
        $exp = (int) $claims['exp'];
        if ($exp < $now) {
            throw new SsoException('token expired ' . ($now - $exp) . 's ago');
        }
        if ($iat > $now + self::CLOCK_SKEW) {
            throw new SsoException('token issued in the future (iat - now = ' . ($iat - $now) . 's; check clocks)');
        }
        if ($exp <= $iat) {
            throw new SsoException('token exp is not after iat');
        }
        if ($exp - $iat > max(1, min(self::MAX_TTL, $ttl))) {
            throw new SsoException('token lifetime ' . ($exp - $iat) . 's exceeds sso_ttl ' . $ttl . 's');
        }
    }

    private static function key(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO, '');
    }

    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException('SSO secret must be at least ' . self::MIN_SECRET_BYTES . ' bytes');
        }
    }

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        if (strlen($s) % 4 === 1) {
            return null;
        }
        $raw = base64_decode(str_pad(strtr($s, '-_', '+/'), (int) (ceil(strlen($s) / 4) * 4), '=', STR_PAD_RIGHT), true);
        return $raw === false ? null : $raw;
    }
}
