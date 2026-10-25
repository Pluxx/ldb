<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Support\PrivateDir;

/**
 * One-time-use store for SSO token nonces (replay protection).
 *
 * One empty marker file per used nonce, named sha256(nonce), created atomically with
 * fopen('x'): of two concurrent requests with the same token exactly one succeeds. Lives in a
 * private (0700, owned by the PHP user) directory, see PrivateDir.
 *
 * Unlike the rate limiter this fails CLOSED: without a usable directory every token is
 * rejected, because a token that cannot be marked as used could be replayed.
 *
 * Markers are kept for RETENTION seconds, which is longer than any token can be valid
 * (Sso::MAX_TTL + Sso::CLOCK_SKEW), so a nonce is never forgotten while its token still verifies.
 */
final class SsoNonceStore
{
    public const RETENTION = 900;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? PrivateDir::tempPath('ldb_sso_', __DIR__);
    }

    /**
     * Mark $nonce as used. Returns true on first use, false when it was used before.
     * @throws SsoException when the store is unavailable (token must then be rejected)
     */
    public function consume(string $nonce, ?int $now = null): bool
    {
        if ($nonce === '' || !PrivateDir::ensure($this->dir, 'SSO login')) {
            throw new SsoException('replay protection store unavailable (' . $this->dir . ')');
        }
        $now = $now ?? time();
        $this->gc($now);
        $file = $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $nonce) . '.nonce';
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            clearstatcache(true, $file);
            if (file_exists($file) || is_link($file)) {
                return false;
            }
            throw new SsoException('replay protection store not writable (' . $this->dir . ')');
        }
        fwrite($fh, (string) $now);
        fclose($fh);
        @chmod($file, 0600);
        @touch($file, $now);
        return true;
    }

    /** Remove markers older than RETENTION. */
    private function gc(int $now): void
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.nonce');
        if ($files === false) {
            return;
        }
        $cutoff = $now - self::RETENTION;
        foreach ($files as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($f);
            }
        }
    }
}
