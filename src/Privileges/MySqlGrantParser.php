<?php

declare(strict_types=1);

namespace Ldb\Privileges;

/**
 * Parser for MySQL / MariaDB `SHOW GRANTS` lines. Understands
 *   GRANT <priv [(col, …)], …> ON <*.* | db.* | db.tbl | FUNCTION db.f | PROCEDURE db.p> TO <acct> … [WITH GRANT OPTION]
 *   GRANT <role, …> TO <acct> [WITH ADMIN OPTION]
 * Other lines (PROXY, partial REVOKE …) return null.
 */
final class MySqlGrantParser
{
    /**
     * @return array|null
     *   {type:'privileges', on:{level:'global'|'database'|'table'|'routine', db?, table?, routine_type?},
     *    privileges:[{name, columns:?string[]}], grant_option:bool}
     *   | {type:'roles', roles:[{name, host:?string}], admin_option:bool}
     */
    public static function parse(string $line): ?array
    {
        $tokens = self::tokenize(trim($line));
        if (!$tokens || !self::isWord($tokens[0], 'GRANT')) {
            return null;
        }
        $i = 1;
        $n = count($tokens);
        if ($i < $n && $tokens[$i]['t'] !== 'word') {
            // role list: `r`@`h`, `r2` TO …
            $roles = [];
            while ($i < $n && !self::isWord($tokens[$i], 'TO')) {
                $tk = $tokens[$i];
                if ($tk['t'] === 'id' || $tk['t'] === 'str') {
                    $role = ['name' => $tk['v'], 'host' => null];
                    if ($i + 2 < $n && $tokens[$i + 1]['t'] === 'p' && $tokens[$i + 1]['v'] === '@') {
                        $role['host'] = $tokens[$i + 2]['v'];
                        $i += 2;
                    }
                    $roles[] = $role;
                }
                $i++;
            }
            return ['type' => 'roles', 'roles' => $roles, 'admin_option' => self::hasPhrase($tokens, $i, ['WITH', 'ADMIN', 'OPTION'])];
        }

        $privs = [];
        $words = [];
        while ($i < $n && !self::isWord($tokens[$i], 'ON')) {
            $tk = $tokens[$i];
            if ($tk['t'] === 'word') {
                $words[] = strtoupper($tk['v']);
            } elseif ($tk['t'] === 'p' && $tk['v'] === '(') {
                $cols = [];
                $i++;
                while ($i < $n && !($tokens[$i]['t'] === 'p' && $tokens[$i]['v'] === ')')) {
                    if ($tokens[$i]['t'] === 'id' || $tokens[$i]['t'] === 'word' || $tokens[$i]['t'] === 'str') {
                        $cols[] = $tokens[$i]['v'];
                    }
                    $i++;
                }
                $privs[] = ['name' => implode(' ', $words), 'columns' => $cols];
                $words = [];
            } elseif ($tk['t'] === 'p' && $tk['v'] === ',') {
                if ($words) {
                    $privs[] = ['name' => implode(' ', $words), 'columns' => null];
                }
                $words = [];
            }
            $i++;
        }
        if ($words) {
            $privs[] = ['name' => implode(' ', $words), 'columns' => null];
        }
        if ($i >= $n) {
            return null;
        }
        $i++; // ON
        $on = ['level' => 'global'];
        if ($i < $n && $tokens[$i]['t'] === 'word' && in_array(strtoupper($tokens[$i]['v']), ['TABLE', 'FUNCTION', 'PROCEDURE', 'PACKAGE'], true)) {
            $kind = strtoupper($tokens[$i]['v']);
            if ($kind !== 'TABLE') {
                $on = ['level' => 'routine', 'routine_type' => $kind];
            }
            $i++;
        }
        $parts = [];
        while ($i < $n && !self::isWord($tokens[$i], 'TO')) {
            $tk = $tokens[$i];
            if ($tk['t'] === 'id' || $tk['t'] === 'str' || ($tk['t'] === 'word' && strtoupper($tk['v']) !== 'TO')) {
                $parts[] = $tk['v'];
            } elseif ($tk['t'] === 'p' && $tk['v'] === '*') {
                $parts[] = '*';
            }
            $i++;
        }
        if (count($parts) === 1) {
            $parts = $parts[0] === '*' ? ['*', '*'] : [null, $parts[0]];
        }
        if (count($parts) !== 2) {
            return null;
        }
        [$db, $obj] = $parts;
        if ($on['level'] === 'routine') {
            $on += ['db' => $db, 'name' => $obj];
        } elseif ($db === '*' && $obj === '*') {
            $on = ['level' => 'global'];
        } elseif ($obj === '*') {
            $on = ['level' => 'database', 'db' => $db];
        } else {
            $on = ['level' => 'table', 'db' => $db, 'table' => $obj];
        }
        foreach ($privs as $p) {
            if ($p['name'] === 'PROXY') {
                return null;
            }
        }
        return [
            'type' => 'privileges',
            'on' => $on,
            'privileges' => $privs,
            'grant_option' => self::hasPhrase($tokens, $i, ['WITH', 'GRANT', 'OPTION']),
        ];
    }

    /**
     * Escape a literal database name for use as a database-level grant pattern
     * (`GRANT … ON `db`.*`): `\`, `_` and `%` are wildcards/escapes there.
     */
    public static function escapePattern(string $db): string
    {
        return preg_replace('/[\\\\_%]/', '\\\\$0', $db);
    }

    /** Reverse of escapePattern: `\\`, `\_` and `\%` become the plain character. */
    public static function unescapePattern(string $raw): string
    {
        return preg_replace('/\\\\([\\\\_%])/', '$1', $raw);
    }

    /** Does a stored database pattern contain an unescaped `_` or `%` wildcard? */
    public static function hasWildcard(string $raw): bool
    {
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i++) {
            $c = $raw[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '_' || $c === '%') {
                return true;
            }
        }
        return false;
    }

    private static function isWord(array $tk, string $w): bool
    {
        return $tk['t'] === 'word' && strtoupper($tk['v']) === $w;
    }

    private static function hasPhrase(array $tokens, int $from, array $phrase): bool
    {
        $n = count($tokens);
        for ($i = $from; $i + count($phrase) <= $n; $i++) {
            $ok = true;
            foreach ($phrase as $k => $w) {
                if (!self::isWord($tokens[$i + $k], $w)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** Tokens: word (bare), id (`…`/"…"), str ('…'), p (punctuation). */
    private static function tokenize(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '`' || $c === '"' || $c === "'") {
                $q = $c;
                $v = '';
                $i++;
                while ($i < $len) {
                    if ($q === "'" && $s[$i] === '\\' && $i + 1 < $len) {
                        $v .= $s[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($s[$i] === $q) {
                        if ($i + 1 < $len && $s[$i + 1] === $q) {
                            $v .= $q;
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    $v .= $s[$i];
                    $i++;
                }
                $i++;
                $out[] = ['t' => $q === "'" ? 'str' : 'id', 'v' => $v];
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$\x80-\xff]/', $c)) {
                $j = $i;
                while ($j < $len && preg_match('/[A-Za-z0-9_$\x80-\xff]/', $s[$j])) {
                    $j++;
                }
                $out[] = ['t' => 'word', 'v' => substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $out[] = ['t' => 'p', 'v' => $c];
            $i++;
        }
        return $out;
    }
}
