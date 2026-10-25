<?php

declare(strict_types=1);

namespace Ldb\Monitor;

use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;
use PDO;

/**
 * Server monitoring (server.metrics / server.slowLog / server.advice): read-only statistics
 * queries per engine. Raw values are normalised by MetricsNormalizer, hints by Advisor.
 * SQLite is unsupported (capability `monitor` false).
 */
abstract class ServerMonitor
{
    public const SLOW_LIMIT_MAX = 100;

    protected DriverInterface $db;

    final public function __construct(DriverInterface $db)
    {
        $this->db = $db;
    }

    public static function supports(string $driverId): bool
    {
        return in_array($driverId, ['mysql', 'pgsql', 'sqlsrv'], true);
    }

    public static function for(DriverInterface $db): self
    {
        switch ($db::id()) {
            case 'mysql':
                return new MySqlMonitor($db);
            case 'pgsql':
                return new PgSqlMonitor($db);
            case 'sqlsrv':
                return new SqlSrvMonitor($db);
        }
        throw ApiException::unsupported();
    }

    /** Normalised snapshot (see MetricsNormalizer). Cheap: polled every 2–10 s. */
    abstract public function metrics(): array;

    /**
     * Top / recent statements.
     * @param string $source 'auto' or one of the engine's sources
     * @param string $order  'total' | 'avg' | 'count'
     * @return array{source:string,sources:string[],hint:?string,rows:array}
     *   rows: {text, sql (executable sample or null), db, user, count, total_ms, avg_ms, max_ms,
     *          rows_examined, rows_sent, no_index, last_seen}
     */
    abstract public function slowLog(int $limit, string $source, string $order): array;

    /** @return array{hints:array,snapshot_ts:int} */
    public function advice(): array
    {
        $snap = $this->metrics();
        return ['hints' => Advisor::advise($snap), 'ts' => $snap['ts']];
    }

    /** @return array<int,array<string,mixed>> */
    protected function rows(string $sql, array $params = []): array
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $rows;
    }

    protected function row(string $sql, array $params = []): array
    {
        return $this->rows($sql, $params)[0] ?? [];
    }

    /** Uniform top-statement row (sanitised strings, ms rounded). */
    protected static function stmtRow(array $r): array
    {
        $f = static function ($v, int $digits = 2): ?float {
            return $v === null || $v === '' ? null : round((float) $v, $digits);
        };
        $i = static function ($v): ?int {
            return $v === null || $v === '' ? null : (int) $v;
        };
        $text = MetricsNormalizer::text($r['text'] ?? null, 4000);
        $sql = isset($r['sql']) ? MetricsNormalizer::text($r['sql'], 4000) : null;
        return [
            'text' => $text,
            'sql' => $sql === null || substr($sql, -3) === '…' ? null : $sql,
            'db' => MetricsNormalizer::text($r['db'] ?? null, 128),
            'user' => MetricsNormalizer::text($r['user'] ?? null, 128),
            'count' => $i($r['count'] ?? null),
            'total_ms' => $f($r['total_ms'] ?? null),
            'avg_ms' => $f($r['avg_ms'] ?? null),
            'max_ms' => $f($r['max_ms'] ?? null),
            'rows_examined' => $i($r['rows_examined'] ?? null),
            'rows_sent' => $i($r['rows_sent'] ?? null),
            'no_index' => $i($r['no_index'] ?? null),
            'last_seen' => MetricsNormalizer::text($r['last_seen'] ?? null, 40),
        ];
    }

    protected static function limit(int $limit): int
    {
        return max(1, min(self::SLOW_LIMIT_MAX, $limit));
    }

    protected static function orderKey(string $order): string
    {
        return in_array($order, ['total', 'avg', 'count'], true) ? $order : 'total';
    }
}
