<?php

declare(strict_types=1);

/*
 * LDB configuration. Copy to config/config.php and adjust. Every key is optional;
 * the values shown are the defaults unless noted.
 */
return [
    // Show detailed error messages (stack traces) in API responses. Never enable in production.
    'debug' => false,

    // Preset servers shown on the login screen. "id" is referenced by the login form ("preset").
    // Credentials are never stored here; users still type user/password.
    'servers' => [
        // ['id' => 'local', 'label' => 'Local MariaDB', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306],
        // ['id' => 'pg', 'label' => 'Postgres', 'driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'postgres'],
        // ['id' => 'mssql', 'label' => 'SQL Server', 'driver' => 'sqlsrv', 'host' => 'mssql', 'port' => 1433],
        // 'read_only' => true forces read-only connections for a preset (users cannot turn it off):
        // ['id' => 'prod', 'label' => 'Production (read-only)', 'driver' => 'mysql', 'host' => 'db.prod', 'read_only' => true],
        // Per-preset driver 'options' (all optional):
        //   MySQL:      ['ssl_ca' => '/etc/ssl/ca.pem', 'ssl_verify' => true]
        //   PostgreSQL: ['sslmode' => 'require']   (disable, allow, prefer, require, verify-ca, verify-full)
        //   SQL Server: ['trust_server_certificate' => false, 'encrypt' => true,
        //                'pdo_driver' => 'dblib']  (dblib = pdo_dblib/FreeTDS instead of pdo_sqlsrv)
        // ['id' => 'mssql2', 'label' => 'SQL Server (TLS)', 'driver' => 'sqlsrv', 'host' => 'mssql', 'options' => ['trust_server_certificate' => false, 'encrypt' => true]],
        // ['id' => 'tds', 'label' => 'SQL Server (FreeTDS)', 'driver' => 'sqlsrv', 'host' => 'mssql', 'options' => ['pdo_driver' => 'dblib']],
    ],

    // Force every connection to be read-only (no writes through the UI, the SQL editor only
    // accepts SELECT-like statements, and the database session is switched to read-only where
    // the engine supports it). Users can always opt in per connection on the login form.
    // A database account with only SELECT privileges remains the real security boundary.
    'read_only' => false,

    // When true, only the presets above can be used (no free host entry). This also applies to
    // SQLite: it is then offered only if a preset with 'driver' => 'sqlite' exists (the user still
    // types the file, which must be inside sqlite_paths).
    'only_presets' => false,

    // When true, the login form accepts any host/port. Off by default (prevents using LDB to
    // probe internal hosts). With no 'servers' and this off, localhost MySQL/PostgreSQL presets are offered.
    'allow_arbitrary_server' => false,

    // Directories (or glob patterns) where SQLite database files may be opened or created.
    // Empty array disables SQLite entirely. Paths are checked with realpath().
    // With paths configured, the login screen offers "SQLite file" even when
    // allow_arbitrary_server is false (the allow-list and the password below protect it), but not
    // with only_presets unless a SQLite preset exists.
    'sqlite_paths' => [
        // '/var/lib/ldb/sqlite',
        // '/srv/data/*.sqlite',
    ],

    // SQLite has no users of its own: every SQLite login must enter this password. Generate with
    //   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
    // Without it SQLite logins are refused (unless sqlite_allow_no_password is true — trusted LAN only).
    // Never put sqlite_paths inside the web root.
    'sqlite_password_hash' => '',
    'sqlite_allow_no_password' => false,

    // Force the Secure cookie flag (e.g. behind a TLS-terminating reverse proxy that does not
    // pass HTTPS=on to PHP). null = auto-detect from the request.
    'cookie_secure' => null,

    // Directory for login rate-limit counters. Default: a private (0700) directory in the system
    // temp dir; on shared hosting without a private /tmp set this to a directory of your own.
    'rate_limit_dir' => null,

    // Maximum rows per page when browsing tables.
    'max_rows_browse' => 1000,

    // Query timeout in seconds (applied where the driver supports it).
    'query_timeout' => 30,

    // Database connect timeout in seconds.
    'connect_timeout' => 10,

    // Maximum import upload size in bytes (also bounded by php.ini upload_max_filesize/post_max_size).
    'upload_max' => 64 * 1024 * 1024,

    // Default UI theme: 'light', 'dark' or 'system'.
    'theme_default' => 'system',

    // Log out after this many seconds of inactivity. 0 disables the idle timeout.
    'session_idle_timeout' => 1800,

    // Export time limit in seconds for a single export request (0 = no limit), and optional PHP
    // memory_limit for exports (e.g. '512M' or '-1'; null = keep the php.ini value).
    'export_time_limit' => 0,
    'export_memory_limit' => null,

    // Private (0700) directory for the running-query registry used to cancel queries (sql.cancel).
    // null = a private directory in the system temp dir; set it on shared hosting or when PHP-FPM
    // workers use different temp dirs.
    'run_registry_dir' => null,

    // Session cookie name.
    'session_name' => 'ldb_sid',

    // Login rate limit: failed attempts per window (seconds) per client IP.
    'login_max_attempts' => 5,
    'login_window' => 300,

    // Single sign-on links for control panels (see docs/sso.md and SECURITY.md). Whoever knows
    // this secret can log in to every server SSO may target: keep it out of the web root and
    // version control. Generate with  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Empty (default) or shorter than 32 bytes disables SSO.
    'sso_secret' => '',
    // Maximum token lifetime in seconds (exp - iat), at most 300. Keep it short.
    'sso_ttl' => 60,
    // Preset ids SSO may target; [] = all presets.
    'sso_allowed_presets' => [],
    // Allow tokens with driver/host/port instead of a preset (ignored with only_presets).
    'sso_allow_arbitrary_server' => false,
    // Private (0700) directory for used token nonces (replay protection); null = system temp dir.
    'sso_nonce_dir' => null,

    // Two-factor authentication (TOTP apps + recovery codes; SECURITY.md). Needs secret_key:
    // at least 32 random bytes, e.g.  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Losing or changing it makes all 2FA records unreadable (those logins are then refused
    // until reset with  php bin/twofa reset --preset=ID --user=NAME).
    'secret_key' => '',
    // Persistent, private (0700) directory OUTSIDE the web root where 2FA records are stored.
    // Required for 2FA and has no default (not even the system temp dir, which could be wiped and
    // silently drop 2FA): with null, 2FA stays unavailable.
    'data_dir' => null,
    // Require 2FA: true for every login, or a list of preset ids, e.g. ['prod'].
    'twofa_required' => false,
    // Allow SSO links for logins that have 2FA enabled (the panel vouches for the user).
    'sso_bypass_2fa' => false,
    // Wrong second-factor codes per login before a lockout, and the window in seconds.
    'twofa_max_failures' => 10,
    'twofa_window' => 900,
];
