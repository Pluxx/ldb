<?php

declare(strict_types=1);

namespace Ldb\Export;

class MarkdownExporter extends AbstractExporter
{
    public function mimeType(): string
    {
        return 'text/markdown; charset=utf-8';
    }

    public function extension(): string
    {
        return 'md';
    }

    public static function optionSpecs(): array
    {
        return [
            ['name' => 'structure', 'type' => 'bool', 'label' => 'Include column overview', 'default' => false],
            ['name' => 'data', 'type' => 'bool', 'label' => 'Include data', 'default' => true],
            ['name' => 'null_as', 'type' => 'text', 'label' => 'Write NULL as', 'default' => 'NULL'],
        ];
    }

    public function export(array $tables, $out): void
    {
        $structure = $this->flag('structure', false);
        $data = $this->flag('data', true);
        $nullAs = (string) $this->opt('null_as', 'NULL');
        foreach ($this->sources($tables) as $src) {
            $this->write($out, '## ' . $this->cell($src['name']) . "\n\n");
            if ($structure && $src['table'] !== null) {
                $info = $this->tableInfoSafe($src['table']);
                $cols = $info['columns'] ?? [];
                if ($cols) {
                    $this->write($out, "| Column | Type | Null | Default | Key |\n| --- | --- | --- | --- | --- |\n");
                    foreach ($cols as $c) {
                        $this->write($out, '| ' . implode(' | ', [
                            $this->cell((string) ($c['name'] ?? '')),
                            $this->cell((string) ($c['full_type'] ?? $c['type'] ?? '')),
                            !empty($c['nullable']) ? 'YES' : 'NO',
                            isset($c['default']) ? $this->cell((string) $c['default']) : '',
                            !empty($c['primary']) ? 'PRI' : '',
                        ]) . " |\n");
                    }
                    $this->write($out, "\n");
                }
            }
            if ($data) {
                $any = false;
                $this->stream($src, function (array $cols) use ($out) {
                    if (!$cols) {
                        return;
                    }
                    $this->write($out, '| ' . implode(' | ', array_map([$this, 'cell'], $cols)) . " |\n");
                    $this->write($out, '|' . str_repeat(' --- |', count($cols)) . "\n");
                }, function (array $row) use ($out, $nullAs, &$any) {
                    $any = true;
                    $cells = [];
                    foreach ($row as $v) {
                        $t = $this->textCell($v);
                        $cells[] = $t === null ? $nullAs : $this->cell($t);
                    }
                    $this->write($out, '| ' . implode(' | ', $cells) . " |\n");
                });
                $this->write($out, "\n");
            }
        }
    }

    private function cell(string $s): string
    {
        $s = str_replace(['\\', '|'], ['\\\\', '\\|'], $s);
        return str_replace(["\r\n", "\r", "\n"], '<br>', $s);
    }
}
