<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Db\PgSqlDriver;
use Ldb\Http\ApiException;
use PDO;

/**
 * PostgreSQL: role attributes (pg_roles), memberships (pg_auth_members), database ACLs (shared
 * catalog) and — for one database at a time — schema, table, sequence, column, function and
 * default privileges (aclexplode of the ACL columns, owner defaults via acldefault()).
 */
final class PgSqlPrivileges extends PrivilegeManager
{
    private ?PgSqlPrivilegePlanner $planner = null;

    private function rows(string $sql, array $params = []): array
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function value(string $sql, array $params = [])
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function planner(): AbstractPrivilegePlanner
    {
        if ($this->planner === null) {
            $this->planner = new PgSqlPrivilegePlanner((int) $this->value('SELECT current_setting(\'server_version_num\')::int'));
        }
        return $this->planner;
    }

    private function pp(): PgSqlPrivilegePlanner
    {
        /** @var PgSqlPrivilegePlanner $p */
        $p = $this->planner();
        return $p;
    }

    public function read(string $user, ?string $host, ?string $db = null): array
    {
        if ($db !== null && $db !== '' && $db !== $this->db->currentDatabase()) {
            $this->db->select($db);
        }
        $p = $this->pp();
        $current = (string) $this->value('SELECT current_user');
        $currentDb = (string) $this->value('SELECT current_database()');
        $r = $this->rows('SELECT oid, * FROM pg_roles WHERE rolname = ?', [$user]);
        $r = $r[0] ?? null;
        $state = [
            'driver' => 'pgsql',
            'flavor' => 'pgsql',
            'version' => (string) $this->value('SHOW server_version'),
            'account' => ['user' => $user, 'host' => null, 'exists' => $r !== null, 'is_role' => $r !== null && !$r['rolcanlogin']],
            'self' => $current === $user,
            'current_user' => ['user' => $current, 'host' => null],
            'current_database' => $currentDb,
            'global' => [],
            'databases' => [],
            'schemas' => [],
            'tables' => [],
            'columns' => [],
            'routines' => [],
            'default_privileges' => [],
            'roles' => ['granted' => [], 'available' => [], 'default' => [], 'members' => []],
            'grants' => [],
            'catalog' => $p->catalog(),
            'descriptions' => [],
            'features' => [
                'global' => false, 'databases' => true, 'tables' => true, 'columns' => true, 'schemas' => true,
                'roles' => true, 'default_roles' => false, 'role_host' => false, 'limits' => [], 'lock' => true,
                'password_expire' => false, 'require_ssl' => false, 'attributes' => array_merge(PgSqlPrivilegePlanner::ATTRIBUTES, ['connection_limit', 'valid_until']),
                'deny' => false, 'server_roles' => false, 'db_roles' => false, 'create_role' => true, 'rename' => true,
                'clone' => true, 'host' => false, 'object_db' => true,
            ],
        ];
        if ($r === null) {
            return $state;
        }
        $oid = (int) $r['oid'];
        $state['account'] += [
            'attributes' => [
                'superuser' => (bool) $r['rolsuper'], 'createdb' => (bool) $r['rolcreatedb'], 'createrole' => (bool) $r['rolcreaterole'],
                'login' => (bool) $r['rolcanlogin'], 'replication' => (bool) $r['rolreplication'], 'bypassrls' => (bool) $r['rolbypassrls'],
                'inherit' => (bool) $r['rolinherit'], 'connection_limit' => (int) $r['rolconnlimit'],
                'valid_until' => $r['rolvaliduntil'] === null ? null : (string) $r['rolvaliduntil'],
            ],
            'locked' => !$r['rolcanlogin'],
        ];

        foreach ($this->rows(
            'SELECT g.rolname, m.admin_option FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid WHERE m.member = ? ORDER BY g.rolname',
            [$oid]
        ) as $m) {
            $state['roles']['granted'][] = ['name' => $m['rolname'], 'host' => null, 'admin' => (bool) $m['admin_option']];
        }
        foreach ($this->rows(
            'SELECT u.rolname FROM pg_auth_members m JOIN pg_roles u ON u.oid = m.member WHERE m.roleid = ? ORDER BY u.rolname',
            [$oid]
        ) as $m) {
            $state['roles']['members'][] = $m['rolname'];
        }
        foreach ($this->rows('SELECT rolname, rolcanlogin FROM pg_roles WHERE oid <> ? ORDER BY rolname LIKE \'pg\\_%\', rolname', [$oid]) as $m) {
            $state['roles']['available'][] = ['name' => $m['rolname'], 'host' => null, 'login' => (bool) $m['rolcanlogin'], 'predefined' => strpos($m['rolname'], 'pg_') === 0];
        }

        // database privileges (shared catalog: all databases)
        $dbs = [];
        foreach ($this->rows(
            "SELECT d.datname, pg_get_userbyid(d.datdba) AS owner, d.datdba = ? AS is_owner FROM pg_database d
             WHERE NOT d.datistemplate AND d.datallowconn ORDER BY d.datname",
            [$oid]
        ) as $d) {
            $dbs[$d['datname']] = ['db' => $d['datname'], 'owner' => $d['owner'], 'is_owner' => (bool) $d['is_owner'], 'privileges' => [], 'public' => []];
        }
        foreach ($this->rows(
            "SELECT d.datname, a.privilege_type, a.grantee FROM pg_database d
             CROSS JOIN LATERAL aclexplode(COALESCE(d.datacl, acldefault('d', d.datdba))) a
             WHERE (a.grantee = ? OR a.grantee = 0) AND NOT d.datistemplate AND d.datallowconn",
            [$oid]
        ) as $a) {
            if (isset($dbs[$a['datname']])) {
                $dbs[$a['datname']][(int) $a['grantee'] === 0 ? 'public' : 'privileges'][] = $a['privilege_type'];
            }
        }
        $state['databases'] = array_values($dbs);

        // objects of the connected database
        $sysNs = "n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'";
        foreach ($this->rows(
            "SELECT n.nspname, n.nspowner = ? AS is_owner,
                    COALESCE((SELECT array_to_json(array_agg(a.privilege_type)) FROM aclexplode(COALESCE(n.nspacl, acldefault('n', n.nspowner))) a WHERE a.grantee = ?), '[]') AS privs
             FROM pg_namespace n WHERE $sysNs ORDER BY n.nspname",
            [$oid, $oid]
        ) as $s) {
            $privs = json_decode((string) $s['privs'], true) ?: [];
            if ($privs) {
                $state['schemas'][] = ['db' => $currentDb, 'schema' => $s['nspname'], 'is_owner' => (bool) $s['is_owner'], 'privileges' => array_values(array_unique($privs))];
            }
        }
        $tables = [];
        foreach ($this->rows(
            "SELECT n.nspname, c.relname, c.relkind, a.privilege_type
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault((CASE WHEN c.relkind = 'S' THEN 's' ELSE 'r' END)::\"char\", c.relowner))) a
             WHERE a.grantee = ? AND c.relkind IN ('r','p','v','m','f','S') AND $sysNs
             ORDER BY 1, 2",
            [$oid]
        ) as $t) {
            $k = $t['nspname'] . "\0" . $t['relname'];
            $tables[$k]['db'] = $currentDb;
            $tables[$k]['schema'] = $t['nspname'];
            $tables[$k]['table'] = $t['relname'];
            $tables[$k]['kind'] = $t['relkind'] === 'S' ? 'sequence' : 'table';
            $tables[$k]['privileges'][] = $t['privilege_type'];
        }
        $state['tables'] = array_values($tables);
        foreach ($this->rows(
            "SELECT n.nspname, c.relname, att.attname, array_to_json(array_agg(a.privilege_type)) AS privs
             FROM pg_attribute att JOIN pg_class c ON c.oid = att.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             CROSS JOIN LATERAL aclexplode(att.attacl) a
             WHERE att.attacl IS NOT NULL AND a.grantee = ? AND att.attnum > 0 AND NOT att.attisdropped AND $sysNs
             GROUP BY 1, 2, 3 ORDER BY 1, 2, 3",
            [$oid]
        ) as $c) {
            $state['columns'][] = ['db' => $currentDb, 'schema' => $c['nspname'], 'table' => $c['relname'], 'column' => $c['attname'], 'privileges' => json_decode((string) $c['privs'], true) ?: []];
        }
        foreach ($this->rows(
            "SELECT n.nspname, p.proname, pg_get_function_identity_arguments(p.oid) AS args, array_to_json(array_agg(a.privilege_type)) AS privs
             FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace CROSS JOIN LATERAL aclexplode(p.proacl) a
             WHERE p.proacl IS NOT NULL AND a.grantee = ? AND $sysNs GROUP BY 1, 2, 3 ORDER BY 1, 2",
            [$oid]
        ) as $f) {
            $state['routines'][] = ['db' => $currentDb, 'schema' => $f['nspname'], 'name' => $f['proname'] . '(' . $f['args'] . ')', 'kind' => 'FUNCTION', 'privileges' => json_decode((string) $f['privs'], true) ?: []];
        }
        try {
            foreach ($this->rows(
                "SELECT pg_get_userbyid(d.defaclrole) AS owner, n.nspname, d.defaclobjtype, array_to_json(array_agg(a.privilege_type)) AS privs
                 FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace CROSS JOIN LATERAL aclexplode(d.defaclacl) a
                 WHERE a.grantee = ? GROUP BY 1, 2, 3 ORDER BY 1, 2, 3",
                [$oid]
            ) as $d) {
                $types = ['r' => 'TABLES', 'S' => 'SEQUENCES', 'f' => 'FUNCTIONS', 'T' => 'TYPES', 'n' => 'SCHEMAS'];
                $state['default_privileges'][] = [
                    'db' => $currentDb, 'owner' => $d['owner'], 'schema' => $d['nspname'],
                    'object_type' => $types[$d['defaclobjtype']] ?? $d['defaclobjtype'], 'privileges' => json_decode((string) $d['privs'], true) ?: [],
                ];
            }
        } catch (\PDOException $e) {
        }
        try {
            $state['grants'] = $this->db->userGrants($user, null);
        } catch (\Throwable $e) {
        }
        return $state;
    }

    public function objects(string $db): array
    {
        if ($db !== $this->db->currentDatabase()) {
            $this->db->select($db);
        }
        $sysNs = "n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'";
        $schemas = array_column($this->rows("SELECT n.nspname FROM pg_namespace n WHERE $sysNs ORDER BY 1"), 'nspname');
        $tables = [];
        foreach ($this->rows(
            "SELECT n.nspname, c.relname, c.relkind,
                    (SELECT array_to_json(array_agg(a.attname ORDER BY a.attnum)) FROM pg_attribute a
                     WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped) AS cols
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r','p','v','m','f','S') AND $sysNs ORDER BY 1, 2 LIMIT 5000"
        ) as $t) {
            $kind = $t['relkind'] === 'S' ? 'sequence' : (in_array($t['relkind'], ['v', 'm'], true) ? 'view' : 'table');
            $tables[] = ['schema' => $t['nspname'], 'name' => $t['relname'], 'kind' => $kind, 'columns' => $kind === 'sequence' ? [] : (json_decode((string) $t['cols'], true) ?: [])];
        }
        return ['db' => $db, 'schemas' => $schemas, 'tables' => $tables];
    }

    public function createRole(string $name, ?string $host): array
    {
        return $this->pp()->createRole($name);
    }

    public function dropRole(string $name, ?string $host): array
    {
        if ($name === (string) $this->value('SELECT current_user')) {
            throw ApiException::badRequest('Cannot drop the role you are logged in with');
        }
        return $this->pp()->dropRole($name);
    }
}
