<?php

declare(strict_types=1);

namespace Ldb\Monitor;

/**
 * Rule-based tuning hints from a `server.metrics` snapshot (pure; unit-tested with fixtures).
 * Counters are totals since server start (or stats reset), so the rules look at ratios and
 * per-hour averages, with minimum sample sizes to avoid noise on idle or freshly started servers.
 *
 * Each hint: {id, severity:'critical'|'warning'|'info', metric, value, params}. The UI translates
 * `monitor.advice.<id>.title|detail|suggestion` with `params` (numbers; keys ending in `bytes`
 * are byte sizes). Wording is deliberately conservative ("consider …").
 */
final class Advisor
{
    private const ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

    /** @return array<int,array<string,mixed>> sorted by severity */
    public static function advise(array $snapshot): array
    {
        $c = (array) ($snapshot['counters'] ?? []);
        $g = (array) ($snapshot['gauges'] ?? []);
        $s = (array) ($snapshot['settings'] ?? []);
        $uptime = isset($snapshot['uptime']) ? (float) $snapshot['uptime'] : null;
        $long = is_array($snapshot['long'] ?? null) ? $snapshot['long'] : [];
        $out = [];
        switch ((string) ($snapshot['driver'] ?? '')) {
            case 'mysql':
                $out = self::mysql($c, $g, $s, $uptime);
                break;
            case 'pgsql':
                $out = self::pgsql($c, $g, $s, $uptime);
                break;
            case 'sqlsrv':
                $out = self::sqlsrv($g);
                break;
        }
        $near = self::connections($g);
        if ($near !== null) {
            $out[] = $near;
        }
        $lr = self::longRunning($long);
        if ($lr !== null) {
            $out[] = $lr;
        }
        if ($uptime !== null && $uptime < 86400 && $out !== [] && ($snapshot['driver'] ?? '') !== 'sqlsrv') {
            $out[] = self::hint('uptime_short', 'info', 'Uptime', $uptime, ['hours' => round($uptime / 3600, 1)]);
        }
        usort($out, static function (array $a, array $b): int {
            return self::ORDER[$a['severity']] <=> self::ORDER[$b['severity']];
        });
        return $out;
    }

