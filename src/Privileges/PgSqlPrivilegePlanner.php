<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Http\ApiException;

/**
 * PostgreSQL role attributes, memberships and object privileges. Schema / table / column /
 * sequence statements carry the database they must run in.
 */
final class PgSqlPrivilegePlanner extends AbstractPrivilegePlanner
{
    public const DATABASE = ['CONNECT' => 'admin', 'CREATE' => 'structure', 'TEMPORARY' => 'structure'];
    public const SCHEMA = ['USAGE' => 'data', 'CREATE' => 'structure'];
    private const TABLE = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data', 'TRUNCATE' => 'data',
        'REFERENCES' => 'structure', 'TRIGGER' => 'structure',
    ];
    public const SEQUENCE = ['USAGE' => 'data', 'SELECT' => 'data', 'UPDATE' => 'data'];
    public const COLUMN = ['SELECT', 'INSERT', 'UPDATE', 'REFERENCES'];
    public const ATTRIBUTES = ['superuser', 'createdb', 'createrole', 'login', 'replication', 'bypassrls', 'inherit'];
    private const ALIASES = ['TEMP' => 'TEMPORARY'];

    private int $version;

    /** @param int $version server_version_num, e.g. 170002 */
    public function __construct(int $version)
    {
        $this->version = $version;
    }

    private function table(): array
    {
        $t = self::TABLE;
        if ($this->version >= 170000) {
            $t['MAINTAIN'] = 'structure';
        }
        return $t;
    }

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
            'table' => $map($this->table()),
            'sequence' => $map(self::SEQUENCE),
            'column' => $map(array_fill_keys(self::COLUMN, 'data')),
        ];
    }

    protected function allowedKeys(): array
    {
        return ['attributes', 'databases', 'schemas', 'tables', 'columns', 'roles', 'rename', 'account'];
    }

    public function ident(string $name, string $what = 'name'): string
    {
        $this->assertName($name, $what, 63);
        if (strlen($name) > 63) {
            throw ApiException::badRequest('Invalid ' . $what . ' (longer than 63 bytes)');
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function rel(array $e): array
    {
        $schema = isset($e['schema']) && is_string($e['schema']) && $e['schema'] !== '' ? $e['schema'] : 'public';
        $table = $this->nameField($e, 'table', 63);
        return [$schema, $table, $this->ident($schema, 'schema name') . '.' . $this->ident($table, 'table name')];
    }

    public function plan(string $user, ?string $host, array $changes, ?array $current = null): array
    {
        $this->checkKeys($changes);
        $role = $this->ident($user, 'role name');
        $self = !empty($current['self']);
        $st = [];
        $lockout = [];
        $warnings = [];
        $cat = $this->catalog();
        $wl = static fn (string $lvl): array => array_column($cat[$lvl], 'name');

        // attributes → one ALTER ROLE
        $attrs = $this->section($changes, 'attributes');
        $account = $this->section($changes, 'account');
        foreach (array_keys($account) as $k) {
            if (!in_array($k, ['lock', 'unlock'], true)) {
                throw ApiException::badRequest('Unknown account option: ' . $k);
            }
        }
        // lock/unlock = NOLOGIN/LOGIN
        if ($this->optBool($account, 'lock') === true) {
            $attrs['login'] = false;
        } elseif ($this->optBool($account, 'unlock') === true) {
            $attrs['login'] = true;
        }
        $curAttr = $current['account']['attributes'] ?? null;
        $opts = [];
        foreach ($attrs as $k => $v) {
            if (in_array($k, self::ATTRIBUTES, true)) {
                $b = $this->optBool($attrs, $k);
                if ($b === null || ($curAttr !== null && array_key_exists($k, $curAttr) && (bool) $curAttr[$k] === $b)) {
                    continue;
                }
                $opts[] = ($b ? '' : 'NO') . strtoupper($k);
                if ($self && !$b && in_array($k, ['superuser', 'createrole', 'login'], true)) {
                    $lockout[] = 'revoke_' . $k;
                }
            } elseif ($k === 'connection_limit') {
                $n = $this->optInt($attrs, $k, -1, 2147483647);
                if ($n === null || ($curAttr !== null && (int) ($curAttr['connection_limit'] ?? -2) === $n)) {
                    continue;
                }
                $opts[] = 'CONNECTION LIMIT ' . $n;
                if ($self && $n >= 0 && $n < 2) {
                    $lockout[] = 'limit_self';
                }
            } elseif ($k === 'valid_until') {
                $v = $v === null || $v === '' ? 'infinity' : $v;
                if (!is_string($v) || !preg_match('/^(infinity|\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?( ?(Z|[+-]\d{2}(:?\d{2})?))?)$/i', $v)) {
                    throw ApiException::badRequest('valid_until must be a date/time (YYYY-MM-DD[ HH:MM[:SS]][±TZ]) or empty');
                }
                $curV = $curAttr['valid_until'] ?? null;
                if ($curAttr !== null && (($curV === null && strtolower($v) === 'infinity') || (string) $curV === $v)) {
                    continue;
                }
                // validated above: digits, '-', ':', '.', ' ', 'T', 'Z', '+' only → a plain literal is safe
                $opts[] = "VALID UNTIL '" . $v . "'";
                if ($self && strtolower($v) !== 'infinity') {
                    $lockout[] = 'expire_self';
                }
            } else {
                throw ApiException::badRequest('Unknown role attribute: ' . $k);
            }
        }
        if ($opts) {
            $st[] = self::stmt('ALTER ROLE ' . $role . ' WITH ' . implode(' ', $opts));
        }

        // memberships
        $roles = $this->section($changes, 'roles');
        foreach (array_keys($roles) as $k) {
            if (!in_array($k, ['add', 'remove'], true)) {
                throw ApiException::badRequest('Unknown roles option: ' . $k);
            }
        }
        $available = $current['roles']['available'] ?? null;
        $granted = $current['roles']['granted'] ?? null;
        $add = [];
        $remove = [];
        foreach ((array) ($roles['add'] ?? []) as $r) {
            $ref = $this->roleRef($r, false);
            if ($ref['name'] === $user) {
                throw ApiException::badRequest('A role cannot be a member of itself');
            }
            if ($available !== null && !self::hasRole($available, $ref)) {
                throw ApiException::badRequest('Unknown role: ' . $ref['name']);
            }
            if ($granted === null || !self::hasRole($granted, $ref)) {
                $add[] = $this->ident($ref['name'], 'role name');
            }
        }
        foreach ((array) ($roles['remove'] ?? []) as $r) {
            $ref = $this->roleRef($r, false);
            if ($granted === null || self::hasRole($granted, $ref)) {
                $remove[] = $this->ident($ref['name'], 'role name');
            }
        }
        if ($remove) {
            $st[] = self::stmt('REVOKE ' . implode(', ', array_unique($remove)) . ' FROM ' . $role);
        }
        if ($add) {
            $st[] = self::stmt('GRANT ' . implode(', ', array_unique($add)) . ' TO ' . $role);
        }

        // databases (cluster-wide catalog, run anywhere)
        foreach ($this->entries($changes, 'databases') as $e) {
            $db = $this->nameField($e, 'db', 63);
            $ar = $this->addRemove($e, $wl('database'), 'database ' . $db, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['databases'] ?? null, ['db' => $db]));
            if ($self && in_array('CONNECT', $ar['remove'], true)) {
                $lockout[] = 'revoke_connect';
            }
            $st = array_merge($st, $this->grantRevoke('DATABASE ' . $this->ident($db, 'database name'), $ar, $role, null));
        }

        // schemas
        foreach ($this->entries($changes, 'schemas') as $e) {
            $db = $this->nameField($e, 'db', 63);
            $schema = $this->nameField($e, 'schema', 63);
            $ar = $this->addRemove($e, $wl('schema'), 'schema ' . $schema, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['schemas'] ?? null, ['db' => $db, 'schema' => $schema]));
            if ($self && in_array('USAGE', $ar['remove'], true)) {
                $lockout[] = 'revoke_usage';
            }
            $st = array_merge($st, $this->grantRevoke('SCHEMA ' . $this->ident($schema, 'schema name'), $ar, $role, $db));
        }

        // tables / sequences
        foreach ($this->entries($changes, 'tables') as $e) {
            $db = $this->nameField($e, 'db', 63);
            [$schema, $table, $q] = $this->rel($e);
            $isSeq = ($e['kind'] ?? 'table') === 'sequence';
            $ar = $this->addRemove($e, $wl($isSeq ? 'sequence' : 'table'), ($isSeq ? 'sequence ' : 'table ') . $table, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['tables'] ?? null, ['db' => $db, 'schema' => $schema, 'table' => $table]));
            $st = array_merge($st, $this->grantRevoke(($isSeq ? 'SEQUENCE ' : 'TABLE ') . $q, $ar, $role, $db));
        }

        // columns grouped per table
        $byTable = [];
        foreach ($this->entries($changes, 'columns') as $e) {
            $db = $this->nameField($e, 'db', 63);
            [$schema, $table, $q] = $this->rel($e);
            $col = $this->nameField($e, 'column', 63);
            $this->ident($col, 'column name');
            $ar = $this->addRemove($e, self::COLUMN, 'column ' . $col, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['columns'] ?? null, ['db' => $db, 'schema' => $schema, 'table' => $table, 'column' => $col]));
            $key = $db . "\0" . $q;
            $byTable[$key]['db'] = $db;
            $byTable[$key]['on'] = $q;
            foreach (['add', 'remove'] as $op) {
                foreach ($ar[$op] as $p) {
                    $byTable[$key][$op][$p][] = $col;
                }
            }
        }
        foreach ($byTable as $t) {
            foreach (['remove' => ['REVOKE', ' FROM '], 'add' => ['GRANT', ' TO ']] as $op => [$verb, $prep]) {
                if (empty($t[$op])) {
                    continue;
                }
                $list = [];
                foreach (self::COLUMN as $p) {
                    if (!empty($t[$op][$p])) {
                        $list[] = $p . ' (' . implode(', ', array_map(fn ($c) => $this->ident($c), array_values(array_unique($t[$op][$p])))) . ')';
                    }
                }
                $st[] = self::stmt($verb . ' ' . implode(', ', $list) . ' ON TABLE ' . $t['on'] . $prep . $role, $t['db']);
            }
        }

        // rename
        $rename = $this->section($changes, 'rename');
        if ($rename) {
            $new = isset($rename['user']) && is_string($rename['user']) ? $rename['user'] : '';
            if ($new !== '' && $new !== $user) {
                $st[] = self::stmt('ALTER ROLE ' . $role . ' RENAME TO ' . $this->ident($new, 'role name'));
                $warnings[] = 'pg_rename_md5';
                if ($self) {
                    $lockout[] = 'rename_self';
                }
            }
        }

        return ['statements' => $st, 'warnings' => $warnings, 'self_lockout' => array_values(array_unique($lockout))];
    }

    private function grantRevoke(string $on, array $ar, string $role, ?string $db): array
    {
        $out = [];
        if ($ar['remove']) {
            $out[] = self::stmt('REVOKE ' . implode(', ', $ar['remove']) . ' ON ' . $on . ' FROM ' . $role, $db);
        }
        if ($ar['add']) {
            $out[] = self::stmt('GRANT ' . implode(', ', $ar['add']) . ' ON ' . $on . ' TO ' . $role, $db);
        }
        return $out;
    }

    public function createRole(string $name, bool $login = false): array
    {
        return [self::stmt('CREATE ROLE ' . $this->ident($name, 'role name') . ($login ? ' WITH LOGIN' : ''))];
    }

    public function dropRole(string $name): array
    {
        return [self::stmt('DROP ROLE ' . $this->ident($name, 'role name'))];
    }
}
