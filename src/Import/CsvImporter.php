<?php

declare(strict_types=1);

namespace Ldb\Import;

use Ldb\Db\DriverInterface;

class CsvImporter
{
    /** @var DriverInterface */
    private $db;
    /** @var array */
    private $options;

    /** id => [label, iconv name] */
    private static $encodings = [
        'UTF-8' => ['Unicode (UTF-8)', 'UTF-8'],
        'UTF-16' => ['Unicode (UTF-16, BOM detected)', 'UTF-16'],
        'UTF-16LE' => ['Unicode (UTF-16LE)', 'UTF-16LE'],
        'UTF-16BE' => ['Unicode (UTF-16BE)', 'UTF-16BE'],
        'SJIS-win' => ['Japanese (Shift_JIS / CP932)', 'CP932'],
        'eucJP-win' => ['Japanese (EUC-JP)', 'EUC-JP'],
        'GB18030' => ['Chinese Simplified (GB18030)', 'GB18030'],
        'BIG-5' => ['Chinese Traditional (Big5)', 'BIG5'],
        'EUC-KR' => ['Korean (EUC-KR)', 'EUC-KR'],
        'UHC' => ['Korean (CP949 / UHC)', 'CP949'],
        'Windows-1258' => ['Vietnamese (Windows-1258)', 'CP1258'],
        'Windows-1251' => ['Cyrillic (Windows-1251)', 'CP1251'],
        'KOI8-R' => ['Cyrillic (KOI8-R)', 'KOI8-R'],
        'KOI8-U' => ['Cyrillic, Ukrainian (KOI8-U)', 'KOI8-U'],
        'Windows-1256' => ['Arabic (Windows-1256)', 'CP1256'],
        'Windows-1250' => ['Central European (Windows-1250)', 'CP1250'],
        'Windows-1252' => ['Western European (Windows-1252)', 'CP1252'],
        'Windows-1253' => ['Greek (Windows-1253)', 'CP1253'],
        'Windows-1254' => ['Turkish (Windows-1254)', 'CP1254'],
        'Windows-1255' => ['Hebrew (Windows-1255)', 'CP1255'],
        'Windows-1257' => ['Baltic (Windows-1257)', 'CP1257'],
        'ISO-8859-1' => ['ISO-8859-1 Latin 1', 'ISO-8859-1'],
        'ISO-8859-2' => ['ISO-8859-2 Latin 2', 'ISO-8859-2'],
        'ISO-8859-3' => ['ISO-8859-3 Latin 3', 'ISO-8859-3'],
        'ISO-8859-4' => ['ISO-8859-4 Latin 4', 'ISO-8859-4'],
        'ISO-8859-5' => ['ISO-8859-5 Cyrillic', 'ISO-8859-5'],
        'ISO-8859-6' => ['ISO-8859-6 Arabic', 'ISO-8859-6'],
        'ISO-8859-7' => ['ISO-8859-7 Greek', 'ISO-8859-7'],
        'ISO-8859-8' => ['ISO-8859-8 Hebrew', 'ISO-8859-8'],
        'ISO-8859-9' => ['ISO-8859-9 Latin 5 (Turkish)', 'ISO-8859-9'],
        'ISO-8859-10' => ['ISO-8859-10 Latin 6', 'ISO-8859-10'],
        'ISO-8859-11' => ['ISO-8859-11 Thai', 'ISO-8859-11'],
        'ISO-8859-13' => ['ISO-8859-13 Latin 7 (Baltic)', 'ISO-8859-13'],
        'ISO-8859-14' => ['ISO-8859-14 Latin 8', 'ISO-8859-14'],
        'ISO-8859-15' => ['ISO-8859-15 Latin 9', 'ISO-8859-15'],
    ];

    public function __construct(DriverInterface $db, array $options = [])
    {
        $this->db = $db;
        $this->options = $options;
    }