    private static function mysql(array $c, array $g, array $s, ?float $uptime): array
    {
        $out = [];
        $hours = $uptime !== null && $uptime > 0 ? $uptime / 3600 : null;

        $req = (float) ($c['cache_requests'] ?? 0);
        if ($req >= 10000 && isset($g['cache_hit_ratio']) && $g['cache_hit_ratio'] < 99) {
            $ratio = (float) $g['cache_hit_ratio'];
            $out[] = self::hint('mysql.bp_hit', $ratio < 95 ? 'critical' : 'warning', 'Innodb_buffer_pool_reads', $ratio, [
                'ratio' => round($ratio, 2), 'size_bytes' => $s['innodb_buffer_pool_size'] ?? null,
            ]);
        }

        $tmp = (float) ($c['tmp_tables'] ?? 0);
        $disk = (float) ($c['tmp_disk_tables'] ?? 0);
        if ($tmp >= 1000 && $disk / $tmp > 0.25) {
            $out[] = self::hint('mysql.tmp_disk', 'warning', 'Created_tmp_disk_tables', $disk, [
                'pct' => round($disk / $tmp * 100, 1),
                'tmp_bytes' => $s['tmp_table_size'] ?? null, 'heap_bytes' => $s['max_heap_table_size'] ?? null,
            ]);
        }

        $fj = (float) ($c['select_full_join'] ?? 0);
        if ($hours !== null && $fj >= 100 && $fj / $hours > 10) {
            $out[] = self::hint('mysql.full_join', 'warning', 'Select_full_join', $fj, [
                'count' => $fj, 'per_hour' => round($fj / $hours, 1),
            ]);
        }

        $max = (float) ($g['max_connections'] ?? 0);
        $used = (float) ($g['max_used_connections'] ?? 0);
        $cur = (float) ($g['connections'] ?? 0);
        if ($max > 0 && $used / $max >= 0.85 && $cur / $max < 0.8) {
            $out[] = self::hint('mysql.max_used', 'warning', 'Max_used_connections', $used, [
                'used' => $used, 'max' => $max, 'pct' => round($used / $max * 100),
            ]);
        }

        $conns = (float) ($c['connections'] ?? 0);
        $aborted = (float) ($c['aborted_connects'] ?? 0);
        if ($conns >= 1000 && $aborted >= 100 && $aborted / $conns > 0.05) {
            $out[] = self::hint('mysql.aborted', 'info', 'Aborted_connects', $aborted, [
                'count' => $aborted, 'pct' => round($aborted / $conns * 100, 1),
            ]);
        }

        $q = (float) ($c['queries'] ?? 0);
        $slow = (float) ($c['slow_queries'] ?? 0);
        if ($q >= 10000 && $slow >= 100 && $slow / $q > 0.01) {
            $out[] = self::hint('mysql.slow_ratio', 'warning', 'Slow_queries', $slow, [
                'count' => $slow, 'pct' => round($slow / $q * 100, 2), 'long_query_time' => $s['long_query_time'] ?? null,
            ]);
        }
        if (self::off($s['slow_query_log'] ?? null) && self::off($s['performance_schema'] ?? null)) {
            $out[] = self::hint('mysql.no_query_stats', 'info', 'slow_query_log', 'OFF', []);
        }

        $hits = (float) ($c['table_cache_hits'] ?? 0);
        $miss = (float) ($c['table_cache_misses'] ?? 0);
        if ($hits + $miss >= 1000 && $miss / ($hits + $miss) > 0.1) {
            $out[] = self::hint('mysql.table_cache', 'info', 'Table_open_cache_misses', $miss, [
                'pct' => round($miss / ($hits + $miss) * 100, 1), 'size' => $s['table_open_cache'] ?? null,
            ]);
        }

        $locks = (float) ($c['row_lock_waits'] ?? 0);
        if ($hours !== null && $locks >= 100 && $locks / $hours > 100) {
            $out[] = self::hint('mysql.row_locks', 'info', 'Innodb_row_lock_waits', $locks, [
                'count' => $locks, 'per_hour' => round($locks / $hours, 1),
            ]);
        }
        return $out;
    }

    private static function pgsql(array $c, array $g, array $s, ?float $uptime): array
    {
        $out = [];
        $req = (float) ($c['cache_requests'] ?? 0);
        if ($req >= 10000 && isset($g['cache_hit_ratio']) && $g['cache_hit_ratio'] < 99) {
            $ratio = (float) $g['cache_hit_ratio'];
            $out[] = self::hint('pg.cache_hit', $ratio < 90 ? 'critical' : 'warning', 'blks_hit / blks_read', $ratio, [
                'ratio' => round($ratio, 2), 'shared_buffers' => $s['shared_buffers'] ?? null,
            ]);
        }

        $idleTx = (float) ($g['idle_in_tx'] ?? 0);
        $longest = (float) ($g['longest_idle_tx'] ?? 0);
        if ($idleTx > 0 && $longest >= 60) {
            $out[] = self::hint('pg.idle_in_tx', $longest >= 600 ? 'critical' : 'warning', 'idle in transaction', $idleTx, [
                'count' => $idleTx, 'seconds' => round($longest),
                'timeout' => (string) ($s['idle_in_transaction_session_timeout'] ?? '0'),
            ]);
        }

        $dl = (float) ($c['deadlocks'] ?? 0);
        if ($dl > 0) {
            $out[] = self::hint('pg.deadlocks', 'info', 'deadlocks', $dl, ['count' => $dl]);
        }

        $temp = (float) ($c['temp_bytes'] ?? 0);
        $days = $uptime !== null && $uptime > 0 ? max(1.0, $uptime / 86400) : 1.0;
        if ($temp / $days > 1073741824) {
            $out[] = self::hint('pg.temp', 'info', 'temp_bytes', $temp, [
                'total_bytes' => $temp, 'work_mem' => $s['work_mem'] ?? null,
            ]);
        }

        $timed = (float) ($c['checkpoints_timed'] ?? 0);
        $reqd = (float) ($c['checkpoints_req'] ?? 0);
        if ($timed + $reqd >= 10 && $reqd > $timed) {
            $out[] = self::hint('pg.checkpoints', 'warning', 'checkpoints_req', $reqd, [
                'requested' => $reqd, 'timed' => $timed, 'max_wal_size' => $s['max_wal_size'] ?? null,
            ]);
        }

        $q = (float) ($c['queries'] ?? 0);
        $rb = (float) ($c['rollbacks'] ?? 0);
        if ($q >= 1000 && $rb / $q > 0.05) {
            $out[] = self::hint('pg.rollbacks', 'info', 'xact_rollback', $rb, ['pct' => round($rb / $q * 100, 1)]);
        }

        $lag = $g['replication_lag'] ?? null;
        if ($lag !== null && (float) $lag > 30) {
            $out[] = self::hint('pg.replication_lag', (float) $lag > 300 ? 'critical' : 'warning', 'replication lag', (float) $lag, ['seconds' => round((float) $lag)]);
        }
        return $out;
    }

