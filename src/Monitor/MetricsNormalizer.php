<?php

declare(strict_types=1);

namespace Ldb\Monitor;

/**
 * Turns raw engine statistics into the engine-neutral `server.metrics` snapshot (pure functions,
 * unit-tested with fixture arrays). Shape:
 *   {driver, flavor?, ts (ms since epoch), uptime (s|null), mix_unit ('statements'|'rows'|null),
 *    counters:{name:int}  monotonically increasing totals — the client derives rates from deltas,
 *    gauges:{name:number} point-in-time values, settings:{name:scalar} for the advisor,
 *    long:[{id,user,db,time,state,query}] statements running ≥ LONG_SEC seconds}
 * Keys whose source value is missing are omitted, so the UI can hide what an engine lacks.
 *
 * Counter names: queries, select, insert, update, delete, slow_queries, connections,
 * aborted_connects, bytes_in, bytes_out, cache_requests, cache_misses, row_lock_waits, tmp_tables,
 * tmp_disk_tables, select_full_join, select_scan, table_cache_hits, table_cache_misses,
 * opened_tables, commits, rollbacks, deadlocks, temp_bytes, temp_files, checkpoints_timed,
 * checkpoints_req, buffers_checkpoint, buffers_backend, compilations, lock_waits, transactions.
 * Gauge names: connections, max_connections, max_used_connections, running, idle, idle_in_tx,
 * waiting, longest_idle_tx, bp_pages_dirty, bp_pages_total, bp_pages_free, cache_hit_ratio (%),
 * page_life_expectancy, blocked, replication_lag, replicas.
 */
final class MetricsNormalizer
{
    /** Threshold (seconds) for the "long-running queries" list. */
    public const LONG_SEC = 10;

    /** SHOW GLOBAL STATUS names read by mysql() (lower case). */
    public const MYSQL_STATUS = [
        'uptime', 'questions', 'com_select', 'com_insert', 'com_insert_select', 'com_replace',
        'com_replace_select', 'com_update', 'com_update_multi', 'com_delete', 'com_delete_multi',
        'slow_queries', 'connections', 'aborted_connects', 'threads_connected', 'threads_running',
        'max_used_connections', 'bytes_received', 'bytes_sent', 'innodb_buffer_pool_read_requests',
        'innodb_buffer_pool_reads', 'innodb_buffer_pool_pages_dirty', 'innodb_buffer_pool_pages_total',
        'innodb_buffer_pool_pages_free', 'innodb_row_lock_waits', 'created_tmp_tables',
        'created_tmp_disk_tables', 'select_full_join', 'select_scan', 'table_open_cache_hits',
        'table_open_cache_misses', 'opened_tables',
    ];

    /** SHOW GLOBAL VARIABLES names read by mysql() (lower case). */
    public const MYSQL_VARIABLES = [
        'max_connections', 'slow_query_log', 'long_query_time', 'log_output', 'performance_schema',
        'innodb_buffer_pool_size', 'tmp_table_size', 'max_heap_table_size', 'table_open_cache',
        'join_buffer_size',
    ];

