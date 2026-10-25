<?php

declare(strict_types=1);

namespace Ldb\Db;

use Ldb\Config;
use Ldb\Http\ApiException;

/**
 * Creates driver instances and enforces connection policy (presets, SQLite allow-list).
 */
final class DriverFactory
{
    /** @var array<string,class-string> */
    private const DRIVERS = [
        'mysql' => MySqlDriver::class,
        'pgsql' => PgSqlDriver::class,
        'sqlite' => SqliteDriver::class,
        'sqlsrv' => SqlSrvDriver::class,
    ];

    private const LABELS = [
        'mysql' => 'MySQL / MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlite' => 'SQLite',
        'sqlsrv' => 'SQL Server',
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return array<int,array{id:string,label:string,available:bool}> */
    public function drivers(): array
    {
        $list = [];
        foreach (self::DRIVERS as $id => $class) {
            $list[] = [
                'id' => $id,
                'label' => class_exists($class) ? $class::label() : self::LABELS[$id],
                'available' => $this->isAvailable($id),
            ];
        }
        return $list;
    }

    public function isAvailable(string $id): bool
    {
        $class = self::DRIVERS[$id] ?? null;
        if ($class === null || !class_exists($class) || !$class::isAvailable()) {
            return false;
        }
        if ($id === 'sqlite' && $this->config->arr('sqlite_paths') === []) {
            return false;
        }
        // With only_presets every driver (SQLite included) needs a preset of its own; otherwise
        // SQLite is offered whenever sqlite_paths is configured, even without allow_arbitrary_server.
        if ($this->config->bool('only_presets') && $this->presetsFor($id) === []) {
            return false;
        }
        return true;
    }

    /** Public (secret-free) preset list for the login screen. @return array<int,array<string,mixed>> */
    public function presets(): array
    {
        $out = [];
        foreach ($this->config->arr('servers') as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($p['id'] ?? $i),
                'label' => (string) ($p['label'] ?? ($p['host'] ?? 'Server ' . $i)),
                'driver' => (string) ($p['driver'] ?? 'mysql'),
                'host' => isset($p['host']) ? (string) $p['host'] : null,
                'port' => isset($p['port']) ? (int) $p['port'] : null,
                'database' => isset($p['database']) ? (string) $p['database'] : null,
                'user' => isset($p['user']) ? (string) $p['user'] : null,
                'read_only' => !empty($p['read_only']) || $this->config->bool('read_only'),
            ];
        }
        return $out;
    }

