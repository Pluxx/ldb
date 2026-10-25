<?php

declare(strict_types=1);

namespace Ldb\Diff;

/**
 * Pure normalisation helpers for schema comparison: column types, defaults, DDL text of
 * views/routines/triggers/events (DEFINER, own database/schema qualifiers, whitespace) and
 * auto-generated constraint/index names. No database access.
 */
final class Normalizer
{
    /** Integer types whose display width (MySQL < 8 / MariaDB "int(11)") carries no meaning. */
    private const MYSQL_INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    /**
     * Comparable type string of a Column: lower-case type name and modifiers, values/lengths
     * kept verbatim (enum values are case-sensitive), whitespace collapsed. MySQL integer display
     * widths are dropped (except tinyint(1), the boolean convention).
     */
    public static function type(array $col, string $driver): string
    {
        $full = isset($col['full_type']) && (string) $col['full_type'] !== '' ? (string) $col['full_type'] : '';
        if ($full === '') {
            $full = (string) ($col['type'] ?? '');
            $len = isset($col['length']) ? trim((string) $col['length']) : '';
            if ($len !== '') {
                $full .= '(' . $len . ')';
            }
            if (!empty($col['unsigned'])) {
                $full .= ' unsigned';
            }
        }
        $full = trim((string) preg_replace('/\s+/', ' ', $full));
        if (preg_match('/^([^(]*?)\s*\((.*)\)(.*)$/s', $full, $m)) {
            $base = strtolower(trim($m[1]));
            $args = $m[2];
            $rest = strtolower(trim($m[3]));
        } else {
            $base = strtolower($full);
            $args = null;
            $rest = '';
        }
        if ($args !== null && !preg_match("/'/", $args)) {
            $args = (string) preg_replace('/\s*,\s*/', ',', trim($args));
        }
        if ($driver === 'mysql') {
            if ($rest === '' && preg_match('/^(\w+)\s+(.*)$/', $base, $bm) && $args === null) {
                // "int unsigned" without parentheses
                $base = $bm[1];
                $rest = $bm[2];
            }
            if ($base === 'integer') {
                $base = 'int';
            }
            if (in_array($base, self::MYSQL_INT_TYPES, true) && $args !== null && !($base === 'tinyint' && $args === '1')) {
                $args = null;
            }
        }
        return $base . ($args !== null ? '(' . $args . ')' : '') . ($rest !== '' ? ' ' . $rest : '');
    }

    /**
     * Comparable default: "none", "null", "v:<literal>" or "e:<normalised expression>".
     * A nullable column without a default behaves like DEFAULT NULL.
     */
    public static function defaultValue(array $col): string
    {
        $kind = (string) ($col['default_kind'] ?? 'none');
        $nullable = !empty($col['nullable']);
        if (!empty($col['generated'])) {
            return 'none';
        }
        if ($kind === 'null' || ($kind === 'none' && $nullable)) {
            return $nullable ? 'null' : 'none';
        }
        if ($kind === 'value') {
            return 'v:' . (string) ($col['default'] ?? '');
        }
        if ($kind === 'expression') {
            return 'e:' . self::expression((string) ($col['default'] ?? ''));
        }
        return 'none';
    }

    /** Expression text for comparison: outer parentheses, whitespace and "now()" spellings unified. */
    public static function expression(string $e): string
    {
        $e = strtolower(trim((string) preg_replace('/\s+/', ' ', $e)));
        while (strlen($e) >= 2 && $e[0] === '(' && substr($e, -1) === ')' && self::balanced(substr($e, 1, -1))) {
            $e = trim(substr($e, 1, -1));
        }
        $e = (string) preg_replace('/\b(current_timestamp|localtimestamp|localtime|current_date|current_time)\s*\(\s*\)/', '$1', $e);
        $e = (string) preg_replace('/\bnow\s*\(\s*\)/', 'current_timestamp', $e);
        return (string) preg_replace('/\s*([(),])\s*/', '$1', $e);
    }

    private static function balanced(string $s): bool
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /** MySQL "on update CURRENT_TIMESTAMP" from Column.extra (normalised), else null. */
    public static function onUpdate(array $col): ?string
    {
        if (isset($col['on_update']) && trim((string) $col['on_update']) !== '') {
            return self::expression((string) $col['on_update']);
        }
        if (preg_match('/on update ([a-z_]+(?:\(\d*\))?)/i', (string) ($col['extra'] ?? ''), $m)) {
            return self::expression($m[1]);
        }
        return null;
    }

    /** Generated column expression (+ storage kind) or null. */
    public static function generated(array $col): ?string
    {
        $g = isset($col['generated']) ? trim((string) $col['generated']) : '';
        if ($g === '') {
            return null;
        }
        $extra = (string) ($col['extra'] ?? '');
        $stored = !empty($col['stored']) || stripos($extra, 'STORED') !== false || stripos($extra, 'PERSISTENT') !== false;
        return ($stored ? 'stored:' : 'virtual:') . trim((string) preg_replace('/\s+/', ' ', $g));
    }

    /**
     * DDL text of a view/routine/trigger/event for comparison: DEFINER removed (when asked),
     * qualifiers of the snapshot's own database/schema removed, MySQL event STARTS timestamps
     * removed (they default to the creation time), whitespace collapsed, trailing ";" dropped.
     */
    public static function ddl(?string $ddl, string $driver, ?string $database, ?string $schema, bool $stripDefiner): ?string
    {
        if ($ddl === null) {
            return null;
        }
        $s = $ddl;
        if ($stripDefiner) {
            $s = self::stripDefiner($s);
        }
        $s = self::unqualify($s, $driver, $database, $schema);
        if ($driver === 'mysql') {
            $s = (string) preg_replace("/\\s+STARTS\\s+'[^']*'/i", '', $s);
            $s = (string) preg_replace('/\bALGORITHM\s*=\s*UNDEFINED\s+/i', '', $s);
        }
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        $s = rtrim($s, "; \t\n\r");
        // "( x" / "x )" spacing differences from pretty printers
        return (string) preg_replace('/\(\s+/', '(', (string) preg_replace('/\s+\)/', ')', $s));
    }

