<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Support\PrivateDir;

/**
 * File-based sliding-window rate limiter (no external storage required).
 */
final class RateLimiter
{
    private string $dir;
    private int $maxAttempts;
    private int $window;

    public function __construct(int $maxAttempts = 5, int $window = 300, ?string $dir = null)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->window = max(1, $window);
        $this->dir = $dir ?? PrivateDir::tempPath('ldb_ratelimit_', __DIR__);
    }

    /**
     * Rate-limit bucket for a client address: IPv6 clients usually control a whole /64, so
     * they are grouped by prefix (otherwise every attempt could come from a fresh address).
     */
    public static function ipBucket(string $ip): string
    {
        $bin = @inet_pton($ip);
        if (is_string($bin) && strlen($bin) === 16) {
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }
        return $ip;
    }

    public function tooManyAttempts(string $key): bool
    {
        return count($this->attempts($key)) >= $this->maxAttempts;
    }

    public function hit(string $key): void
    {
        $this->modify($key, function (array $times): array {
            $times[] = time();
            return $times;
        });
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return int[] timestamps inside the current window */
    private function attempts(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file) || !$this->dirIsSafe()) {
            return [];
        }
        $raw = @file_get_contents($file);
        return $this->prune($raw === false ? [] : (array) json_decode($raw, true));
    }

    private function modify(string $key, callable $fn): void
    {
        if (!PrivateDir::ensure($this->dir, 'rate limiter')) {
            return;
        }
        $fh = @fopen($this->file($key), 'c+');
        if ($fh === false) {
            return; // fail open on storage problems rather than locking everybody out
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $times = $this->prune($raw ? (array) json_decode($raw, true) : []);
            $times = $fn($times);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode(array_values($times)));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
    }

    /** @param array<mixed> $times @return int[] */
    private function prune(array $times): array
    {
        $cutoff = time() - $this->window;
        return array_values(array_filter(array_map('intval', $times), static fn (int $t): bool => $t > $cutoff));
    }

    /** Unsafe (shared/foreign) directory → limiter disabled (logged), see PrivateDir. */
    private function dirIsSafe(): bool
    {
        return PrivateDir::isSafe($this->dir, 'rate limiter');
    }

    private function file(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
