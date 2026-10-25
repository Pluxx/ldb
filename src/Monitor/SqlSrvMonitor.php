<?php

declare(strict_types=1);

namespace Ldb\Monitor;

use PDO;

final class SqlSrvMonitor extends ServerMonitor
{
    public function metrics(): array
    {
        $perf = $this->db->pdo()->query(
            "SELECT RTRIM(object_name), RTRIM(counter_name), RTRIM(instance_name), cntr_value
             FROM sys.dm_os_performance_counters
             WHERE counter_name IN ('Batch Requests/sec', 'SQL Compilations/sec', 'Transactions/sec',
                   'Lock Waits/sec', 'Number of Deadlocks/sec', 'User Connections', 'Processes blocked',
                   'Page life expectancy', 'Buffer cache hit ratio', 'Buffer cache hit ratio base')"
        )->fetchAll(PDO::FETCH_NUM);
        $extra = $this->row(
            'SELECT DATEDIFF(SECOND, (SELECT sqlserver_start_time FROM sys.dm_os_sys_info), SYSDATETIME()) AS uptime,
                    @@MAX_CONNECTIONS AS max_connections,
                    (SELECT COUNT(*) FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id = r.session_id
                      WHERE s.is_user_process = 1 AND r.session_id <> @@SPID) AS running'
        );
        $long = $this->rows(
            'SELECT TOP 10 r.session_id AS id, s.login_name AS [user], DB_NAME(r.database_id) AS db,
                    r.total_elapsed_time / 1000.0 AS time, r.status + COALESCE(\': \' + r.wait_type, \'\') AS state,
                    LEFT(t.text, 300) AS query
             FROM sys.dm_exec_requests r
             JOIN sys.dm_exec_sessions s ON s.session_id = r.session_id
             OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) t
             WHERE s.is_user_process = 1 AND r.session_id <> @@SPID AND r.total_elapsed_time >= ' . (MetricsNormalizer::LONG_SEC * 1000) . '
             ORDER BY r.total_elapsed_time DESC'
        );
        return MetricsNormalizer::sqlsrv($perf, $extra, $long, microtime(true));
    }

    public function slowLog(int $limit, string $source, string $order): array
    {
        $limit = self::limit($limit);
        $orderBy = ['total' => 'qs.total_elapsed_time', 'avg' => 'qs.total_elapsed_time / qs.execution_count', 'count' => 'qs.execution_count'][self::orderKey($order)];
        // Times are microseconds.
        $rows = $this->rows(
            "SELECT TOP $limit
                    SUBSTRING(t.text, qs.statement_start_offset / 2 + 1,
                      (CASE WHEN qs.statement_end_offset = -1 THEN DATALENGTH(t.text) ELSE qs.statement_end_offset END
                       - qs.statement_start_offset) / 2 + 1) AS text,
                    DB_NAME(t.dbid) AS db, NULL AS [user], qs.execution_count AS count,
                    qs.total_elapsed_time / 1000.0 AS total_ms,
                    qs.total_elapsed_time / 1000.0 / NULLIF(qs.execution_count, 0) AS avg_ms,
                    qs.max_elapsed_time / 1000.0 AS max_ms, qs.total_logical_reads AS rows_examined,
                    qs.total_rows AS rows_sent, NULL AS no_index,
                    CONVERT(varchar(19), qs.last_execution_time, 120) AS last_seen
             FROM sys.dm_exec_query_stats qs
             CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) t
             WHERE t.text NOT LIKE '%dm_exec_query_stats%' AND t.text NOT LIKE '%dm_os_performance_counters%'
             ORDER BY $orderBy DESC"
        );
        $rows = array_map(static function (array $r): array {
            if (!preg_match('/@\w/', (string) $r['text'])) {
                $r['sql'] = $r['text'];
            }
            return $r;
        }, $rows);
        return ['source' => 'query_stats', 'sources' => ['query_stats'], 'hint' => null, 'rows' => array_map(static fn (array $r): array => self::stmtRow($r), $rows)];
    }
}
