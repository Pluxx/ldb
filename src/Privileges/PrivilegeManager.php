<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Db\DriverInterface;
use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqlSrvDriver;
use Ldb\Http\ApiException;

/**
 * Structured privileges of one account (server.privileges*, ARCHITECTURE.md "Privileges").
 * Reads the state from the server, plans changes with the driver's planner (validated, quoted,
 * no-ops dropped) and applies them statement by statement.
 */
abstract class PrivilegeManager
{
    protected DriverInterface $db;

    public function __construct(DriverInterface $db)
    {
        $this->db = $db;
    }

    /** Manager for a connected driver; 'unsupported' for SQLite. */
    public static function for(DriverInterface $db): self
    {
        if ($db instanceof MySqlDriver) {
            return new MySqlPrivileges($db);
        }
        if ($db instanceof PgSqlDriver) {
            return new PgSqlPrivileges($db);
        }
        if ($db instanceof SqlSrvDriver) {
            return new SqlSrvPrivileges($db);
        }
        throw ApiException::unsupported('Privileges are not supported by this driver');
    }

    abstract public function planner(): AbstractPrivilegePlanner;

    /**
     * PrivilegeState of an account. $db: database whose object-level privileges are read
     * (PostgreSQL / SQL Server; MySQL always returns all levels).
     */
    abstract public function read(string $user, ?string $host, ?string $db = null): array;

    /** Tables (with columns) and schemas of a database, for the object pickers. */
    abstract public function objects(string $db): array;

    /** @return array<int,array{sql:string,db:?string}> */
    abstract public function createRole(string $name, ?string $host): array;

    /** @return array<int,array{sql:string,db:?string}> */
    abstract public function dropRole(string $name, ?string $host): array;

    /** Databases referenced by a change spec (object-level state must be read there). */
    protected function referencedDatabases(array $changes): array
    {
        $dbs = [];
        foreach (['schemas', 'tables', 'columns'] as $k) {
            foreach ((array) ($changes[$k] ?? []) as $e) {
                if (is_array($e) && isset($e['db']) && is_string($e['db']) && $e['db'] !== '') {
                    $dbs[$e['db']] = true;
                }
            }
        }
        return array_keys($dbs);
    }

    /**
     * Current state for planning: account-level state plus object-level state of every
     * referenced database (merged).
     */
    protected function stateFor(string $user, ?string $host, array $changes): array
    {
        $dbs = $this->referencedDatabases($changes);
        $state = $this->read($user, $host, $dbs[0] ?? null);
        foreach (array_slice($dbs, 1) as $d) {
            $more = $this->read($user, $host, $d);
            foreach (['schemas', 'tables', 'columns'] as $k) {
                $state[$k] = array_merge($state[$k] ?? [], $more[$k] ?? []);
            }
            $state['db_users'] = ($state['db_users'] ?? []) + ($more['db_users'] ?? []);
            $state['db_roles'] = ($state['db_roles'] ?? []) + ($more['db_roles'] ?? []);
        }
        return $state;
    }

    /**
     * @return array{statements:array<int,array{sql:string,db:?string}>,warnings:string[],self_lockout:string[],self:bool}
     */
    public function plan(string $user, ?string $host, array $changes): array
    {
        $orig = $this->db->currentDatabase();
        try {
            $changes = $this->expandClone($user, $host, $changes);
            $state = $this->stateFor($user, $host, $changes);
            if (empty($state['account']['exists'])) {
                throw ApiException::notFound('Account not found');
            }
            $plan = $this->planner()->plan($user, $host, $changes, $state);
            $plan['self'] = !empty($state['self']);
            return $plan;
        } finally {
            $this->restoreDb($orig);
        }
    }

    /**
     * Re-plan and execute; refuses (code self_lockout) when the plan could lock the login
     * account out unless $confirmSelf. Every statement runs (errors are collected) unless
     * $stopOnError.
     * @return array{results:array<int,array{sql:string,db:?string,ok:bool,error:?string}>,executed:int,failed:int,warnings:string[]}
     */
    public function apply(string $user, ?string $host, array $changes, bool $confirmSelf, bool $stopOnError = false): array
    {
        $plan = $this->plan($user, $host, $changes);
        if ($plan['self_lockout'] && !$confirmSelf) {
            throw (new ApiException(
                'These changes could lock your own login out of the server. Confirm to apply them anyway.',
                'self_lockout',
                409
            ))->withDetails(['reasons' => $plan['self_lockout']]);
        }
        $out = $this->run($plan['statements'], $stopOnError);
        $out['warnings'] = $plan['warnings'];
        return $out;
    }

