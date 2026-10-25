<?php

declare(strict_types=1);

namespace Ldb\Export;

/**
 * SpreadsheetML 2003 (.xml) readable by Excel and LibreOffice; one worksheet per table.
 */
class ExcelXmlExporter extends AbstractExporter
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
            ['name' => 'header', 'type' => 'bool', 'label' => 'First row contains column names', 'default' => true],
        ];
    }

    public function export(array $tables, $out): void
    {
        $header = $this->flag('header', true);
        $data = $this->flag('data', true);
        $this->write($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?mso-application progid=\"Excel.Sheet\"?>\n"
            . "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:o=\"urn:schemas-microsoft-com:office:office\""
            . " xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n"
            . " <Styles><Style ss:ID=\"h\"><Font ss:Bold=\"1\"/></Style></Styles>\n");
        $used = [];
        foreach ($this->sources($tables) as $src) {
            $sheet = $this->sheetName($src['name'], $used);
            $this->write($out, ' <Worksheet ss:Name="' . $this->esc($sheet) . "\">\n  <Table>\n");
            $this->stream($src, function (array $cols) use ($out, $header) {
                if ($header && $cols) {
                    $row = '   <Row>';
                    foreach ($cols as $c) {
                        $row .= '<Cell ss:StyleID="h"><Data ss:Type="String">' . $this->esc($c) . '</Data></Cell>';
                    }
                    $this->write($out, $row . "</Row>\n");
                }
            }, function (array $r) use ($out, $data) {
                if (!$data) {
                    return;
                }
                $row = '   <Row>';
                foreach ($r as $v) {
                    $v = $this->scalar($v);
                    if ($v === null) {
                        $row .= '<Cell/>';
                    } elseif (is_int($v) || is_float($v)) {
                        $s = is_int($v) ? (string) $v : $this->floatStr($v);
                        $row .= $s === '' ? '<Cell/>' : '<Cell><Data ss:Type="Number">' . $s . '</Data></Cell>';
                    } else {
                        $s = $this->textCell($v);
                        if ($s !== null && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,14})?$/', $s) === 1) {
                            $row .= '<Cell><Data ss:Type="Number">' . $s . '</Data></Cell>';
                        } else {
                            $row .= '<Cell><Data ss:Type="String">' . $this->esc((string) $s) . '</Data></Cell>';
                        }
                    }
                }
                $this->write($out, $row . "</Row>\n");
            });
            $this->write($out, "  </Table>\n </Worksheet>\n");
        }
        $this->write($out, "</Workbook>\n");
    }

    /** @param string[] $used */
    private function sheetName(string $name, array &$used): string
    {
        $n = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', '_', $name), "' ");
        if ($n === '') {
            $n = 'Sheet';
        }
        $base = mb_substr($n, 0, 31);
        $cand = $base;
        $i = 2;
        while (isset($used[mb_strtolower($cand)])) {
            $suffix = '_' . $i++;
            $cand = mb_substr($base, 0, 31 - strlen($suffix)) . $suffix;
        }
        $used[mb_strtolower($cand)] = true;
        return $cand;
    }

    private function esc(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace("\n", '&#10;', htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** XML comment trailer (the document stays unterminated, i.e. not well-formed). */
    public function writeFailure($out, string $message): void
    {
        $msg = str_replace('--', '- -', self::failureMessage($message));
        $this->write($out, "\n<!-- LDB EXPORT FAILED: " . $msg . " -->\n");
    }
}
