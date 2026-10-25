<?php

declare(strict_types=1);

namespace Ldb\Sql;

/**
 * Splits SQL scripts into individual statements.
 *
 * Understands '…' "…" `…` [ … ] quoting (per dialect), -- / # / C-style comments (nested in
 * PostgreSQL), PostgreSQL $tag$ dollar quoting, the MySQL client "DELIMITER" command and the
 * SQL Server "GO" batch separator. Leading comments of a statement are dropped; MySQL
 * executable comments (/*! … *\/, /*+ … *\/) are kept as statement content.
 *
 * Compound statements: a ";" inside the body of a routine definition does not end it. Once the
 * leading keywords identify one — MySQL/MariaDB CREATE [OR REPLACE] [DEFINER=…] [SQL SECURITY …]
 * [AGGREGATE] PROCEDURE|FUNCTION|TRIGGER|EVENT and BEGIN NOT ATOMIC, PostgreSQL CREATE [OR REPLACE]
 * FUNCTION|PROCEDURE (BEGIN ATOMIC bodies), SQLite CREATE [TEMP] TRIGGER, SQL Server CREATE|ALTER
 * [OR ALTER] PROC|PROCEDURE|FUNCTION|TRIGGER — a block stack is kept: BEGIN (not BEGIN WORK /
 * TRAN / TRANSACTION …) and CASE open, MySQL IF/LOOP/WHILE/REPEAT/FOR in statement position open
 * and END IF/LOOP/WHILE/REPEAT/FOR/CASE close them, T-SQL END TRY/CATCH close BEGIN TRY/CATCH, a
 * bare END closes the innermost BEGIN or CASE. Only a ";" with an empty stack ends the statement.
 * On SQL Server every statement tracks BEGIN … END blocks (IF … BEGIN …; …; END). Unbalanced
 * input loses nothing: GO and the end of input flush the remainder. Not done for a custom
 * DELIMITER (the client semantics already work there).
 *
 * Streaming: feed() accepts arbitrary chunks and returns the statements completed so far;
 * finish() flushes the remainder. Memory stays bounded by the largest single statement.
 *
 * Options (second constructor argument):
 *  - keepLeadingComments (bool, default false): keep comments that precede a statement as part of it
 *    (import behaviour); comment-only fragments are still dropped.
 *  - stripBom (bool, default false): drop a leading UTF-8 byte order mark.
 *  - go (bool, default true for sqlsrv): treat "GO" lines as batch separators.
 *  - wholeBatches (bool, default = go): once a GO line was seen, every batch is returned as ONE
 *    statement, unsplit (sqlcmd / SSMS semantics); a script without GO is split at ";" as usual.
 *    The first batch is held back until its GO (or the end), so memory grows with batch size —
 *    importers should pass false.
 *  - nestedComments (bool, default true for pgsql): block comments nest.
 */
final class SqlSplitter
{
    private const NORMAL = 0;
    private const QUOTED = 1;
    private const LINE_COMMENT = 2;
    private const BLOCK_COMMENT = 3;
    private const DOLLAR = 4;

    private string $dialect;
    private bool $mysql;
    private bool $pgsql;
    private bool $sqlite;
    private bool $sqlsrv;

    private bool $keepComments;
    private bool $stripBom;
    private bool $goBatches;
    private bool $wholeBatches;
    private bool $nestedComments;
    private bool $bomPending;

    private string $delimiter = ';';
    private string $buffer = '';
    private int $pos = 0;
    private int $state = self::NORMAL;
    /** Offset of the first content character of the current statement, -1 when none yet. */
    private int $contentStart = -1;
    /** Offset of the first leading comment of the current statement (keepLeadingComments), -1 when none. */
    private int $leadStart = -1;
    private string $closeQuote = '';
    private bool $backslashEscapes = false;
    private int $commentDepth = 0;
    private string $dollarTag = '';

    // ---- compound statement tracking (per statement) ----
    private const HEAD_FIRST = 0;
    private const HEAD_CREATE = 1;
    private const HEAD_DEFINER = 2;
    private const HEAD_SECURITY = 3;
    private const HEAD_BEGIN = 4;
    private const HEAD_ROUTINE = 5;
    private const HEAD_BLOCK = 6;
    private const HEAD_NONE = 7;