    /** Execute planned statements in order (switching databases where needed). */
    public function run(array $statements, bool $stopOnError = false): array
    {
        $orig = $this->db->currentDatabase();
        $results = [];
        $ok = 0;
        $failed = 0;
        try {
            foreach ($statements as $s) {
                $r = ['sql' => $s['sql'], 'db' => $s['db'], 'ok' => true, 'error' => null];
                try {
                    if ($s['db'] !== null && $s['db'] !== $this->db->currentDatabase()) {
                        $this->db->select($s['db']);
                    }
                    $this->db->pdo()->exec($s['sql']);
                    $ok++;
                } catch (\PDOException $e) {
                    $r['ok'] = false;
                    $r['error'] = $e->getMessage();
                    $failed++;
                } catch (ApiException $e) {
                    $r['ok'] = false;
                    $r['error'] = $e->getMessage();
                    $failed++;
                }
                $results[] = $r;
                if (!$r['ok'] && $stopOnError) {
                    break;
                }
            }
        } finally {
            $this->restoreDb($orig);
        }
        return ['results' => $results, 'executed' => $ok, 'failed' => $failed];
    }

    protected function restoreDb(?string $orig): void
    {
        if ($orig !== null && $orig !== '' && $orig !== $this->db->currentDatabase()) {
            try {
                $this->db->select($orig);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * clone_from {user, host?}: add every privilege the source account has and the target
     * lacks (nothing is removed). Replaced by the equivalent add lists.
     */
    protected function expandClone(string $user, ?string $host, array $changes): array
    {
        if (!isset($changes['clone_from'])) {
            return $changes;
        }
        $src = $changes['clone_from'];
        unset($changes['clone_from']);
        if (!is_array($src) || !isset($src['user']) || !is_string($src['user'])) {
            throw ApiException::badRequest('clone_from needs a user');
        }
        $srcHost = isset($src['host']) && is_string($src['host']) && $src['host'] !== '' ? $src['host'] : null;
        $db = isset($src['db']) && is_string($src['db']) && $src['db'] !== '' ? $src['db'] : null;
        $from = $this->read($src['user'], $srcHost, $db);
        if (empty($from['account']['exists'])) {
            throw ApiException::notFound('Source account not found');
        }
        $to = $this->read($user, $host, $db);
        return $this->mergeClone($changes, $from, $to);
    }

    /** Add lists for everything $from has that $to has not (object levels + roles). */
    protected function mergeClone(array $changes, array $from, array $to): array
    {
        $global = array_values(array_diff((array) ($from['global'] ?? []), (array) ($to['global'] ?? [])));
        if ($global) {
            $changes['global']['add'] = array_values(array_unique(array_merge((array) ($changes['global']['add'] ?? []), $global)));
        }
        $levels = ['databases' => ['db'], 'schemas' => ['db', 'schema'], 'tables' => ['db', 'schema', 'table'], 'columns' => ['db', 'schema', 'table', 'column']];
        foreach ($levels as $k => $keys) {
            foreach ((array) ($from[$k] ?? []) as $row) {
                $match = [];
                foreach ($keys as $kk) {
                    if (array_key_exists($kk, $row)) {
                        $match[$kk] = $row[$kk];
                    }
                }
                $have = self::findRow($to[$k] ?? [], $match);
                $add = array_values(array_diff((array) ($row['privileges'] ?? []), $have));
                if ($add) {
                    $entry = $match + ['add' => $add];
                    if (isset($row['kind'])) {
                        $entry['kind'] = $row['kind'];
                    }
                    $changes[$k][] = $entry;
                }
            }
        }
        $roles = [];
        foreach ((array) ($from['roles']['granted'] ?? []) as $r) {
            $roles[] = ['name' => $r['name'], 'host' => $r['host'] ?? null];
        }
        if ($roles) {
            $changes['roles']['add'] = array_merge((array) ($changes['roles']['add'] ?? []), $roles);
        }
        return $changes;
    }

    /** Privileges of the first row matching all $match fields ([] when none). */
    protected static function findRow(array $list, array $match): array
    {
        foreach ($list as $row) {
            $ok = true;
            foreach ($match as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return (array) ($row['privileges'] ?? []);
            }
        }
        return [];
    }
}
