<?php

declare(strict_types=1);

namespace Ldb\Export;

class CsvExporter extends AbstractExporter
{
    public function mimeType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public static function optionSpecs(): array
    {
        return [
            ['name' => 'delimiter', 'type' => 'select', 'label' => 'Delimiter', 'default' => ',', 'choices' => [
                ['value' => ',', 'label' => 'Comma (,)'],
                ['value' => ';', 'label' => 'Semicolon (;)'],
                ['value' => "\t", 'label' => 'Tab'],
                ['value' => '|', 'label' => 'Pipe (|)'],
            ]],
            ['name' => 'enclosure', 'type' => 'text', 'label' => 'Enclosure', 'default' => '"'],
            ['name' => 'escape', 'type' => 'text', 'label' => 'Escape character (empty = double the enclosure)', 'default' => ''],
            ['name' => 'line_ending', 'type' => 'select', 'label' => 'Line ending', 'default' => 'lf', 'choices' => [
                ['value' => 'lf', 'label' => 'LF (\\n)'],
                ['value' => 'crlf', 'label' => 'CRLF (\\r\\n)'],
            ]],
            ['name' => 'header', 'type' => 'bool', 'label' => 'First row contains column names', 'default' => true],
            ['name' => 'null_as', 'type' => 'text', 'label' => 'Write NULL as', 'default' => '\\N'],
            ['name' => 'bom', 'type' => 'bool', 'label' => 'UTF-8 BOM (for Excel)', 'default' => false],
            ['name' => 'multi', 'type' => 'bool', 'label' => 'Allow several tables (sections)', 'default' => false],
            ['name' => 'excel_safe', 'type' => 'bool', 'label' => 'Neutralise spreadsheet formulas (prefix =, +, -, @ with \')', 'default' => true],
        ];
    }

    public function export(array $tables, $out): void
    {
        $sources = $this->sources($tables);
        $multi = $this->flag('multi', false);
        if (count($sources) > 1 && !$multi) {
            throw new \InvalidArgumentException('CSV export supports a single table (enable "multi" for several).');
        }

        $delim = (string) $this->opt('delimiter', ',');
        if ($delim === '\\t' || strtolower($delim) === 'tab') {
            $delim = "\t";
        }
        $delim = $delim === '' ? ',' : $delim;
        $enc = (string) $this->opt('enclosure', '"');
        $esc = (string) $this->opt('escape', '');
        $le = strtolower((string) $this->opt('line_ending', 'lf'));
        $eol = ($le === 'crlf' || $le === "\r\n") ? "\r\n" : "\n";
        $header = $this->flag('header', true);
        $nullAs = (string) $this->opt('null_as', '\\N');
        $data = $this->flag('data', true);
        $safe = $this->flag('excel_safe', true);

        if ($this->flag('bom', false)) {
            $this->write($out, "\xEF\xBB\xBF");
        }

        $first = true;
        foreach ($sources as $src) {
            if (!$first) {
                $this->write($out, $eol);
            }
            if (count($sources) > 1) {
                $this->write($out, '# ' . str_replace(["\r", "\n"], ' ', $src['name']) . $eol);
            }
            $first = false;

            $fmt = function (array $cells) use ($delim, $enc, $esc, $nullAs, $safe): string {
                $parts = [];
                foreach ($cells as $c) {
                    if ($c === null) {
                        $parts[] = $nullAs;
                        continue;
                    }
                    $c = (string) $c;
                    $parts[] = $this->field($safe ? self::neutraliseFormula($c) : $c, $delim, $enc, $esc);
                }
                return implode($delim, $parts);
            };

            $this->stream($src, function (array $cols) use ($out, $header, $fmt, $eol) {
                if ($header) {
                    $this->write($out, $fmt($cols) . $eol);
                }
            }, function (array $row) use ($out, $fmt, $eol, $data) {
                if (!$data) {
                    return;
                }
                $cells = [];
                foreach ($row as $v) {
                    $cells[] = $this->textCell($v);
                }
                $this->write($out, $fmt($cells) . $eol);
            });
        }
    }

    /**
     * CSV/formula injection: spreadsheets evaluate cells starting with = + - @ (also after a tab
     * or CR), e.g. =HYPERLINK(…) or DDE payloads stored by an attacker in the database.
     * Such cells get a leading apostrophe; plain numbers like -5 are left alone.
     */
    public static function neutraliseFormula(string $s): string
    {
        if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }

    private function field(string $s, string $delim, string $enc, string $esc): string
    {
        if ($enc === '') {
            return $s;
        }
        $need = $s === '' ? false : (strpos($s, $delim) !== false || strpos($s, $enc) !== false
            || strpos($s, "\n") !== false || strpos($s, "\r") !== false
            || ($esc !== '' && strpos($s, $esc) !== false));
        if (!$need) {
            return $s;
        }
        if ($esc === '' || $esc === $enc) {
            $s = str_replace($enc, $enc . $enc, $s);
        } else {
            $s = str_replace($esc, $esc . $esc, $s);
            $s = str_replace($enc, $esc . $enc, $s);
        }
        return $enc . $s . $enc;
    }
}
