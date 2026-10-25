<?php

declare(strict_types=1);

namespace Ldb\Sql;

/**
 * Lightweight SQL pretty printer: uppercases keywords, starts major clauses on new lines,
 * indents sub-queries and breaks select lists / boolean conditions. String literals, quoted
 * identifiers and comments are copied verbatim.
 */
final class SqlFormatter
{
    private const T_WS = 0;
    private const T_COMMENT_LINE = 1;
    private const T_COMMENT_BLOCK = 2;
    private const T_STRING = 3;   // literals and quoted identifiers
    private const T_NUMBER = 4;
    private const T_WORD = 5;
    private const T_PUNCT = 6;    // ( ) , ; .
    private const T_OP = 7;

    private const KEYWORDS = [
        'ADD', 'ALL', 'ALTER', 'AND', 'ANY', 'AS', 'ASC', 'AUTO_INCREMENT', 'BEGIN', 'BETWEEN', 'BIGINT',
        'BINARY', 'BLOB', 'BOOLEAN', 'BY', 'CASCADE', 'CASE', 'CHAR', 'CHARACTER', 'CHARSET', 'CHECK',
        'COLLATE', 'COLUMN', 'COMMENT', 'COMMIT', 'CONSTRAINT', 'CREATE', 'CROSS', 'CURRENT_DATE',
        'CURRENT_TIMESTAMP', 'DATABASE', 'DATE', 'DATETIME', 'DECIMAL', 'DECLARE', 'DEFAULT', 'DELETE',
        'DESC', 'DISTINCT', 'DOUBLE', 'DROP', 'ELSE', 'END', 'ENGINE', 'EXCEPT', 'EXISTS', 'EXPLAIN',
        'FALSE', 'FETCH', 'FIRST', 'FLOAT', 'FOR', 'FOREIGN', 'FROM', 'FULL', 'FUNCTION', 'GRANT', 'GROUP',
        'HAVING', 'IF', 'IGNORE', 'ILIKE', 'IN', 'INDEX', 'INNER', 'INSERT', 'INT', 'INTEGER', 'INTERSECT',
        'INTERVAL', 'INTO', 'IS', 'JOIN', 'KEY', 'LATERAL', 'LEFT', 'LIKE', 'LIMIT', 'NATURAL', 'NEXT',
        'NOT', 'NULL', 'NULLS', 'OFFSET', 'ON', 'ONLY', 'OR', 'ORDER', 'OUTER', 'OVER', 'PARTITION',
        'PRIMARY', 'PROCEDURE', 'REFERENCES', 'REGEXP', 'RENAME', 'REPLACE', 'RESTRICT', 'RETURNING',
        'RETURNS', 'REVOKE', 'RIGHT', 'ROLLBACK', 'ROW', 'ROWS', 'SCHEMA', 'SELECT', 'SET', 'SHOW',
        'SMALLINT', 'TABLE', 'TEMPORARY', 'TEXT', 'THEN', 'TIMESTAMP', 'TINYINT', 'TO', 'TOP',
        'TRANSACTION', 'TRIGGER', 'TRUE', 'TRUNCATE', 'UNION', 'UNIQUE', 'UNSIGNED', 'UPDATE', 'USE',
        'USING', 'VALUES', 'VARCHAR', 'VIEW', 'WHEN', 'WHERE', 'WINDOW', 'WITH', 'RECURSIVE',
    ];

    private const FUNCTIONS = [
        'ABS', 'AVG', 'CAST', 'COALESCE', 'CONCAT', 'CONVERT', 'COUNT', 'DATE_FORMAT', 'EXTRACT',
        'GREATEST', 'GROUP_CONCAT', 'IFNULL', 'ISNULL', 'JSON_EXTRACT', 'LEAST', 'LENGTH', 'LOWER',
        'LTRIM', 'MAX', 'MIN', 'NOW', 'NULLIF', 'ROUND', 'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'RTRIM',
        'STRING_AGG', 'SUBSTR', 'SUBSTRING', 'SUM', 'TRIM', 'UPPER', 'ARRAY_AGG', 'TO_CHAR', 'DATEADD',
        'DATEDIFF', 'GETDATE', 'LEN', 'CHAR_LENGTH',
    ];

