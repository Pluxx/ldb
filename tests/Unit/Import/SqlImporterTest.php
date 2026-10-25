<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Import;

use Ldb\Import\SqlImporter;
use Ldb\Tests\Unit\Support\FakeSqliteDriver;
use PHPUnit\Framework\TestCase;

final class SqlImporterTest extends TestCase
{
    private function tmp(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'oph');
        file_put_contents($f, $content);
        return $f;
    }

    private const SQL = "-- dump\nCREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT);\nINSERT INTO t VALUES (1, 'a;b');\nINSERT INTO t VALUES (2, '日本語');\nBROKEN STATEMENT;\nINSERT INTO t VALUES (3, 'it''s');\nSELECT * FROM t;\n";

    public function testImportsAndCollectsErrors(): void
    {
        $db = new FakeSqliteDriver();
        $f = $this->tmp(self::SQL);
        $r = (new SqlImporter($db, []))->importFile($f);
        unlink($f);
        $this->assertSame(6, $r['statements']);
        $this->assertCount(1, $r['errors']);
        $this->assertSame('BROKEN STATEMENT', $r['errors'][0]['statement']);
        $this->assertSame(['a;b', '日本語', "it's"], $db->pdo()->query('SELECT name FROM t ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testStopOnError(): void
    {
        $db = new FakeSqliteDriver();
        $f = $this->tmp(self::SQL);
        $r = (new SqlImporter($db, ['stop_on_error' => true]))->importFile($f);
        unlink($f);
        $this->assertSame(4, $r['statements']);
        $this->assertTrue($r['aborted']);
        $this->assertSame(2, (int) $db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testSkipStatements(): void
    {
        $db = new FakeSqliteDriver();
        $db->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $f = $this->tmp(self::SQL);
        $r = (new SqlImporter($db, ['skip_statements' => 2]))->importFile($f);
        unlink($f);
        $this->assertSame(2, $r['skipped']);
        $this->assertSame(['日本語', "it's"], $db->pdo()->query('SELECT name FROM t ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testGzip(): void
    {
        $db = new FakeSqliteDriver();
        $f = $this->tmp(gzencode(self::SQL));
        $r = (new SqlImporter($db, []))->importFile($f);
        unlink($f);
        $this->assertSame(6, $r['statements']);
        $this->assertSame(3, (int) $db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testMaxErrors(): void
    {
        $db = new FakeSqliteDriver();
        $f = $this->tmp("X1;X2;X3;X4;");
        $r = (new SqlImporter($db, ['max_errors' => 2]))->importFile($f);
        unlink($f);
        $this->assertCount(2, $r['errors']);
        $this->assertTrue($r['aborted']);
    }
}