    /**
     * Validate login input against policy and return normalised connection params:
     * driver, host, port, user, password, database, file, options, server (display), preset.
     * @param array<string,mixed> $input
     * @param bool|null $allowArbitrary free host/port allowed? null = login-page policy
     *        (`allow_arbitrary_server` and not `only_presets`); SSO passes its own policy.
     * @return array<string,mixed>
     */
    public function resolveLogin(array $input, ?bool $allowArbitrary = null): array
    {
        $preset = null;
        $presetId = isset($input['preset']) && $input['preset'] !== '' ? (string) $input['preset'] : null;
        if ($presetId !== null) {
            foreach ($this->config->arr('servers') as $i => $p) {
                if (is_array($p) && (string) ($p['id'] ?? $i) === $presetId) {
                    $preset = $p;
                    break;
                }
            }
            if ($preset === null) {
                throw ApiException::badRequest('Unknown server preset');
            }
        }

        $driver = (string) ($preset['driver'] ?? $input['driver'] ?? 'mysql');
        if (!isset(self::DRIVERS[$driver])) {
            throw ApiException::badRequest('Unknown driver');
        }
        if (!$this->isAvailable($driver)) {
            throw ApiException::unsupported('Driver "' . $driver . '" is not available on this server');
        }

        $params = [
            'driver' => $driver,
            'host' => null,
            'port' => null,
            'user' => (string) ($input['user'] ?? ($preset['user'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'database' => self::optString($input['database'] ?? ($preset['database'] ?? null)),
            'file' => null,
            'options' => is_array($preset['options'] ?? null) ? $preset['options'] : [],
            'preset' => $presetId,
        ];
        // Read-only can only be added by the user, never removed: preset/global config force it.
        $params['read_only'] = !empty($preset['read_only']) || $this->config->bool('read_only')
            || self::truthy($input['read_only'] ?? false);
        $params['options']['query_timeout'] = $this->config->int('query_timeout', 30);
        $params['options']['connect_timeout'] = $this->config->int('connect_timeout', 10);

        if ($driver === 'sqlite') {
            // Path allow-list + password protect SQLite, so no preset / allow_arbitrary_server is
            // needed; only_presets without a SQLite preset is refused by isAvailable() above.
            $this->checkSqlitePassword(is_scalar($input['password'] ?? null) ? (string) $input['password'] : '');
            $file = self::optString($input['file'] ?? ($input['database'] ?? null));
            if ($file === null) {
                throw ApiException::badRequest('Missing SQLite database file');
            }
            $params['file'] = $this->checkSqlitePath($file);
            $params['database'] = null;
            $params['server'] = basename($params['file']);
            return $params;
        }

        if ($preset !== null) {
            $params['host'] = isset($preset['host']) ? (string) $preset['host'] : 'localhost';
            $params['port'] = isset($preset['port']) ? (int) $preset['port'] : null;
        } else {
            $allowArbitrary = $allowArbitrary
                ?? (!$this->config->bool('only_presets') && $this->config->bool('allow_arbitrary_server'));
            if (!$allowArbitrary) {
                throw new ApiException('Only preconfigured servers may be used', 'bad_request', 403);
            }
            $host = trim((string) ($input['host'] ?? ''));
            $params['host'] = $host === '' ? 'localhost' : $host;
            $port = $input['port'] ?? null;
            $params['port'] = is_numeric($port) && (int) $port > 0 && (int) $port < 65536 ? (int) $port : null;
        }
        if ($params['port'] === null) {
            $class = self::DRIVERS[$driver];
            $params['port'] = $class::defaultPort();
        }
        if (preg_match('/[\x00-\x1F;=]/', (string) $params['host'])) {
            // Guard DSN injection (";dbname=…" etc.).
            throw ApiException::badRequest('Invalid host name');
        }
        $params['server'] = $params['host'] . ($params['port'] !== null ? ':' . $params['port'] : '');
        return $params;
    }

    /** Whether SQLite logins must supply the configured password. */
    public function sqlitePasswordRequired(): bool
    {
        return !$this->config->bool('sqlite_allow_no_password');
    }

    /**
     * SQLite has no authentication of its own, so without this check anybody reaching the login
     * page could open every database in `sqlite_paths`. Requires `sqlite_password_hash`
     * (password_hash() output) unless `sqlite_allow_no_password` is explicitly enabled.
     */
    private function checkSqlitePassword(string $password): void
    {
        if (!$this->sqlitePasswordRequired()) {
            return;
        }
        $hash = $this->config->get('sqlite_password_hash');
        if (!is_string($hash) || $hash === '') {
            throw new ApiException(
                'SQLite login is disabled: set sqlite_password_hash in config/config.php',
                'unsupported',
                403
            );
        }
        if (!password_verify($password, $hash)) {
            throw new ApiException('Login failed: wrong password', 'db_error', 401);
        }
    }

    /** Instantiate and connect a driver from stored connection params. */
    public function connect(array $params): DriverInterface
    {
        $driver = (string) ($params['driver'] ?? '');
        $class = self::DRIVERS[$driver] ?? null;
        if ($class === null || !class_exists($class)) {
            throw ApiException::unsupported('Unknown or unavailable driver');
        }
        if ($driver === 'sqlite') {
            // Re-check on every request: config may have changed since login.
            $params['file'] = $this->checkSqlitePath((string) ($params['file'] ?? ''));
        }
        $readOnly = $this->isReadOnly($params);
        $params['read_only'] = $readOnly;
        /** @var DriverInterface $db */
        $db = new $class();
        $db->connect($params);
        if ($readOnly) {
            $db->setReadOnly(true);
        }
        return $db;
    }

    /**
     * Effective read-only state of a connection (stored params or public meta): the flag chosen
     * at login, the global `read_only` config, or a preset marked `read_only` (re-checked on
     * every request, so tightening the config applies to existing sessions too).
     * @param array<string,mixed> $conn
     */
    public function isReadOnly(array $conn): bool
    {
        if (!empty($conn['read_only']) || $this->config->bool('read_only')) {
            return true;
        }
        $presetId = isset($conn['preset']) && $conn['preset'] !== '' ? (string) $conn['preset'] : null;
        if ($presetId !== null) {
            foreach ($this->config->arr('servers') as $i => $p) {
                if (is_array($p) && (string) ($p['id'] ?? $i) === $presetId) {
                    return !empty($p['read_only']);
                }
            }
        }
        return false;
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Resolve a SQLite path and verify it is inside one of the allowed locations.
     * Non-existing files are allowed (they will be created) if their directory is allowed.
     */
    public function checkSqlitePath(string $file): string
    {
        $allowed = $this->config->arr('sqlite_paths');
        if ($allowed === []) {
            throw ApiException::unsupported('SQLite is disabled (no sqlite_paths configured)');
        }
        if ($file === '' || strpos($file, "\0") !== false || $file === ':memory:' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $file)) {
            throw ApiException::badRequest('Invalid SQLite file path');
        }

        $real = realpath($file);
        if ($real === false) {
            $dir = realpath(dirname($file));
            $base = basename($file);
            if ($dir === false || !is_dir($dir) || $base === '' || $base === '.' || $base === '..') {
                throw ApiException::badRequest('SQLite file path does not exist');
            }
            $real = $dir . DIRECTORY_SEPARATOR . $base;
        } elseif (is_dir($real)) {
            throw ApiException::badRequest('SQLite path is a directory');
        }

        foreach ($allowed as $entry) {
            $entry = (string) $entry;
            if ($entry === '') {
                continue;
            }
            if (strpbrk($entry, '*?[') !== false) {
                // Glob: resolve the static directory part, match the rest with fnmatch.
                $dirPart = dirname($entry);
                $realDir = strpbrk($dirPart, '*?[') === false ? realpath($dirPart) : false;
                $pattern = $realDir !== false ? $realDir . DIRECTORY_SEPARATOR . basename($entry) : $entry;
                if (fnmatch($pattern, $real, FNM_PATHNAME)) {
                    return $real;
                }
                continue;
            }
            $realEntry = realpath($entry);
            if ($realEntry === false) {
                continue;
            }
            if (is_file($realEntry) ? $real === $realEntry
                : strpos($real, rtrim($realEntry, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
                return $real;
            }
        }
        throw new ApiException('SQLite file is outside the allowed directories', 'bad_request', 403);
    }

    /** @param mixed $v */
    private static function optString($v): ?string
    {
        if ($v === null || !is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @return array<int,array<string,mixed>> */
    private function presetsFor(string $driver): array
    {
        return array_values(array_filter($this->presets(), static fn (array $p): bool => $p['driver'] === $driver));
    }
}
