<?php

declare(strict_types=1);

namespace Ldb\Sql;

/**
 * Minimal, conservative SQL lexer shared by ReadOnlyGuard and TransactionPolicy.
 *
 * Lexing of SQL is ambiguous without knowing server settings (backslash escapes, MySQL "--"
 * rules, executable comments, nested block comments), so variants() returns every plausible
 * interpretation for the dialect; callers scan the input under all of them and take the
 * strictest verdict.
 */
final class SqlLexer
{
    private string $dialect;

    public function __construct(string $dialect = 'mysql')
    {
        $this->dialect = $dialect;
    }

    /**
     * Tokenize under every lexer variant of the dialect.
     * @return array<int,array<int,array{0:string,1:string}>>
     */
    public function tokenizeAll(string $sql): array
    {
        $out = [];
        foreach ($this->variants() as $o) {
            $out[] = $this->tokenize($sql, $o);
        }
        return $out;
    }

    /**
     * Lexer interpretations to try for the dialect.
     * @return array<int,array<string,bool>>
     */
    public function variants(): array
    {
        $out = [];
        switch ($this->dialect) {
            case 'mysql':
                foreach ([true, false] as $bs) {
                    foreach ([true, false] as $strictDash) {
                        // Executable comments /*!NNNNN … */ run only on servers >= NNNNN: try both.
                        foreach ([true, false] as $exec) {
                            $out[] = ['backslash' => $bs, 'estring' => false, 'hash' => true, 'strictDash' => $strictDash,
                                'nested' => false, 'dollar' => false, 'bracket' => false, 'mysqlExec' => $exec];
                        }
                    }
                }
                return $out;
            case 'pgsql':
                foreach ([true, false] as $bs) {
                    foreach ([true, false] as $nested) {
                        $out[] = ['backslash' => $bs, 'estring' => true, 'hash' => false, 'strictDash' => false,
                            'nested' => $nested, 'dollar' => true, 'bracket' => false, 'mysqlExec' => false];
                    }
                }
                return $out;
            case 'sqlsrv':
                foreach ([true, false] as $nested) {
                    $out[] = ['backslash' => false, 'estring' => false, 'hash' => false, 'strictDash' => false,
                        'nested' => $nested, 'dollar' => false, 'bracket' => true, 'mysqlExec' => false];
                }
                return $out;
            case 'sqlite':
                return [['backslash' => false, 'estring' => false, 'hash' => false, 'strictDash' => false,
                    'nested' => false, 'dollar' => false, 'bracket' => true, 'mysqlExec' => false]];
            default:
                // Unknown dialect: union of everything.
                foreach ([true, false] as $bs) {
                    foreach ([true, false] as $nested) {
                        foreach ([true, false] as $exec) {
                            $out[] = ['backslash' => $bs, 'estring' => true, 'hash' => true, 'strictDash' => !$nested,
                                'nested' => $nested, 'dollar' => true, 'bracket' => true, 'mysqlExec' => $exec];
                        }
                    }
                }
                return $out;
        }
    }