    /** Remove a MySQL "DEFINER=user@host" clause. */
    public static function stripDefiner(string $sql): string
    {
        return (string) preg_replace(
            '/\s+DEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s@]+)(?:\s*@\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s]+))?/i',
            '',
            $sql,
            1
        );
    }

    /** Remove `db`. (MySQL) or "schema". / schema. (PostgreSQL) qualifiers of the snapshot's own namespace. */
    public static function unqualify(string $sql, string $driver, ?string $database, ?string $schema): string
    {
        if ($driver === 'mysql' && $database !== null && $database !== '') {
            $sql = str_replace('`' . str_replace('`', '``', $database) . '`.', '', $sql);
        } elseif ($driver === 'pgsql' && $schema !== null && $schema !== '') {
            $sql = str_replace('"' . str_replace('"', '""', $schema) . '".', '', $sql);
            if (preg_match('/^[a-z_][a-z0-9_$]*$/', $schema)) {
                $sql = (string) preg_replace('/(?<![\w"$.])' . preg_quote($schema, '/') . '\.(?=["a-z_])/i', '', $sql);
            }
        }
        return $sql;
    }

    /**
     * Rewrite DDL from the source namespace to the target one: `src`. → `tgt`. (MySQL),
     * "src". / src. → "tgt". (PostgreSQL). Unchanged when the names are equal.
     */
    public static function retarget(string $sql, string $driver, ?string $fromDb, ?string $fromSchema, ?string $toDb, ?string $toSchema): string
    {
        if ($driver === 'mysql' && $fromDb !== null && $fromDb !== '' && $toDb !== null && $toDb !== '' && $fromDb !== $toDb) {
            $q = static fn (string $n): string => '`' . str_replace('`', '``', $n) . '`';
            $sql = str_replace($q($fromDb) . '.', $q($toDb) . '.', $sql);
            if (preg_match('/^[A-Za-z0-9_$]+$/', $fromDb)) {
                // unquoted qualifier as typed in a routine/trigger head
                $sql = (string) preg_replace('/(?<![\w`$.])' . preg_quote($fromDb, '/') . '\.(?=[`A-Za-z_])/', $q($toDb) . '.', $sql);
            }
        } elseif ($driver === 'pgsql' && $fromSchema !== null && $fromSchema !== '' && $toSchema !== null && $toSchema !== '' && $fromSchema !== $toSchema) {
            $q = static fn (string $n): string => '"' . str_replace('"', '""', $n) . '"';
            $sql = str_replace($q($fromSchema) . '.', $q($toSchema) . '.', $sql);
            if (preg_match('/^[a-z_][a-z0-9_$]*$/', $fromSchema)) {
                $sql = (string) preg_replace('/(?<![\w"$.])' . preg_quote($fromSchema, '/') . '\.(?=["a-z_])/i', $q($toSchema) . '.', $sql);
            }
        }
        return $sql;
    }

    /**
     * Whether a constraint/index name looks generated by the engine (then objects are matched by
     * definition, not by name): SQLite autoindexes, PostgreSQL {table}_…_{pkey|key|idx|fkey|check},
     * MySQL {table}_ibfk_N / {table}_chk_N.
     */
    public static function isAutoName(string $name, string $table, string $driver, string $kind): bool
    {
        if ($driver === 'sqlite') {
            return $kind === 'fk' || stripos($name, 'sqlite_autoindex_') === 0;
        }
        $t = preg_quote($table, '/');
        if ($driver === 'pgsql') {
            return (bool) preg_match('/^' . $t . '_.*(pkey|key|idx|fkey|check|excl)\d*$/', $name);
        }
        if ($driver === 'mysql') {
            return (bool) preg_match('/^' . $t . '_(ibfk|chk)_\d+$/', $name);
        }
        return false;
    }

    /** Foreign key action with engine defaults unified (null / RESTRICT → NO ACTION). */
    public static function fkAction(?string $a): string
    {
        $a = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $a)));
        return $a === '' || $a === 'RESTRICT' ? 'NO ACTION' : $a;
    }

    /** CHECK constraint text for comparison. */
    public static function check(string $def): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $def));
        if (preg_match('/^CHECK\s*(.*)$/is', $s, $m)) {
            $s = trim($m[1]);
        }
        $s = self::expression($s);
        return (string) preg_replace('/\s*(not valid|no inherit)\s*$/', ' $1', $s);
    }

    /** Index DDL (PostgreSQL pg_get_indexdef / SQLite sql) without the index name, for comparison. */
    public static function indexDdl(?string $ddl, string $driver, ?string $schema): ?string
    {
        if ($ddl === null || $ddl === '') {
            return null;
        }
        $s = self::unqualify($ddl, $driver, null, $schema);
        $s = (string) preg_replace('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")*"|`[^`]*`|\[[^\]]*\]|\S+)\s+ON\s+/i', 'CREATE $1INDEX ON ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** Whether $sql mentions identifier $name as a word (case-insensitive), quoted or not. */
    public static function mentions(string $sql, string $name): bool
    {
        if ($name === '') {
            return false;
        }
        return (bool) preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($name, '/') . '(?![A-Za-z0-9_$])/i', $sql);
    }
}
