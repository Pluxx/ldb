<?php

declare(strict_types=1);

namespace Ldb\Db;

use PDO;

/**
 * PDO connection for SQLite that refuses statements able to touch files outside the
 * configured `sqlite_paths` allow-list: ATTACH DATABASE (opens/creates any file the web server
 * can write, e.g. a .php file in the web root) and VACUUM … INTO (writes a copy anywhere).
 * PHP 7.4 offers no authorizer hook for PDO SQLite, so every SQL entry point is checked here.
 * (load_extension() is already disabled by pdo_sqlite.)
 */
final class SqlitePdo extends PDO
{
    /**
     * @param string $statement
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        self::assertAllowed((string) $statement);
        return parent::exec($statement);
    }

    /**
     * @param string $statement
     * @param array<int,mixed> $options
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = [])
    {
        self::assertAllowed((string) $statement);
        return parent::prepare($statement, $options);
    }

    /**
     * @param string $statement
     * @param int|null $mode
     * @param mixed ...$args
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        self::assertAllowed((string) $statement);
        return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
    }

    /**
     * Throws when any statement in $sql is ATTACH or VACUUM … INTO. Strings, quoted identifiers
     * and comments are skipped, so names like "attach" stay usable.
     */
    public static function assertAllowed(string $sql): void
    {
        $len = strlen($sql);
        $first = null;      // first keyword of the current statement (after EXPLAIN [QUERY PLAN])
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = $i + 1;
                while (true) {
                    $end = strpos($sql, $c, $end);
                    if ($end === false) {
                        return; // unterminated literal: SQLite rejects the statement anyway
                    }
                    if ($end + 1 < $len && $sql[$end + 1] === $c) {
                        $end += 2; // doubled quote
                        continue;
                    }
                    break;
                }
                $i = $end + 1;
                continue;
            }
            if ($c === '[') {
                $end = strpos($sql, ']', $i + 1);
                if ($end === false) {
                    return;
                }
                $i = $end + 1;
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($c === ';') {
                $first = null;
                $i++;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$')) {
                    $j++;
                }
                $word = strtoupper(substr($sql, $i, $j - $i));
                $i = $j;
                if ($first === null) {
                    if ($word === 'EXPLAIN' || $word === 'QUERY' || $word === 'PLAN') {
                        continue;
                    }
                    $first = $word;
                    if ($word === 'ATTACH') {
                        throw new \PDOException('ATTACH DATABASE is disabled: it could open files outside the allowed SQLite directories');
                    }
                } elseif ($first === 'VACUUM' && $word === 'INTO') {
                    throw new \PDOException('VACUUM INTO is disabled: it could write files outside the allowed SQLite directories');
                }
                continue;
            }
            if ($first === null && !ctype_space($c) && $c !== '(') {
                $first = $c; // statement starts with something else (e.g. a number)
            }
            $i++;
        }
    }
}