    /** @return array list of {id,label} for the UI */
    public static function encodings(): array
    {
        $out = [];
        foreach (self::$encodings as $id => $e) {
            $out[] = ['id' => $id, 'label' => $e[0]];
        }
        return $out;
    }

    /** @return string iconv charset name for an encoding id (case-insensitive, aliases accepted) */
    public static function iconvName(string $enc): string
    {
        $e = trim($enc);
        foreach (self::$encodings as $id => $v) {
            if (strcasecmp($id, $e) === 0 || strcasecmp($v[1], $e) === 0) {
                return $v[1];
            }
        }
        $alias = [
            'shift_jis' => 'CP932', 'sjis' => 'CP932', 'cp932' => 'CP932', 'shift-jis' => 'CP932',
            'euc-jp' => 'EUC-JP', 'big5' => 'BIG5', 'cp949' => 'CP949', 'utf8' => 'UTF-8',
            'cp1251' => 'CP1251', 'cp1258' => 'CP1258',
        ];
        $k = strtolower($e);
        return $alias[$k] ?? $e;
    }

    private function opt(string $k, $default)
    {
        return array_key_exists($k, $this->options) && $this->options[$k] !== null ? $this->options[$k] : $default;
    }

    private function bool(string $k, bool $default): bool
    {
        $v = $this->opt($k, $default);
        if (is_string($v)) {
            return !in_array(strtolower(trim($v)), ['', '0', 'false', 'no', 'off'], true);
        }
        return (bool) $v;
    }

    /**
     * @return array{rows:int,errors:array,aborted:bool,time_ms:float}
     */
    public function importFile(string $path, string $table): array
    {
        @set_time_limit(0);
        $start = microtime(true);
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Import file not readable');
        }

        $delim = (string) $this->opt('delimiter', ',');
        if ($delim === '\\t' || strtolower($delim) === 'tab') {
            $delim = "\t";
        }
        $delim = $delim === '' ? ',' : $delim[0];
        $enclosure = (string) $this->opt('enclosure', '"');
        $enclosure = $enclosure === '' ? '"' : $enclosure[0];
        $escape = (string) $this->opt('escape', '');
        $escape = $escape === '' ? '' : $escape[0];
        $header = $this->bool('header', true);
        $skipRows = max(0, (int) $this->opt('skip_rows', 0));
        $nullAs = $this->opt('null_as', '\\N');
        $emptyAsNull = $this->bool('empty_as_null', false);
        $batchSize = max(1, (int) ($this->options['batch_size'] ?? $this->options['batch'] ?? 500));
        $maxErrors = max(1, (int) $this->opt('max_errors', 50));

        $info = $this->db->tableInfo($table);
        $tableCols = [];
        foreach (($info['columns'] ?? []) as $c) {
            $tableCols[strtolower((string) $c['name'])] = (string) $c['name'];
        }
        if (!$tableCols) {
            throw new \RuntimeException('Table has no columns: ' . $table);
        }

        $fh = $this->openStream($path);
        $line = 0;
        $read = function () use ($fh, $delim, $enclosure, $escape, &$line) {
            $r = fgetcsv($fh, 0, $delim, $enclosure, $escape);
            if ($r !== false) {
                $line++;
            }
            return $r;
        };

