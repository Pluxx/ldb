<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Export;

use Ldb\Export\ExporterFactory;
use Ldb\Tests\Unit\Support\FakeSqliteDriver;
use PHPUnit\Framework\TestCase;

final class ExportersTest extends TestCase
{
    /** @var FakeSqliteDriver */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakeSqliteDriver();
        $p = $this->db->pdo();
        $p->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, score REAL, data BLOB)');
        $st = $p->prepare('INSERT INTO t VALUES (?,?,?,?)');
        $st->bindValue(1, 1);
        $st->bindValue(2, "O'Reilly; \"x\"\nline2");
        $st->bindValue(3, 1.5);
        $st->bindValue(4, "\xff\x00\xfe", \PDO::PARAM_LOB);
        $st->execute();
        $st->execute([2, '日本語|テスト', null, null]);
        $st->execute([3, 'Привет', 3.0, null]);
        $p->exec('CREATE VIEW v AS SELECT id, name FROM t');
    }

    private function export(string $fmt, array $tables, array $opts = []): string
    {
        $out = fopen('php://memory', 'w+');
        ExporterFactory::create($fmt, $this->db, $opts)->export($tables, $out);
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public function testFormatsMetadata(): void
    {
        $f = ExporterFactory::formats();
        $ids = array_column($f, 'id');
        $this->assertSame(['sql', 'csv', 'json', 'xml', 'markdown', 'excel'], $ids);
        foreach ($f as $fmt) {
            $this->assertArrayHasKey('extension', $fmt);
            foreach ($fmt['options'] as $o) {
                $this->assertContains($o['type'], ['bool', 'select', 'text']);
                $this->assertArrayHasKey('default', $o);
                if ($o['type'] === 'select') {
                    $this->assertNotEmpty($o['choices']);
                }
            }
        }
    }

    public function testSqlRoundTrip(): void
    {
        $sql = $this->export('sql', ['t'], ['drop_table' => true, 'extended_inserts' => 2, 'transaction' => true]);
        $this->assertStringContainsString('DROP TABLE IF EXISTS "t";', $sql);
        $this->assertStringContainsString("X'ff00fe'", $sql);
        $this->assertSame(2, substr_count($sql, 'INSERT INTO'));

        $target = new FakeSqliteDriver();
        $target->pdo()->exec($sql);
        $rows = $target->pdo()->query('SELECT id, name, score, hex(data) h FROM t ORDER BY id')->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertSame("O'Reilly; \"x\"\nline2", $rows[0]['name']);
        $this->assertSame('FF00FE', $rows[0]['h']);
        $this->assertSame('日本語|テスト', $rows[1]['name']);
        $this->assertNull($rows[1]['score']);
    }

    public function testSqlOptionsAndViews(): void
    {
        $sql = $this->export('sql', ['t', 'v'], ['if_not_exists' => true, 'insert_style' => 'insert_ignore', 'data' => true]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('INSERT OR IGNORE INTO "t"', $sql);
        $this->assertStringContainsString('CREATE VIEW', $sql);
        $this->assertStringNotContainsString('INSERT OR IGNORE INTO "v"', $sql);
        $structureOnly = $this->export('sql', ['t'], ['data' => false]);
        $this->assertStringNotContainsString('INSERT', $structureOnly);
        $dataOnly = $this->export('sql', ['t'], ['structure' => false]);
        $this->assertStringNotContainsString('CREATE', $dataOnly);
    }

    public function testSqlQuery(): void
    {
        $sql = $this->export('sql', ['ignored'], ['query' => 'SELECT id FROM t WHERE id < 3;']);
        $this->assertStringContainsString('INSERT INTO "query" ("id")', $sql);
        $this->assertSame(1, substr_count($sql, 'INSERT INTO'));
    }

    public function testCsv(): void
    {
        $csv = $this->export('csv', ['t'], ['bom' => true, 'line_ending' => 'crlf']);
        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        $body = substr($csv, 3);
        $this->assertStringStartsWith("id,name,score,data\r\n", $body);
        $this->assertStringContainsString("\"O'Reilly; \"\"x\"\"\nline2\"", $body);
        $this->assertStringContainsString(base64_encode("\xff\x00\xfe"), $body);
        $this->assertStringContainsString('2,日本語|テスト,\N,\N', $body);

        $parsed = $this->parseCsv($body);
        $this->assertCount(4, $parsed);
        $this->assertSame("O'Reilly; \"x\"\nline2", $parsed[1][1]);
    }

    private function parseCsv(string $s): array
    {
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, str_replace("\r\n", "\n", $s));
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '"')) !== false) {
            $rows[] = $r;
        }
        return $rows;
    }

    public function testCsvOptionsAndMulti(): void
    {
        $csv = $this->export('csv', ['t'], ['delimiter' => ';', 'header' => false, 'null_as' => '']);
        $this->assertStringStartsWith('1;"O', $csv);
        $this->assertStringContainsString("2;日本語|テスト;;\n", $csv);
        $multi = $this->export('csv', ['t', 'v'], ['multi' => true]);
        $this->assertStringContainsString("# t\n", $multi);
        $this->assertStringContainsString("\n# v\n", $multi);
        $this->expectException(\InvalidArgumentException::class);
        $this->export('csv', ['t', 'v']);
    }

    public function testJson(): void
    {
        foreach ([true, false] as $pretty) {
            $j = json_decode($this->export('json', ['t', 'v'], ['pretty' => $pretty]), true);
            $this->assertIsArray($j, 'pretty=' . var_export($pretty, true));
            $this->assertCount(3, $j['t']);
            $this->assertCount(3, $j['v']);
            $this->assertSame(['$binary' => base64_encode("\xff\x00\xfe")], $j['t'][0]['data']);
            $this->assertNull($j['t'][1]['score']);
            $this->assertSame('日本語|テスト', $j['t'][1]['name']);
        }
        $empty = json_decode($this->export('json', ['t'], ['query' => 'SELECT * FROM t WHERE 0']), true);
        $this->assertSame(['query' => []], $empty);
    }

    public function testXml(): void
    {
        $xml = $this->export('xml', ['t']);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
        $x = new \DOMXPath($doc);
        $this->assertSame(4, $x->query('//table[@name="t"]/structure/column')->length);
        $this->assertSame(3, $x->query('//table/data/row')->length);
        $this->assertSame("O'Reilly; \"x\"\nline2", $x->query('//row[1]/field[@name="name"]')->item(0)->textContent);
        $this->assertSame('base64', $x->query('//row[1]/field[@name="data"]')->item(0)->getAttribute('encoding'));
        $this->assertSame('true', $x->query('//row[2]/field[@name="score"]')->item(0)->getAttribute('null'));
        $noStruct = $this->export('xml', ['t'], ['structure' => false]);
        $this->assertStringNotContainsString('<structure>', $noStruct);
    }

    public function testMarkdown(): void
    {
        $md = $this->export('markdown', ['t'], ['structure' => true]);
        $this->assertStringContainsString("## t\n", $md);
        $this->assertStringContainsString('| id | name | score | data |', $md);
        $this->assertStringContainsString('日本語\\|テスト', $md);
        $this->assertStringContainsString('line2', $md);
        $this->assertStringNotContainsString("line2 |\nline2", $md);
        $this->assertStringContainsString('<br>', $md);
        $this->assertStringContainsString('| NULL |', $md);
        $this->assertStringContainsString('| Column | Type |', $md);
    }

    public function testExcelXml(): void
    {
        $xml = $this->export('excel', ['t', 'v']);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
        $this->assertSame(2, $doc->getElementsByTagName('Worksheet')->length);
        $this->assertSame(8, $doc->getElementsByTagName('Row')->length);
        $this->assertStringContainsString('<Data ss:Type="Number">1.5</Data>', $xml);
        $this->assertStringContainsString('日本語|テスト', $xml);
    }

    public function testUnknownFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExporterFactory::create('nope', $this->db, []);
    }
}