    /** Characters of unquoted words/numbers (ASCII letters, digits, _, $ and all non-ASCII bytes). */
    private const IDENT = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$"
        . "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F"
        . "\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF"
        . "\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        . "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    /** Routine kinds per dialect (word after CREATE …). */
    private const KINDS = [
        'mysql' => ['PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT'],
        'pgsql' => ['FUNCTION', 'PROCEDURE'],
        'sqlite' => ['TRIGGER'],
        'sqlsrv' => ['PROC', 'PROCEDURE', 'FUNCTION', 'TRIGGER'],
    ];
    /** Words that may stand between CREATE and the routine kind. */
    private const HEAD_SKIP = [
        'mysql' => ['OR', 'REPLACE', 'AGGREGATE'],
        'pgsql' => ['OR', 'REPLACE'],
        'sqlite' => ['TEMP', 'TEMPORARY'],
        'sqlsrv' => ['OR', 'ALTER'],
    ];
    /** Words after BEGIN that make it a transaction statement, not a block. */
    private const BEGIN_TX = [
        'mysql' => ['WORK'],
        'pgsql' => [],
        'sqlite' => ['TRANSACTION', 'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'],
        'sqlsrv' => ['TRAN', 'TRANSACTION', 'DISTRIBUTED', 'DIALOG', 'CONVERSATION'],
    ];
    /** Words after END that close a named construct ("END IF"); anything else makes it a bare END. */
    private const END_KINDS = [
        'mysql' => ['IF', 'LOOP', 'WHILE', 'REPEAT', 'FOR', 'CASE'],
        'pgsql' => [],
        'sqlite' => [],
        'sqlsrv' => ['TRY', 'CATCH'],
    ];
    /** MySQL tokens after which IF/LOOP/WHILE/REPEAT/FOR start a statement (open a block). */
    private const STATEMENT_POSITION = [';', 'BEGIN', 'THEN', 'ELSE', 'DO', 'LOOP', 'REPEAT', ':', 'ROW'];

    private int $head = self::HEAD_FIRST;
    /** The current statement is a routine definition / compound statement. */
    private bool $routine = false;
    /** @var string[] open blocks: BEGIN, CASE, IF, LOOP, WHILE, REPEAT, FOR */
    private array $stack = [];
    /** '' | 'BEGIN' | 'END': keyword whose meaning depends on the next token. */
    private string $pending = '';
    /** Last significant token of the statement (upper-cased word, punctuation char, 'Q' for a quoted token). */
    private string $lastSig = '';

    // ---- wholeBatches ----
    private bool $sawGo = false;
    /** Offset of the first content of the current batch, -1 when none yet. */
    private int $batchStart = -1;
    /** @var string[] statements of the current batch, split at ";" (used when no GO is seen) */
    private array $batchStmts = [];

    /**
     * @param string $dialect mysql | pgsql | sqlite | sqlsrv
     * @param array{keepLeadingComments?:bool,stripBom?:bool,go?:bool,wholeBatches?:bool,nestedComments?:bool} $options
     */
    public function __construct(string $dialect = 'mysql', array $options = [])
    {
        $this->dialect = strtolower($dialect);
        $this->mysql = $this->dialect === 'mysql';
        $this->pgsql = $this->dialect === 'pgsql';
        $this->sqlite = $this->dialect === 'sqlite';
        $this->sqlsrv = $this->dialect === 'sqlsrv';
        $this->keepComments = (bool) ($options['keepLeadingComments'] ?? false);
        $this->stripBom = (bool) ($options['stripBom'] ?? false);
        $this->goBatches = (bool) ($options['go'] ?? $this->sqlsrv);
        $this->wholeBatches = $this->sqlsrv && $this->goBatches && (bool) ($options['wholeBatches'] ?? true);
        $this->nestedComments = (bool) ($options['nestedComments'] ?? $this->pgsql);
        $this->bomPending = $this->stripBom;
    }

