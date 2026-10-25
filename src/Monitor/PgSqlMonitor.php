<?php

declare(strict_types=1);

namespace Ldb\Monitor;

final class PgSqlMonitor extends ServerMonitor
{
    private ?int $versionNum = null;

    private function version(): int
    {
        if ($this->versionNum === null) {
            $this->versionNum = (int) $this->db->pdo()->query("SELECT current_setting('server_version_num')")->fetchColumn();
        }
        return $this->versionNum;
    }

    public function metrics(): array
    {
        $v = $this->version();
        $client = $v >= 100000 ? "backend_type = 'client backend'" : 'datname IS NOT NULL';
        $raw = $this->row(
            "SELECT sum(xact_commit) AS xact_commit, sum(xact_rollback) AS xact_rollback,
                    sum(blks_read) AS blks_read, sum(blks_hit) AS blks_hit,
                    sum(tup_returned) AS tup_returned, sum(tup_fetched) AS tup_fetched,
                    sum(tup_inserted) AS tup_inserted, sum(tup_updated) AS tup_updated,
                    sum(tup_deleted) AS tup_deleted, sum(deadlocks) AS deadlocks,
                    sum(temp_bytes) AS temp_bytes, sum(temp_files) AS temp_files
             FROM pg_stat_database"
        );
        $raw += $this->row(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE state = 'active') AS active,
                    count(*) FILTER (WHERE state = 'idle') AS idle,
                    count(*) FILTER (WHERE state LIKE 'idle in transaction%') AS idle_in_tx,
                    count(*) FILTER (WHERE state = 'active' AND wait_event_type = 'Lock') AS waiting,
                    COALESCE(max(EXTRACT(EPOCH FROM now() - state_change)) FILTER (WHERE state LIKE 'idle in transaction%'), 0) AS longest_idle_tx,
                    current_setting('max_connections') AS max_connections,
                    EXTRACT(EPOCH FROM now() - pg_postmaster_start_time()) AS uptime,
                    current_setting('shared_buffers') AS shared_buffers, current_setting('work_mem') AS work_mem,
                    current_setting('max_wal_size') AS max_wal_size,
                    current_setting('idle_in_transaction_session_timeout') AS idle_in_transaction_session_timeout,
                    current_setting('server_version_num') AS version_num
             FROM pg_stat_activity WHERE $client"
        );
        try {
            if ($v >= 170000) {
                $raw += $this->row(
                    'SELECT num_timed AS checkpoints_timed, num_requested AS checkpoints_req,
                            buffers_written AS buffers_checkpoint FROM pg_stat_checkpointer'
                );
            } else {
                $raw += $this->row(
                    'SELECT checkpoints_timed, checkpoints_req, buffers_checkpoint, buffers_backend FROM pg_stat_bgwriter'
                );
            }
        } catch (\PDOException $e) {
            // pg_monitor role missing on managed services: no checkpoint stats
        }
        try {
            if ($this->db->pdo()->query('SELECT pg_is_in_recovery()')->fetchColumn()) {
                $raw['replication_lag'] = $this->db->pdo()->query(
                    'SELECT COALESCE(EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp()), 0)'
                )->fetchColumn();
            } elseif ($v >= 100000) {
                $rep = $this->row('SELECT count(*) AS replicas, max(EXTRACT(EPOCH FROM replay_lag)) AS lag FROM pg_stat_replication');
                if ((int) ($rep['replicas'] ?? 0) > 0) {
                    $raw['replicas'] = $rep['replicas'];
                    $raw['replication_lag'] = $rep['lag'] ?? 0;
                }
            }
        } catch (\PDOException $e) {
        }
        $long = $this->rows(
            "SELECT pid AS id, usename AS user, datname AS db, EXTRACT(EPOCH FROM now() - query_start) AS time,
                    COALESCE(wait_event_type || ': ' || wait_event, state) AS state, left(query, 300) AS query
             FROM pg_stat_activity
             WHERE state = 'active' AND pid <> pg_backend_pid() AND $client
               AND query_start < now() - interval '" . MetricsNormalizer::LONG_SEC . " seconds'
             ORDER BY query_start LIMIT 10"
        );
        return MetricsNormalizer::pgsql($raw, $long, microtime(true));
    }

    public function slowLog(int $limit, string $source, string $order): array
    {
        $limit = self::limit($limit);
        $order = self::orderKey($order);
        $installed = (bool) $this->row("SELECT 1 AS x FROM pg_extension WHERE extname = 'pg_stat_statements'");
        if (!$installed) {
            return ['source' => 'none', 'sources' => [], 'hint' => 'pg_install', 'rows' => []];
        }
        $v = $this->version();
        $total = $v >= 130000 ? 'total_exec_time' : 'total_time';
        $mean = $v >= 130000 ? 'mean_exec_time' : 'mean_time';
        $max = $v >= 130000 ? 'max_exec_time' : 'max_time';
        $orderBy = ['total' => $total, 'avg' => $mean, 'count' => 'calls'][$order];
        try {
            $rows = $this->rows(
                "SELECT s.query AS text, NULL AS sql, d.datname AS db, r.rolname AS user, s.calls AS count,
                        s.$total AS total_ms, s.$mean AS avg_ms, s.$max AS max_ms,
                        s.shared_blks_hit + s.shared_blks_read AS rows_examined, s.rows AS rows_sent,
                        NULL AS no_index, NULL AS last_seen
                 FROM pg_stat_statements s
                 LEFT JOIN pg_database d ON d.oid = s.dbid
                 LEFT JOIN pg_roles r ON r.oid = s.userid
                 WHERE s.query NOT ILIKE '%pg_stat_%' AND s.query <> '<insufficient privilege>'
                 ORDER BY $orderBy DESC NULLS LAST LIMIT $limit"
            );
        } catch (\PDOException $e) {
            // Extension created but library not in shared_preload_libraries.
            return ['source' => 'none', 'sources' => [], 'hint' => 'pg_preload', 'rows' => []];
        }
        $rows = array_map(static function (array $r): array {
            // Normalised text with $1 placeholders is not executable; plain statements are.
            if (!preg_match('/\$\d/', (string) $r['text'])) {
                $r['sql'] = $r['text'];
            }
            return $r;
        }, $rows);
        return ['source' => 'pg_stat_statements', 'sources' => ['pg_stat_statements'], 'hint' => null, 'rows' => array_map(static fn (array $r): array => self::stmtRow($r), $rows)];
    }
}
