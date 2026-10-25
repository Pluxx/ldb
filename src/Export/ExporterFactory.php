<?php

declare(strict_types=1);

namespace Ldb\Export;

use Ldb\Db\DriverInterface;

final class ExporterFactory
{
    /** @var array<string,array{0:string,1:string,2:string}> id => [class, label, extension] */
    private static $map = [
        'sql' => [SqlExporter::class, 'SQL', 'sql'],
        'csv' => [CsvExporter::class, 'CSV', 'csv'],
        'json' => [JsonExporter::class, 'JSON', 'json'],
        'xml' => [XmlExporter::class, 'XML', 'xml'],
        'markdown' => [MarkdownExporter::class, 'Markdown', 'md'],
        'excel' => [ExcelXmlExporter::class, 'Excel (SpreadsheetML XML)', 'xml'],
    ];

    public static function create(string $format, DriverInterface $db, array $options): ExporterInterface
    {
        $format = strtolower($format);
        if ($format === 'md') {
            $format = 'markdown';
        }
        if (!isset(self::$map[$format])) {
            throw new \InvalidArgumentException('Unknown export format: ' . $format);
        }
        $class = self::$map[$format][0];
        return new $class($db, $options);
    }

    /**
     * @return array list of {id,label,extension,options:[{name,type,label,default,choices?}]}
     */
    public static function formats(): array
    {
        $out = [];
        foreach (self::$map as $id => $m) {
            $class = $m[0];
            $out[] = [
                'id' => $id,
                'label' => $m[1],
                'extension' => $m[2],
                'options' => $class::optionSpecs(),
            ];
        }
        return $out;
    }
}
