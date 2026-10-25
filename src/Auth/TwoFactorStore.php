<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;
use Ldb\Support\PrivateDir;

/**
 * Two-factor records, one file per identity: <data_dir>/twofa/<sha256(identity)>.json.
 *
 * The whole record (TOTP secret, recovery code hashes, last used time step, identity) is
 * encrypted with AES-256-GCM under a key derived (HKDF-SHA256) from `secret_key`. The file
 * name is bound as additional authenticated data, so a record copied over another identity's
 * file fails to decrypt. Writes are atomic (temp file + rename, 0600) and read-modify-write
 * cycles hold an exclusive lock per identity (replay check of the last time step).
 *
 * Fails CLOSED: an unsafe directory (not 0700 / foreign owner / symlink) or an unreadable,
 * tampered or undecryptable record throws, so the login is refused instead of silently
 * skipping the second factor.
 */
final class TwoFactorStore
{
    public const MIN_SECRET_BYTES = 32;
    private const HKDF_INFO = 'ldb-2fa v1 store aes-256-gcm';
    private const FORMAT = 1;

    private string $dir;
    private string $key;

    public function __construct(string $dir, string $secretKey)
    {
        if (strlen($secretKey) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException('secret_key must be at least ' . self::MIN_SECRET_BYTES . ' bytes');
        }
        $this->dir = rtrim($dir, '/\\');
        $this->key = hash_hkdf('sha256', $secretKey, Crypto::KEY_BYTES, self::HKDF_INFO, '');
    }

    /** Configured `secret_key`, or null when missing/too short (2FA unavailable). */
    public static function secretKey(Config $config): ?string
    {
        $s = $config->get('secret_key');
        return is_string($s) && strlen($s) >= self::MIN_SECRET_BYTES ? $s : null;
    }

    /** Base directory for persistent state (`data_dir`, default a private dir in the temp dir). */
    public static function dataDir(Config $config): string
    {
        $dir = $config->get('data_dir');
        return is_string($dir) && $dir !== '' ? $dir : PrivateDir::tempPath('ldb_data_', __DIR__);
    }

    /** Directory holding the records. */
    public static function recordDir(Config $config): string
    {
        return rtrim(self::dataDir($config), '/\\') . DIRECTORY_SEPARATOR . 'twofa';
    }

    /**
     * Store from config, or null when `secret_key` or an explicit `data_dir` is not configured.
     * The temp-dir default is not used for 2FA: if it were wiped (reboot, tmp cleaner), enrolled
     * logins would silently lose their second factor.
     */
    public static function fromConfig(Config $config): ?self
    {
        $key = self::secretKey($config);
        $dir = $config->get('data_dir');
        if ($key === null || !is_string($dir) || $dir === '') {
            return null;
        }
        return new self(self::recordDir($config), $key);
    }