    /** Keywords that start a clause on a new line (at the current nesting level). */
    private const CLAUSES = [
        'SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'EXCEPT',
        'INTERSECT', 'VALUES', 'SET', 'INSERT', 'UPDATE', 'DELETE', 'JOIN', 'LEFT', 'RIGHT', 'INNER',
        'CROSS', 'FULL', 'NATURAL', 'RETURNING', 'WINDOW', 'FETCH', 'ON',
    ];

    private const JOIN_MODIFIERS = ['LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'FULL', 'NATURAL'];

    private string $indent;

    public function __construct(string $indent = '    ')
    {
        $this->indent = $indent;
    }

    public static function formatSql(string $sql): string
    {
        return (new self())->format($sql);
    }

    public function format(string $sql): string
    {
        $tokens = $this->tokenize($sql);
        $keywords = array_flip(self::KEYWORDS);
        $functions = array_flip(self::FUNCTIONS);
        $clauses = array_flip(self::CLAUSES);

        $out = '';
        $level = 0;            // indentation from sub-queries
        $parens = [];          // stack: true = sub-query parenthesis
        $clause = '';          // current clause keyword at this level
        $prev = null;          // previous significant token: [type, text]
        $prevKeyword = '';
        $betweenPending = false;
        $pending = null;       // indentation string for a pending line break

        foreach ($tokens as $i => [$type, $text]) {
            if ($type === self::T_WS) {
                continue;
            }
            $upper = strtoupper($text);
            $isKeyword = $type === self::T_WORD && isset($keywords[$upper]);
            $isFunction = $type === self::T_WORD && !$isKeyword && isset($functions[$upper])
                && $this->nextSignificant($tokens, $i) === '(';
            if ($isKeyword || $isFunction) {
                $text = $upper;
            }
            $inSub = $parens === [] || end($parens) === true;
            $pad = str_repeat($this->indent, $level);
            $prevText = $prev === null ? null : $prev[1];

            // Line breaks before clauses / conditions
            if ($isKeyword && $inSub && isset($clauses[$upper])) {
                $isJoinPart = $upper === 'JOIN' || in_array($upper, self::JOIN_MODIFIERS, true);
                $continuesJoin = $isJoinPart && in_array($prevKeyword, self::JOIN_MODIFIERS, true);
                $skip = $continuesJoin
                    || ($upper === 'SET' && $clause !== 'UPDATE')
                    || ($upper === 'ON' && $clause !== 'JOIN')
                    || ($upper === 'SELECT' && in_array($prevKeyword, ['UNION', 'ALL', 'EXCEPT', 'INTERSECT', 'DISTINCT'], true))
                    || ($upper === 'SELECT' && $prevKeyword === 'INSERT')
                    || ($upper === 'UPDATE' && in_array($prevKeyword, ['ON', 'KEY', 'FOR'], true))
                    || ($upper === 'DELETE' && $prevKeyword === 'ON')
                    || ($upper === 'FROM' && $prevKeyword === 'DELETE')
                    || ($upper === 'ORDER' && $prevKeyword === 'BY');
                if (!$skip && $out !== '') {
                    $pending = $upper === 'ON' ? $pad . $this->indent : $pad;
                }
                if (!$continuesJoin && $upper !== 'ON' && !$skip) {
                    $clause = $isJoinPart ? 'JOIN' : $upper;
                }
            } elseif ($isKeyword && ($upper === 'AND' || $upper === 'OR') && $inSub
                && in_array($clause, ['WHERE', 'HAVING', 'JOIN'], true)) {
                if ($betweenPending && $upper === 'AND') {
                    $betweenPending = false;
                } else {
                    $pending = $pad . $this->indent;
                }
            }
            if ($type === self::T_PUNCT && $text === ')') {
                if (array_pop($parens) === true) {
                    $level = max(0, $level - 1);
                    $pending = str_repeat($this->indent, $level);
                }
            }
            if ($prev !== null && $prev[0] === self::T_COMMENT_LINE && $pending === null) {
                $pending = $pad;
            }

            // Spacing
            $space = $out !== '';
            if (in_array($text, [',', ';', ')', '.', '::'], true) && $type !== self::T_STRING) {
                $space = false;
            }
            if ($prevText === '(' || $prevText === '.' || $prevText === '::') {
                $space = false;
            }
            if ($text === '(' && $prev !== null
                && (($prev[0] === self::T_WORD && !isset($keywords[strtoupper($prev[1])])) || $prev[2])) {
                $space = false;
            }

            if ($pending !== null && $out !== '') {
                $out = rtrim($out, ' ') . "\n" . $pending;
            } elseif ($space) {
                $out .= ' ';
            }
            $pending = null;
            $out .= $text;

            // Line breaks after
            if ($type === self::T_PUNCT && $text === '(') {
                $sub = in_array(strtoupper($this->nextSignificant($tokens, $i)), ['SELECT', 'WITH'], true);
                $parens[] = $sub;
                if ($sub) {
                    $level++;
                    $pending = str_repeat($this->indent, $level);
                }
            } elseif ($type === self::T_PUNCT && $text === ',' && $inSub && $clause === 'SELECT') {
                $pending = $pad . $this->indent;
            } elseif ($type === self::T_PUNCT && $text === ';') {
                $level = 0;
                $parens = [];
                $clause = '';
                $prevKeyword = '';
                $pending = '';
                $prev = null;
                continue;
            } elseif ($type === self::T_COMMENT_LINE) {
                $pending = $pad;
            }

            if ($isKeyword) {
                $prevKeyword = $upper;
                if ($upper === 'BETWEEN') {
                    $betweenPending = true;
                }
            } elseif ($type !== self::T_COMMENT_BLOCK && $type !== self::T_COMMENT_LINE) {
                $prevKeyword = '';
            }
            $prev = [$type, $text, $isFunction];
        }

        return rtrim($out);
    }

    /** @param array<int,array{0:int,1:string}> $tokens */
    private function nextSignificant(array $tokens, int $i): string
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($tokens[$j][0] !== self::T_WS && $tokens[$j][0] !== self::T_COMMENT_BLOCK && $tokens[$j][0] !== self::T_COMMENT_LINE) {
                return $tokens[$j][1];
            }
        }
        return '';
    }

    /** @return array<int,array{0:int,1:string}> */
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $rest2 = substr($sql, $i, 2);

            if (ctype_space($c)) {
                $n = strspn($sql, " \t\r\n\f\v", $i);
                $tokens[] = [self::T_WS, substr($sql, $i, $n)];
                $i += $n;
            } elseif ($rest2 === '--' || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $end = $nl === false ? $len : $nl;
                $tokens[] = [self::T_COMMENT_LINE, rtrim(substr($sql, $i, $end - $i), "\r")];
                $i = $end;
            } elseif ($rest2 === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $tokens[] = [self::T_COMMENT_BLOCK, substr($sql, $i, $end - $i)];
                $i = $end;
            } elseif ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
                $close = $c === '[' ? ']' : $c;
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\' && $c !== '`' && $c !== '[') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $close) {
                        if ($j + 1 < $len && $sql[$j + 1] === $close) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $end = min($len, $j + 1);
                $tokens[] = [self::T_STRING, substr($sql, $i, $end - $i)];
                $i = $end;
            } elseif ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $end = $end === false ? $len : $end + strlen($m[0]);
                $tokens[] = [self::T_STRING, substr($sql, $i, $end - $i)];
                $i = $end;
            } elseif (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($sql[$i + 1]))) {
                preg_match('/\G(?:0x[0-9a-fA-F]+|\d*\.?\d+(?:[eE][+-]?\d+)?)/', $sql, $m, 0, $i);
                $n = max(1, strlen($m[0] ?? ''));
                $tokens[] = [self::T_NUMBER, substr($sql, $i, $n)];
                $i += $n;
            } elseif (preg_match('/\G[A-Za-z_@\x80-\xFF][A-Za-z0-9_$@\x80-\xFF]*/', $sql, $m, 0, $i)) {
                $tokens[] = [self::T_WORD, $m[0]];
                $i += strlen($m[0]);
            } elseif (strpos('(),;.', $c) !== false) {
                $tokens[] = [self::T_PUNCT, $c];
                $i++;
            } else {
                preg_match('/\G(?:::|<=>|<>|!=|<=|>=|\|\||&&|->>|->|:=|[^\sA-Za-z0-9_(),;.\'"`\[])/', $sql, $m, 0, $i);
                $n = max(1, strlen($m[0] ?? ''));
                $tokens[] = [self::T_OP, substr($sql, $i, $n)];
                $i += $n;
            }
        }
        return $tokens;
    }
}
