<?php

declare(strict_types=1);

namespace Ldb;

/**
 * Immutable application configuration: defaults merged with config/config.php (if present).
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'debug' => false,
        // Preset servers: [['id' => 'local', 'label' => 'Local', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306], ...]
        'servers' => [],
        'only_presets' => false,
        // Off by default: a free host field lets anyone reaching the login page probe internal hosts.
        'allow_arbitrary_server' => false,
        // Directories (or glob patterns) in which SQLite files may be opened. Empty = SQLite disabled.
        'sqlite_paths' => [],
        // SQLite has no users: logins must enter this password (password_hash() output).
        'sqlite_password_hash' => '',
        // Allow SQLite logins without a password (only for trusted, non-public installs).
        'sqlite_allow_no_password' => false,
        // Force the Secure flag on cookies (behind a TLS-terminating proxy); null = auto-detect.
        'cookie_secure' => null,
        // Directory for the login rate limiter (default: <sys_get_temp_dir()>/ldb_ratelimit_<uid>).
        'rate_limit_dir' => null,
        // Private directory for the running-query registry used by sql.cancel
        // (default: <sys_get_temp_dir()>/ldb_runs_<hash>; must be 0700 and owned by the PHP user).
        'run_registry_dir' => null,
        'max_rows_browse' => 1000,
        'query_timeout' => 30,
        // Force every connection read-only (see SECURITY.md → Read-only connections).
        'read_only' => false,
        'connect_timeout' => 10,
        'upload_max' => 64 * 1024 * 1024,
        'theme_default' => 'system',
        'session_idle_timeout' => 1800,
        'session_name' => 'ldb_sid',
        'login_max_attempts' => 5,
        'login_window' => 300,
        'export_memory_limit' => null,
        'export_time_limit' => 0,
        // Single sign-on links (docs/sso.md). Empty or shorter than 32 bytes = SSO disabled.
        'sso_secret' => '',
        // Maximum token lifetime (exp - iat) in seconds; capped at 300.
        'sso_ttl' => 60,
        // Preset ids SSO tokens may target ([] = all presets).
        'sso_allowed_presets' => [],
        // Allow SSO tokens with a free driver/host/port instead of a preset (never with only_presets).
        'sso_allow_arbitrary_server' => false,
        // Private directory for used SSO nonces (default: <sys_get_temp_dir()>/ldb_sso_<hash>).
        'sso_nonce_dir' => null,
        // Two-factor authentication (SECURITY.md → Two-factor authentication). Key (≥ 32 bytes)
        // for encrypting 2FA records; empty = 2FA unavailable.
        'secret_key' => '',
        // Persistent private state (2FA records in <data_dir>/twofa); default: a private directory
        // in sys_get_temp_dir() (ldb_data_<hash>), which may be wiped on reboot — set a real path.
        'data_dir' => null,
        // true, or a list of preset ids: logins without 2FA must enroll before using anything.
        'twofa_required' => false,
        // Let SSO tokens log in to 2FA-enabled logins (the panel authenticates the user itself).
        'sso_bypass_2fa' => false,
        // Failed second-factor attempts per login (identity) per window (seconds).
        'twofa_max_failures' => 10,
        'twofa_window' => 900,
    ];

    private const LOCAL_SERVERS = [
        ['id' => 'local-mysql', 'label' => 'MySQL / MariaDB (localhost)', 'driver' => 'mysql', 'host' => 'localhost'],
        ['id' => 'local-pgsql', 'label' => 'PostgreSQL (localhost)', 'driver' => 'pgsql', 'host' => 'localhost'],
    ];

    /** @param array<string,mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = array_replace(self::DEFAULTS, $values);
        if ($this->values['servers'] === [] && !$this->values['allow_arbitrary_server']) {
            // Zero-config install: offer the local servers, like phpMyAdmin does.
            $this->values['servers'] = self::LOCAL_SERVERS;
        }
    }

    /** Load from a PHP file returning an array. Missing file → defaults only. */
    public static function load(?string $file = null): self
    {
        $file = $file ?? dirname(__DIR__) . '/config/config.php';
        $values = [];
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $values = $loaded;
            } elseif (isset($config) && is_array($config)) {
                // Also accept files that just assign $config = [...]
                $values = $config;
            }
        }
        return new self($values);
    }

    /** @return mixed */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return array<mixed> */
    public function arr(string $key): array
    {
        $v = $this->get($key, []);
        return is_array($v) ? $v : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
