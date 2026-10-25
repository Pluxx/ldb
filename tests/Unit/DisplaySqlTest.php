<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Db\SqliteDriver;
use Ldb\Db\SqlSrvDriver;
use PHPUnit\Framework\TestCase;

/** renderDisplaySql(): placeholders → quoted literals for the review dialog (never executed). */
final class DisplaySqlTest extends TestCase
{
    public function testScalarsAndQuotes(): void
    {
        $d = new SqliteDriver();
        $sql = $d->renderDisplaySql('UPDATE "t" SET "a" = ?, "b" = ?, "c" = ?, "d" = ? WHERE "id" = ?', [null, 42, 1.5, "O'Neil", true]);
        $this->assertSame('UPDATE "t" SET "a" = NULL, "b" = 42, "c" = 1.5, "d" = \'O\'\'Neil\' WHERE "id" = 1', $sql);
    }

    public function testJapaneseTextIsKeptAndTruncatedMultibyteSafe(): void
    {
        $d = new SqliteDriver();
        $this->assertSame("SELECT '日本語の「テキスト」'", $d->renderDisplaySql('SELECT ?', ['日本語の「テキスト」']));
        $long = str_repeat('あ', 250);
        $out = $d->renderDisplaySql('SELECT ?', [$long], 200);
        $this->assertSame("SELECT '" . str_repeat('あ', 200) . "…'", $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testPlaceholdersInsideQuotedIdentifiersAndStringsAreLeftAlone(): void
    {
        $d = new SqliteDriver();
        $this->assertSame('UPDATE "a?b" SET "c" = \'x\' WHERE \'?\' = 1', $d->renderDisplaySql('UPDATE "a?b" SET "c" = ? WHERE \'?\' = ?', ['x', 1]));
        $m = new MySqlDriver();
        $this->assertSame('UPDATE `q?` SET `v` = 5', $m->renderDisplaySql('UPDATE `q?` SET `v` = ?', [5]));
        $this->assertSame('SELECT ?', $d->renderDisplaySql('SELECT ?', []), 'no params: unchanged');
    }

    public function testBinaryValuesAreShownAsHexPerEngine(): void
    {
        $bin = "\xff\x00\xfe";
        $this->assertSame("SELECT X'FF00FE'", (new SqliteDriver())->renderDisplaySql('SELECT ?', [$bin]));
        $this->assertSame("SELECT X'FF00FE'", (new MySqlDriver())->renderDisplaySql('SELECT ?', [$bin]));
        $this->assertSame("SELECT '\\xFF00FE'::bytea", (new PgSqlDriver())->renderDisplaySql('SELECT ?', [$bin]));
        $this->assertSame('SELECT 0xFF00FE', (new SqlSrvDriver())->renderDisplaySql('SELECT ?', [$bin]));
        $big = str_repeat("\xff", 100);
        $out = (new SqliteDriver())->renderDisplaySql('SELECT ?', [$big]);
        $this->assertStringStartsWith("SELECT X'" . str_repeat('FF', 32) . "'", $out);
        $this->assertStringContainsString('100 bytes', $out);
    }

    public function testEngineSpecificStringEscaping(): void
    {
        $this->assertSame("SELECT 'a\\\\b''c'", (new MySqlDriver())->renderDisplaySql('SELECT ?', ["a\\b'c"]));
        $this->assertSame("SELECT 'a\\b''c'", (new PgSqlDriver())->renderDisplaySql('SELECT ?', ["a\\b'c"]));
        $this->assertSame("SELECT N'日本', 'abc'", (new SqlSrvDriver())->renderDisplaySql('SELECT ?, ?', ['日本', 'abc']));
    }
}
