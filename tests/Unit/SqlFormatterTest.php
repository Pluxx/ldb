<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Sql\SqlFormatter;
use PHPUnit\Framework\TestCase;

final class SqlFormatterTest extends TestCase
{
    public function testUppercasesKeywordsAndBreaksClauses(): void
    {
        $out = SqlFormatter::formatSql('select id, name from users where id = 1 order by name');
        $this->assertSame("SELECT id,\n    name\nFROM users\nWHERE id = 1\nORDER BY name", $out);
    }

    public function testStringLiteralsAndCommentsAreUntouched(): void
    {
        $sql = "select 'select from where' as s, \"from\" -- keep where lower\nfrom t /* select inside */ where x = 'a  b'";
        $out = SqlFormatter::formatSql($sql);
        $this->assertStringContainsString("'select from where'", $out);
        $this->assertStringContainsString('"from"', $out);
        $this->assertStringContainsString('-- keep where lower', $out);
        $this->assertStringContainsString('/* select inside */', $out);
        $this->assertStringContainsString("'a  b'", $out);
    }

    public function testJoinsAndConditions(): void
    {
        $out = SqlFormatter::formatSql('select * from a left join b on a.id = b.a_id and b.x between 1 and 5 where a.y = 1 or a.z = 2');
        $this->assertSame(
            "SELECT *\nFROM a\nLEFT JOIN b\n    ON a.id = b.a_id\n    AND b.x BETWEEN 1 AND 5\nWHERE a.y = 1\n    OR a.z = 2",
            $out
        );
    }

    public function testFunctionCallsHaveNoSpaceBeforeParen(): void
    {
        $out = SqlFormatter::formatSql('select count(*), max(id) from t where id in (1, 2)');
        $this->assertStringContainsString('COUNT(*)', $out);
        $this->assertStringContainsString('MAX(id)', $out);
        $this->assertStringContainsString('IN (1, 2)', $out);
    }

    public function testSubqueryIsIndented(): void
    {
        $out = SqlFormatter::formatSql('select * from t where id in (select id from u)');
        $this->assertStringContainsString("IN (\n    SELECT id\n    FROM u\n)", $out);
    }

    public function testMultipleStatementsAndIdempotence(): void
    {
        $once = SqlFormatter::formatSql('update t set a = 1 where b = 2; delete from t where c = 3;');
        $this->assertSame("UPDATE t\nSET a = 1\nWHERE b = 2;\nDELETE FROM t\nWHERE c = 3;", $once);
        $this->assertSame($once, SqlFormatter::formatSql($once));
    }

    public function testUnicodeIdentifiersAndPostgresCasts(): void
    {
        $out = SqlFormatter::formatSql("select 名前, x::text from テーブル where y = 'Tiếng Việt'");
        $this->assertSame("SELECT 名前,\n    x::TEXT\nFROM テーブル\nWHERE y = 'Tiếng Việt'", $out);
    }
}