    private static function sqlsrv(array $g): array
    {
        $out = [];
        if (isset($g['page_life_expectancy']) && $g['page_life_expectancy'] < 300) {
            $out[] = self::hint('mssql.ple', 'warning', 'Page life expectancy', $g['page_life_expectancy'], ['seconds' => $g['page_life_expectancy']]);
        }
        if (isset($g['cache_hit_ratio']) && $g['cache_hit_ratio'] < 95) {
            $out[] = self::hint('mssql.cache_hit', 'warning', 'Buffer cache hit ratio', $g['cache_hit_ratio'], ['ratio' => $g['cache_hit_ratio']]);
        }
        if (!empty($g['blocked'])) {
            $out[] = self::hint('mssql.blocked', 'warning', 'Processes blocked', $g['blocked'], ['count' => $g['blocked']]);
        }
        return $out;
    }

    private static function connections(array $g): ?array
    {
        $max = (float) ($g['max_connections'] ?? 0);
        $cur = (float) ($g['connections'] ?? 0);
        if ($max <= 0 || $cur / $max < 0.8) {
            return null;
        }
        return self::hint('conn.near_max', $cur / $max >= 0.95 ? 'critical' : 'warning', 'max_connections', $cur, [
            'current' => $cur, 'max' => $max, 'pct' => round($cur / $max * 100),
        ]);
    }

    private static function longRunning(array $long): ?array
    {
        $over = array_values(array_filter($long, static fn ($r): bool => is_array($r) && (float) ($r['time'] ?? 0) >= 60));
        if ($over === []) {
            return null;
        }
        $maxSec = max(array_map(static fn (array $r): float => (float) $r['time'], $over));
        return self::hint('long_running', $maxSec >= 600 ? 'critical' : 'warning', 'processlist', count($over), [
            'count' => count($over), 'seconds' => round($maxSec),
        ]);
    }

    private static function off($v): bool
    {
        return $v !== null && in_array(strtoupper((string) $v), ['OFF', '0'], true);
    }

    /** @param mixed $value */
    private static function hint(string $id, string $severity, string $metric, $value, array $params): array
    {
        $clean = [];
        foreach ($params as $k => $v) {
            if ($v !== null) {
                $clean[$k] = is_float($v) && floor($v) === $v && abs($v) < 9.0E15 ? (int) $v : $v;
            }
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 9.0E15) {
            $value = (int) $value;
        }
        return ['id' => $id, 'severity' => $severity, 'metric' => $metric, 'value' => $value, 'params' => (object) $clean];
    }
}
