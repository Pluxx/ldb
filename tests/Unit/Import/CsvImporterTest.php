<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Import;

use Ldb\Import\CsvImporter;
use Ldb\Tests\Unit\Support\FakeSqliteDriver;
use PHPUnit\Framework\TestCase;

final class CsvImporterTest extends TestCase
{
    /** @var FakeSqliteDriver */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakeSqliteDriver();
        $this->db->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
    }

    private function imp(string $content, array $opts = []): array
    {
        $f = tempnam(sys_get_temp_dir(), 'oph');
        file_put_contents($f, $content);
        try {
            return (new CsvImporter($this->db, $opts))->importFile($f, 't');
        } finally {
            unlink($f);
        }
    }

    private function names(): array
    {
        return $this->db->pdo()->query('SELECT name FROM t ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testHeaderQuotesNullAndBom(): void
    {
        $r = $this->imp("\xEF\xBB\xBFid,name,note\n1,\"a,\"\"b\"\"\",\"line1\nline2\"\n2,x,\\N\n");
        $this->assertSame(2, $r['rows']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('a,"b"', $this->names()[0]);
        $this->assertNull($this->db->pdo()->query('SELECT note FROM t WHERE id=2')->fetchColumn());
        $this->assertSame("line1\nline2", $this->db->pdo()->query('SELECT note FROM t WHERE id=1')->fetchColumn());
    }

    public function testUnknownHeaderColumnThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bogus');
        $this->imp("id,bogus\n1,2\n");
    }

    public function testNoHeaderSkipRowsAndSemicolon(): void
    {
        $r = $this->imp("junk\n1;a;n\n2;b;m\n", ['header' => false, 'skip_rows' => 1, 'delimiter' => ';']);
        $this->assertSame(2, $r['rows']);
        $this->assertSame(['a', 'b'], $this->names());
    }

    public function testExplicitColumnsMappingWithIgnoredColumn(): void
    {
        $r = $this->imp("a,b,c\nIGNORED,5,five\n", ['columns' => ['', 'id', 'name']]);
        $this->assertSame(1, $r['rows']);
        $this->assertSame(['five'], $this->names());
    }

    public function testErrorsAreReportedPerLine(): void
    {
        $r = $this->imp("id,name\n1,a\n1,dup\n2,b\n3\n", ['batch_size' => 10]);
        $this->assertSame(2, $r['rows']);
        $this->assertCount(2, $r['errors']);
        $this->assertSame(3, $r['errors'][0]['line']);
        $this->assertSame(5, $r['errors'][1]['line']);
        $this->assertSame(['a', 'b'], $this->names());
    }

    public function testTruncateFirstAndBatches(): void
    {
        $this->db->pdo()->exec("INSERT INTO t VALUES (99,'old',NULL)");
        $csv = "id,name\n";
        for ($i = 1; $i <= 25; $i++) {
            $csv .= "$i,n$i\n";
        }
        $r = $this->imp($csv, ['truncate_first' => true, 'batch_size' => 7]);
        $this->assertSame(25, $r['rows']);
        $this->assertSame(25, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testShiftJis(): void
    {
        $utf = "id,name\n1,日本語のテスト\n2,表計算\n";
        $r = $this->imp(mb_convert_encoding($utf, 'SJIS-win', 'UTF-8'), ['encoding' => 'SJIS-win']);
        $this->assertSame(2, $r['rows']);
        $this->assertSame(['日本語のテスト', '表計算'], $this->names());
    }

    public function testCp1251(): void
    {
        $utf = "id,name\n1,Привет мир\n2,Москва\n";
        $r = $this->imp(mb_convert_encoding($utf, 'Windows-1251', 'UTF-8'), ['encoding' => 'Windows-1251']);
        $this->assertSame(2, $r['rows']);
        $this->assertSame(['Привет мир', 'Москва'], $this->names());
    }

    public function testUtf16WithBom(): void
    {
        $utf = "id,name\n1,한국어\n2,中文\n";
        $r = $this->imp("\xFF\xFE" . mb_convert_encoding($utf, 'UTF-16LE', 'UTF-8'), ['encoding' => 'UTF-16']);
        $this->assertSame(2, $r['rows']);
        $this->assertSame(['한국어', '中文'], $this->names());
    }

    public function testEncodingListHasIds(): void
    {
        $ids = array_column(CsvImporter::encodings(), 'id');
        foreach (['UTF-8', 'UTF-16LE', 'SJIS-win', 'eucJP-win', 'GB18030', 'BIG-5', 'UHC', 'Windows-1258', 'Windows-1251', 'KOI8-R', 'ISO-8859-15'] as $id) {
            $this->assertContains($id, $ids);
        }
    }
}
