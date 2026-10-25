<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;
use Ldb\Http\Request;

/**
 * PHP session wrapper holding several database connections per user.
 *
 * Credentials are stored AES-256-GCM encrypted in $_SESSION; the key lives only in a separate
 * HttpOnly, SameSite=Strict cookie, so a leaked session file alone cannot reveal passwords.
 */
final class SessionManager
{
    public const KEY_COOKIE = 'ldb_k';
    private const SLOT = 'ldb';

    private Config $config;
    private Request $request;
    private ?string $key = null;
    private bool $started = false;
    private ?Csrf $csrf = null;

    public function __construct(Config $config, Request $request)
    {
        $this->config = $config;
        $this->request = $request;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.cookie_httponly', '1');
            session_name((string) $this->config->get('session_name', 'ldb_sid'));
            $opts = $this->cookieOptions();
            unset($opts['expires']);
            session_set_cookie_params(['lifetime' => 0] + $opts);
            if (!session_start()) {
                throw new \RuntimeException('Could not start session');
            }
        }
        $this->started = true;
        if (!isset($_SESSION[self::SLOT]) || !is_array($_SESSION[self::SLOT])) {
            $_SESSION[self::SLOT] = ['connections' => [], 'last' => time()];
        }
        $this->loadKey();
        $this->enforceIdleTimeout();
        $_SESSION[self::SLOT]['last'] = time();
    }

    public function csrf(): Csrf
    {
        $this->start();
        if ($this->csrf === null) {
            $this->csrf = new Csrf($_SESSION[self::SLOT]);
        }
        return $this->csrf;
    }

    public function isAuthenticated(): bool
    {
        $this->start();
        return $this->connectionIds() !== [];
    }

    /**
     * Store a new connection; returns its id. Regenerates the session id (fixation defence).
     * @param array<string,mixed> $params full connection params including password
     * @param array<string,mixed> $meta public info: driver, host, user, label, server
     */
    public function addConnection(array $params, array $meta): string
    {
        $this->start();
        session_regenerate_id(true);
        $id = substr(Crypto::randomToken(9), 0, 12);
        $json = (string) json_encode($params, JSON_UNESCAPED_UNICODE);
        $_SESSION[self::SLOT]['connections'][$id] = [
            'meta' => ['id' => $id] + $meta,
            'secret' => Crypto::encrypt($json, $this->key(), $id),
        ];
        return $id;
    }

    /** @return array<string,mixed>|null decrypted connection params */
    public function connectionParams(string $id): ?array
    {
        $this->start();
        $entry = $_SESSION[self::SLOT]['connections'][$id] ?? null;
        if (!is_array($entry) || !isset($entry['secret'])) {
            return null;
        }
        $plain = Crypto::decrypt((string) $entry['secret'], $this->key(), $id);
        if ($plain === null) {
            // Key cookie lost or rotated: the stored credentials are useless now.
            $this->removeConnection($id);
            return null;
        }
        $params = json_decode($plain, true);
        return is_array($params) ? $params : null;
    }

    /** @return array<int,array<string,mixed>> public metadata of all connections */
    public function connections(): array
    {
        $this->start();
        $list = [];
        foreach ($_SESSION[self::SLOT]['connections'] as $entry) {
            if (is_array($entry) && isset($entry['meta'])) {
                $list[] = $entry['meta'];
            }
        }
        return $list;
    }

    /** @return string[] */
    public function connectionIds(): array
    {
        $this->start();
        return array_map('strval', array_keys($_SESSION[self::SLOT]['connections']));
    }

    public function removeConnection(string $id): void
    {
        $this->start();
        unset($_SESSION[self::SLOT]['connections'][$id], $_SESSION[self::SLOT]['transient']['enroll|' . $id]);
    }

    /**
     * Merge public metadata fields into a stored connection (e.g. clear the 2FA enrollment flag).
     * @param array<string,mixed> $changes null values remove a field
     */
    public function updateConnectionMeta(string $id, array $changes): void
    {
        $this->start();
        if (!isset($_SESSION[self::SLOT]['connections'][$id]['meta'])) {
            return;
        }
        foreach ($changes as $k => $v) {
            if ($k === 'id') {
                continue;
            }
            if ($v === null) {
                unset($_SESSION[self::SLOT]['connections'][$id]['meta'][$k]);
            } else {
                $_SESSION[self::SLOT]['connections'][$id]['meta'][$k] = $v;
            }
        }
    }

    /**
     * Short-lived encrypted session state (pending 2FA login, enrollment secret): $data is
     * encrypted under the key cookie like stored credentials, expires after $ttl seconds and
     * carries an attempt counter.
     * @param array<string,mixed> $data
     */
    public function putTransient(string $slot, array $data, int $ttl): void
    {
        $this->start();
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        $_SESSION[self::SLOT]['transient'][$slot] = [
            'secret' => Crypto::encrypt($json, $this->key(), 'transient|' . $slot),
            'expires' => time() + max(1, $ttl),
            'attempts' => 0,
        ];
    }

    /**
     * @return array{data:array<string,mixed>,attempts:int,expires:int}|null null when missing,
     *         expired (then removed) or undecryptable
     */
    public function transient(string $slot): ?array
    {
        $this->start();
        $entry = $_SESSION[self::SLOT]['transient'][$slot] ?? null;
        if (!is_array($entry) || !isset($entry['secret'], $entry['expires'])) {
            return null;
        }
        if ((int) $entry['expires'] < time()) {
            $this->forgetTransient($slot);
            return null;
        }
        $plain = Crypto::decrypt((string) $entry['secret'], $this->key(), 'transient|' . $slot);
        $data = $plain === null ? null : json_decode($plain, true);
        if (!is_array($data)) {
            $this->forgetTransient($slot);
            return null;
        }
        return ['data' => $data, 'attempts' => (int) ($entry['attempts'] ?? 0), 'expires' => (int) $entry['expires']];
    }

    /** Count a failed attempt; returns the new count. */
    public function bumpTransientAttempts(string $slot): int
    {
        $this->start();
        if (!isset($_SESSION[self::SLOT]['transient'][$slot])) {
            return 0;
        }
        $n = (int) ($_SESSION[self::SLOT]['transient'][$slot]['attempts'] ?? 0) + 1;
        $_SESSION[self::SLOT]['transient'][$slot]['attempts'] = $n;
        return $n;
    }

    public function forgetTransient(string $slot): void
    {
        $this->start();
        unset($_SESSION[self::SLOT]['transient'][$slot]);
    }

    public function logoutAll(): void
    {
        $this->start();
        $_SESSION[self::SLOT]['connections'] = [];
        $_SESSION[self::SLOT]['transient'] = [];
        session_regenerate_id(true);
        $this->csrf()->regenerate();
        // New key cookie: anything encrypted under the old key (copies of the session) is dead.
        $this->issueKey();
    }

    /**
     * Current session id (still known after close()). Secret: only use it hashed, e.g. as part
     * of a lookup key that must be bound to this session (Sql\RunRegistry).
     */
    public function id(): string
    {
        $this->start();
        return (string) session_id();
    }

    /** Release the session lock early (long-running queries/exports must not block other tabs). */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function key(): string
    {
        if ($this->key === null) {
            $this->loadKey();
        }
        return (string) $this->key;
    }

    private function loadKey(): void
    {
        $cookie = $_COOKIE[self::KEY_COOKIE] ?? '';
        $raw = is_string($cookie) ? base64_decode(strtr($cookie, '-_', '+/'), true) : false;
        if ($raw !== false && strlen($raw) === Crypto::KEY_BYTES) {
            $this->key = $raw;
            return;
        }
        // New key: anything encrypted with an older key is unrecoverable, so drop it.
        $_SESSION[self::SLOT]['connections'] = [];
        $_SESSION[self::SLOT]['transient'] = [];
        $this->issueKey();
    }

    private function issueKey(): void
    {
        $this->key = Crypto::generateKey();
        $encoded = rtrim(strtr(base64_encode($this->key), '+/', '-_'), '=');
        if (!headers_sent()) {
            setcookie(self::KEY_COOKIE, $encoded, $this->cookieOptions());
        }
        $_COOKIE[self::KEY_COOKIE] = $encoded;
    }

    private function enforceIdleTimeout(): void
    {
        $timeout = $this->config->int('session_idle_timeout', 1800);
        $last = (int) ($_SESSION[self::SLOT]['last'] ?? 0);
        if ($timeout > 0 && $last > 0 && time() - $last > $timeout) {
            $_SESSION[self::SLOT]['connections'] = [];
            $_SESSION[self::SLOT]['transient'] = [];
        }
    }

    /** @return array<string,mixed> */
    private function cookieOptions(): array
    {
        return [
            'expires' => 0,
            'path' => $this->cookiePath(),
            'secure' => $this->config->get('cookie_secure') !== null
                ? $this->config->bool('cookie_secure') : $this->request->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    /** Directory of the public URL (works when installed in a sub-directory or behind rewrites). */
    private function cookiePath(): string
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($uri === '' || $uri[0] !== '/') {
            return '/';
        }
        $dir = substr($uri, -1) === '/' ? $uri : str_replace('\\', '/', dirname($uri));
        return ($dir === '' || $dir === '.' || $dir === '/') ? '/' : rtrim($dir, '/') . '/';
    }
}