    /**
     * @param array<string,mixed> $status SHOW GLOBAL STATUS as name => value (any case)
     * @param array<string,mixed> $vars   SHOW GLOBAL VARIABLES as name => value (any case)
     * @param array<int,array<string,mixed>> $long
     */
    public static function mysql(array $status, array $vars, array $long, float $ts, bool $mariaDb = false): array
    {
        $s = array_change_key_case($status, CASE_LOWER);
        $v = array_change_key_case($vars, CASE_LOWER);
        $n = static fn (string $k): ?float => self::num($s[$k] ?? null);
        $sum = static function (string ...$keys) use ($n): ?float {
            $total = null;
            foreach ($keys as $k) {
                $x = $n($k);
                if ($x !== null) {
                    $total = ($total ?? 0) + $x;
                }
            }
            return $total;
        };

        $counters = [
            'queries' => $n('questions'),
            'select' => $n('com_select'),
            'insert' => $sum('com_insert', 'com_insert_select', 'com_replace', 'com_replace_select'),
            'update' => $sum('com_update', 'com_update_multi'),
            'delete' => $sum('com_delete', 'com_delete_multi'),
            'slow_queries' => $n('slow_queries'),
            'connections' => $n('connections'),
            'aborted_connects' => $n('aborted_connects'),
            'bytes_in' => $n('bytes_received'),
            'bytes_out' => $n('bytes_sent'),
            'cache_requests' => $n('innodb_buffer_pool_read_requests'),
            'cache_misses' => $n('innodb_buffer_pool_reads'),
            'row_lock_waits' => $n('innodb_row_lock_waits'),
            'tmp_tables' => $n('created_tmp_tables'),
            'tmp_disk_tables' => $n('created_tmp_disk_tables'),
            'select_full_join' => $n('select_full_join'),
            'select_scan' => $n('select_scan'),
            'table_cache_hits' => $n('table_open_cache_hits'),
            'table_cache_misses' => $n('table_open_cache_misses'),
            'opened_tables' => $n('opened_tables'),
        ];
        $gauges = [
            'connections' => $n('threads_connected'),
            'running' => $n('threads_running'),
            'max_used_connections' => $n('max_used_connections'),
            'max_connections' => self::num($v['max_connections'] ?? null),
            'bp_pages_dirty' => $n('innodb_buffer_pool_pages_dirty'),
            'bp_pages_total' => $n('innodb_buffer_pool_pages_total'),
            'bp_pages_free' => $n('innodb_buffer_pool_pages_free'),
            'cache_hit_ratio' => self::hitRatio($counters['cache_requests'], $counters['cache_misses']),
        ];
        $settings = [];
        foreach (self::MYSQL_VARIABLES as $k) {
            if (array_key_exists($k, $v) && $v[$k] !== null) {
                $settings[$k] = is_numeric($v[$k]) ? self::num($v[$k]) : (string) $v[$k];
            }
        }
        return self::snapshot('mysql', $ts, $n('uptime'), 'statements', $counters, $gauges, $settings, $long)
            + ['flavor' => $mariaDb ? 'mariadb' : 'mysql'];
    }

    /**
     * @param array<string,mixed> $raw flat row: pg_stat_database sums (xact_commit, xact_rollback,
     *   blks_read, blks_hit, tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted,
     *   deadlocks, temp_bytes, temp_files), activity counts (active, idle, idle_in_tx, waiting,
     *   total, longest_idle_tx), max_connections, uptime, checkpoint stats (checkpoints_timed,
     *   checkpoints_req, buffers_checkpoint, buffers_backend? — already mapped for PG ≥ 17),
     *   replication_lag?, replicas?, settings (shared_buffers, work_mem, max_wal_size,
     *   idle_in_transaction_session_timeout) as strings.
     */
    public static function pgsql(array $raw, array $long, float $ts): array
    {
        $n = static fn (string $k): ?float => self::num($raw[$k] ?? null);
        $commits = $n('xact_commit');
        $rollbacks = $n('xact_rollback');
        $hit = $n('blks_hit');
        $read = $n('blks_read');
        $requests = $hit === null || $read === null ? null : $hit + $read;
        $counters = [
            'queries' => $commits === null || $rollbacks === null ? null : $commits + $rollbacks,
            'commits' => $commits,
            'rollbacks' => $rollbacks,
            'select' => $n('tup_fetched'),
            'insert' => $n('tup_inserted'),
            'update' => $n('tup_updated'),
            'delete' => $n('tup_deleted'),
            'tup_returned' => $n('tup_returned'),
            'cache_requests' => $requests,
            'cache_misses' => $read,
            'deadlocks' => $n('deadlocks'),
            'temp_bytes' => $n('temp_bytes'),
            'temp_files' => $n('temp_files'),
            'checkpoints_timed' => $n('checkpoints_timed'),
            'checkpoints_req' => $n('checkpoints_req'),
            'buffers_checkpoint' => $n('buffers_checkpoint'),
            'buffers_backend' => $n('buffers_backend'),
        ];
        $gauges = [
            'connections' => $n('total'),
            'max_connections' => $n('max_connections'),
            'running' => $n('active'),
            'idle' => $n('idle'),
            'idle_in_tx' => $n('idle_in_tx'),
            'waiting' => $n('waiting'),
            'longest_idle_tx' => $n('longest_idle_tx'),
            'cache_hit_ratio' => self::hitRatio($requests, $read),
            'replication_lag' => $n('replication_lag'),
            'replicas' => $n('replicas'),
        ];
        $settings = [];
        foreach (['shared_buffers', 'work_mem', 'max_wal_size', 'idle_in_transaction_session_timeout', 'version_num'] as $k) {
            if (isset($raw[$k])) {
                $settings[$k] = is_numeric($raw[$k]) ? self::num($raw[$k]) : (string) $raw[$k];
            }
        }
        return self::snapshot('pgsql', $ts, $n('uptime'), 'rows', $counters, $gauges, $settings, $long);
    }

