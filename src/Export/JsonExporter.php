<?php

declare(strict_types=1);

namespace Ldb\Export;

class JsonExporter extends AbstractExporter
{
    public function mimeType(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public static function optionSpecs(): array
    {
        return [
            ['name' => 'pretty', 'type' => 'bool', 'label' => 'Pretty print', 'default' => true],
        ];
    }

    public function export(array $tables, $out): void
    {
        $pretty = $this->flag('pretty', true);
        $data = $this->flag('data', true);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $nl = $pretty ? "\n" : '';
        $this->write($out, '{' . $nl);
        $firstTable = true;
        foreach ($this->sources($tables) as $src) {
            $key = json_encode($src['name'], $flags);
            $this->write($out, ($firstTable ? '' : ',' . $nl) . ($pretty ? '  ' : '') . $key . ($pretty ? ': [' : ':['));
            $firstTable = false;
            $firstRow = true;
            if ($data) {
                $this->stream($src, function (array $cols) {
                }, function (array $row) use ($out, &$firstRow, $pretty, $flags) {
                    $obj = [];
                    foreach ($row as $k => $v) {
                        $obj[(string) $k] = $this->jsonValue($v);
                    }
                    if (!$obj) {
                        $enc = '{}';
                    } else {
                        $enc = json_encode($obj, $flags | ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_PARTIAL_OUTPUT_ON_ERROR);
                        if ($pretty) {
                            $enc = str_replace("\n", "\n    ", $enc);
                        }
                    }
                    $this->write($out, ($firstRow ? '' : ',') . ($pretty ? "\n    " : '') . $enc);
                    $firstRow = false;
                });
            }
            $this->write($out, ($pretty && !$firstRow ? "\n  " : '') . ']');
        }
        $this->write($out, $nl . '}' . $nl);
    }

    /** Trailing "/* ... *\/" comment: never valid JSON, so a truncated document cannot parse. */
    public function writeFailure($out, string $message): void
    {
        $msg = str_replace('*/', '* /', self::failureMessage($message));
        $this->write($out, "\n/* LDB EXPORT FAILED: " . $msg . " */\n");
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private function jsonValue($v)
    {
        $v = $this->scalar($v);
        if ($v === null || is_bool($v) || is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (is_nan($v) || is_infinite($v)) ? null : $v;
        }
        $v = (string) $v;
        if ($this->isBinary($v)) {
            return ['$binary' => base64_encode($v)];
        }
        return $v;
    }
}
