<?php

declare(strict_types=1);

namespace Ldb\Support;

/**
 * Private per-install, per-user storage directories for small state files (login rate limiter,
 * running-query registry).
 *
 * On shared hosts another account could pre-create a directory with a predictable name in the
 * common temp dir (or plant a symlink) to read or reset our state or make us overwrite its
 * files. A directory is therefore only used when it is a real directory (no symlink), owned by
 * the PHP process user and not accessible to group/others (0700). Unsafe → callers disable
 * the feature (fail open) and a message is logged; configure a private directory instead.
 */
final class PrivateDir
{
    /**
     * Default location in sys_get_temp_dir(): $prefix + hash of $seed (an install specific path)
     * and the process user, so different installs/users never share a directory.
     */
    public static function tempPath(string $prefix, string $seed): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix
            . substr(hash('sha256', $seed . '|' . self::uid()), 0, 12);
    }

    /** Create $dir (0700) when missing, then check it. @param string $purpose used in the log message */
    public static function ensure(string $dir, string $purpose): bool
    {
        if (!is_dir($dir) && !is_link($dir)) {
            @mkdir($dir, 0700, true);
        }
        return self::isSafe($dir, $purpose);
    }

    /** True when $dir is a private directory of this user (see class doc). */
    public static function isSafe(string $dir, string $purpose): bool
    {
        clearstatcache(true, $dir);
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return true; // no POSIX permissions on Windows
        }
        $perms = @fileperms($dir);
        $owner = @fileowner($dir);
        $uid = self::uid();
        if ($perms === false || ($perms & 0077) !== 0 || ($uid !== null && $owner !== $uid)) {
            error_log('[ldb] ' . $purpose . ' disabled: ' . $dir . ' is not a private directory of this user');
            return false;
        }
        return true;
    }

    /** Effective uid of the PHP process (null without ext-posix). */
    public static function uid(): ?int
    {
        // getmyuid() is the script owner, not the process user: unusable for this check.
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
