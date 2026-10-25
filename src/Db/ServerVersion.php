<?php

declare(strict_types=1);

namespace Ldb\Db;

/**
 * Server version parsing, comparison and the support policy.
 *
 * LDB officially supports every database version released in 2018 or later. Older servers can
 * still be used (no hard block); serverInfo() and the connection metadata then report
 * version_supported = false and the UI shows a warning. Version-dependent features are decided
 * by the drivers' capabilities() with atLeast().
 *
 * Flavors: mysql | mariadb | pgsql | sqlsrv | sqlite.
 */
final class ServerVersion
{
    /** First release of 2018 or later per flavor (the minimum fully supported version). */
    public const MINIMUM = [
        'mysql' => '8.0.11',   // first 8.0 GA, 2018-04
        'mariadb' => '10.3',   // 10.3 GA, 2018-05
        'pgsql' => '11',       // 2018-10
        'sqlsrv' => '15.0',    // SQL Server 2019
        'sqlite' => '3.22.0',  // 2018-01
    ];

    /** Product names used in messages. */
    public const PRODUCT = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
        'sqlite' => 'SQLite',
    ];

    /**
     * Numeric version from a server version string, e.g.
     *  "8.0.11" → "8.0.11", "10.3.39-MariaDB-1:10.3.39+maria~ubu2004" → "10.3.39",
     *  "5.5.5-10.3.39-MariaDB" → "10.3.39", "PostgreSQL 11.22 (Debian …) on x86_64" → "11.22",
     *  "11.22 (Debian 11.22-1.pgdg90+1)" → "11.22", "17beta1" → "17", "15.0.4415.2" → "15.0.4415.2".
     * Returns '' when the string contains no version number.
     */
    public static function normalize(string $raw): string
    {
        $s = trim($raw);
        // MariaDB behind the MySQL 5.5 compatibility prefix of the client handshake.
        $s = (string) preg_replace('/^5\.5\.5-(?=\d+\.\d+)/', '', $s);
        if (!preg_match('/(\d+(?:\.\d+){0,3})/', $s, $m)) {
            return '';
        }
        return $m[1];
    }

    /**
     * Compare two version strings numerically (missing parts count as 0, so "10.3" == "10.3.0").
     * @return int -1, 0 or 1
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        $n = max(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x !== $y) {
                return $x < $y ? -1 : 1;
            }
        }
        return 0;
    }

    /** True when $version >= $min. An unknown version ('') counts as new (no feature is hidden). */
    public static function atLeast(string $version, string $min): bool
    {
        $v = self::normalize($version);
        if ($v === '') {
            return true;
        }
        return self::compare($v, $min) >= 0;
    }

    public static function minimum(string $flavor): ?string
    {
        return self::MINIMUM[$flavor] ?? null;
    }

    /** False only when the version is known AND older than the minimum of the flavor. */
    public static function isSupported(string $flavor, string $version): bool
    {
        $min = self::minimum($flavor);
        $v = self::normalize($version);
        if ($min === null || $v === '') {
            return true;
        }
        return self::compare($v, $min) >= 0;
    }

    /**
     * Fields merged into serverInfo() and the connection metadata.
     * version_number is the normalized version (for messages; 'version' stays as reported).
     * @return array{version_supported:bool,min_version:?string,version_flavor:string,version_number:string}
     */
    public static function support(string $flavor, string $version): array
    {
        return [
            'version_number' => self::normalize($version),
            'version_supported' => self::isSupported($flavor, $version),
            'min_version' => self::minimum($flavor),
            'version_flavor' => $flavor,
        ];
    }

    /** @return int[] */
    private static function parts(string $v): array
    {
        $v = self::normalize($v);
        if ($v === '') {
            return [0];
        }
        return array_map('intval', explode('.', $v));
    }
}