    /**
     * Minimal lexer. Tokens: ['w', WORD] unquoted word (upper-cased), ['p', char] punctuation,
     * ['q', NAME] quoted identifier (upper-cased content), ['s', ''] string literal, ['n', ''] number.
     * Comments and whitespace are dropped.
     * @param array<string,bool> $o
     * @return array<int,array{0:string,1:string}>
     */
    public function tokenize(string $sql, array $o): array
    {
        $tokens = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';

            if (ctype_space($c)) {
                $i++;
                continue;
            }
            // Comments
            if ($c === '-' && $n === '-') {
                $after = $i + 2 < $len ? $sql[$i + 2] : '';
                if (!$o['strictDash'] || $after === '' || ctype_space($after)) {
                    $end = strpos($sql, "\n", $i);
                    $i = $end === false ? $len : $end + 1;
                    continue;
                }
            }
            if ($c === '#' && $o['hash']) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === '/' && $n === '*') {
                if ($o['mysqlExec']) {
                    // MySQL/MariaDB executable comments: /*! … */, /*!50100 … */, /*M! … */
                    $k = $i + 2;
                    if ($k < $len && ($sql[$k] === 'M' || $sql[$k] === 'm') && ($sql[$k + 1] ?? '') === '!') {
                        $k++;
                    }
                    if (($sql[$k] ?? '') === '!') {
                        $k++;
                        while ($k < $len && ctype_digit($sql[$k])) {
                            $k++;
                        }
                        $i = $k; // lex the content as code; the closing */ becomes punctuation
                        continue;
                    }
                }
                $i = $this->skipBlockComment($sql, $i, $len, $o['nested']);
                continue;
            }
            // String literals
            if ($c === "'") {
                $i = $this->skipQuoted($sql, $i, $len, "'", $o['backslash']);
                $tokens[] = ['s', ''];
                continue;
            }
            if ($c === '"' || $c === '`' || ($c === '[' && $o['bracket'])) {
                $close = $c === '[' ? ']' : $c;
                $end = $this->skipQuoted($sql, $i, $len, $close, $c === '"' && $o['backslash'] && $this->dialect === 'mysql');
                $name = str_replace($close . $close, $close, substr($sql, $i + 1, max(0, $end - $i - 2)));
                $tokens[] = ['q', strtoupper($name)];
                $i = $end;
                continue;
            }
            if ($c === '$' && $o['dollar']) {
                if (preg_match('/\G\$([A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*)?\$/', $sql, $m, 0, $i)) {
                    $tag = $m[0];
                    $end = strpos($sql, $tag, $i + strlen($tag));
                    $i = $end === false ? $len : $end + strlen($tag);
                    $tokens[] = ['s', ''];
                    continue;
                }
            }
            // Words
            if (ctype_alpha($c) || $c === '_' || ord($c) >= 0x80) {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || ord($sql[$j]) >= 0x80)) {
                    $j++;
                }
                $word = strtoupper(substr($sql, $i, $j - $i));
                // PostgreSQL E'…' / U&'…' and N'…', X'…', B'…' prefixes: the prefix belongs to the literal
                if ($j < $len && $sql[$j] === "'" && in_array($word, ['E', 'N', 'X', 'B', 'U', 'R', 'NE'], true)) {
                    $bs = $o['backslash'] || ($o['estring'] && $word === 'E');
                    $i = $this->skipQuoted($sql, $j, $len, "'", $bs);
                    $tokens[] = ['s', ''];
                    continue;
                }
                $tokens[] = ['w', $word];
                $i = $j;
                continue;
            }
            if (ctype_digit($c)) {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '.' || $sql[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['n', ''];
                $i = $j;
                continue;
            }
            $tokens[] = ['p', $c];
            $i++;
        }
        return $tokens;
    }

    /** Position after a quoted run starting at $i (opening char at $i). Unterminated → end. */
    private function skipQuoted(string $sql, int $i, int $len, string $close, bool $backslash): int
    {
        $k = $i + 1;
        while ($k < $len) {
            $ch = $sql[$k];
            if ($backslash && $ch === '\\') {
                $k += 2;
                continue;
            }
            if ($ch === $close) {
                if ($k + 1 < $len && $sql[$k + 1] === $close) {
                    $k += 2; // doubled quote
                    continue;
                }
                return $k + 1;
            }
            $k++;
        }
        return $len;
    }

    private function skipBlockComment(string $sql, int $i, int $len, bool $nested): int
    {
        if (!$nested) {
            $end = strpos($sql, '*/', $i + 2);
            return $end === false ? $len : $end + 2;
        }
        $depth = 0;
        $k = $i;
        while ($k < $len) {
            if ($sql[$k] === '/' && ($sql[$k + 1] ?? '') === '*') {
                $depth++;
                $k += 2;
                continue;
            }
            if ($sql[$k] === '*' && ($sql[$k + 1] ?? '') === '/') {
                $depth--;
                $k += 2;
                if ($depth === 0) {
                    return $k;
                }
                continue;
            }
            $k++;
        }
        return $len;
    }
}
