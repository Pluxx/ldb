<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Export;

use Ldb\Controller\ExportController;
use Ldb\Export\AbstractExporter;
use Ldb\Export\ExporterFactory;
use Ldb\Tests\Unit\Support\FakeSqliteDriver;
use PHPUnit\Framework\TestCase;

final class ExportFailureTest extends TestCase
{
    /** @var FakeSqliteDriver */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakeSqliteDriver();
        $this->db->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->pdo()->exec("INSERT INTO t VALUES (1, 'a'), (2, 'b')");
    }

    /** Export [t, missing]: the second source fails after the first was streamed. */
    private function failing(string $fmt, array $opts = []): string
    {
        $out = fopen('php://memory', 'w+');
        $ex = ExporterFactory::create($fmt, $this->db, $opts + ['multi' => true]);
        $log = ini_set('error_log', '/dev/null');
        try {
            ExportController::runExport($ex, ['t', 'missing_table'], $out);
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertTrue($ex->hasWritten());
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** @return array<string,array{0:string}> */
    public function formatProvider(): array
    {
        return ['sql' => ['sql'], 'csv' => ['csv'], 'json' => ['json'], 'xml' => ['xml'], 'markdown' => ['markdown'], 'excel' => ['excel']];
    }

    /** @dataProvider formatProvider */
    public function testEveryFormatEndsWithMarker(string $fmt): void
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            $body = $this->failing($fmt);
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertStringContainsString('LDB EXPORT FAILED: ', $body);
        $tail = substr($body, (int) strrpos($body, 'LDB EXPORT FAILED'));
        $this->assertMatchesRegularExpression('/\ALDB EXPORT FAILED: [^\n]+/', $tail, 'marker message is a single line');
        $this->assertStringContainsString('missing_table', $body);
    }

    public function testSqlTrailerIsSyntaxError(): void
    {
        $body = $this->failing('sql');
        $this->assertMatchesRegularExpression('/\n-- LDB EXPORT FAILED: [^\n]+\nLDB_EXPORT_FAILED;\n\z/', $body);
        $target = new FakeSqliteDriver();
        $this->expectException(\PDOException::class);
        $target->pdo()->exec($body);
    }

    public function testCsvAndMarkdownTrailer(): void
    {
        foreach (['csv', 'markdown'] as $fmt) {
            $body = $this->failing($fmt);
            $this->assertMatchesRegularExpression('/\n# LDB EXPORT FAILED: [^\n]+\n\z/', $body, $fmt);
        }
    }

    public function testJsonTrailerKeepsDocumentInvalid(): void
    {
        $body = $this->failing('json');
        $this->assertMatchesRegularExpression('~\n/\* LDB EXPORT FAILED: [^\n]+ \*/\n\z~', $body);
        $this->assertNull(json_decode($body));
    }

    public function testXmlTrailerIsComment(): void
    {
        foreach (['xml', 'excel'] as $fmt) {
            $body = $this->failing($fmt);
            $this->assertMatchesRegularExpression('/\n<!-- LDB EXPORT FAILED: [^\n]+ -->\n\z/', $body, $fmt);
        }
    }

    public function testGzipStreamContainsMarker(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'oph_t');
        $this->assertIsString($tmp);
        $fh = fopen($tmp, 'wb');
        $this->assertIsResource($fh);
        $filter = stream_filter_append($fh, 'zlib.deflate', STREAM_FILTER_WRITE, ['level' => 6, 'window' => 31]);
        $this->assertIsResource($filter);
        $ex = ExporterFactory::create('sql', $this->db, []);
        $log = ini_set('error_log', '/dev/null');
        try {
            ExportController::runExport($ex, ['t', 'missing_table'], $fh);
        } finally {
            ini_set('error_log', (string) $log);
        }
        stream_filter_remove($filter);
        fclose($fh);
        $plain = gzdecode((string) file_get_contents($tmp));
        @unlink($tmp);
        $this->assertIsString($plain);
        $this->assertStringContainsString('INSERT INTO "t"', $plain);
        $this->assertStringEndsWith("LDB_EXPORT_FAILED;\n", $plain);
    }

    public function testFailureBeforeAnyOutputIsRethrown(): void
    {
        $out = fopen('php://memory', 'w+');
        // csv: the failing query precedes any output byte
        $ex = ExporterFactory::create('csv', $this->db, ['query' => 'SELECT * FROM nope', 'bom' => false]);
        $this->expectException(\Throwable::class);
        try {
            ExportController::runExport($ex, [], $out);
        } finally {
            $this->assertFalse($ex->hasWritten());
        }
    }

    public function testMessageSanitizing(): void
    {
        $m = AbstractExporter::failureMessage("line1\nline2\r\n\ttab password=hunter2 dsn mysql://root:secret@host/db \x00");
        $this->assertStringNotContainsString("\n", $m);
        $this->assertStringNotContainsString('hunter2', $m);
        $this->assertStringNotContainsString('secret@', $m);
        $this->assertStringContainsString('line1 line2 tab', $m);

        $long = AbstractExporter::failureMessage(str_repeat('日本語', 200));
        $this->assertSame(300, mb_strlen($long, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));

        $bad = AbstractExporter::failureMessage("bad \xff\xfe bytes");
        $this->assertTrue(mb_check_encoding($bad, 'UTF-8'));
        $this->assertSame('unknown error', AbstractExporter::failureMessage(" \n "));
    }

    public function testCommentTerminatorsInMessageAreNeutralised(): void
    {
        $this->assertSame(1, substr_count($this->writeFailure('json', 'x */ y'), '*/'));
        $this->assertSame(1, substr_count($this->writeFailure('xml', 'x --> y -- z'), '-->'));
    }

    private function writeFailure(string $fmt, string $msg): string
    {
        $out = fopen('php://memory', 'w+');
        ExporterFactory::create($fmt, $this->db, [])->writeFailure($out, $msg);
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
