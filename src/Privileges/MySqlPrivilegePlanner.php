<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Http\ApiException;

/**
 * MySQL / MariaDB / Percona privilege statements. Account and role names are quoted as
 * identifiers (`user`@`host`), which does not depend on NO_BACKSLASH_ESCAPES; the empty
 * (anonymous) user name is written as ''.
 */
final class MySqlPrivilegePlanner extends AbstractPrivilegePlanner
{
    /** Static global privileges (MySQL 5.7/8.0), group data|structure|admin (phpMyAdmin grouping). */
    private const GLOBAL_MYSQL = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data', 'FILE' => 'data',
        'CREATE' => 'structure', 'ALTER' => 'structure', 'INDEX' => 'structure', 'DROP' => 'structure',
        'CREATE TEMPORARY TABLES' => 'structure', 'SHOW VIEW' => 'structure', 'CREATE ROUTINE' => 'structure',
        'ALTER ROUTINE' => 'structure', 'EXECUTE' => 'structure', 'CREATE VIEW' => 'structure',
        'EVENT' => 'structure', 'TRIGGER' => 'structure', 'CREATE TABLESPACE' => 'structure',
        'GRANT OPTION' => 'admin', 'SUPER' => 'admin', 'PROCESS' => 'admin', 'RELOAD' => 'admin',
        'SHUTDOWN' => 'admin', 'SHOW DATABASES' => 'admin', 'LOCK TABLES' => 'admin', 'REFERENCES' => 'admin',
        'REPLICATION CLIENT' => 'admin', 'REPLICATION SLAVE' => 'admin', 'CREATE USER' => 'admin',
    ];
    /** MySQL 8.0 additions (static) */
    private const GLOBAL_MYSQL8 = ['CREATE ROLE' => 'admin', 'DROP ROLE' => 'admin'];
    /** Common MySQL 8 dynamic privileges (more are taken from SHOW PRIVILEGES at runtime). */
    private const DYNAMIC_MYSQL8 = [
        'APPLICATION_PASSWORD_ADMIN', 'AUDIT_ADMIN', 'BACKUP_ADMIN', 'BINLOG_ADMIN', 'BINLOG_ENCRYPTION_ADMIN',
        'CLONE_ADMIN', 'CONNECTION_ADMIN', 'ENCRYPTION_KEY_ADMIN', 'FLUSH_OPTIMIZER_COSTS', 'FLUSH_STATUS',
        'FLUSH_TABLES', 'FLUSH_USER_RESOURCES', 'GROUP_REPLICATION_ADMIN', 'INNODB_REDO_LOG_ARCHIVE',
        'PERSIST_RO_VARIABLES_ADMIN', 'REPLICATION_APPLIER', 'REPLICATION_SLAVE_ADMIN', 'RESOURCE_GROUP_ADMIN',
        'RESOURCE_GROUP_USER', 'ROLE_ADMIN', 'SERVICE_CONNECTION_ADMIN', 'SESSION_VARIABLES_ADMIN', 'SET_USER_ID',
        'SHOW_ROUTINE', 'SYSTEM_USER', 'SYSTEM_VARIABLES_ADMIN', 'TABLE_ENCRYPTION_ADMIN', 'XA_RECOVER_ADMIN',
    ];
    /** MariaDB 10.5+ additions */
    private const GLOBAL_MARIADB = [
        'DELETE HISTORY' => 'data',
        'BINLOG ADMIN' => 'admin', 'BINLOG MONITOR' => 'admin', 'BINLOG REPLAY' => 'admin',
        'CONNECTION ADMIN' => 'admin', 'FEDERATED ADMIN' => 'admin', 'READ_ONLY ADMIN' => 'admin',
        'REPLICATION MASTER ADMIN' => 'admin', 'REPLICATION SLAVE ADMIN' => 'admin', 'SET USER' => 'admin',
        'SLAVE MONITOR' => 'admin',
    ];
    private const DATABASE = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data',
        'CREATE' => 'structure', 'ALTER' => 'structure', 'INDEX' => 'structure', 'DROP' => 'structure',
        'CREATE TEMPORARY TABLES' => 'structure', 'SHOW VIEW' => 'structure', 'CREATE ROUTINE' => 'structure',
        'ALTER ROUTINE' => 'structure', 'EXECUTE' => 'structure', 'CREATE VIEW' => 'structure',
        'EVENT' => 'structure', 'TRIGGER' => 'structure',
        'GRANT OPTION' => 'admin', 'LOCK TABLES' => 'admin', 'REFERENCES' => 'admin',
    ];
    private const TABLE = [
        'SELECT' => 'data', 'INSERT' => 'data', 'UPDATE' => 'data', 'DELETE' => 'data',
        'CREATE' => 'structure', 'ALTER' => 'structure', 'INDEX' => 'structure', 'DROP' => 'structure',
        'SHOW VIEW' => 'structure', 'CREATE VIEW' => 'structure', 'TRIGGER' => 'structure',
        'GRANT OPTION' => 'admin', 'REFERENCES' => 'admin',
    ];
    public const COLUMN = ['SELECT', 'INSERT', 'UPDATE', 'REFERENCES'];
    private const ALIASES = ['GRANT' => 'GRANT OPTION', 'REPLICATION REPLICA' => 'REPLICATION SLAVE', 'SLAVE MONITOR' => 'SLAVE MONITOR', 'REPLICA MONITOR' => 'SLAVE MONITOR', 'DELETE VERSIONING ROWS' => 'DELETE HISTORY'];
    /** Revoking any of these from the login account itself needs confirm_self. */
    private const CRITICAL = ['SUPER', 'CREATE USER', 'GRANT OPTION', 'SYSTEM_USER', 'ROLE_ADMIN', 'CONNECTION_ADMIN', 'CONNECTION ADMIN', 'SET USER', 'SET_USER_ID'];
    public const LIMITS = ['max_queries_per_hour', 'max_updates_per_hour', 'max_connections_per_hour', 'max_user_connections'];

    private bool $mariaDb;
    private string $version;
    /** @var array<string,string> name => group */
    private array $global;

    /**
     * @param string[] $extraGlobal further global privilege names reported by SHOW PRIVILEGES
     */
    public function __construct(bool $mariaDb, string $version, array $extraGlobal = [])
    {
        $this->mariaDb = $mariaDb;
        $this->version = $version;
        $g = self::GLOBAL_MYSQL;
        if ($mariaDb) {
            $g += self::GLOBAL_MARIADB;
        } elseif ($this->atLeast('8.0.0')) {
            $g += self::GLOBAL_MYSQL8;
            foreach (self::DYNAMIC_MYSQL8 as $p) {
                $g[$p] = 'admin';
            }
        }
        foreach ($extraGlobal as $p) {
            $p = self::canon((string) $p);
            if (preg_match('/^[A-Z][A-Z_]*( [A-Z_]+)*$/', $p) && !in_array($p, ['USAGE', 'PROXY', 'ALL', 'ALL PRIVILEGES'], true) && !isset($g[$p])) {
                $g[$p] = 'admin';
            }
        }
        $this->global = $g;
    }

    private function atLeast(string $v): bool
    {
        $num = preg_match('/^(\d+\.\d+\.\d+)/', $this->version, $m) ? $m[1] : '0.0.0';
        return version_compare($num, $v, '>=');
    }

    public function supportsRoles(): bool
    {
        return $this->mariaDb ? $this->atLeast('10.0.5') : $this->atLeast('8.0.0');
    }

    /** MariaDB roles have no host part; MySQL 8 roles are `name`@`host`. */
    public function rolesHaveHost(): bool
    {
        return !$this->mariaDb;
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
        $db = self::DATABASE;
        $tb = self::TABLE;
        if ($this->mariaDb) {
            $db['DELETE HISTORY'] = 'data';
            $tb['DELETE HISTORY'] = 'data';
        }
        return [
            'global' => $map($this->global),
            'database' => $map($db),
            'table' => $map($tb),
            'column' => $map(array_fill_keys(self::COLUMN, 'data')),
        ];
    }

    /** All privileges of a level that ALL PRIVILEGES stands for (GRANT OPTION excluded). */
    public function allOf(string $level): array
    {
        $c = $this->catalog();
        $names = array_column($c[$level] ?? [], 'name');
        if ($level === 'global') {
            // ALL on *.* means the static privileges only (dynamic ones are listed separately)
            $static = array_keys(self::GLOBAL_MYSQL + ($this->mariaDb ? self::GLOBAL_MARIADB : ($this->atLeast('8.0.0') ? self::GLOBAL_MYSQL8 : [])));
            $names = array_values(array_intersect($names, $static));
        }
        return array_values(array_filter($names, static fn ($n) => $n !== 'GRANT OPTION'));
    }

    protected function allowedKeys(): array
    {
        return ['global', 'databases', 'tables', 'columns', 'roles', 'limits', 'account', 'rename'];
    }

    // ------------------------------------------------------------------ quoting

    public function ident(string $name, string $what = 'name', int $max = 64): string
    {
        $this->assertName($name, $what, $max);
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function account(string $user, ?string $host): string
    {
        $this->assertName($user, 'user name', 128, true);
        $host = $host === null || $host === '' ? '%' : $host;
        $this->assertName($host, 'host', 255);
        return ($user === '' ? "''" : '`' . str_replace('`', '``', $user) . '`') . '@`' . str_replace('`', '``', $host) . '`';
    }

    public function role(array $ref): string
    {
        if ($this->mariaDb) {
            return $this->ident($ref['name'], 'role name', 128);
        }
        return $this->account($ref['name'], $ref['host'] ?? '%');
    }

    /**
     * `db`.* — a database-level grant target is a pattern (`_` and `%` are wildcards), so a literal
     * name is escaped; an existing wildcard pattern ($pattern) is used exactly as stored.
     */
    private function onDb(string $db, bool $pattern = false): string
    {
        $this->assertName($db, 'database name', 64);
        return $this->ident($pattern ? $db : MySqlGrantParser::escapePattern($db), 'database name', 192) . '.*';
    }

    private function onTable(string $db, string $table): string
    {
        return $this->ident($db, 'database name') . '.' . $this->ident($table, 'table name');
    }

    // ------------------------------------------------------------------ plan

    public function plan(string $user, ?string $host, array $changes, ?array $current = null): array
    {
        $this->checkKeys($changes);
        // MariaDB roles are plain names (no host part)
        $acct = $this->mariaDb && !empty($current['account']['is_role'])
            ? $this->ident($user, 'role name', 128)
            : $this->account($user, $host);
        $self = !empty($current['self']);
        $st = [];
        $warnings = [];
        $lockout = [];
        $cat = $this->catalog();
        $wl = static fn (string $lvl): array => array_column($cat[$lvl], 'name');

        // account options: one ALTER USER (REQUIRE, WITH limits, lock, password expiry)
        $parts = [];
        $account = $this->section($changes, 'account');
        foreach (array_keys($account) as $k) {
            if (!in_array($k, ['lock', 'unlock', 'password_expire', 'require_ssl'], true)) {
                throw ApiException::badRequest('Unknown account option: ' . $k);
            }
        }
        if (array_key_exists('require_ssl', $account) && $account['require_ssl'] !== null) {
            $r = $account['require_ssl'];
            $r = $r === true ? 'SSL' : ($r === false ? 'NONE' : strtoupper((string) $r));
            if (!in_array($r, ['NONE', 'SSL', 'X509'], true)) {
                throw ApiException::badRequest('require_ssl must be NONE, SSL or X509');
            }
            if ($current === null || strtoupper((string) ($current['account']['require_ssl'] ?? '')) !== $r) {
                $parts[] = 'REQUIRE ' . $r;
                if ($self && $r !== 'NONE') {
                    $lockout[] = 'require_ssl_self';
                }
            }
        }
        $limits = $this->section($changes, 'limits');
        $with = [];
        foreach ($limits as $k => $v) {
            if ($k === 'max_statement_time' && $this->mariaDb) {
                if ($v === null || $v === '') {
                    continue;
                }
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > 31536000) {
                    throw ApiException::badRequest('Invalid value for max_statement_time');
                }
                $num = rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.');
                if ($current === null || (float) ($current['account']['limits'][$k] ?? -1) !== (float) $v) {
                    $with[] = 'MAX_STATEMENT_TIME ' . ($num === '' ? '0' : $num);
                }
                continue;
            }
            if (!in_array($k, self::LIMITS, true)) {
                throw ApiException::badRequest('Unknown resource limit: ' . $k);
            }
            $n = $this->optInt($limits, $k, 0, 2147483647);
            if ($n === null) {
                continue;
            }
            if ($current === null || (int) ($current['account']['limits'][$k] ?? -1) !== $n) {
                $with[] = strtoupper($k) . ' ' . $n;
            }
        }
        if ($with) {
            $parts[] = 'WITH ' . implode(' ', $with);
        }
        $lock = $this->optBool($account, 'lock');
        if ($this->optBool($account, 'unlock') === true) {
            if ($lock === true) {
                throw ApiException::badRequest('lock and unlock at once');
            }
            $lock = false;
        }
        if ($lock !== null && ($current === null || (bool) ($current['account']['locked'] ?? !$lock) !== $lock)) {
            $parts[] = $lock ? 'ACCOUNT LOCK' : 'ACCOUNT UNLOCK';
            if ($lock && $self) {
                $lockout[] = 'lock_self';
            }
        }
        if (array_key_exists('password_expire', $account) && $account['password_expire'] !== null && $account['password_expire'] !== false) {
            $pe = $account['password_expire'];
            if ($pe === true || $pe === 'now') {
                $parts[] = 'PASSWORD EXPIRE';
                if ($self) {
                    $lockout[] = 'expire_self';
                }
            } elseif ($pe === 'never') {
                $parts[] = 'PASSWORD EXPIRE NEVER';
            } elseif ($pe === 'default') {
                $parts[] = 'PASSWORD EXPIRE DEFAULT';
            } elseif ((is_int($pe) || (is_string($pe) && ctype_digit($pe))) && (int) $pe >= 1 && (int) $pe <= 65535) {
                $parts[] = 'PASSWORD EXPIRE INTERVAL ' . (int) $pe . ' DAY';
            } else {
                throw ApiException::badRequest('Invalid password_expire');
            }
        }
        if ($parts) {
            $st[] = self::stmt('ALTER USER ' . $acct . ' ' . implode(' ', $parts));
        }

        // roles
        $roles = $this->section($changes, 'roles');
        foreach (array_keys($roles) as $k) {
            if (!in_array($k, ['add', 'remove', 'default'], true)) {
                throw ApiException::badRequest('Unknown roles option: ' . $k);
            }
        }
        $roleAdd = [];
        $roleRemove = [];
        if ($roles) {
            if (!$this->supportsRoles()) {
                throw ApiException::unsupported('Roles need MySQL 8.0 or MariaDB 10.0.5');
            }
            $available = $current['roles']['available'] ?? null;
            $granted = $current['roles']['granted'] ?? null;
            foreach ((array) ($roles['add'] ?? []) as $r) {
                $ref = $this->roleRef($r, $this->rolesHaveHost());
                if ($available !== null && !self::hasRole($available, $ref)) {
                    throw ApiException::badRequest('Unknown role: ' . $ref['name']);
                }
                if ($granted === null || !self::hasRole($granted, $ref)) {
                    $roleAdd[] = $ref;
                }
            }
            foreach ((array) ($roles['remove'] ?? []) as $r) {
                $ref = $this->roleRef($r, $this->rolesHaveHost());
                if ($granted === null || self::hasRole($granted, $ref)) {
                    $roleRemove[] = $ref;
                }
            }
        }
        if ($roleRemove) {
            if ($this->mariaDb) {
                foreach ($roleRemove as $r) {
                    $st[] = self::stmt('REVOKE ' . $this->role($r) . ' FROM ' . $acct);
                }
            } else {
                $st[] = self::stmt('REVOKE ' . implode(', ', array_map([$this, 'role'], $roleRemove)) . ' FROM ' . $acct);
            }
        }
        if ($roleAdd) {
            if ($this->mariaDb) {
                foreach ($roleAdd as $r) {
                    $st[] = self::stmt('GRANT ' . $this->role($r) . ' TO ' . $acct);
                }
            } else {
                $st[] = self::stmt('GRANT ' . implode(', ', array_map([$this, 'role'], $roleAdd)) . ' TO ' . $acct);
            }
        }

        // global
        $g = $this->addRemove($this->section($changes, 'global'), $wl('global'), 'global', self::ALIASES);
        $cur = $current !== null ? array_map([self::class, 'canon'], (array) ($current['global'] ?? [])) : null;
        $g = self::effective($g, $cur);
        if ($self && $g['remove']) {
            if (array_intersect($g['remove'], self::CRITICAL)) {
                $lockout[] = 'revoke_admin';
            }
            if ($cur !== null && $cur !== [] && !array_diff($cur, $g['remove'])) {
                $lockout[] = 'revoke_all';
            }
        }
        $st = array_merge($st, $this->grantRevoke('*.*', $g, $acct));

        // databases
        foreach ($this->entries($changes, 'databases') as $e) {
            $db = $this->nameField($e, 'db', 64);
            $pat = !empty($e['pattern']);
            $ar = $this->addRemove($e, $wl('database'), 'database ' . $db, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['databases'] ?? null, ['db' => $db, 'pattern' => $pat]));
            if ($self && $ar['remove'] && $current !== null) {
                $now = self::findPrivs($current['databases'] ?? null, ['db' => $db, 'pattern' => $pat]) ?? [];
                if ($now && !array_diff($now, $ar['remove'])) {
                    $lockout[] = 'revoke_database';
                }
            }
            $st = array_merge($st, $this->grantRevoke($this->onDb($db, $pat), $ar, $acct));
        }

        // tables
        foreach ($this->entries($changes, 'tables') as $e) {
            $db = $this->nameField($e, 'db', 64);
            $table = $this->nameField($e, 'table', 64);
            $ar = $this->addRemove($e, $wl('table'), 'table ' . $table, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['tables'] ?? null, ['db' => $db, 'table' => $table]));
            $st = array_merge($st, $this->grantRevoke($this->onTable($db, $table), $ar, $acct));
        }

        // columns: one GRANT / REVOKE per table listing priv (col, …)
        $byTable = [];
        foreach ($this->entries($changes, 'columns') as $e) {
            $db = $this->nameField($e, 'db', 64);
            $table = $this->nameField($e, 'table', 64);
            $col = $this->nameField($e, 'column', 64);
            $ar = $this->addRemove($e, self::COLUMN, 'column ' . $col, self::ALIASES);
            $ar = self::effective($ar, self::findPrivs($current['columns'] ?? null, ['db' => $db, 'table' => $table, 'column' => $col]));
            $key = $db . "\0" . $table;
            $byTable[$key]['on'] = $this->onTable($db, $table);
            $this->ident($col, 'column name');
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
                $st[] = self::stmt($verb . ' ' . implode(', ', $list) . ' ON ' . $t['on'] . $prep . $acct);
            }
        }

        // default roles (after role grants)
        if (array_key_exists('default', $roles) && $roles['default'] !== null) {
            if (!is_array($roles['default'])) {
                throw ApiException::badRequest('roles.default must be a list');
            }
            $defs = array_map(fn ($r) => $this->roleRef($r, $this->rolesHaveHost()), $roles['default']);
            if ($this->mariaDb && count($defs) > 1) {
                throw ApiException::badRequest('MariaDB supports only one default role');
            }
            $currentDefaults = $current['roles']['default'] ?? null;
            $same = $currentDefaults !== null && count($currentDefaults) === count($defs);
            if ($same) {
                foreach ($defs as $d) {
                    if (!self::hasRole($currentDefaults, $d)) {
                        $same = false;
                    }
                }
            }
            if (!$same) {
                $list = $defs ? implode(', ', array_map([$this, 'role'], $defs)) : 'NONE';
                $st[] = self::stmt('SET DEFAULT ROLE ' . $list . ($this->mariaDb ? ' FOR ' : ' TO ') . $acct);
            }
        }

        // rename (last)
        $rename = $this->section($changes, 'rename');
        if ($rename) {
            $newUser = isset($rename['user']) && is_string($rename['user']) ? $rename['user'] : $user;
            $newHost = isset($rename['host']) && is_string($rename['host']) && $rename['host'] !== '' ? $rename['host'] : ($host ?? '%');
            $to = $this->account($newUser, $newHost);
            if ($to !== $acct) {
                $st[] = self::stmt('RENAME USER ' . $acct . ' TO ' . $to);
                if ($self) {
                    $lockout[] = 'rename_self';
                }
            }
        }

        return ['statements' => $st, 'warnings' => $warnings, 'self_lockout' => array_values(array_unique($lockout))];
    }

    /** REVOKE then GRANT on one object; GRANT OPTION becomes WITH GRANT OPTION. */
    private function grantRevoke(string $on, array $ar, string $acct): array
    {
        $out = [];
        if ($ar['remove']) {
            $out[] = self::stmt('REVOKE ' . implode(', ', $ar['remove']) . ' ON ' . $on . ' FROM ' . $acct);
        }
        if ($ar['add']) {
            $go = in_array('GRANT OPTION', $ar['add'], true);
            $privs = array_values(array_diff($ar['add'], ['GRANT OPTION']));
            $out[] = self::stmt('GRANT ' . ($privs ? implode(', ', $privs) : 'USAGE') . ' ON ' . $on . ' TO ' . $acct . ($go ? ' WITH GRANT OPTION' : ''));
        }
        return $out;
    }

    public function createRole(string $name, ?string $host): array
    {
        if (!$this->supportsRoles()) {
            throw ApiException::unsupported('Roles need MySQL 8.0 or MariaDB 10.0.5');
        }
        return [self::stmt('CREATE ROLE ' . $this->role(['name' => $name, 'host' => $host ?: '%']))];
    }

    public function dropRole(string $name, ?string $host): array
    {
        if (!$this->supportsRoles()) {
            throw ApiException::unsupported('Roles need MySQL 8.0 or MariaDB 10.0.5');
        }
        return [self::stmt('DROP ROLE ' . $this->role(['name' => $name, 'host' => $host ?: '%']))];
    }
}
