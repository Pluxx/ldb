<?php

declare(strict_types=1);

namespace Ldb\Privileges;

use Ldb\Http\ApiException;

/**
 * Pure SQL generation for the privileges editor (no database connection): validates a
 * PrivilegeChanges spec (ARCHITECTURE.md) against per-driver privilege whitelists and turns it
 * into the minimal list of GRANT / REVOKE / ALTER statements. When the current state of the
 * account is passed, no-op changes (granting what is already granted, revoking what is not) are
 * dropped and role names are checked against the server's roles.
 *
 * Every statement is {sql, db}: `db` is the database the statement must run in (PostgreSQL /
 * SQL Server object privileges), null when it can run anywhere.
 */
abstract class AbstractPrivilegePlanner
{
    /** Keys of a PrivilegeChanges spec this driver understands (others → bad_request). */
    abstract protected function allowedKeys(): array;

    /**
     * @param array $changes PrivilegeChanges
     * @param array|null $current PrivilegeState of the account (filtering, roles, self check)
     * @return array{statements:array<int,array{sql:string,db:?string}>,warnings:string[],self_lockout:string[]}
     */
    abstract public function plan(string $user, ?string $host, array $changes, ?array $current = null): array;

    /** Privilege catalog for the UI: level => [{name, group}]. */
    abstract public function catalog(): array;

    // ------------------------------------------------------------------ validation helpers

