<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Auth\SessionManager;
use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;
use Ldb\Http\Request;

/**
 * Shared plumbing for API controllers: connection lookup, driver creation, parameter helpers.
 * Controller actions return plain data (wrapped as {"ok":true,"data":…}) or a Response.
 */
abstract class AbstractController
{
    protected Config $config;
    protected SessionManager $session;
    protected DriverFactory $factory;
    protected Request $request;
    private ?DriverInterface $driver = null;

    public function __construct(Config $config, SessionManager $session, DriverFactory $factory, Request $request)
    {
        $this->config = $config;
        $this->session = $session;
        $this->factory = $factory;
        $this->request = $request;
    }

    /** Connection id from the request; falls back to the only connection if there is just one. */
    protected function connId(): string
    {
        $conn = $this->request->optString('conn');
        if ($conn !== null) {
            return $conn;
        }
        $ids = $this->session->connectionIds();
        if (count($ids) === 1) {
            return $ids[0];
        }
        throw ApiException::badRequest('Missing parameter: conn');
    }

    /**
     * Connected driver for the request's connection, with db/schema selected when given.
     */
    protected function db(bool $selectDatabase = true): DriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        $params = $this->session->connectionParams($this->connId());
        if ($params === null) {
            throw ApiException::authRequired('Unknown or expired connection');
        }
        $db = $this->factory->connect($params);
        if ($selectDatabase) {
            $database = $this->request->optString('db');
            $schema = $this->request->optString('schema');
            if ($database !== null || $schema !== null) {
                $db->select($database, $schema);
            }
        }
        return $this->driver = $db;
    }

    /** Whether the request's connection is read-only (login choice, preset or global config). */
    protected function isReadOnly(): bool
    {
        $params = $this->session->connectionParams($this->connId());
        if ($params === null) {
            throw ApiException::authRequired('Unknown or expired connection');
        }
        return $this->factory->isReadOnly($params);
    }

    /** Like db() but requires the `db` parameter. */
    protected function dbWithDatabase(): DriverInterface
    {
        $this->request->string('db');
        return $this->db();
    }

    protected function table(): string
    {
        return $this->request->string('table');
    }

    /** @return string[] list of table names from param $name (array or JSON) */
    protected function stringList(string $name, bool $required = true): array
    {
        $list = array_values(array_filter(
            array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $this->request->arr($name)),
            static fn (string $v): bool => $v !== ''
        ));
        if ($required && $list === []) {
            throw ApiException::badRequest("Missing parameter: $name");
        }
        return $list;
    }

    protected function requireCapability(DriverInterface $db, string $cap): void
    {
        $caps = $db->capabilities();
        if (empty($caps[$cap])) {
            throw ApiException::unsupported();
        }
    }

    protected static function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /** Multibyte-safe preview of a string (never cuts inside a UTF-8 sequence). */
    protected static function preview(string $s, int $max = 200): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max, 'UTF-8') . '…';
    }

    /** Expression casting a column to text for LIKE comparisons. */
    protected static function textExpr(DriverInterface $db, string $quotedColumn): string
    {
        switch ($db::id()) {
            case 'pgsql':
                return 'CAST(' . $quotedColumn . ' AS TEXT)';
            case 'sqlsrv':
                return 'CAST(' . $quotedColumn . ' AS NVARCHAR(MAX))';
            default:
                return $quotedColumn;
        }
    }

    /** Escape a value for LIKE … ESCAPE '!' (portable across all supported engines). */
    protected static function likeEscape(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
    }

    /** Run a list of statements (DDL builders) and wrap failures as db_error with the SQL. */
    protected function runStatements(DriverInterface $db, array $sqls): void
    {
        foreach ($sqls as $sql) {
            try {
                // Through the driver (not raw PDO) so drivers can recover from failed multi-step DDL.
                $db->query($sql, 0);
            } catch (\PDOException $e) {
                throw ApiException::dbError($e->getMessage(), $sql, $e);
            }
        }
    }
}