    /**
     * @param array<int,array{0:string,1:string,2:string,3:mixed}> $perf rows of
     *   sys.dm_os_performance_counters as [object_name, counter_name, instance_name, cntr_value]
     * @param array<string,mixed> $extra uptime, running, max_connections
     */
    public static function sqlsrv(array $perf, array $extra, array $long, float $ts): array
    {
        $get = static function (string $object, string $counter, ?string $instance = null) use ($perf): ?float {
            foreach ($perf as $r) {
                $obj = strtolower(trim((string) $r[0]));
                // object_name is "SQLServer:Buffer Manager" or "MSSQL$INSTANCE:Buffer Manager".
                $objName = ($p = strrpos($obj, ':')) === false ? $obj : substr($obj, $p + 1);
                if ($objName === strtolower($object)
                    && strcasecmp(trim((string) $r[1]), $counter) === 0
                    && ($instance === null || strcasecmp(trim((string) $r[2]), $instance) === 0)) {
                    return self::num($r[3]);
                }
            }
            return null;
        };
        $ratio = $get('Buffer Manager', 'Buffer cache hit ratio');
        $base = $get('Buffer Manager', 'Buffer cache hit ratio base');
        $counters = [
            'queries' => $get('SQL Statistics', 'Batch Requests/sec'),
            'compilations' => $get('SQL Statistics', 'SQL Compilations/sec'),
            'transactions' => $get('Databases', 'Transactions/sec', '_Total'),
            'lock_waits' => $get('Locks', 'Lock Waits/sec', '_Total'),
            'deadlocks' => $get('Locks', 'Number of Deadlocks/sec', '_Total'),
        ];
        $gauges = [
            'connections' => $get('General Statistics', 'User Connections'),
            'blocked' => $get('General Statistics', 'Processes blocked'),
            'page_life_expectancy' => $get('Buffer Manager', 'Page life expectancy'),
            'cache_hit_ratio' => $ratio !== null && $base !== null && $base > 0 ? round(min(100.0, $ratio / $base * 100), 2) : null,
            'running' => self::num($extra['running'] ?? null),
            'max_connections' => self::num($extra['max_connections'] ?? null),
        ];
        return self::snapshot('sqlsrv', $ts, self::num($extra['uptime'] ?? null), null, $counters, $gauges, [], $long);
    }

    /**
     * Normalise long-running statement rows ({id,user,db,time,state,query}) — ids and strings
     * are sanitised (valid UTF-8, query preview ≤ 300 chars), time as float seconds.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function longRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $out[] = [
                'id' => (string) ($r['id'] ?? ''),
                'user' => self::text($r['user'] ?? null, 128),
                'db' => self::text($r['db'] ?? null, 128),
                'time' => round((float) ($r['time'] ?? 0), 1),
                'state' => self::text($r['state'] ?? null, 128),
                'query' => self::text($r['query'] ?? null, 300),
            ];
        }
        return $out;
    }

    /** Valid-UTF-8, length-limited string (null stays null). */
    public static function text($v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
    }

    /** Lifetime hit ratio in percent (null without enough data). */
    public static function hitRatio(?float $requests, ?float $misses): ?float
    {
        if ($requests === null || $misses === null || $requests <= 0) {
            return null;
        }
        return round(max(0.0, min(100.0, (1 - $misses / $requests) * 100)), 3);
    }

    /** @return float|null numeric value (ints stay exact up to 2^53) */
    public static function num($v): ?float
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        return is_numeric($s) ? (float) $s : null;
    }

    private static function snapshot(string $driver, float $ts, ?float $uptime, ?string $mixUnit, array $counters, array $gauges, array $settings, array $long): array
    {
        $clean = static function (array $a): array {
            $out = [];
            foreach ($a as $k => $x) {
                if ($x !== null) {
                    // Whole numbers as int (JSON without ".0"); floats keep their fraction.
                    $out[$k] = is_float($x) && floor($x) === $x && abs($x) < 9.0E15 ? (int) $x : $x;
                }
            }
            return $out;
        };
        return [
            'driver' => $driver,
            'ts' => (int) round($ts * 1000),
            'uptime' => $uptime === null ? null : (int) $uptime,
            'mix_unit' => $mixUnit,
            'counters' => (object) $clean($counters),
            'gauges' => (object) $clean($gauges),
            'settings' => (object) $clean($settings),
            'long' => self::longRows($long),
        ];
    }
}