    /** @return string[] */
    public static function splitSql(string $sql, string $dialect = 'mysql', array $options = []): array
    {
        return (new self($dialect, $options))->split($sql);
    }

    /** Split a complete script. Resets any previous streaming state. @return string[] */
    public function split(string $sql): array
    {
        $this->reset();
        $out = $this->feed($sql);
        foreach ($this->finish() as $stmt) {
            $out[] = $stmt;
        }
        return $out;
    }

    /** Add a chunk; returns statements that are complete now. @return string[] */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        return $this->scan(false);
    }

    /** Flush the trailing statement (if any) and reset. @return string[] */
    public function finish(): array
    {
        $out = $this->scan(true);
        $this->reset();
        return $out;
    }

    /**
     * Read a stream in chunks and yield statements one by one.
     * @param resource $handle
     * @return \Generator<int,string>
     */
    public function splitStream($handle, int $chunkSize = 1048576): \Generator
    {
        $this->reset();
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }
            foreach ($this->feed($chunk) as $stmt) {
                yield $stmt;
            }
        }
        foreach ($this->finish() as $stmt) {
            yield $stmt;
        }
    }

    /**
     * Pull chunks from a reader callback (function(int $bytes): string|false, '' / false at EOF)
     * and yield statements one by one.
     * @return \Generator<int,string>
     */
    public function splitReader(callable $read, int $chunkSize = 65536): \Generator
    {
        $this->reset();
        $chunkSize = max(16, $chunkSize);
        while (true) {
            $chunk = $read($chunkSize);
            if ($chunk === false || $chunk === '' || $chunk === null) {
                break;
            }
            foreach ($this->feed($chunk) as $stmt) {
                yield $stmt;
            }
        }
        foreach ($this->finish() as $stmt) {
            yield $stmt;
        }
    }

    /** Currently active statement delimiter (changes after a DELIMITER command). */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function reset(): void
    {
        $this->delimiter = ';';
        $this->buffer = '';
        $this->pos = 0;
        $this->state = self::NORMAL;
        $this->contentStart = -1;
        $this->leadStart = -1;
        $this->bomPending = $this->stripBom;
        $this->commentDepth = 0;
        $this->dollarTag = '';
        $this->closeQuote = '';
        $this->sawGo = false;
        $this->batchStart = -1;
        $this->batchStmts = [];
        $this->resetBlocks();
    }

    private function resetBlocks(): void
    {
        $this->head = self::HEAD_FIRST;
        $this->routine = false;
        $this->stack = [];
        $this->pending = '';
        $this->lastSig = '';
    }

    /**
     * Whether a statement (SqlLexer tokens, see TransactionPolicy) starts like a routine
     * definition or compound statement whose body may contain ";" — i.e. the splitter keeps it
     * whole when its blocks balance.
     * @param array<int,array{0:string,1:string}> $tokens
     */
    public static function isRoutineDefinition(array $tokens, string $dialect): bool
    {
        $state = self::HEAD_FIRST;
        foreach ($tokens as $tok) {
            $state = self::headStep($state, $tok[0] === 'w', $tok[0] === 'w' ? strtoupper($tok[1]) : $tok[1], strtolower($dialect));
            if ($state === self::HEAD_ROUTINE || $state === self::HEAD_BLOCK) {
                return true;
            }
            if ($state === self::HEAD_NONE) {
                return false;
            }
        }
        return false;
    }

    /** One step of the routine-head recognizer. */
    private static function headStep(int $state, bool $word, string $t, string $dialect): int
    {
        $kinds = self::KINDS[$dialect] ?? self::KINDS['mysql'];
        $mysql = !isset(self::KINDS[$dialect]) || $dialect === 'mysql';
        switch ($state) {
            case self::HEAD_FIRST:
                if ($word && ($t === 'CREATE' || ($t === 'ALTER' && $dialect === 'sqlsrv'))) {
                    return self::HEAD_CREATE;
                }
                return $word && $t === 'BEGIN' && $mysql ? self::HEAD_BEGIN : self::HEAD_NONE;
            case self::HEAD_BEGIN: // MariaDB BEGIN NOT ATOMIC … END
                return $word && $t === 'NOT' ? self::HEAD_BLOCK : self::HEAD_NONE;
            case self::HEAD_CREATE:
                if (!$word) {
                    return self::HEAD_NONE;
                }
                if (in_array($t, $kinds, true)) {
                    return self::HEAD_ROUTINE;
                }
                if ($mysql && $t === 'DEFINER') {
                    return self::HEAD_DEFINER;
                }
                if ($mysql && $t === 'SQL') {
                    return self::HEAD_SECURITY;
                }
                return in_array($t, self::HEAD_SKIP[$dialect] ?? self::HEAD_SKIP['mysql'], true) ? self::HEAD_CREATE : self::HEAD_NONE;
            case self::HEAD_DEFINER: // DEFINER = user@host | CURRENT_USER[()]: skip until the next clause
                if ($word && in_array($t, $kinds, true)) {
                    return self::HEAD_ROUTINE;
                }
                if ($word && $t === 'SQL') {
                    return self::HEAD_SECURITY;
                }
                return $word && $t === 'AGGREGATE' ? self::HEAD_CREATE : self::HEAD_DEFINER;
            case self::HEAD_SECURITY: // SQL SECURITY DEFINER|INVOKER
                if ($word && $t === 'SECURITY') {
                    return self::HEAD_SECURITY;
                }
                return $word && ($t === 'DEFINER' || $t === 'INVOKER') ? self::HEAD_CREATE : self::HEAD_NONE;
        }
        return self::HEAD_NONE;
    }

    /** Word tracking needed for the current statement. */
    private function tracking(): bool
    {
        return $this->delimiter === ';'
            && ($this->head !== self::HEAD_NONE || $this->routine || ($this->sqlsrv && ($this->stack !== [] || $this->pending !== '')));
    }

    /**
     * Feed one token of the current statement (outside literals/comments) to the block tracker.
     * $t: upper-cased word, punctuation character, or 'Q' for a quoted token.
     */
    private function token(bool $word, string $t): void
    {
        if ($this->pending !== '') {
            $p = $this->pending;
            $this->pending = '';
            if ($p === 'END') {
                if ($word && in_array($t, self::END_KINDS[$this->dialect] ?? [], true)) {
                    $this->closeNamed($t);
                    $this->lastSig = $t;
                    return;
                }
                if ($word && $t === 'CONVERSATION' && $this->sqlsrv) {
                    $this->lastSig = $t; // END CONVERSATION statement
                    return;
                }
                $this->closeBare();
            } else { // BEGIN
                $isBlock = $this->pgsql ? ($word && $t === 'ATOMIC')
                    : !($word ? in_array($t, self::BEGIN_TX[$this->dialect] ?? [], true) : $t === ';');
                if ($isBlock) {
                    $this->stack[] = 'BEGIN';
                    if ($word && ($t === 'TRY' || $t === 'CATCH' || $t === 'ATOMIC')) {
                        return; // lastSig stays BEGIN (statement position)
                    }
                } elseif ($word) {
                    $this->lastSig = $t; // BEGIN TRANSACTION …
                    return;
                }
            }
        }

        if ($this->head !== self::HEAD_NONE) {
            $h = self::headStep($this->head, $word, $t, $this->dialect);
            if ($h === self::HEAD_ROUTINE || $h === self::HEAD_BLOCK) {
                $this->routine = true;
                if ($h === self::HEAD_BLOCK) {
                    $this->stack[] = 'BEGIN';
                }
                $this->head = self::HEAD_NONE;
                $this->lastSig = $t;
                return;
            }
            $this->head = $h;
            if ($h !== self::HEAD_NONE || !$this->sqlsrv) {
                $this->lastSig = $t;
                return;
            }
            // SQL Server: an ordinary statement — still look for BEGIN … END blocks
        }

        $prev = $this->lastSig;
        $this->lastSig = $t;
        if (!$word || $prev === '.' || $prev === '@') {
            return; // punctuation, qualified name part, variable
        }
        if ($t === 'BEGIN' || $t === 'END') {
            $this->pending = $t;
        } elseif ($t === 'CASE') {
            if ($this->routine || $this->stack !== []) {
                $this->stack[] = 'CASE';
            }
        } elseif ($this->mysql && $this->routine && in_array($t, ['IF', 'LOOP', 'WHILE', 'REPEAT', 'FOR'], true)
            && in_array($prev, self::STATEMENT_POSITION, true)) {
            $this->stack[] = $t;
        }
    }

    /** "END IF" etc.: close the innermost matching construct (not past a BEGIN); END TRY/CATCH close a BEGIN. */
    private function closeNamed(string $kind): void
    {
        if ($kind === 'TRY' || $kind === 'CATCH') {
            $kind = 'BEGIN';
        }
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i] === $kind) {
                array_splice($this->stack, $i);
                return;
            }
            if ($this->stack[$i] === 'BEGIN') {
                return; // unmatched: ignore
            }
        }
    }

    /** Bare END: close the innermost BEGIN or CASE (and anything unclosed above it). */
    private function closeBare(): void
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i] === 'BEGIN' || $this->stack[$i] === 'CASE') {
                array_splice($this->stack, $i);
                return;
            }
        }
    }

    /** @return string[] */
    private function scan(bool $final): array
    {
        $out = [];
        $buf = $this->buffer;
        $len = strlen($buf);
        $pos = $this->pos;

        if ($this->bomPending) {
            if ($len < 3 && !$final && strncmp($buf, "\xEF\xBB\xBF", $len) === 0) {
                return $out; // possibly a split BOM
            }
            $this->bomPending = false;
            if (strncmp($buf, "\xEF\xBB\xBF", 3) === 0) {
                $buf = (string) substr($buf, 3);
                $len = strlen($buf);
                $pos = max(0, $pos - 3);
            }
        }

        while ($pos < $len) {
            if ($this->state === self::QUOTED) {
                $q = $this->closeQuote;
                $pos += strcspn($buf, $this->backslashEscapes ? $q . '\\' : $q, $pos);
                if ($pos >= $len) {
                    break;
                }
                if ($buf[$pos] === '\\') {
                    if ($pos + 1 >= $len && !$final) {
                        break;
                    }
                    $pos += 2;
                    continue;
                }
                // Closing quote — unless doubled ('' "" `` ]]), which is an escaped quote.
                if ($pos + 1 >= $len && !$final) {
                    break;
                }
                if ($pos + 1 < $len && $buf[$pos + 1] === $q) {
                    $pos += 2;
                    continue;
                }
                $pos++;
                $this->state = self::NORMAL;
                continue;
            }

            if ($this->state === self::LINE_COMMENT) {
                $nl = strpos($buf, "\n", $pos);
                if ($nl === false) {
                    $pos = $len;
                    break;
                }
                $pos = $nl; // the newline itself is processed in NORMAL state (GO detection)
                $this->state = self::NORMAL;
                continue;
            }

            if ($this->state === self::BLOCK_COMMENT) {
                $pos += strcspn($buf, $this->nestedComments ? '*/' : '*', $pos);
                if ($pos + 1 >= $len) {
                    if ($final) {
                        $pos = $len;
                    }
                    break;
                }
                if ($buf[$pos] === '*' && $buf[$pos + 1] === '/') {
                    $pos += 2;
                    if (--$this->commentDepth <= 0) {
                        $this->state = self::NORMAL;
                    }
                } elseif ($buf[$pos] === '/' && $buf[$pos + 1] === '*') {
                    $pos += 2;
                    $this->commentDepth++;
                } else {
                    $pos++;
                }
                continue;
            }

            if ($this->state === self::DOLLAR) {
                $close = '$' . $this->dollarTag . '$';
                $found = strpos($buf, $close, $pos);
                if ($found === false) {
                    $pos = $final ? $len : max($pos, $len - strlen($close) + 1);
                    break;
                }
                $pos = $found + strlen($close);
                $this->state = self::NORMAL;
                continue;
            }

            // ---- NORMAL state ----
            if ($this->contentStart < 0) {
                $pos += strspn($buf, " \t\r\n\f\v", $pos);
                if ($pos >= $len) {
                    break;
                }
            }
            $c = $buf[$pos];

            // Comments
            if ($c === '-' || $c === '/' || ($c === '#' && $this->mysql)) {
                $r = $this->commentStart($buf, $pos, $len, $final);
                if ($r === -2) {
                    break;
                }
                if ($r > 0) {
                    if ($this->keepComments && $this->contentStart < 0 && $this->leadStart < 0) {
                        $this->leadStart = $pos;
                    }
                    $pos += $r;
                    continue;
                }
            }

            if ($this->contentStart < 0) {
                // Client commands only valid at the start of a statement.
                if ($this->mysql && ($c === 'D' || $c === 'd')) {
                    $r = $this->delimiterCommand($buf, $pos, $len, $final);
                    if ($r === -2) {
                        break;
                    }
                    if ($r >= 0) {
                        $this->leadStart = -1;
                        $this->resetBlocks();
                        $pos = $r;
                        continue;
                    }
                }
                if ($this->sqlsrv && $this->goBatches && ($c === 'G' || $c === 'g')) {
                    $r = $this->goLine($buf, $pos, $len, $final);
                    if ($r === -2) {
                        break;
                    }
                    if ($r >= 0) {
                        $this->endBatch($out, $buf, $pos, false);
                        $pos = $r;
                        continue;
                    }
                }
            }

            // Delimiter
            $dlen = strlen($this->delimiter);
            if ($c === $this->delimiter[0]) {
                if ($dlen > 1 && $pos + $dlen > $len && !$final
                    && strncmp(substr($buf, $pos), $this->delimiter, $len - $pos) === 0) {
                    break;
                }
                if ($dlen === 1 || substr_compare($buf, $this->delimiter, $pos, $dlen) === 0) {
                    if ($dlen === 1 && $c === ';' && $this->tracking()) {
                        $this->token(false, ';');
                        if ($this->stack !== []) {
                            $pos++; // inside a compound statement body
                            continue;
                        }
                    }
                    $this->emit($out, $buf, $pos);
                    $pos += $dlen;
                    continue;
                }
            }

            if ($this->contentStart < 0) {
                $this->contentStart = $pos;
            }

            // Quotes
            if ($c === "'" || $c === '"' || ($c === '`' && ($this->mysql || $this->sqlite))
                || ($c === '[' && ($this->sqlsrv || $this->sqlite))) {
                $this->closeQuote = $c === '[' ? ']' : $c;
                $this->backslashEscapes = ($this->mysql && $c !== '`')
                    || ($this->pgsql && $c === "'" && $this->isEscapeStringPrefix($buf, $pos));
                $this->state = self::QUOTED;
                if ($this->tracking()) {
                    $this->token(false, 'Q');
                }
                $pos++;
                continue;
            }

            if ($c === '$' && $this->pgsql && !($pos > 0 && $this->isIdentChar($buf[$pos - 1]))) {
                if (preg_match('/\G\$([A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*)?\$/', $buf, $m, 0, $pos)) {
                    $this->dollarTag = $m[1] ?? '';
                    $this->state = self::DOLLAR;
                    if ($this->tracking()) {
                        $this->token(false, 'Q');
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
                if (!$final && preg_match('/\G\$[A-Za-z0-9_\x80-\xFF]*\z/', $buf, $m, 0, $pos)) {
                    break; // possibly an incomplete tag at the chunk boundary
                }
            }

            if ($c === "\n" && $this->sqlsrv && $this->goBatches) {
                $r = $this->goLine($buf, $pos + 1, $len, $final);
                if ($r === -2) {
                    break;
                }
                if ($r >= 0) {
                    $this->endBatch($out, $buf, $pos, false);
                    $pos = $r;
                    continue;
                }
            }

            if ($this->delimiter === ';') {
                if ($this->tracking()) {
                    // Token by token: words feed the block tracker.
                    if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\f" || $c === "\v" || $c === "\n") {
                        $pos++;
                        continue;
                    }
                    $wl = strspn($buf, self::IDENT, $pos);
                    if ($wl > 0) {
                        if ($pos + $wl >= $len && !$final) {
                            break; // the word may continue in the next chunk
                        }
                        $this->token(true, strtoupper(substr($buf, $pos, $wl)));
                        $pos += $wl;
                        continue;
                    }
                    $this->token(false, $c);
                    $pos++;
                    continue;
                }
                if ($this->sqlsrv) {
                    // Outside blocks only BEGIN matters: jump from B to B.
                    if (($c === 'B' || $c === 'b')
                        && !($pos > 0 && ($this->isIdentChar($buf[$pos - 1]) || $buf[$pos - 1] === '.' || $buf[$pos - 1] === '@'))) {
                        $wl = strspn($buf, self::IDENT, $pos);
                        if ($pos + $wl >= $len && !$final) {
                            break;
                        }
                        if ($wl === 5 && strncasecmp(substr($buf, $pos, 5), 'BEGIN', 5) === 0) {
                            $this->pending = 'BEGIN';
                            $this->lastSig = 'BEGIN';
                            $pos += 5;
                            continue; // the next token decides
                        }
                        $pos += $wl;
                    } else {
                        $pos++;
                    }
                    $pos += strcspn($buf, $this->specials() . 'Bb', $pos);
                    continue;
                }
            }

            $pos++;
            $pos += strcspn($buf, $this->specials(), $pos);
        }

        if ($final) {
            $this->endBatch($out, $buf, $len, true);
            $pos = $len;
        }

        // Compact the buffer: keep only the unfinished statement (or batch).
        $base = $this->batchStart >= 0 ? $this->batchStart
            : ($this->leadStart >= 0 ? $this->leadStart : ($this->contentStart >= 0 ? $this->contentStart : $pos));
        if ($base > 0) {
            $this->buffer = (string) substr($buf, $base);
            $pos -= $base;
            if ($this->batchStart >= 0) {
                $this->batchStart -= $base;
            }
            if ($this->contentStart >= 0) {
                $this->contentStart -= $base;
            }
            if ($this->leadStart >= 0) {
                $this->leadStart -= $base;
            }
        } else {
            $this->buffer = $buf;
        }
        $this->pos = $pos;
        return $out;
    }

    /** @param string[] $out */
    private function emit(array &$out, string $buf, int $end): void
    {
        if ($this->contentStart >= 0) {
            $from = $this->leadStart >= 0 ? $this->leadStart : $this->contentStart;
            $stmt = rtrim(substr($buf, $from, $end - $from));
            if ($this->wholeBatches) {
                if ($this->batchStart < 0) {
                    $this->batchStart = $from;
                }
                if ($stmt !== '') {
                    $this->batchStmts[] = $stmt;
                }
            } elseif ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        $this->contentStart = -1;
        $this->leadStart = -1;
        $this->resetBlocks();
    }

    /**
     * End of a GO batch ($eof false) or of the input. With wholeBatches, a batch is one
     * statement once any GO was seen; without GO the ";"-split statements are returned.
     * @param string[] $out
     */
    private function endBatch(array &$out, string $buf, int $end, bool $eof): void
    {
        if (!$this->wholeBatches) {
            $this->emit($out, $buf, $end);
            return;
        }
        if ($eof && !$this->sawGo) {
            $this->emit($out, $buf, $end);
            foreach ($this->batchStmts as $stmt) {
                $out[] = $stmt;
            }
        } else {
            $from = $this->batchStart;
            if ($from < 0 && $this->contentStart >= 0) {
                $from = $this->leadStart >= 0 ? $this->leadStart : $this->contentStart;
            }
            if ($from >= 0) {
                $stmt = rtrim(substr($buf, $from, $end - $from));
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
            }
            $this->sawGo = $this->sawGo || !$eof;
        }
        $this->batchStmts = [];
        $this->batchStart = -1;
        $this->contentStart = -1;
        $this->leadStart = -1;
        $this->resetBlocks();
    }

    /**
     * Detect a comment start at $pos. Returns the number of chars consumed (>0) and switches
     * state, 0 if not a comment, -2 if more input is needed.
     */
    private function commentStart(string $buf, int $pos, int $len, bool $final): int
    {
        $c = $buf[$pos];
        if ($c === '#') {
            $this->state = self::LINE_COMMENT;
            return 1;
        }
        if ($pos + 1 >= $len) {
            return $final ? 0 : -2;
        }
        $n = $buf[$pos + 1];
        if ($c === '-' && $n === '-') {
            if ($this->mysql) {
                // MySQL requires whitespace/control char (or EOF) after "--".
                if ($pos + 2 >= $len) {
                    if (!$final) {
                        return -2;
                    }
                } elseif (ord($buf[$pos + 2]) > 32) {
                    return 0;
                }
            }
            $this->state = self::LINE_COMMENT;
            return 2;
        }
        if ($c === '/' && $n === '*') {
            if ($this->mysql) {
                if ($this->keepComments && $pos + 3 >= $len && !$final && $pos + 2 < $len && $buf[$pos + 2] === 'M') {
                    return -2;
                }
                if ($pos + 2 >= $len && !$final) {
                    return -2;
                }
                if ($pos + 2 < $len && ($buf[$pos + 2] === '!' || $buf[$pos + 2] === '+'
                    || ($this->keepComments && $buf[$pos + 2] === 'M' && substr($buf, $pos + 3, 1) === '!'))) {
                    // Executable comment: part of the statement.
                    if ($this->contentStart < 0) {
                        $this->contentStart = $pos;
                    }
                }
            }
            $this->state = self::BLOCK_COMMENT;
            $this->commentDepth = 1;
            return 2;
        }
        return 0;
    }

    /** MySQL client "DELIMITER x" line. Returns position after the line, -1 if none, -2 need more. */
    private function delimiterCommand(string $buf, int $pos, int $len, bool $final): int
    {
        $head = substr($buf, $pos, 10);
        if (strlen($head) < 10 && !$final && strncasecmp('DELIMITER ', $head, strlen($head)) === 0) {
            return -2;
        }
        if (!preg_match('/^DELIMITER[ \t]/i', $head)) {
            return -1;
        }
        $nl = strpos($buf, "\n", $pos);
        if ($nl === false && !$final) {
            return -2;
        }
        $line = substr($buf, $pos, ($nl === false ? $len : $nl) - $pos);
        if (!preg_match('/^DELIMITER[ \t]+(\S+)/i', $line, $m)) {
            return -1;
        }
        $this->delimiter = $m[1];
        return $nl === false ? $len : $nl + 1;
    }

    /** SQL Server "GO [count]" line starting at $pos. Returns end position, -1 if none, -2 need more. */
    private function goLine(string $buf, int $pos, int $len, bool $final): int
    {
        $window = substr($buf, $pos, 64);
        if (preg_match('/^[ \t]*GO(?:[ \t]+\d+)?[ \t]*(\r?\n|$)/i', $window, $m)) {
            if ($m[1] !== '') {
                return $pos + strlen($m[0]);
            }
            if ($pos + strlen($window) < $len) {
                return -1; // window cut, line is longer than a GO line
            }
            return $final ? $len : -2;
        }
        if (!$final && $pos + strlen($window) >= $len && strlen($window) < 64
            && preg_match('/^[ \t]*(?:G(?:O(?:[ \t]+\d*)?[ \t]*\r?)?)?$/i', $window)) {
            return -2;
        }
        return -1;
    }

    /** PostgreSQL E'…' strings allow backslash escapes. */
    private function isEscapeStringPrefix(string $buf, int $pos): bool
    {
        if ($pos < 1 || ($buf[$pos - 1] !== 'E' && $buf[$pos - 1] !== 'e')) {
            return false;
        }
        return $pos < 2 || !$this->isIdentChar($buf[$pos - 2]);
    }

    private function isIdentChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80;
    }

    /** Characters that may start something interesting in NORMAL state. */
    private function specials(): string
    {
        $s = "'\"-/" . $this->delimiter[0];
        if ($this->mysql) {
            $s .= '#`';
        } elseif ($this->sqlite) {
            $s .= '`[';
        } elseif ($this->sqlsrv) {
            $s .= "[\n";
        } elseif ($this->pgsql) {
            $s .= '$';
        }
        return $s;
    }
}
