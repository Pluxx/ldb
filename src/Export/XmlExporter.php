<?php

declare(strict_types=1);

namespace Ldb\Export;

class XmlExporter extends AbstractExporter
{
    public function mimeType(): string
    {
        return 'application/xml';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public static function optionSpecs(): array
    {
        return [
            ['name' => 'structure', 'type' => 'bool', 'label' => 'Include column definitions', 'default' => true],
            ['name' => 'data', 'type' => 'bool', 'label' => 'Include data', 'default' => true],
        ];
    }

    public function export(array $tables, $out): void
    {
        $structure = $this->flag('structure', true);
        $data = $this->flag('data', true);
        $this->write($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ldb_export version=\"1.0\" generated=\""
            . gmdate('Y-m-d\TH:i:s\Z') . "\">\n");
        foreach ($this->sources($tables) as $src) {
            $this->write($out, '  <table name="' . $this->esc($src['name']) . "\">\n");
            if ($structure && $src['table'] !== null) {
                $info = $this->tableInfoSafe($src['table']);
                $this->write($out, "    <structure>\n");
                foreach (($info['columns'] ?? []) as $c) {
                    $attrs = ' name="' . $this->esc((string) ($c['name'] ?? '')) . '"'
                        . ' type="' . $this->esc((string) ($c['full_type'] ?? $c['type'] ?? '')) . '"'
                        . ' nullable="' . (!empty($c['nullable']) ? 'true' : 'false') . '"'
                        . ' primary="' . (!empty($c['primary']) ? 'true' : 'false') . '"';
                    if (isset($c['default']) && $c['default'] !== null) {
                        $attrs .= ' default="' . $this->esc((string) $c['default']) . '"';
                    }
                    $this->write($out, '      <column' . $attrs . "/>\n");
                }
                $this->write($out, "    </structure>\n");
            }
            if ($data) {
                $this->write($out, "    <data>\n");
                $this->stream($src, function (array $cols) {
                }, function (array $row) use ($out) {
                    $s = '      <row>';
                    foreach ($row as $k => $v) {
                        $v = $this->scalar($v);
                        $name = ' name="' . $this->esc((string) $k) . '"';
                        if ($v === null) {
                            $s .= '<field' . $name . ' null="true"/>';
                            continue;
                        }
                        if (is_bool($v)) {
                            $v = $v ? '1' : '0';
                        } elseif (is_float($v)) {
                            $v = $this->floatStr($v);
                        } else {
                            $v = (string) $v;
                        }
                        if ($this->needsBase64($v)) {
                            $s .= '<field' . $name . ' encoding="base64">' . base64_encode($v) . '</field>';
                        } else {
                            $s .= '<field' . $name . '>' . $this->esc($v) . '</field>';
                        }
                    }
                    $this->write($out, $s . "</row>\n");
                });
                $this->write($out, "    </data>\n");
            }
            $this->write($out, "  </table>\n");
        }
        $this->write($out, "</ldb_export>\n");
    }

    /** XML comment trailer (the document stays unterminated, i.e. not well-formed). */
    public function writeFailure($out, string $message): void
    {
        $msg = str_replace('--', '- -', self::failureMessage($message));
        $this->write($out, "\n<!-- LDB EXPORT FAILED: " . $msg . " -->\n");
    }

    private function needsBase64(string $v): bool
    {
        if ($v === '') {
            return false;
        }
        return !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v) === 1;
    }

    private function esc(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
