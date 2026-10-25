<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use PDO;

/**
 * MySQL / MariaDB privileges: object levels from SHOW GRANTS (parsed), account options from
 * mysql.user / mysql.global_priv, roles from mysql.role_edges + default_roles (MySQL 8) or
 * mysql.roles_mapping (MariaDB).
 */
final class MySqlPrivileges extends PrivilegeManager
{
    private ?MySqlPrivilegePlanner $planner = null;
    /** @var array<string,string> privilege => server description (SHOW PRIVILEGES) */
    private array $descriptions = [];

    private function my(): MySqlDriver
    {
        /** @var MySqlDriver $d */
        $d = $this->db;
        return $d;
    }

    private function rows(string $sql, array $params = []): array
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($r) => array_change_key_case($r, CASE_LOWER), $rows);
    }

    public function planner(): AbstractPrivilegePlanner
    {
        if ($this->planner === null) {
            $extra = [];
            try {
                foreach ($this->rows('SHOW PRIVILEGES') as $r) {
                    $name = AbstractPrivilegePlanner::canon((string) ($r['privilege'] ?? ''));
                    $context = (string) ($r['context'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    if ($name === 'GRANT') {
                        $name = 'GRANT OPTION';
                    }
                    $this->descriptions[$name] = (string) ($r['comment'] ?? '');
                    if (stripos($context, 'Server Admin') !== false || stripos($context, 'File access') !== false) {
                        $extra[] = $name;
                    }
                }
            } catch (\PDOException $e) {
            }
            $this->planner = new MySqlPrivilegePlanner($this->my()->isMariaDb(), $this->my()->serverVersion(), $extra);
        }
        return $this->planner;
    }

    private function mp(): MySqlPrivilegePlanner
    {
        /** @var MySqlPrivilegePlanner $p */
        $p = $this->planner();
        return $p;
    }

    /** CURRENT_USER() split into [user, host]. */
    private function currentAccount(): array
    {
        $cur = (string) $this->db->pdo()->query('SELECT CURRENT_USER()')->fetchColumn();
        $pos = strrpos($cur, '@');
        return $pos === false ? [$cur, '%'] : [substr($cur, 0, $pos), substr($cur, $pos + 1)];
    }

    public function read(string $user, ?string $host, ?string $db = null): array
    {
        $host = $host === null || $host === '' ? '%' : $host;
        $p = $this->mp();
        $maria = $this->my()->isMariaDb();
        [$cu, $ch] = $this->currentAccount();

        $u = $this->rows('SELECT * FROM mysql.user WHERE User = ? AND Host = ?', [$user, $host]);
        $u = $u[0] ?? null;
        $isRole = false;
        $account = ['user' => $user, 'host' => $host, 'exists' => $u !== null];
        if ($u !== null) {
            $isRole = strtoupper((string) ($u['is_role'] ?? 'N')) === 'Y';
            $ssl = strtoupper((string) ($u['ssl_type'] ?? ''));
            $account += [
                'plugin' => $u['plugin'] ?? null,
                'locked' => isset($u['account_locked']) ? strtoupper((string) $u['account_locked']) === 'Y' : null,
                'password_expired' => isset($u['password_expired']) ? strtoupper((string) $u['password_expired']) === 'Y' : null,
                'password_lifetime' => isset($u['password_lifetime']) && $u['password_lifetime'] !== null ? (int) $u['password_lifetime'] : null,
                'password_last_changed' => $u['password_last_changed'] ?? null,
                'require_ssl' => $ssl === '' ? 'NONE' : ($ssl === 'ANY' ? 'SSL' : $ssl),
                'limits' => [
                    'max_queries_per_hour' => (int) ($u['max_questions'] ?? 0),
                    'max_updates_per_hour' => (int) ($u['max_updates'] ?? 0),
                    'max_connections_per_hour' => (int) ($u['max_connections'] ?? 0),
                    'max_user_connections' => (int) ($u['max_user_connections'] ?? 0),
                ],
            ];
            if ($maria) {
                $account['limits']['max_statement_time'] = isset($u['max_statement_time']) ? (float) $u['max_statement_time'] : 0.0;
                try {
                    $gp = $this->rows(
                        "SELECT JSON_VALUE(Priv, '$.account_locked') AS l, JSON_VALUE(Priv, '$.password_lifetime') AS pl,
                                JSON_VALUE(Priv, '$.password_last_changed') AS plc
                         FROM mysql.global_priv WHERE User = ? AND Host = ?",
                        [$user, $host]
                    );
                    if ($gp) {
                        $account['locked'] = in_array((string) $gp[0]['l'], ['true', '1'], true);
                        $pl = $gp[0]['pl'];
                        $account['password_lifetime'] = $pl === null || (int) $pl === -1 ? null : (int) $pl;
                        $account['password_expired'] = (int) ($gp[0]['plc'] ?? 1) === 0;
                    }
                } catch (\PDOException $e) {
                }
            }
        } elseif ($maria) {
            // MariaDB roles live in mysql.user with Host = '' (is_role = Y)
            $r = $this->rows("SELECT * FROM mysql.user WHERE User = ? AND is_role = 'Y'", [$user]);
            if ($r) {
                $isRole = true;
                $account['exists'] = true;
                $account['host'] = null;
            }
        }

        $state = [
            'driver' => 'mysql',
            'flavor' => $maria ? 'mariadb' : 'mysql',
            'version' => $this->my()->serverVersion(),
            'account' => $account + ['is_role' => $isRole],
            'self' => $cu === $user && $ch === $host,
            'current_user' => ['user' => $cu, 'host' => $ch],
            'global' => [],
            'databases' => [],
            'tables' => [],
            'columns' => [],
            'routines' => [],
            'roles' => ['granted' => [], 'available' => [], 'default' => []],
            'grants' => [],
            'catalog' => $p->catalog(),
            'descriptions' => $this->descriptions,
            'features' => $this->features(),
        ];
        if (!$account['exists']) {
            return $state;
        }

        $acctSql = $isRole && $maria ? $p->ident($user, 'role name', 128) : $p->account($user, $host);
        $grants = [];
        foreach ($this->db->pdo()->query('SHOW GRANTS FOR ' . $acctSql)->fetchAll(PDO::FETCH_NUM) as $r) {
            $grants[] = (string) $r[0];
        }
        $state['grants'] = $grants;

        $dbs = [];
        $dbPattern = [];
        $dbName = [];
        $tables = [];
        $columns = [];
        foreach ($grants as $line) {
            $g = MySqlGrantParser::parse($line);
            if ($g === null) {
                continue;
            }
            if ($g['type'] === 'roles') {
                foreach ($g['roles'] as $r) {
                    $state['roles']['granted'][] = ['name' => $r['name'], 'host' => $maria ? null : ($r['host'] ?? '%'), 'admin' => $g['admin_option']];
                }
                continue;
            }
            $on = $g['on'];
            $level = $on['level'];
            $names = [];
            foreach ($g['privileges'] as $pr) {
                $name = AbstractPrivilegePlanner::canon($pr['name']);
                if ($name === 'GRANT') {
                    $name = 'GRANT OPTION';
                }
                if ($name === 'DELETE VERSIONING ROWS') {
                    $name = 'DELETE HISTORY';
                }
                if ($pr['columns'] !== null && $level === 'table') {
                    foreach ($pr['columns'] as $c) {
                        $k = $on['db'] . "\0" . $on['table'] . "\0" . $c;
                        $columns[$k]['db'] = $on['db'];
                        $columns[$k]['table'] = $on['table'];
                        $columns[$k]['column'] = $c;
                        $columns[$k]['privileges'][] = $name;
                    }
                    continue;
                }
                if ($name === 'USAGE') {
                    continue;
                }
                if ($name === 'ALL' || $name === 'ALL PRIVILEGES') {
                    $names = array_merge($names, $p->allOf($level === 'routine' ? 'table' : $level));
                    continue;
                }
                $names[] = $name;
            }
            if ($g['grant_option']) {
                $names[] = 'GRANT OPTION';
            }
            if ($level === 'global') {
                $state['global'] = array_values(array_unique(array_merge($state['global'], $names)));
            } elseif ($level === 'database') {
                // stored form is a pattern: unescape literal names for display, keep wildcard patterns as stored
                $pat = MySqlGrantParser::hasWildcard($on['db']);
                $shown = $pat ? $on['db'] : MySqlGrantParser::unescapePattern($on['db']);
                $dk = ($pat ? 'p' : 'l') . $shown;
                $dbPattern[$dk] = $pat;
                $dbName[$dk] = $shown;
                $dbs[$dk] = array_values(array_unique(array_merge($dbs[$on['db']] ?? [], $names)));
            } elseif ($level === 'table') {
                $k = $on['db'] . "\0" . $on['table'];
                $tables[$k] = ['db' => $on['db'], 'table' => $on['table'], 'privileges' => array_values(array_unique(array_merge($tables[$k]['privileges'] ?? [], $names)))];
                if ($g['grant_option'] && !$names) {
                    $tables[$k]['privileges'][] = 'GRANT OPTION';
                }
            } elseif ($level === 'routine') {
                $routineNames = array_values(array_filter(array_map(fn ($x) => AbstractPrivilegePlanner::canon($x['name']), $g['privileges']), fn ($x) => $x !== 'USAGE'));
                $state['routines'][] = ['db' => $on['db'], 'name' => $on['name'], 'kind' => $on['routine_type'], 'privileges' => $routineNames];
            }
        }
        foreach ($dbs as $name => $privs) {
            if ($privs) {
                $state['databases'][] = ['db' => $dbName[$name], 'pattern' => $dbPattern[$name], 'privileges' => $privs];
            }
        }
        foreach ($tables as $t) {
            if ($t['privileges']) {
                $state['tables'][] = $t;
            }
        }
        foreach ($columns as $c) {
            $c['privileges'] = array_values(array_unique($c['privileges']));
            $state['columns'][] = $c;
        }

        if ($p->supportsRoles()) {
            $state['roles']['available'] = $this->availableRoles($user, $maria ? null : $host);
            $state['roles']['default'] = $this->defaultRoles($user, $host, $isRole);
            foreach ($state['roles']['granted'] as &$g) {
                $g['default'] = self::inList($state['roles']['default'], $g);
            }
            unset($g);
        }
        return $state;
    }

    private static function inList(array $list, array $ref): bool
    {
        foreach ($list as $r) {
            if ($r['name'] === $ref['name'] && ($r['host'] ?? null) === ($ref['host'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function features(): array
    {
        $p = $this->mp();
        $maria = $this->my()->isMariaDb();
        $limits = MySqlPrivilegePlanner::LIMITS;
        if ($maria) {
            $limits[] = 'max_statement_time';
        }
        return [
            'global' => true, 'databases' => true, 'tables' => true, 'columns' => true, 'schemas' => false,
            'roles' => $p->supportsRoles(), 'default_roles' => $p->supportsRoles() ? ($maria ? 'single' : 'multi') : false,
            'role_host' => !$maria,
            'limits' => $limits, 'lock' => true, 'password_expire' => true, 'require_ssl' => true,
            'attributes' => [], 'deny' => false, 'server_roles' => false, 'db_roles' => false,
            'create_role' => $p->supportsRoles(), 'rename' => true, 'clone' => true, 'host' => true,
        ];
    }

    /** Roles that can be granted: MariaDB is_role accounts; MySQL 8 role-like accounts. */
    private function availableRoles(string $user, ?string $host): array
    {
        $out = [];
        try {
            if ($this->my()->isMariaDb()) {
                foreach ($this->rows("SELECT User AS u FROM mysql.user WHERE is_role = 'Y' ORDER BY User") as $r) {
                    if ($r['u'] !== $user) {
                        $out[] = ['name' => $r['u'], 'host' => null];
                    }
                }
            } else {
                // MySQL has no role flag: accounts used as roles (role_edges) or created by
                // CREATE ROLE (locked, no password).
                foreach ($this->rows(
                    "SELECT DISTINCT User AS u, Host AS h FROM mysql.user u
                     WHERE (account_locked = 'Y' AND authentication_string = '')
                        OR EXISTS (SELECT 1 FROM mysql.role_edges e WHERE e.FROM_USER = u.User AND e.FROM_HOST = u.Host)
                     ORDER BY User, Host"
                ) as $r) {
                    if (!($r['u'] === $user && $r['h'] === $host)) {
                        $out[] = ['name' => $r['u'], 'host' => $r['h']];
                    }
                }
            }
        } catch (\PDOException $e) {
        }
        return $out;
    }

    private function defaultRoles(string $user, string $host, bool $isRole): array
    {
        try {
            if ($this->my()->isMariaDb()) {
                if ($isRole) {
                    return [];
                }
                $r = $this->rows('SELECT default_role AS d FROM mysql.user WHERE User = ? AND Host = ?', [$user, $host]);
                return isset($r[0]['d']) && $r[0]['d'] !== '' && $r[0]['d'] !== null ? [['name' => $r[0]['d'], 'host' => null]] : [];
            }
            $out = [];
            foreach ($this->rows(
                'SELECT DEFAULT_ROLE_USER AS u, DEFAULT_ROLE_HOST AS h FROM mysql.default_roles WHERE USER = ? AND HOST = ? ORDER BY 1, 2',
                [$user, $host]
            ) as $r) {
                $out[] = ['name' => $r['u'], 'host' => $r['h']];
            }
            return $out;
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function objects(string $db): array
    {
        $tables = [];
        foreach ($this->rows(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$db]
        ) as $r) {
            $tables[$r['name']] = ['name' => $r['name'], 'schema' => null, 'kind' => stripos((string) $r['type'], 'VIEW') !== false ? 'view' : 'table', 'columns' => []];
        }
        foreach ($this->rows(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$db]
        ) as $r) {
            if (isset($tables[$r['t']])) {
                $tables[$r['t']]['columns'][] = $r['c'];
            }
        }
        return ['db' => $db, 'schemas' => [], 'tables' => array_values($tables)];
    }

    public function createRole(string $name, ?string $host): array
    {
        return $this->mp()->createRole($name, $host);
    }

    public function dropRole(string $name, ?string $host): array
    {
        [$cu, $ch] = $this->currentAccount();
        if ($cu === $name && ($this->my()->isMariaDb() || $ch === ($host ?: '%'))) {
            throw ApiException::badRequest('Cannot drop the account you are logged in with');
        }
        return $this->mp()->dropRole($name, $host);
    }
}