    protected function checkKeys(array $changes): void
    {
        $allowed = $this->allowedKeys();
        foreach (array_keys($changes) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw ApiException::badRequest('Unknown privilege change: ' . $k);
            }
        }
    }

    /** Canonical privilege name: trimmed, upper case, single spaces. */
    public static function canon(string $p): string
    {
        return strtoupper((string) preg_replace('/\s+/', ' ', trim($p)));
    }

    /**
     * Validated, de-duplicated privilege names from $value (list of strings).
     * @param string[] $whitelist
     * @param array<string,string> $aliases
     * @return string[]
     */
    protected function privList($value, array $whitelist, string $what, array $aliases = []): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw ApiException::badRequest('Expected a list of privileges for ' . $what);
        }
        $out = [];
        foreach ($value as $p) {
            if (!is_string($p)) {
                throw ApiException::badRequest('Invalid privilege for ' . $what);
            }
            $c = self::canon($p);
            $c = $aliases[$c] ?? $c;
            if (!in_array($c, $whitelist, true)) {
                throw ApiException::badRequest('Privilege not allowed for ' . $what . ': ' . mb_substr($p, 0, 64, 'UTF-8'));
            }
            if (!in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** {add, remove[, deny]} of an entry, validated; a privilege may only appear in one list. */
    protected function addRemove(array $entry, array $whitelist, string $what, array $aliases = [], bool $deny = false): array
    {
        $add = $this->privList($entry['add'] ?? null, $whitelist, $what, $aliases);
        $remove = $this->privList($entry['remove'] ?? null, $whitelist, $what, $aliases);
        $denied = $deny ? $this->privList($entry['deny'] ?? null, $whitelist, $what, $aliases) : [];
        if (!$deny && isset($entry['deny']) && $entry['deny'] !== []) {
            throw ApiException::badRequest('DENY is not supported by this server');
        }
        // single-privilege form {priv, add:true} / {priv, remove:true}
        if (isset($entry['priv']) && is_string($entry['priv'])) {
            $one = $this->privList([$entry['priv']], $whitelist, $what, $aliases);
            if (($entry['add'] ?? null) === true) {
                $add = $one;
            } elseif (($entry['remove'] ?? null) === true) {
                $remove = $one;
            } else {
                throw ApiException::badRequest('Missing add/remove for ' . $what);
            }
        }
        if (array_intersect($add, $remove) || array_intersect($add, $denied) || array_intersect($remove, $denied)) {
            throw ApiException::badRequest('A privilege cannot be added and removed at once (' . $what . ')');
        }
        return ['add' => $add, 'remove' => $remove, 'deny' => $denied];
    }

    /** List of objects (arrays) under $key. */
    protected function entries(array $changes, string $key): array
    {
        $v = $changes[$key] ?? [];
        if (!is_array($v)) {
            throw ApiException::badRequest('Expected a list for ' . $key);
        }
        $out = [];
        foreach ($v as $e) {
            if (!is_array($e)) {
                throw ApiException::badRequest('Invalid entry in ' . $key);
            }
            $out[] = $e;
        }
        return $out;
    }

    /** Required non-empty name field of an entry. */
    protected function nameField(array $e, string $key, int $maxChars = 128): string
    {
        $v = $e[$key] ?? null;
        if (!is_string($v)) {
            throw ApiException::badRequest('Missing ' . $key);
        }
        $this->assertName($v, $key, $maxChars);
        return $v;
    }

    /** Names must be non-empty, valid UTF-8, without NUL bytes and not overly long. */
    protected function assertName(string $v, string $what, int $maxChars = 128, bool $allowEmpty = false): void
    {
        if (($v === '' && !$allowEmpty) || strpos($v, "\0") !== false || !mb_check_encoding($v, 'UTF-8')
            || mb_strlen($v, 'UTF-8') > $maxChars) {
            throw ApiException::badRequest('Invalid ' . $what);
        }
    }

    protected function optBool(array $a, string $key): ?bool
    {
        if (!array_key_exists($key, $a) || $a[$key] === null) {
            return null;
        }
        if (!is_bool($a[$key])) {
            throw ApiException::badRequest('Expected true/false for ' . $key);
        }
        return $a[$key];
    }

    protected function optInt(array $a, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $a) || $a[$key] === null || $a[$key] === '') {
            return null;
        }
        $v = $a[$key];
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw ApiException::badRequest('Invalid value for ' . $key);
        }
        return $v;
    }

    /** Sub-object $key of the spec (or []). */
    protected function section(array $changes, string $key): array
    {
        $v = $changes[$key] ?? [];
        if ($v === null) {
            return [];
        }
        if (!is_array($v)) {
            throw ApiException::badRequest('Expected an object for ' . $key);
        }
        return $v;
    }

    protected static function stmt(string $sql, ?string $db = null): array
    {
        return ['sql' => $sql, 'db' => $db];
    }

    /**
     * Drop no-ops against the current privileges of an object: add only what is missing,
     * remove only what is there. $current null = unknown (keep everything).
     */
    protected static function effective(array $ar, ?array $current, ?array $currentDenied = null): array
    {
        if ($current === null) {
            return $ar;
        }
        $denied = $currentDenied ?? [];
        $add = array_values(array_filter($ar['add'], static fn ($p) => !in_array($p, $current, true)));
        $remove = array_values(array_filter($ar['remove'], static fn ($p) => in_array($p, $current, true) || in_array($p, $denied, true)));
        $deny = array_values(array_filter($ar['deny'] ?? [], static fn ($p) => !in_array($p, $denied, true)));
        return ['add' => $add, 'remove' => $remove, 'deny' => $deny];
    }

    /** Privileges of an object in a state list ([{…keys…, privileges}]) or null when absent. */
    protected static function findPrivs(?array $list, array $match, string $field = 'privileges'): ?array
    {
        if ($list === null) {
            return null;
        }
        foreach ($list as $row) {
            $ok = true;
            foreach ($match as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) { // booleans compare as '' / '1'
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return array_map([self::class, 'canon'], (array) ($row[$field] ?? []));
            }
        }
        return [];
    }

    /** Role reference {name, host?} from a string or an object. */
    protected function roleRef($r, bool $withHost): array
    {
        if (is_string($r)) {
            $r = ['name' => $r];
        }
        if (!is_array($r) || !isset($r['name']) || !is_string($r['name'])) {
            throw ApiException::badRequest('Invalid role');
        }
        $this->assertName($r['name'], 'role name', 128);
        $host = null;
        if ($withHost) {
            $host = isset($r['host']) && is_string($r['host']) && $r['host'] !== '' ? $r['host'] : '%';
            $this->assertName($host, 'role host', 255);
        }
        return ['name' => $r['name'], 'host' => $host];
    }

    /** True when the role ref is in a list of {name, host?}. */
    protected static function hasRole(array $list, array $ref): bool
    {
        foreach ($list as $r) {
            if ((string) ($r['name'] ?? '') === $ref['name']
                && ($ref['host'] === null || (string) ($r['host'] ?? '%') === $ref['host'])) {
                return true;
            }
        }
        return false;
    }
}