        try {
            for ($i = 0; $i < $skipRows; $i++) {
                if ($read() === false) {
                    break;
                }
            }

            // file column index => table column (or null = ignore)
            $mapping = [];
            $explicit = $this->opt('columns', null);
            if (is_array($explicit) && $explicit) {
                if ($header) {
                    $read();
                }
                foreach (array_values($explicit) as $i => $name) {
                    $mapping[$i] = $this->resolveColumn($name, $tableCols);
                }
            } elseif ($header) {
                $h = $read();
                if ($h === false) {
                    return $this->result(0, [], false, $start);
                }
                $unknown = [];
                foreach ($h as $i => $name) {
                    $name = trim($this->convert((string) $name));
                    if ($name === '') {
                        $mapping[$i] = null;
                        continue;
                    }
                    $col = $tableCols[strtolower($name)] ?? null;
                    if ($col === null) {
                        $unknown[] = $name;
                    }
                    $mapping[$i] = $col;
                }
                if ($unknown) {
                    throw new \RuntimeException('Unknown column(s) in CSV header: ' . implode(', ', $unknown));
                }
            } else {
                foreach (array_values($tableCols) as $i => $name) {
                    $mapping[$i] = $name;
                }
            }

            $insertCols = [];
            foreach ($mapping as $i => $col) {
                if ($col !== null) {
                    $insertCols[] = $col;
                }
            }
            if (!$insertCols) {
                throw new \RuntimeException('No CSV column maps to a table column');
            }
            $nc = count($insertCols);
            $mappedIdx = array_keys(array_filter($mapping, function ($c) {
                return $c !== null;
            }));
            $expectedFields = $header && !is_array($explicit) ? count($mapping) : max(array_keys($mapping)) + 1;

            if ($this->bool('truncate_first', false)) {
                $this->db->truncateTable($table);
            }

            $id = $this->db::id();
            $paramLimit = $id === 'sqlite' ? 999 : ($id === 'sqlsrv' ? 2000 : 60000);
            $batchSize = max(1, min($batchSize, intdiv($paramLimit, $nc)));

            $pdo = $this->db->pdo();
            $prefix = 'INSERT INTO ' . $this->db->quoteTable($table) . ' ('
                . implode(', ', array_map(function ($c) {
                    return $this->db->quoteIdentifier($c);
                }, $insertCols)) . ') VALUES ';
            $ph = '(' . implode(', ', array_fill(0, $nc, '?')) . ')';
            $stmts = [];
            $prepare = function (int $n) use (&$stmts, $pdo, $prefix, $ph) {
                if (!isset($stmts[$n])) {
                    if (count($stmts) > 4) {
                        $stmts = [];
                    }
                    $stmts[$n] = $pdo->prepare($prefix . implode(', ', array_fill(0, $n, $ph)));
                }
                return $stmts[$n];
            };

            $rows = 0;
            $errors = [];
            $aborted = false;
            $batch = [];

            $flush = function () use (&$batch, &$rows, &$errors, &$stmts, $pdo, $prepare, $maxErrors, &$aborted) {
                if (!$batch) {
                    return;
                }
                $own = !$pdo->inTransaction();
                try {
                    $flat = [];
                    foreach ($batch as $b) {
                        foreach ($b[1] as $v) {
                            $flat[] = $v;
                        }
                    }
                    if ($own) {
                        $pdo->beginTransaction();
                    }
                    $prepare(count($batch))->execute($flat);
                    if ($own) {
                        $pdo->commit();
                    }
                    $rows += count($batch);
                } catch (\Throwable $e) {
                    unset($stmts[count($batch)]); // PHP 8.4 pdo_sqlite: a failed statement cannot be re-executed
                    if ($own && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (count($batch) === 1) {
                        $errors[] = ['line' => $batch[0][0], 'message' => $e->getMessage()];
                    } else {
                        foreach ($batch as $b) {
                            try {
                                $prepare(1)->execute($b[1]);
                                $rows++;
                            } catch (\Throwable $e2) {
                                unset($stmts[1]); // PHP 8.4 pdo_sqlite: a statement that failed cannot be re-executed
                                $errors[] = ['line' => $b[0], 'message' => $e2->getMessage()];
                                if (count($errors) >= $maxErrors) {
                                    $aborted = true;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (count($errors) >= $maxErrors) {
                    $aborted = true;
                }
                $batch = [];
            };

            while (($rec = $read()) !== false) {
                if ($rec === [null]) {
                    continue; // blank line
                }
                $n = count($rec);
                if ($n !== $expectedFields) {
                    $errors[] = ['line' => $line, 'message' => 'Expected ' . $expectedFields . ' fields, got ' . $n];
                    if (count($errors) >= $maxErrors) {
                        $aborted = true;
                        break;
                    }
                    continue;
                }
                $vals = [];
                foreach ($mappedIdx as $i) {
                    $v = $rec[$i];
                    if ($v !== null) {
                        $v = $this->convert((string) $v);
                    }
                    if ($v === null || ($nullAs !== null && $v === (string) $nullAs) || ($emptyAsNull && $v === '')) {
                        $v = null;
                    }
                    $vals[] = $v;
                }
                $batch[] = [$line, $vals];
                if (count($batch) >= $batchSize) {
                    $flush();
                    if ($aborted) {
                        break;
                    }
                }
            }
            if (!$aborted) {
                $flush();
            }
            return $this->result($rows, $errors, $aborted, $start);
        } finally {
            fclose($fh);
        }
    }

    private function result(int $rows, array $errors, bool $aborted, float $start): array
    {
        usort($errors, function ($a, $b) {
            return $a['line'] <=> $b['line'];
        });
        return [
            'rows' => $rows,
            'errors' => $errors,
            'aborted' => $aborted,
            'time_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    /**
     * @param mixed $name
     * @param array $tableCols
     */
    private function resolveColumn($name, array $tableCols): ?string
    {
        if ($name === null || $name === false) {
            return null;
        }
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $col = $tableCols[strtolower($name)] ?? null;
        if ($col === null) {
            throw new \RuntimeException('Unknown column in mapping: ' . $name);
        }
        return $col;
    }

    /** Fields are already UTF-8 (stream filter); only guard against invalid sequences. */
    private function convert(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /** @return resource */
    private function openStream(string $path)
    {
        $plain = fopen($path, 'rb');
        if ($plain === false) {
            throw new \RuntimeException('Cannot open import file');
        }
        $magic = (string) fread($plain, 2);
        fclose($plain);
        $uri = strncmp($magic, "\x1f\x8b", 2) === 0 ? 'compress.zlib://' . $path : $path;

        $fh = fopen($uri, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open import file');
        }
        $enc = (string) $this->opt('encoding', 'UTF-8');
        $iconv = self::iconvName($enc);
        $isUtf16 = stripos($iconv, 'UTF-16') === 0;

        $head = (string) fread($fh, 3);
        $skip = 0;
        if ($isUtf16) {
            $b2 = substr($head, 0, 2);
            if ($b2 === "\xFF\xFE") {
                $skip = 2;
                if ($iconv === 'UTF-16') {
                    $iconv = 'UTF-16LE';
                }
            } elseif ($b2 === "\xFE\xFF") {
                $skip = 2;
                if ($iconv === 'UTF-16') {
                    $iconv = 'UTF-16BE';
                }
            } elseif ($iconv === 'UTF-16') {
                $iconv = 'UTF-16LE';
            }
        } elseif ($head === "\xEF\xBB\xBF") {
            $skip = 3;
        }
        fseek($fh, $skip, SEEK_SET);
        if ($fh && ftell($fh) !== $skip) {
            // non-seekable edge case: re-open and discard
            fclose($fh);
            $fh = fopen($uri, 'rb');
            if ($skip > 0) {
                fread($fh, $skip);
            }
        }

        if (strcasecmp($iconv, 'UTF-8') !== 0 && strcasecmp($iconv, 'UTF8') !== 0) {
            if (!function_exists('iconv')) {
                throw new \RuntimeException('The iconv extension is required for encoding conversion');
            }
            $f = @stream_filter_append($fh, 'convert.iconv.' . $iconv . '/UTF-8', STREAM_FILTER_READ);
            if ($f === false) {
                throw new \RuntimeException('Unsupported encoding: ' . $enc);
            }
        }
        return $fh;
    }
}
