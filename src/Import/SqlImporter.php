<?php

declare(strict_types=1);

namespace Ldb\Import;

use Ldb\Db\DriverInterface;
use Ldb\Sql\SqlSplitter;

class SqlImporter
{
    /** @var DriverInterface */
    private $db;
    /** @var array */
    private $options;

    public function __construct(DriverInterface $db, array $options = [])
    {
        $this->db = $db;
        $this->options = $options;
    }

    /**
     * @return array{statements:int,skipped:int,position:int,aborted:bool,errors:array,time_ms:float}
     */
    public function importFile(string $path): array
    {
        @set_time_limit(0);
        $start = microtime(true);
        $stopOnError = !empty($this->options['stop_on_error']);
        $skip = max(0, (int) ($this->options['skip_statements'] ?? 0));
        $maxErrors = max(1, (int) ($this->options['max_errors'] ?? 50));
        $id = $this->db::id();

        $handle = $this->open($path);
        // Import semantics: leading comments stay with their statement, flat block comments; "GO" separators only for SQL Server.
        $splitter = new SqlSplitter($id, [
            'keepLeadingComments' => true,
            'stripBom' => true,
            'go' => $id === 'sqlsrv',
            'wholeBatches' => false, // keep splitting at ";" inside GO batches (bounded memory, per-statement errors)
            'nestedComments' => false,
        ]);

        $pdo = $this->db->pdo();
        $executed = 0;
        $skipped = 0;
        $errors = [];
        $aborted = false;
        try {
            foreach ($splitter->splitReader($handle['read']) as $sql) {
                if ($skipped < $skip) {
                    $skipped++;
                    continue;
                }
                $executed++;
                try {
                    $this->run($pdo, $sql);
                } catch (\Throwable $e) {
                    $errors[] = ['statement' => $this->preview($sql), 'message' => $e->getMessage()];
                    if ($stopOnError || count($errors) >= $maxErrors) {
                        $aborted = true;
                        break;
                    }
                }
            }
        } finally {
            call_user_func($handle['close']);
        }

        return [
            'statements' => $executed,
            'skipped' => $skipped,
            'position' => $skipped + $executed,
            'aborted' => $aborted,
            'errors' => $errors,
            'time_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    private function preview(string $sql): string
    {
        if (!mb_check_encoding($sql, 'UTF-8')) {
            $sql = (string) mb_convert_encoding($sql, 'UTF-8', 'UTF-8');
        }
        return mb_substr($sql, 0, 300, 'UTF-8');
    }

    private function run(\PDO $pdo, string $sql): void
    {
        if ($this->returnsRows($sql)) {
            $st = $pdo->query($sql);
            if ($st === false) {
                $ei = $pdo->errorInfo();
                throw new \RuntimeException((string) ($ei[2] ?? 'Statement failed'));
            }
            try {
                do {
                    // drain
                } while ($st->nextRowset());
            } catch (\Throwable $e) {
                // drivers without multiple rowsets
            }
            $st->closeCursor();
            return;
        }
        if ($pdo->exec($sql) === false) {
            $ei = $pdo->errorInfo();
            throw new \RuntimeException((string) ($ei[2] ?? 'Statement failed'));
        }
    }

    private function returnsRows(string $sql): bool
    {
        if (!preg_match('/^(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*(?!\!).*?\*\/)*([A-Za-z]+)/s', $sql, $m)) {
            return false;
        }
        return in_array(strtolower($m[1]), ['select', 'show', 'describe', 'desc', 'explain', 'with', 'pragma', 'call', 'values', 'table', 'exec', 'execute'], true);
    }

    /** @return array{read:callable,close:callable} */
    private function open(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Import file not readable: ' . $path);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open import file');
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);

        if (strncmp($magic, "\x1f\x8b", 2) === 0) {
            if (!function_exists('gzopen')) {
                throw new \RuntimeException('zlib extension is required for .gz files');
            }
            $gz = gzopen($path, 'rb');
            if ($gz === false) {
                throw new \RuntimeException('Cannot open gzip file');
            }
            return [
                'read' => static function (int $n) use ($gz) {
                    return gzeof($gz) ? '' : gzread($gz, $n);
                },
                'close' => static function () use ($gz) {
                    gzclose($gz);
                },
            ];
        }
        if (strncmp($magic, "PK\x03\x04", 4) === 0) {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive (ext-zip) is required for .zip files');
            }
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true || $zip->numFiles < 1) {
                throw new \RuntimeException('Cannot open zip file');
            }
            $name = $zip->getNameIndex(0);
            $stream = $zip->getStream((string) $name);
            if (!is_resource($stream)) {
                throw new \RuntimeException('Cannot read first zip entry');
            }
            return [
                'read' => static function (int $n) use ($stream) {
                    return feof($stream) ? '' : fread($stream, $n);
                },
                'close' => static function () use ($stream, $zip) {
                    fclose($stream);
                    $zip->close();
                },
            ];
        }
        if (strncmp($magic, 'BZh', 3) === 0) {
            if (!function_exists('bzopen')) {
                throw new \RuntimeException('bz2 extension is required for .bz2 files');
            }
            $bz = bzopen($path, 'r');
            if ($bz === false) {
                throw new \RuntimeException('Cannot open bzip2 file');
            }
            return [
                'read' => static function (int $n) use ($bz) {
                    $d = bzread($bz, $n);
                    return is_string($d) ? $d : '';
                },
                'close' => static function () use ($bz) {
                    bzclose($bz);
                },
            ];
        }
        $fh = fopen($path, 'rb');
        return [
            'read' => static function (int $n) use ($fh) {
                return feof($fh) ? '' : fread($fh, $n);
            },
            'close' => static function () use ($fh) {
                fclose($fh);
            },
        ];
    }
}
