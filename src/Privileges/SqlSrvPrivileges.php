<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Db\SqlSrvDriver;
use Ldb\Http\ApiException;
use PDO;

/**
 * SQL Server: login (sys.server_principals), server roles and permissions; for one database at
 * a time the mapped database user, its database roles and sys.database_permissions
 * (database / schema / object / column, GRANT / GRANT_WITH_GRANT_OPTION / DENY).
 */
final class SqlSrvPrivileges extends PrivilegeManager
{
    private ?SqlSrvPrivilegePlanner $planner = null;

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
        $st->closeCursor();
        return $v === false ? null : $v;
    }

    public function planner(): AbstractPrivilegePlanner
    {
        return $this->planner ?? ($this->planner = new SqlSrvPrivilegePlanner());
    }

    private function sp(): SqlSrvPrivilegePlanner
    {
        /** @var SqlSrvPrivilegePlanner $p */
        $p = $this->planner();
        return $p;
    }

    public function read(string $user, ?string $host, ?string $db = null): array
    {
        if ($db !== null && $db !== '' && $db !== $this->db->currentDatabase()) {
            $this->db->select($db);
        }
        $currentDb = (string) $this->value('SELECT DB_NAME()');
        $self = (string) $this->value('SELECT SUSER_SNAME()');
        $login = $this->rows(
            "SELECT principal_id, type_desc, is_disabled, default_database_name, CONVERT(varchar(19), create_date, 120) AS created
             FROM sys.server_principals WHERE name = ? AND type IN ('S','U','G','E','X')",
            [$user]
        );
        $login = $login[0] ?? null;
        $state = [
            'driver' => 'sqlsrv',
            'flavor' => 'sqlsrv',
            'version' => (string) $this->value("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128))"),
            'account' => ['user' => $user, 'host' => null, 'exists' => $login !== null, 'is_role' => false],
            'self' => $self === $user,
            'current_user' => ['user' => $self, 'host' => null],
            'current_database' => $currentDb,
            'global' => [],
            'server_permissions' => [],
            'databases' => [],
            'schemas' => [],
            'tables' => [],
            'columns' => [],
            'routines' => [],
            'roles' => ['granted' => [], 'available' => [], 'default' => []],
            'db_users' => [],
            'db_roles' => [],
            'grants' => [],
            'catalog' => $this->sp()->catalog(),
            'descriptions' => [],
            'features' => [
                'global' => false, 'databases' => true, 'tables' => true, 'columns' => true, 'schemas' => true,
                'roles' => false, 'server_roles' => true, 'db_roles' => true, 'default_roles' => false, 'role_host' => false,
                'limits' => [], 'lock' => true, 'password_expire' => false, 'require_ssl' => false, 'attributes' => [],
                'deny' => true, 'create_role' => true, 'rename' => true, 'clone' => false, 'host' => false,
                'object_db' => true, 'db_user' => true,
            ],
        ];
        if ($login === null) {
            return $state;
        }
        $pid = (int) $login['principal_id'];
        $state['account'] += [
            'type' => $login['type_desc'], 'locked' => (bool) $login['is_disabled'],
            'default_database' => $login['default_database_name'], 'created' => $login['created'],
        ];
        foreach ($this->rows(
            'SELECT r.name FROM sys.server_role_members m JOIN sys.server_principals r ON r.principal_id = m.role_principal_id
             WHERE m.member_principal_id = ? ORDER BY r.name',
            [$pid]
        ) as $r) {
            $state['roles']['granted'][] = ['name' => $r['name'], 'host' => null];
        }
        foreach ($this->rows("SELECT name, is_fixed_role FROM sys.server_principals WHERE type = 'R' AND name <> 'public' ORDER BY is_fixed_role DESC, name") as $r) {
            $state['roles']['available'][] = ['name' => $r['name'], 'host' => null, 'predefined' => (bool) $r['is_fixed_role']];
        }
        foreach ($this->rows('SELECT state_desc, permission_name FROM sys.server_permissions WHERE grantee_principal_id = ? ORDER BY permission_name', [$pid]) as $p) {
            $state['server_permissions'][] = ['permission' => $p['permission_name'], 'state' => $p['state_desc']];
        }

        $dbUser = $this->rows(
            'SELECT dp.principal_id, dp.name FROM sys.database_principals dp JOIN sys.server_principals sp ON sp.sid = dp.sid WHERE sp.name = ?',
            [$user]
        );
        $dbUser = $dbUser[0] ?? null;
        $state['db_users'][$currentDb] = $dbUser !== null ? (string) $dbUser['name'] : null;
        $roles = ['granted' => [], 'available' => []];
        foreach ($this->rows("SELECT name, is_fixed_role FROM sys.database_principals WHERE type = 'R' AND name <> 'public' ORDER BY is_fixed_role DESC, name") as $r) {
            $roles['available'][] = ['name' => $r['name'], 'host' => null, 'predefined' => (bool) $r['is_fixed_role']];
        }
        if ($dbUser !== null) {
            $uid = (int) $dbUser['principal_id'];
            foreach ($this->rows(
                'SELECT r.name FROM sys.database_role_members m JOIN sys.database_principals r ON r.principal_id = m.role_principal_id
                 WHERE m.member_principal_id = ? ORDER BY r.name',
                [$uid]
            ) as $r) {
                $roles['granted'][] = ['name' => $r['name'], 'host' => null];
            }
            $acc = [];
            foreach ($this->rows(
                "SELECT p.class, p.state, p.permission_name, SCHEMA_NAME(p.major_id) AS schema_name,
                        OBJECT_SCHEMA_NAME(p.major_id) AS obj_schema, OBJECT_NAME(p.major_id) AS obj, COL_NAME(p.major_id, p.minor_id) AS col
                 FROM sys.database_permissions p WHERE p.grantee_principal_id = ? AND p.class IN (0, 1, 3)",
                [$uid]
            ) as $p) {
                $class = (int) $p['class'];
                $field = $p['state'] === 'D' ? 'denied' : 'privileges';
                if ($class === 0) {
                    $k = 'databases|' . $currentDb;
                    $acc[$k] = ($acc[$k] ?? []) + ['level' => 'databases', 'db' => $currentDb];
                } elseif ($class === 3) {
                    $k = 'schemas|' . $p['schema_name'];
                    $acc[$k] = ($acc[$k] ?? []) + ['level' => 'schemas', 'db' => $currentDb, 'schema' => $p['schema_name']];
                } elseif ($p['col'] !== null) {
                    $k = 'columns|' . $p['obj_schema'] . '|' . $p['obj'] . '|' . $p['col'];
                    $acc[$k] = ($acc[$k] ?? []) + ['level' => 'columns', 'db' => $currentDb, 'schema' => $p['obj_schema'], 'table' => $p['obj'], 'column' => $p['col']];
                } elseif ($p['obj'] !== null) {
                    $k = 'tables|' . $p['obj_schema'] . '|' . $p['obj'];
                    $acc[$k] = ($acc[$k] ?? []) + ['level' => 'tables', 'db' => $currentDb, 'schema' => $p['obj_schema'], 'table' => $p['obj']];
                } else {
                    continue;
                }
                $acc[$k][$field][] = (string) $p['permission_name'];
            }
            foreach ($acc as $row) {
                $level = $row['level'];
                unset($row['level']);
                $row += ['privileges' => [], 'denied' => []];
                $state[$level][] = $row;
            }
        }
        $state['db_roles'][$currentDb] = $roles;
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
        $schemas = array_column($this->rows(
            "SELECT name FROM sys.schemas WHERE schema_id < 16384 AND name NOT IN ('sys', 'INFORMATION_SCHEMA', 'guest') ORDER BY name"
        ), 'name');
        $tables = [];
        foreach ($this->rows(
            "SELECT TOP 5000 o.object_id, SCHEMA_NAME(o.schema_id) AS sch, o.name, o.type FROM sys.objects o
             WHERE o.type IN ('U', 'V') AND o.is_ms_shipped = 0 ORDER BY 2, 3"
        ) as $t) {
            $tables[(int) $t['object_id']] = ['schema' => $t['sch'], 'name' => $t['name'], 'kind' => trim((string) $t['type']) === 'V' ? 'view' : 'table', 'columns' => []];
        }
        foreach ($this->rows(
            "SELECT c.object_id, c.name FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id
             WHERE o.type IN ('U', 'V') AND o.is_ms_shipped = 0 ORDER BY c.object_id, c.column_id"
        ) as $c) {
            if (isset($tables[(int) $c['object_id']])) {
                $tables[(int) $c['object_id']]['columns'][] = $c['name'];
            }
        }
        return ['db' => $db, 'schemas' => $schemas, 'tables' => array_values($tables)];
    }

    public function createRole(string $name, ?string $host): array
    {
        $db = $this->db->currentDatabase();
        if ($db === null || $db === '') {
            throw ApiException::badRequest('Select a database for the new role');
        }
        return $this->sp()->createRole($name, $db);
    }

    public function dropRole(string $name, ?string $host): array
    {
        $db = $this->db->currentDatabase();
        if ($db === null || $db === '') {
            throw ApiException::badRequest('Select a database');
        }
        return $this->sp()->dropRole($name, $db);
    }
}