    /**
     * Whether a record file exists for $identity — works without the key. Static so that a
     * missing secret_key cannot hide existing enrollments (callers then fail closed).
     * @throws \RuntimeException when the directory exists but is not private
     */
    public static function recordExists(string $dir, string $identity): bool
    {
        $dir = rtrim($dir, '/\\');
        clearstatcache();
        if (!file_exists($dir) && !is_link($dir)) {
            return false;
        }
        if (!PrivateDir::isSafe($dir, 'two-factor store')) {
            throw new \RuntimeException('two-factor store directory is not private: ' . $dir);
        }
        return is_file($dir . DIRECTORY_SEPARATOR . self::name($identity) . '.json');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function exists(string $identity): bool
    {
        return self::recordExists($this->dir, $identity);
    }

    /**
     * @return array<string,mixed>|null decrypted record, null when there is none
     * @throws \RuntimeException unreadable, tampered or wrong key
     */
    public function load(string $identity): ?array
    {
        if (!$this->exists($identity)) {
            return null;
        }
        return $this->read($this->file($identity), $identity);
    }

    /** @param array<string,mixed> $record */
    public function save(string $identity, array $record): void
    {
        $this->withLock($identity, function () use ($identity, $record): void {
            $this->write($identity, $record);
        });
    }

    public function delete(string $identity): bool
    {
        $done = false;
        $this->withLock($identity, function () use ($identity, &$done): void {
            $file = $this->file($identity);
            $done = is_file($file) && @unlink($file);
        });
        return $done;
    }

    /**
     * Locked read-modify-write. $fn receives the record (or null) and returns the new record,
     * null to delete it, or false to leave it unchanged. Returns $fn's return value.
     * @param callable(array<string,mixed>|null):(array<string,mixed>|null|false) $fn
     * @return array<string,mixed>|null|false
     */
    public function update(string $identity, callable $fn)
    {
        $result = false;
        $this->withLock($identity, function () use ($identity, $fn, &$result): void {
            $file = $this->file($identity);
            $record = is_file($file) ? $this->read($file, $identity) : null;
            $result = $fn($record);
            if (is_array($result)) {
                $this->write($identity, $result);
            } elseif ($result === null && $record !== null) {
                @unlink($file);
            }
        });
        return $result;
    }

    /**
     * All records (for bin/twofa list). Undecryptable files are reported with 'error'.
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        clearstatcache();
        if (!is_dir($this->dir)) {
            return [];
        }
        if (!PrivateDir::isSafe($this->dir, 'two-factor store')) {
            throw new \RuntimeException('two-factor store directory is not private: ' . $this->dir);
        }
        $out = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            $record = null;
            try {
                $record = $this->decryptFile($file, $name);
            } catch (\RuntimeException $e) {
                $out[] = ['file' => $name, 'error' => $e->getMessage()];
                continue;
            }
            $out[] = ['file' => $name] + $record;
        }
        return $out;
    }

    // ------------------------------------------------------------------ internals

    private static function name(string $identity): string
    {
        return hash('sha256', $identity);
    }

    private function file(string $identity): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . self::name($identity) . '.json';
    }

    private static function aad(string $name): string
    {
        return 'ldb-2fa v' . self::FORMAT . '|' . $name;
    }

    /** @return array<string,mixed> */
    private function read(string $file, string $identity): array
    {
        $record = $this->decryptFile($file, self::name($identity));
        if (!isset($record['identity']) || !is_string($record['identity']) || !hash_equals($record['identity'], $identity)) {
            throw new \RuntimeException('two-factor record does not belong to this identity');
        }
        return $record;
    }

    /** @return array<string,mixed> */
    private function decryptFile(string $file, string $name): array
    {
        if (is_link($file)) {
            throw new \RuntimeException('two-factor record is a symlink');
        }
        $raw = @file_get_contents($file);
        $outer = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($outer) || ($outer['v'] ?? null) !== self::FORMAT || !is_string($outer['data'] ?? null)) {
            throw new \RuntimeException('two-factor record is unreadable');
        }
        $plain = Crypto::decrypt($outer['data'], $this->key, self::aad($name));
        if ($plain === null) {
            throw new \RuntimeException('two-factor record failed authentication (tampered or secret_key changed)');
        }
        $record = json_decode($plain, true);
        if (!is_array($record)) {
            throw new \RuntimeException('two-factor record is corrupt');
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function write(string $identity, array $record): void
    {
        $record['identity'] = $identity;
        $name = self::name($identity);
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('two-factor record is not encodable');
        }
        $payload = (string) json_encode(['v' => self::FORMAT, 'data' => Crypto::encrypt($json, $this->key, self::aad($name))]);
        $tmp = $this->dir . DIRECTORY_SEPARATOR . '.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = @fopen($tmp, 'x');
        if ($fh === false) {
            throw new \RuntimeException('two-factor store is not writable: ' . $this->dir);
        }
        @chmod($tmp, 0600);
        $ok = fwrite($fh, $payload) === strlen($payload) && fflush($fh);
        fclose($fh);
        if (!$ok || !@rename($tmp, $this->file($identity))) {
            @unlink($tmp);
            throw new \RuntimeException('could not write two-factor record');
        }
    }

    private function withLock(string $identity, callable $fn): void
    {
        if (!PrivateDir::ensure($this->dir, 'two-factor store')) {
            throw new \RuntimeException('two-factor store directory is unavailable or not private: ' . $this->dir);
        }
        $lock = $this->dir . DIRECTORY_SEPARATOR . self::name($identity) . '.lock';
        $fh = @fopen($lock, 'c');
        if ($fh === false) {
            throw new \RuntimeException('could not lock two-factor record');
        }
        @chmod($lock, 0600);
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('could not lock two-factor record');
            }
            $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
