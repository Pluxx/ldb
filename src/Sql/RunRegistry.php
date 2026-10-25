<?php

declare(strict_types=1);

namespace Ldb\Sql;

use Ldb\Support\PrivateDir;

/**
 * Registry of running SQL-editor queries, so a second request (sql.cancel) can find the
 * database backend that executes them.
 *
 * One small JSON file per run in a private (0700, owned by the PHP user) directory, named
 * sha256(session id | run id): an entry can only be found again with the same session, so a
 * user can never look up (or cancel) somebody else's query, and the session id is not stored.
 * Entries hold {driver, backend, conn, started, cancelled} — no credentials; the cancelling
 * request uses the connection stored in its own session.
 *
 * Storage problems fail open: the query runs, it just cannot be cancelled (logged once by
 * PrivateDir). Entries older than the TTL (default 1 day) are ignored and garbage-collected
 * opportunistically on register().
 */
final class RunRegistry
{
    public const RUN_ID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    private string $dir;
    private int $ttl;
    private int $gcDivisor;

    /**
     * @param string|null $dir private directory (default: per-install, per-user dir in sys_get_temp_dir())
     * @param int $ttl seconds after which entries are stale
     * @param int $gcDivisor run GC on 1 of $gcDivisor register() calls (1 = always)
     */
    public function __construct(?string $dir = null, int $ttl = 86400, int $gcDivisor = 20)
    {
        $this->dir = $dir ?? PrivateDir::tempPath('ldb_runs_', __DIR__);
        $this->ttl = max(1, $ttl);
        $this->gcDivisor = max(1, $gcDivisor);
    }

    public static function isValidRunId(string $runId): bool
    {
        return (bool) preg_match(self::RUN_ID_PATTERN, $runId);
    }

    /**
     * Record a running query. Returns false when storage is unavailable.
     * @param array{driver:string,backend:string,conn:string} $entry
     */
    public function register(string $sessionId, string $runId, array $entry): bool
    {
        if (!self::isValidRunId($runId) || $sessionId === '' || !PrivateDir::ensure($this->dir, 'query cancellation')) {
            return false;
        }
        if ($this->gcDivisor === 1 || random_int(1, $this->gcDivisor) === 1) {
            $this->gc();
        }
        $data = [
            'driver' => (string) ($entry['driver'] ?? ''),
            'backend' => (string) ($entry['backend'] ?? ''),
            'conn' => (string) ($entry['conn'] ?? ''),
            'started' => microtime(true),
            'cancelled' => false,
        ];
        $file = $this->file($sessionId, $runId);
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($data)) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Entry of a run of this session, or null (unknown, finished, stale or storage unsafe).
     * @return array{driver:string,backend:string,conn:string,started:float,cancelled:bool}|null
     */
    public function lookup(string $sessionId, string $runId): ?array
    {
        if (!self::isValidRunId($runId) || $sessionId === '' || !PrivateDir::isSafe($this->dir, 'query cancellation')) {
            return null;
        }
        $file = $this->file($sessionId, $runId);
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['started']) || (float) $data['started'] < microtime(true) - $this->ttl) {
            return null;
        }
        return [
            'driver' => (string) ($data['driver'] ?? ''),
            'backend' => (string) ($data['backend'] ?? ''),
            'conn' => (string) ($data['conn'] ?? ''),
            'started' => (float) $data['started'],
            'cancelled' => !empty($data['cancelled']),
        ];
    }

    /** True when sql.cancel was requested for this run. */
    public function isCancelled(string $sessionId, string $runId): bool
    {
        $e = $this->lookup($sessionId, $runId);
        return $e !== null && $e['cancelled'];
    }

    /**
     * Flag a run as cancelled (before its backend is interrupted, so the executing request can
     * tell a user cancel from other errors). Never re-creates a removed entry.
     */
    public function markCancelled(string $sessionId, string $runId): bool
    {
        if ($this->lookup($sessionId, $runId) === null) {
            return false;
        }
        $fh = @fopen($this->file($sessionId, $runId), 'r+b');
        if ($fh === false) {
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            $data = json_decode((string) stream_get_contents($fh), true);
            if (!is_array($data)) {
                flock($fh, LOCK_UN);
                return false;
            }
            $data['cancelled'] = true;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }

    public function remove(string $sessionId, string $runId): void
    {
        if (!self::isValidRunId($runId) || $sessionId === '') {
            return;
        }
        $file = $this->file($sessionId, $runId);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Delete entries (and leftover temp files) older than the TTL. @return int number removed */
    public function gc(?float $now = null): int
    {
        if (!PrivateDir::isSafe($this->dir, 'query cancellation')) {
            return 0;
        }
        $cutoff = ($now ?? microtime(true)) - $this->ttl;
        $removed = 0;
        // No GLOB_BRACE: unavailable on some platforms (musl).
        foreach ((array) glob($this->dir . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT) as $f) {
            if (!is_string($f) || !preg_match('/\.(?:json|tmp)$/', $f) || !is_file($f) || is_link($f)) {
                continue;
            }
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $cutoff && @unlink((string) $f)) {
                $removed++;
            }
        }
        return $removed;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    private function file(string $sessionId, string $runId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $sessionId . '|' . $runId) . '.json';
    }
}
