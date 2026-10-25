<?php

declare(strict_types=1);

namespace Ldb\Export;

use Ldb\Db\DriverInterface;
use PDO;

/**
 * Shared plumbing for exporters: option access, safe writing, streaming row reads, value helpers.
 */
abstract class AbstractExporter implements ExporterInterface
{
    /** @var DriverInterface */
    protected $db;

    /** @var array */
    protected $options;

    /** @var string */
    protected $driverId;

    public function __construct(DriverInterface $db, array $options)
    {
        $this->db = $db;
        $this->options = $options;
        $this->driverId = $db::id();
    }

    /**
     * Option specs ([{name,type,label,default,choices?}]) of this exporter.
     *
     * @return array
     */
    public static function optionSpecs(): array
    {
        return [];
    }

    /** @param mixed $default */
    protected function opt(string $key, $default = null)
    {
        if (array_key_exists($key, $this->options) && $this->options[$key] !== null) {
            return $this->options[$key];
        }
        return $default;
    }

    protected function flag(string $key, bool $default): bool
    {
        $v = $this->opt($key, $default);
        if (is_string($v)) {
            return !in_array(strtolower(trim($v)), ['', '0', 'false', 'no', 'off'], true);
        }
        return (bool) $v;
    }

    /** @var bool */
    private $written = false;

    public function hasWritten(): bool
    {
        return $this->written;
    }

    /**
     * Default trailer (CSV, Markdown): a final "# LDB EXPORT FAILED: ..." line.
     */
    public function writeFailure($out, string $message): void
    {
        $this->write($out, "\n# LDB EXPORT FAILED: " . self::failureMessage($message) . "\n");
    }

    /**
     * Single-line, UTF-8 safe, credential-free text of at most $max characters for failure trailers.
     */
    public static function failureMessage(string $message, int $max = 300): string
    {
        if (!mb_check_encoding($message, 'UTF-8')) {
            $message = (string) mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        }
        // Credentials: key=value pairs, user:pass@ in URLs/DSNs, IDENTIFIED BY '...'.
        $message = (string) preg_replace('/\b(password|passwd|pwd|pass|secret|token|api[_-]?key)(\s*[=:]\s*)(\'[^\']*\'|"[^"]*"|[^\s,;&]+)/i', '$1$2***', $message);
        $message = (string) preg_replace('~(://[^/\s:@]*):[^/\s@]*@~', '$1:***@', $message);
        $message = (string) preg_replace('/\bIDENTIFIED\s+(?:WITH\s+\w+\s+)?BY\s+(?:\'[^\']*\'|"[^"]*")/i', 'IDENTIFIED BY ***', $message);
        // One line: control characters (incl. newlines) and Unicode line/paragraph separators become spaces.
        $message = (string) preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]+/u', ' ', $message);
        $message = trim((string) preg_replace('/\s{2,}/u', ' ', $message));
        if ($message === '') {
            $message = 'unknown error';
        }
        if (mb_strlen($message, 'UTF-8') > $max) {
            $message = rtrim(mb_substr($message, 0, $max - 1, 'UTF-8')) . "\u{2026}";
        }
        return $message;
    }

    /** @param resource $out */
    protected function write($out, string $s): void
    {
        $this->written = $this->written || $s !== '';
        $len = strlen($s);
        $done = 0;
        while ($done < $len) {
            $w = fwrite($out, $done === 0 ? $s : substr($s, $done));
            if ($w === false || $w === 0) {
                throw new \RuntimeException('Writing export output failed');
            }
            $done += $w;
        }
    }

    /**
     * @param string[] $tables
     * @return array list of ['name'=>string,'sql'=>string,'table'=>?string]
     */
    protected function sources(array $tables): array
    {
        $query = trim((string) $this->opt('query', ''));
        if ($query !== '') {
            $query = rtrim($query, "; \t\r\n");
            return [['name' => 'query', 'sql' => $query, 'table' => null]];
        }
        $where = trim((string) $this->opt('where', ''));
        $list = [];
        foreach ($tables as $t) {
            $t = (string) $t;
            $sql = 'SELECT * FROM ' . $this->db->quoteTable($t);
            if ($where !== '' && count($tables) === 1) {
                $sql .= ' WHERE ' . $where;
            }
            $list[] = ['name' => $t, 'sql' => $sql, 'table' => $t];
        }
        return $list;
    }

    /**
     * Stream rows of a source. $onColumns(string[] $names) is called once before the first row.
     *
     * @param array    $source
     * @param callable $onColumns
     * @param callable $onRow function(array $assocRow): void
     */
    protected function stream(array $source, callable $onColumns, callable $onRow): void
    {
        $pdo = $this->db->pdo();
        $restore = null;
        if ($this->driverId === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            try {
                $cur = $pdo->getAttribute(constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'));
                $restore = $cur === null ? true : (bool) $cur;
                $pdo->setAttribute(constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), false);
            } catch (\Throwable $e) {
                $restore = null;
            }
        }
        try {
            $st = $pdo->query($source['sql']);
            if ($st === false) {
                throw new \RuntimeException('Query failed: ' . $source['sql']);
            }
            $names = [];
            try {
                $n = $st->columnCount();
                for ($i = 0; $i < $n; $i++) {
                    $meta = $st->getColumnMeta($i);
                    if (!is_array($meta) || !isset($meta['name'])) {
                        $names = [];
                        break;
                    }
                    $names[] = (string) $meta['name'];
                }
            } catch (\Throwable $e) {
                $names = [];
            }
            $started = false;
            if ($names) {
                $onColumns($names);
                $started = true;
            }
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!$started) {
                    $onColumns(array_map('strval', array_keys($row)));
                    $started = true;
                }
                $onRow($row);
            }
            $st->closeCursor();
            if (!$started) {
                $onColumns([]);
            }
        } finally {
            if ($restore !== null) {
                try {
                    $pdo->setAttribute(constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), $restore);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }

    /**
     * Resolve LOB streams (pgsql bytea).
     *
     * @param mixed $v
     * @return mixed
     */
    protected function scalar($v)
    {
        if (is_resource($v)) {
            $c = stream_get_contents($v);
            return $c === false ? '' : $c;
        }
        return $v;
    }

    /** @param mixed $v */
    protected function isBinary($v): bool
    {
        return is_string($v) && $v !== '' && !mb_check_encoding($v, 'UTF-8');
    }

    protected function floatStr(float $f): string
    {
        if (is_nan($f) || is_infinite($f)) {
            return '';
        }
        $s = var_export($f, true);
        return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
    }

    /**
     * Text form of a cell for text formats; null stays null, binary becomes base64.
     *
     * @param mixed $v
     */
    protected function textCell($v): ?string
    {
        $v = $this->scalar($v);
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return $this->floatStr($v);
        }
        $v = (string) $v;
        if ($this->isBinary($v)) {
            return base64_encode($v);
        }
        return $v;
    }

    /** @return string[] column names of a table's TableInfo (empty on failure) */
    protected function tableInfoSafe(string $table): array
    {
        try {
            $info = $this->db->tableInfo($table);
            return is_array($info) ? $info : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
