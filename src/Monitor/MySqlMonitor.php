<?php

declare(strict_types=1);

namespace Ldb\Monitor;

use Ldb\Db\MySqlDriver;
use PDO;

final class MySqlMonitor extends ServerMonitor
{
    public function metrics(): array
    {
        $pdo = $this->db->pdo();
        $in = "'" . implode("','", MetricsNormalizer::MYSQL_STATUS) . "'";
        $status = $pdo->query('SHOW GLOBAL STATUS WHERE LOWER(Variable_name) IN (' . $in . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
        $vin = "'" . implode("','", MetricsNormalizer::MYSQL_VARIABLES) . "'";
        $vars = $pdo->query('SHOW GLOBAL VARIABLES WHERE LOWER(Variable_name) IN (' . $vin . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
        $long = [];
        try {
            $long = $this->rows(
                "SELECT ID AS id, USER AS user, DB AS db, TIME AS time, STATE AS state, LEFT(INFO, 300) AS query
                 FROM information_schema.PROCESSLIST
                 WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID')
                   AND INFO IS NOT NULL AND ID <> CONNECTION_ID() AND TIME >= " . MetricsNormalizer::LONG_SEC . '
                 ORDER BY TIME DESC LIMIT 10'
            );
        } catch (\PDOException $e) {
            // PROCESS privilege missing: only own threads are visible, nothing to report
        }
        $maria = $this->db instanceof MySqlDriver && $this->db->isMariaDb();
        return MetricsNormalizer::mysql($status, $vars, $long, microtime(true), $maria);
    }

    public function slowLog(int $limit, string $source, string $order): array
    {
        $limit = self::limit($limit);
        $order = self::orderKey($order);
        $vars = array_change_key_case($this->db->pdo()->query(
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('slow_query_log', 'log_output', 'performance_schema')"
        )->fetchAll(PDO::FETCH_KEY_PAIR), CASE_LOWER);
        $sources = [];
        if (strtoupper((string) ($vars['slow_query_log'] ?? '')) === 'ON' && stripos((string) ($vars['log_output'] ?? ''), 'TABLE') !== false) {
            $sources[] = 'slow_log';
        }
        if (strtoupper((string) ($vars['performance_schema'] ?? '')) === 'ON') {
            $sources[] = 'digest';
        }
        if ($sources === []) {
            return ['source' => 'none', 'sources' => [], 'hint' => 'mysql_enable', 'rows' => []];
        }
        $use = in_array($source, $sources, true) ? $source : $sources[0];
        $rows = $use === 'slow_log' ? $this->fromSlowLog($limit) : $this->fromDigest($limit, $order);
        return ['source' => $use, 'sources' => $sources, 'hint' => null, 'rows' => array_map(static fn (array $r): array => self::stmtRow($r), $rows)];
    }

    private function fromSlowLog(int $limit): array
    {
        return $this->rows(
            'SELECT CONVERT(sql_text USING utf8mb4) AS text, CONVERT(sql_text USING utf8mb4) AS `sql`, db,
                    user_host AS user, 1 AS count,
                    (TIME_TO_SEC(query_time) + MICROSECOND(query_time) / 1000000) * 1000 AS total_ms,
                    (TIME_TO_SEC(query_time) + MICROSECOND(query_time) / 1000000) * 1000 AS avg_ms,
                    (TIME_TO_SEC(query_time) + MICROSECOND(query_time) / 1000000) * 1000 AS max_ms,
                    rows_examined, rows_sent, NULL AS no_index, CAST(start_time AS CHAR) AS last_seen
             FROM mysql.slow_log ORDER BY start_time DESC LIMIT ' . $limit
        );
    }

    private function fromDigest(int $limit, string $order): array
    {
        $hasSample = (bool) $this->row(
            "SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'performance_schema'
               AND TABLE_NAME = 'events_statements_summary_by_digest' AND COLUMN_NAME = 'QUERY_SAMPLE_TEXT'"
        );
        $orderBy = ['total' => 'SUM_TIMER_WAIT', 'avg' => 'AVG_TIMER_WAIT', 'count' => 'COUNT_STAR'][$order];
        // Timers are picoseconds. LDB's own monitoring statements (SHOW …, performance_schema /
        // information_schema reads) are left out so they don't dominate the list.
        return $this->rows(
            'SELECT DIGEST_TEXT AS text, ' . ($hasSample ? 'QUERY_SAMPLE_TEXT' : 'NULL') . ' AS `sql`, SCHEMA_NAME AS db,
                    NULL AS user, COUNT_STAR AS count, SUM_TIMER_WAIT / 1000000000 AS total_ms,
                    AVG_TIMER_WAIT / 1000000000 AS avg_ms, MAX_TIMER_WAIT / 1000000000 AS max_ms,
                    SUM_ROWS_EXAMINED AS rows_examined, SUM_ROWS_SENT AS rows_sent,
                    SUM_NO_INDEX_USED + SUM_NO_GOOD_INDEX_USED AS no_index, CAST(LAST_SEEN AS CHAR) AS last_seen
             FROM performance_schema.events_statements_summary_by_digest
             WHERE DIGEST_TEXT IS NOT NULL
               AND DIGEST_TEXT NOT LIKE \'SHOW %\'
               AND DIGEST_TEXT NOT LIKE \'%`performance_schema`%\'
               AND DIGEST_TEXT NOT LIKE \'%`information_schema`%\'
               AND DIGEST_TEXT NOT LIKE \'%performance_schema.%\'
               AND DIGEST_TEXT NOT LIKE \'%information_schema.%\'
             ORDER BY ' . $orderBy . ' DESC LIMIT ' . $limit
        );
    }
}
