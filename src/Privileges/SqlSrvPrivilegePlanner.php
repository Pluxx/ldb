<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Http\ApiException;

/**
 * SQL Server: server role membership of a login, database user / role membership and
 * GRANT / DENY / REVOKE on database, schema, table and column level (basic editing).
 * Database-level statements run in their database (`db`).
 */
final class SqlSrvPrivilegePlanner extends AbstractPrivilegePlanner
{
    private const DATABASE = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data', 'EXECUTE' => 'data',
        'CREATE TABLE' => 'structure', 'CREATE VIEW' => 'structure', 'CREATE PROCEDURE' => 'structure',
        'CREATE FUNCTION' => 'structure', 'CREATE SCHEMA' => 'structure', 'CREATE TYPE' => 'structure',
        'ALTER' => 'structure', 'REFERENCES' => 'structure', 'ALTER ANY SCHEMA' => 'structure',
        'CONNECT' => 'admin', 'CONTROL' => 'admin', 'VIEW DEFINITION' => 'admin', 'SHOWPLAN' => 'admin',
        'VIEW DATABASE STATE' => 'admin', 'BACKUP DATABASE' => 'admin', 'BACKUP LOG' => 'admin',
        'ALTER ANY USER' => 'admin', 'ALTER ANY ROLE' => 'admin', 'TAKE OWNERSHIP' => 'admin',
    ];
    private const SCHEMA = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data', 'EXECUTE' => 'data',
        'REFERENCES' => 'structure', 'ALTER' => 'structure', 'CREATE SEQUENCE' => 'structure',
        'CONTROL' => 'admin', 'VIEW DEFINITION' => 'admin', 'TAKE OWNERSHIP' => 'admin', 'VIEW CHANGE TRACKING' => 'admin',
    ];
    private const TABLE = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data',
        'REFERENCES' => 'structure', 'ALTER' => 'structure',
        'CONTROL' => 'admin', 'VIEW DEFINITION' => 'admin', 'TAKE OWNERSHIP' => 'admin', 'VIEW CHANGE TRACKING' => 'admin',
    ];
    public const COLUMN = ['SELECT', 'UPDATE', 'REFERENCES'];
    public const FIXED_SERVER_ROLES = ['sysadmin', 'serveradmin', 'securityadmin', 'processadmin', 'setupadmin', 'bulkadmin', 'diskadmin', 'dbcreator'];
    public const FIXED_DB_ROLES = ['db_owner', 'db_accessadmin', 'db_securityadmin', 'db_ddladmin', 'db_backupoperator', 'db_datareader', 'db_datawriter', 'db_denydatareader', 'db_denydatawriter'];

    public function catalog(): array
    {
        $map = static function (array $a): array {
            $out = [];
            foreach ($a as $name => $group) {
                $out[] = ['name' => $name, 'group' => $group];
            }
            return $out;
        };
        return [
            'global' => [],
            'database' => $map(self::DATABASE),
            'schema' => $map(self::SCHEMA),
            'table' => $map(self::TABLE),
            'column' => $map(array_fill_keys(self::COLUMN, 'data')),
        ];
    }

    protected function allowedKeys(): array
    {
        return ['server_roles', 'db_roles', 'databases', 'schemas', 'tables', 'columns', 'account', 'create_db_user', 'rename', 'roles'];
    }

    public function ident(string $name, string $what = 'name'): string
    {
        $this->assertName($name, $what, 128);
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    /** Database user name mapped to the login in $db (state db_users) or the login name. */
    private function dbUser(string $login, string $db, ?array $current, array $created): string
    {
        if (isset($created[$db])) {
            return $login;
        }
        $u = $current['db_users'][$db] ?? null;
        if ($current !== null && array_key_exists('db_users', $current) && array_key_exists($db, $current['db_users']) && $u === null) {
            throw ApiException::badRequest('The login has no user in database ' . $db . ' (create one first)');
        }
        return is_string($u) && $u !== '' ? $u : $login;
    }

    public function plan(string $user, ?string $host, array $changes, ?array $current = null): array
    {
        $this->checkKeys($changes);
        $login = $this->ident($user, 'login name');
        $self = !empty($current['self']);
        $st = [];
        $lockout = [];
        $cat = $this->catalog();
        $wl = static fn (string $lvl): array => array_column($cat[$lvl], 'name');

        $account = $this->section($changes, 'account');
        foreach (array_keys($account) as $k) {
            if (!in_array($k, ['lock', 'unlock'], true)) {
                throw ApiException::badRequest('Unknown account option: ' . $k);
            }
        }
        $lock = $this->optBool($account, 'lock');
        if ($this->optBool($account, 'unlock') === true) {
            $lock = false;
        }
        if ($lock !== null && ($current === null || (bool) ($current['account']['locked'] ?? !$lock) !== $lock)) {
            $st[] = self::stmt('ALTER LOGIN ' . $login . ($lock ? ' DISABLE' : ' ENABLE'));
            if ($lock && $self) {
                $lockout[] = 'lock_self';
            }
        }

        // server roles ("roles" is an alias)
        $sr = $this->section($changes, 'server_roles') + $this->section($changes, 'roles');
        $available = $current['roles']['available'] ?? null;
        $granted = $current['roles']['granted'] ?? null;
        foreach (['remove' => 'DROP', 'add' => 'ADD'] as $op => $verb) {
            foreach ((array) ($sr[$op] ?? []) as $r) {
                $ref = $this->roleRef($r, false);
                if ($op === 'add' && $available !== null && !self::hasRole($available, $ref)) {
                    throw ApiException::badRequest('Unknown server role: ' . $ref['name']);
                }
                if ($granted !== null && self::hasRole($granted, $ref) === ($op === 'add')) {
                    continue;
                }
                if ($op === 'remove' && $self && in_array($ref['name'], ['sysadmin', 'securityadmin'], true)) {
                    $lockout[] = 'revoke_admin';
                }
                $st[] = self::stmt('ALTER SERVER ROLE ' . $this->ident($ref['name'], 'role name') . ' ' . $verb . ' MEMBER ' . $login);
            }
        }

        // database users to create
        $created = [];
        $cdu = $changes['create_db_user'] ?? [];
        if (is_array($cdu) && isset($cdu['db'])) {
            $cdu = [$cdu];
        }
        foreach ($this->entries(['create_db_user' => $cdu], 'create_db_user') as $e) {
            $db = $this->nameField($e, 'db');
            if (!empty($current['db_users'][$db])) {
                continue;
            }
            $st[] = self::stmt('CREATE USER ' . $login . ' FOR LOGIN ' . $login, $db);
            $created[$db] = true;
        }

        // database roles [{db, add, remove}]
        foreach ($this->entries($changes, 'db_roles') as $e) {
            $db = $this->nameField($e, 'db');
            $u = $this->ident($this->dbUser($user, $db, $current, $created), 'user name');
            $avail = $current['db_roles'][$db]['available'] ?? null;
            $have = $current['db_roles'][$db]['granted'] ?? null;
            foreach (['remove' => 'DROP', 'add' => 'ADD'] as $op => $verb) {
                foreach ((array) ($e[$op] ?? []) as $r) {
                    $ref = $this->roleRef($r, false);
                    if ($op === 'add' && $avail !== null && !self::hasRole($avail, $ref)) {
                        throw ApiException::badRequest('Unknown database role: ' . $ref['name']);
                    }
                    if ($have !== null && self::hasRole($have, $ref) === ($op === 'add')) {
                        continue;
                    }
                    if ($op === 'remove' && $self && $ref['name'] === 'db_owner') {
                        $lockout[] = 'revoke_db_owner';
                    }
                    $st[] = self::stmt('ALTER ROLE ' . $this->ident($ref['name'], 'role name') . ' ' . $verb . ' MEMBER ' . $u, $db);
                }
            }
        }

        // permissions
        $levels = [
            'databases' => ['database', fn (array $e) => '', ['db']],
            'schemas' => ['schema', fn (array $e) => ' ON SCHEMA::' . $this->ident($this->nameField($e, 'schema'), 'schema name'), ['db', 'schema']],
            'tables' => ['table', fn (array $e) => ' ON OBJECT::' . $this->objName($e), ['db', 'schema', 'table']],
        ];
        foreach ($levels as $key => [$lvl, $on, $keys]) {
            foreach ($this->entries($changes, $key) as $e) {
                $db = $this->nameField($e, 'db');
                if ($key === 'tables' && (!isset($e['schema']) || $e['schema'] === '')) {
                    $e['schema'] = 'dbo';
                }
                $u = $this->ident($this->dbUser($user, $db, $current, $created), 'user name');
                $ar = $this->addRemove($e, $wl($lvl), $lvl, [], true);
                $match = [];
                foreach ($keys as $k) {
                    $match[$k] = $e[$k] ?? '';
                }
                $ar = self::effective($ar, self::findPrivs($current[$key] ?? null, $match), self::findPrivs($current[$key] ?? null, $match, 'denied'));
                if ($key === 'databases' && $self && (in_array('CONNECT', $ar['remove'], true) || in_array('CONNECT', $ar['deny'], true))) {
                    $lockout[] = 'revoke_connect';
                }
                $st = array_merge($st, $this->perm($ar, $on($e), $u, $db));
            }
        }

        // columns, grouped per table and state
        $byTable = [];
        foreach ($this->entries($changes, 'columns') as $e) {
            $db = $this->nameField($e, 'db');
            if (!isset($e['schema']) || $e['schema'] === '') {
                $e['schema'] = 'dbo';
            }
            $col = $this->nameField($e, 'column');
            $this->ident($col, 'column name');
            $ar = $this->addRemove($e, self::COLUMN, 'column ' . $col, [], true);
            $match = ['db' => $db, 'schema' => $e['schema'], 'table' => $e['table'] ?? '', 'column' => $col];
            $ar = self::effective($ar, self::findPrivs($current['columns'] ?? null, $match), self::findPrivs($current['columns'] ?? null, $match, 'denied'));
            $k = $db . "\0" . $this->objName($e);
            $byTable[$k]['db'] = $db;
            $byTable[$k]['on'] = $this->objName($e);
            $byTable[$k]['user'] = $this->ident($this->dbUser($user, $db, $current, $created), 'user name');
            foreach (['add', 'remove', 'deny'] as $op) {
                foreach ($ar[$op] as $p) {
                    $byTable[$k][$op][$p][] = $col;
                }
            }
        }
        foreach ($byTable as $t) {
            foreach (['remove' => ['REVOKE', ' FROM '], 'deny' => ['DENY', ' TO '], 'add' => ['GRANT', ' TO ']] as $op => [$verb, $prep]) {
                foreach (self::COLUMN as $p) {
                    if (!empty($t[$op][$p])) {
                        $cols = implode(', ', array_map(fn ($c) => $this->ident($c), array_values(array_unique($t[$op][$p]))));
                        $st[] = self::stmt($verb . ' ' . $p . ' (' . $cols . ') ON OBJECT::' . $t['on'] . $prep . $t['user'], $t['db']);
                    }
                }
            }
        }

        $rename = $this->section($changes, 'rename');
        if ($rename) {
            $new = isset($rename['user']) && is_string($rename['user']) ? $rename['user'] : '';
            if ($new !== '' && $new !== $user) {
                $st[] = self::stmt('ALTER LOGIN ' . $login . ' WITH NAME = ' . $this->ident($new, 'login name'));
                if ($self) {
                    $lockout[] = 'rename_self';
                }
            }
        }

        return ['statements' => $st, 'warnings' => [], 'self_lockout' => array_values(array_unique($lockout))];
    }

    private function objName(array $e): string
    {
        $schema = isset($e['schema']) && is_string($e['schema']) && $e['schema'] !== '' ? $e['schema'] : 'dbo';
        return $this->ident($schema, 'schema name') . '.' . $this->ident($this->nameField($e, 'table'), 'table name');
    }

    private function perm(array $ar, string $on, string $user, string $db): array
    {
        $out = [];
        if ($ar['remove']) {
            $out[] = self::stmt('REVOKE ' . implode(', ', $ar['remove']) . $on . ' FROM ' . $user, $db);
        }
        if ($ar['deny']) {
            $out[] = self::stmt('DENY ' . implode(', ', $ar['deny']) . $on . ' TO ' . $user, $db);
        }
        if ($ar['add']) {
            $out[] = self::stmt('GRANT ' . implode(', ', $ar['add']) . $on . ' TO ' . $user, $db);
        }
        return $out;
    }

    public function createRole(string $name, string $db): array
    {
        return [self::stmt('CREATE ROLE ' . $this->ident($name, 'role name'), $db)];
    }

    public function dropRole(string $name, string $db): array
    {
        return [self::stmt('DROP ROLE ' . $this->ident($name, 'role name'), $db)];
    }
}
