<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Sql\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSimpleStatements(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::splitSql("SELECT 1;\nSELECT 2;\n"));
        $this->assertSame(['SELECT 1'], SqlSplitter::splitSql('SELECT 1'));
        $this->assertSame([], SqlSplitter::splitSql(" ;; \n -- only a comment\n/* block */"));
    }

    public function testQuotesProtectDelimiters(): void
    {
        $sql = "SELECT 'a;b', \"c;d\", `e;f` FROM t; SELECT 'it''s; fine'; SELECT 'esc\\';x'";
        $this->assertSame([
            "SELECT 'a;b', \"c;d\", `e;f` FROM t",
            "SELECT 'it''s; fine'",
            "SELECT 'esc\\';x'",
        ], SqlSplitter::splitSql($sql));
    }

    public function testComments(): void
    {
        $sql = "-- leading; comment\nSELECT 1 /* inner; */ + 2; # hash; comment\nSELECT 3 -- trailing;\n;";
        $this->assertSame(["SELECT 1 /* inner; */ + 2", "SELECT 3 -- trailing;"], SqlSplitter::splitSql($sql));
    }

    public function testMysqlDoubleDashNeedsWhitespace(): void
    {
        $this->assertSame(['SELECT 5--1', 'SELECT 2'], SqlSplitter::splitSql("SELECT 5--1;SELECT 2"));
    }

    public function testMysqlExecutableCommentIsKept(): void
    {
        $sql = "/*!40101 SET NAMES utf8mb4 */;\n/* plain */ SELECT 1;";
        $this->assertSame(['/*!40101 SET NAMES utf8mb4 */', 'SELECT 1'], SqlSplitter::splitSql($sql));
    }

    public function testDelimiterCommand(): void
    {
        $sql = "DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END//\nDELIMITER ;\nSELECT 3;";
        $this->assertSame([
            'CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END',
            'SELECT 3',
        ], SqlSplitter::splitSql($sql));

        $dump = "DELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a = 1; END ;;\nDELIMITER ;\n";
        $this->assertSame(['CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a = 1; END'], SqlSplitter::splitSql($dump));
    }

    public function testPostgresDollarQuoting(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$body\$ BEGIN RETURN 1; END; \$body\$ LANGUAGE plpgsql;\n"
            . "DO \$\$ BEGIN PERFORM 1; END \$\$; SELECT \$1::int; SELECT E'a\\';b'; SELECT 'a\\'; SELECT 2";
        $this->assertSame([
            "CREATE FUNCTION f() RETURNS int AS \$body\$ BEGIN RETURN 1; END; \$body\$ LANGUAGE plpgsql",
            "DO \$\$ BEGIN PERFORM 1; END \$\$",
            'SELECT $1::int',
            "SELECT E'a\\';b'",
            "SELECT 'a\\'",
            'SELECT 2',
        ], SqlSplitter::splitSql($sql, 'pgsql'));
    }

    public function testPostgresNestedComments(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::splitSql("SELECT 1; /* a /* b; */ c; */ SELECT 2", 'pgsql'));
    }

    public function testPostgresHashIsNotComment(): void
    {
        $this->assertSame(['SELECT 5 # 3', 'SELECT 1'], SqlSplitter::splitSql('SELECT 5 # 3; SELECT 1', 'pgsql'));
    }

    public function testSqlServerBracketsAndGo(): void
    {
        // with GO separators every batch is one statement (sqlcmd / SSMS)
        $sql = "SELECT [a;b] FROM [t]]x]\nGO\nSELECT 2\ngo 2\nSELECT 3;SELECT 4";
        $this->assertSame(['SELECT [a;b] FROM [t]]x]', 'SELECT 2', 'SELECT 3;SELECT 4'], SqlSplitter::splitSql($sql, 'sqlsrv'));
        // without GO: split at ";"
        $this->assertSame(['SELECT [a;b]', 'SELECT 3', 'SELECT 4'], SqlSplitter::splitSql('SELECT [a;b]; SELECT 3;SELECT 4', 'sqlsrv'));
        $this->assertSame(['SELECT 3', 'SELECT 4'], SqlSplitter::splitSql("SELECT 3;SELECT 4\n", 'sqlsrv', ['wholeBatches' => false]));
    }

    public function testUnterminatedStringIsReturnedAsIs(): void
    {
        $this->assertSame(['SELECT 1', "SELECT 'abc;"], SqlSplitter::splitSql("SELECT 1; SELECT 'abc;"));
    }

    public function testMultibyteContent(): void
    {
        $sql = "INSERT INTO t VALUES ('日本語;テキスト'); SELECT 'Tiếng Việt'; SELECT 'שלום'";
        $this->assertSame([
            "INSERT INTO t VALUES ('日本語;テキスト')",
            "SELECT 'Tiếng Việt'",
            "SELECT 'שלום'",
        ], SqlSplitter::splitSql($sql));
    }

    /**
     * @dataProvider dialectProvider
     */
    public function testStreamingMatchesSplitForAnyChunkSize(string $dialect, string $sql): void
    {
        $expected = SqlSplitter::splitSql($sql, $dialect);
        foreach ([1, 2, 3, 5, 7, 13] as $size) {
            $splitter = new SqlSplitter($dialect);
            $got = [];
            foreach (str_split($sql, $size) as $chunk) {
                foreach ($splitter->feed($chunk) as $stmt) {
                    $got[] = $stmt;
                }
            }
            foreach ($splitter->finish() as $stmt) {
                $got[] = $stmt;
            }
            $this->assertSame($expected, $got, "chunk size $size");
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    public function dialectProvider(): array
    {
        return [
            'mysql' => ['mysql', "-- c\nSELECT 'a;''b', \"x\\\"y;\" FROM `t;`; /*!40101 SET x=1*/;\n"
                . "DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT 1; END\$\$\nDELIMITER ;\n# end\nSELECT 2--1\n;SELECT 'ü;'"],
            'pgsql' => ['pgsql', "SELECT \$tag\$ a; \$ b \$tag\$; /* x /* y */ ; */ SELECT E'\\'';"
                . " DO \$\$ BEGIN END \$\$; SELECT a\$b FROM t; SELECT 1"],
            'sqlsrv' => ['sqlsrv', "SELECT [a;]]b]\nGO\nSELECT 'x;'\n  GO 3\nSELECT 1; -- c\nGO\n"],
            'sqlsrv-blocks' => ['sqlsrv', "CREATE PROCEDURE p AS BEGIN SET NOCOUNT ON; BEGIN TRY SELECT 1; END TRY BEGIN CATCH SELECT 2; END CATCH; END;\n"
                . "IF 1 = 1 BEGIN SELECT 'a;'; END; BEGIN TRAN; SELECT CASE WHEN 1 = 1 THEN 1 END; COMMIT"],
            'mysql-routine' => ['mysql', "CREATE DEFINER=`root`@`%` PROCEDURE p(IN x INT) BEGIN DECLARE i INT DEFAULT 0;\n"
                . "lbl: LOOP SET i = i + 1; IF i > x THEN LEAVE lbl; END IF; END LOOP lbl; SELECT CASE WHEN i > 1 THEN 'a;' ELSE 'b' END; END;\nCALL p(3); SELECT 1"],
            'pgsql-atomic' => ['pgsql', "CREATE FUNCTION f(a int) RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT a + 1; SELECT CASE WHEN a > 0 THEN 1 END; END; SELECT f(1)"],
            'sqlite-trigger' => ['sqlite', "CREATE TRIGGER t AFTER INSERT ON x BEGIN UPDATE y SET n = n + 1; DELETE FROM z; END; SELECT 1"],
            'sqlite' => ['sqlite', "CREATE TABLE \"t;\" ([a;] INT, `b;` TEXT); INSERT INTO t VALUES ('x\\'); SELECT 1"],
        ];
    }

    public function testSplitStream(): void
    {
        $fh = fopen('php://memory', 'w+b');
        $this->assertIsResource($fh);
        fwrite($fh, str_repeat("INSERT INTO t VALUES (1, 'a;b');\n", 100));
        rewind($fh);
        $count = 0;
        foreach ((new SqlSplitter())->splitStream($fh, 10) as $stmt) {
            $this->assertSame("INSERT INTO t VALUES (1, 'a;b')", $stmt);
            $count++;
        }
        fclose($fh);
        $this->assertSame(100, $count);
    }

    /** Options SqlImporter uses: leading comments kept, BOM stripped, no GO batches, flat block comments. */
    private const IMPORT = ['keepLeadingComments' => true, 'stripBom' => true, 'go' => false, 'nestedComments' => false];

    /** Split with import options, feeding chunks of the given size (1 = worst case boundaries). */
    private function split(string $sql, string $dialect = 'mysql', int $chunk = 1000000): array
    {
        $pos = 0;
        $splitter = new SqlSplitter($dialect, self::IMPORT);
        return iterator_to_array($splitter->splitReader(static function (int $n) use ($sql, &$pos, $chunk) {
            $piece = substr($sql, $pos, $chunk);
            $pos += strlen($piece);
            return $piece;
        }, 16), false);
    }

    /** Assert same result for whole-buffer and byte-wise feeding. */
    private function assertSplit(array $expected, string $sql, string $dialect = 'mysql'): void
    {
        $this->assertSame($expected, $this->split($sql, $dialect), 'whole');
        $this->assertSame($expected, $this->split($sql, $dialect, 1), 'byte-wise');
        $this->assertSame($expected, $this->split($sql, $dialect, 3), '3-byte');
    }

    public function testImportOptionsDifferFromEditorDefaults(): void
    {
        $sql = "-- lead\nSELECT 1;";
        $this->assertSame(['SELECT 1'], SqlSplitter::splitSql($sql));
        $this->assertSame(["-- lead\nSELECT 1"], SqlSplitter::splitSql($sql, 'mysql', self::IMPORT));
        // GO batches and nested comments are editor behaviour only
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::splitSql("SELECT 1\nGO\nSELECT 2", 'sqlsrv'));
        $this->assertSame(["SELECT 1\nGO\nSELECT 2"], SqlSplitter::splitSql("SELECT 1\nGO\nSELECT 2", 'sqlsrv', self::IMPORT));
        $this->assertSame(['SELECT 1', '/* a /* b */ c', '*/ SELECT 2'], SqlSplitter::splitSql("SELECT 1; /* a /* b */ c; */ SELECT 2", 'pgsql', self::IMPORT));
    }

    public function testImportSimple(): void
    {
        $this->assertSplit(['SELECT 1', 'SELECT 2'], "SELECT 1;\nSELECT 2;\n");
        $this->assertSplit(['SELECT 1', 'SELECT 2'], 'SELECT 1; SELECT 2');
        $this->assertSplit([], "  ;; \n ;");
    }

    public function testImportQuotesHideSemicolons(): void
    {
        $this->assertSplit(["INSERT INTO t VALUES ('a;b', \"c;d\", `e;f`)", 'SELECT 2'], "INSERT INTO t VALUES ('a;b', \"c;d\", `e;f`);SELECT 2;");
    }

    public function testImportDoubledAndBackslashEscapes(): void
    {
        $this->assertSplit(["SELECT 'it''s; ok'", 'SELECT 2'], "SELECT 'it''s; ok';SELECT 2;");
        $this->assertSplit(["SELECT 'a\\'; b'", 'SELECT 2'], "SELECT 'a\\'; b';SELECT 2;");
        // standard SQL: backslash is literal
        $this->assertSplit(["SELECT 'a\\'", 'SELECT 2'], "SELECT 'a\\';SELECT 2;", 'pgsql');
    }

    public function testImportComments(): void
    {
        $sql = "-- header; comment\n# hash; comment\n/* block; comment */\nSELECT 1; -- trailing; x\nSELECT 2 /* a;b */;";
        $this->assertSplit([
            "-- header; comment\n# hash; comment\n/* block; comment */\nSELECT 1",
            "-- trailing; x\nSELECT 2 /* a;b */",
        ], $sql);
    }

    public function testImportCommentOnlyFragmentsSkipped(): void
    {
        $this->assertSplit(['SELECT 1'], "-- nothing\n/* still nothing */;SELECT 1;\n-- end");
    }

    public function testImportMysqlDashRule(): void
    {
        // "--5" is not a comment in MySQL
        $this->assertSplit(['SELECT 1--5', 'SELECT 2'], 'SELECT 1--5;SELECT 2;');
    }

    public function testImportHashIsOperatorOutsideMysql(): void
    {
        $this->assertSplit(["SELECT '{}'::jsonb #> '{a}'", 'SELECT 2'], "SELECT '{}'::jsonb #> '{a}';SELECT 2;", 'pgsql');
    }

    public function testImportConditionalCommentIsContent(): void
    {
        $this->assertSplit(['/*!40101 SET NAMES utf8 */', 'SELECT 1'], "/*!40101 SET NAMES utf8 */;SELECT 1;");
    }

    public function testImportDelimiterDirective(): void
    {
        $sql = "DELIMITER //\nCREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND//\nDELIMITER ;\nSELECT 3;\n";
        $this->assertSplit([
            "CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND",
            'SELECT 3',
        ], $sql);
        $this->assertSplit(['SELECT 1', 'SELECT 2'], "delimiter \$\$\nSELECT 1\$\$\nDELIMITER ;\nSELECT 2;");
    }

    public function testImportDollarQuoting(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$\$ BEGIN RETURN 1; END; \$\$ LANGUAGE plpgsql;SELECT \$tag\$ a ; \$\$ b \$tag\$;SELECT \$1;";
        $this->assertSplit([
            'CREATE FUNCTION f() RETURNS int AS $$ BEGIN RETURN 1; END; $$ LANGUAGE plpgsql',
            'SELECT $tag$ a ; $$ b $tag$',
            'SELECT $1',
        ], $sql, 'pgsql');
    }

    public function testImportPostgresEscapeString(): void
    {
        $this->assertSplit(["SELECT E'a\\'; b'", 'SELECT 2'], "SELECT E'a\\'; b';SELECT 2;", 'pgsql');
    }

    public function testImportBracketQuotes(): void
    {
        $this->assertSplit(['SELECT [a;b] FROM t', 'SELECT 2'], 'SELECT [a;b] FROM t;SELECT 2;', 'sqlsrv');
    }

    public function testImportBomAndUnterminated(): void
    {
        $this->assertSame(['SELECT 1', "SELECT 'oops; x"], $this->split("\xEF\xBB\xBFSELECT 1;SELECT 'oops; x"));
    }

    public function testImportMultibyte(): void
    {
        $this->assertSplit(["INSERT INTO t VALUES ('日本語;テスト')", "INSERT INTO t VALUES ('Привет')"], "INSERT INTO t VALUES ('日本語;テスト');INSERT INTO t VALUES ('Привет');");
    }

    public function testImportLargeStatement(): void
    {
        $big = "INSERT INTO t VALUES ('" . str_repeat('x;', 200000) . "')";
        $r = SqlSplitter::splitSql($big . ';SELECT 1;', 'mysql', self::IMPORT);
        $this->assertCount(2, $r);
        $this->assertSame($big, $r[0]);
    }

    // ---------------------------------------------------------------- compound statements

    /** Editor defaults, fed whole, byte-wise and in 3-byte chunks. */
    private function editorSplit(string $sql, string $dialect, int $chunk, array $options = []): array
    {
        $splitter = new SqlSplitter($dialect, $options);
        $got = [];
        foreach (str_split($sql, $chunk) as $piece) {
            foreach ($splitter->feed($piece) as $stmt) {
                $got[] = $stmt;
            }
        }
        foreach ($splitter->finish() as $stmt) {
            $got[] = $stmt;
        }
        return $got;
    }

    /** Same result in the editor (whole / 1-byte / 3-byte chunks) and with import options. */
    private function assertBlocks(array $expected, string $sql, string $dialect = 'mysql', bool $import = true): void
    {
        $this->assertSame($expected, SqlSplitter::splitSql($sql, $dialect), 'editor whole');
        $this->assertSame($expected, $this->editorSplit($sql, $dialect, 1), 'editor byte-wise');
        $this->assertSame($expected, $this->editorSplit($sql, $dialect, 3), 'editor 3-byte');
        if ($import) {
            $this->assertSplit($expected, $sql, $dialect);
        }
    }

    public function testMysqlProcedureWithoutDelimiter(): void
    {
        $proc = "CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND";
        $this->assertBlocks([$proc, 'CALL p()'], $proc . ";\nCALL p();");
        $this->assertBlocks([$proc], $proc); // no trailing ";"
    }

    public function testMysqlNestedBeginAndDefiner(): void
    {
        $proc = "CREATE DEFINER=`root`@`localhost` PROCEDURE `db`.`p`(IN a INT)\n"
            . "SQL SECURITY INVOKER\nCOMMENT 'x; y'\nBEGIN\n  DECLARE b INT;\n  BEGIN\n    SET b = a;\n    BEGIN END;\n  END;\n  SELECT b;\nEND";
        $this->assertBlocks([$proc, 'SELECT 1'], $proc . ";SELECT 1;");
        $f = 'CREATE DEFINER = CURRENT_USER() FUNCTION f() RETURNS INT DETERMINISTIC BEGIN DECLARE x INT; SET x = 1; RETURN x; END';
        $this->assertBlocks([$f, 'SELECT f()'], $f . '; SELECT f()');
        $f2 = 'CREATE DEFINER=root@localhost SQL SECURITY DEFINER FUNCTION f2() RETURNS INT BEGIN RETURN 1; END';
        $this->assertBlocks([$f2, 'SELECT 2'], $f2 . ';SELECT 2');
    }

    public function testMysqlOrReplaceAndIfNotExists(): void
    {
        $p = 'CREATE OR REPLACE PROCEDURE IF NOT EXISTS p() BEGIN SELECT 1; SELECT 2; END';
        $this->assertBlocks([$p, 'SELECT 3'], "$p;\nSELECT 3");
        $a = 'CREATE AGGREGATE FUNCTION agg(x INT) RETURNS INT BEGIN DECLARE s INT DEFAULT 0; '
            . 'DECLARE CONTINUE HANDLER FOR NOT FOUND RETURN s; LOOP FETCH GROUP NEXT ROW; SET s = s + x; END LOOP; END';
        $this->assertBlocks([$a, 'SELECT 1'], "$a;SELECT 1;");
    }

    public function testMysqlIfCaseAndLoops(): void
    {
        $proc = "CREATE PROCEDURE p(IN n INT)\nBEGIN\n"
            . "  DECLARE i INT DEFAULT 0;\n"
            . "  IF n > 0 THEN SELECT 'pos;'; ELSEIF n < 0 THEN SELECT 'neg'; ELSE IF n = 0 THEN SELECT 0; END IF; END IF;\n"
            . "  CASE n WHEN 1 THEN SELECT 1; WHEN 2 THEN BEGIN SELECT 2; END; ELSE SELECT 3; END CASE;\n"
            . "  SELECT CASE WHEN n > 1 THEN 'big' ELSE 'small' END AS s, IF(n > 0, 1, 0) AS f;\n"
            . "  WHILE i < n DO SET i = i + 1; END WHILE;\n"
            . "  REPEAT SET i = i - 1; UNTIL i <= 0 END REPEAT;\n"
            . "  SET @r = REPEAT('x;', 2);\n"
            . "  lbl: LOOP SET i = i + 1; IF i > 3 THEN LEAVE lbl; END IF; END LOOP lbl;\n"
            . "  outer_b: BEGIN SELECT 9; END outer_b;\n"
            . "  DROP TEMPORARY TABLE IF EXISTS tmp;\n"
            . "END";
        $this->assertBlocks([$proc, 'CALL p(1)', 'SELECT 1'], "$proc;\nCALL p(1);\nSELECT 1;");
    }

    public function testMysqlCursorsAndHandlers(): void
    {
        $proc = "CREATE PROCEDURE cur()\nBEGIN\n"
            . "  DECLARE done INT DEFAULT FALSE;\n"
            . "  DECLARE v INT;\n"
            . "  DECLARE c CURSOR FOR SELECT id FROM t;\n"
            . "  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;\n"
            . "  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;\n"
            . "  START TRANSACTION;\n"
            . "  OPEN c;\n"
            . "  read_loop: LOOP\n    FETCH c INTO v;\n    IF done THEN LEAVE read_loop; END IF;\n  END LOOP;\n"
            . "  CLOSE c;\n  COMMIT;\nEND";
        $this->assertBlocks([$proc, 'SELECT 2'], "-- x\n$proc;\nSELECT 2;", 'mysql', false);
        $this->assertSplit(["-- x\n" . $proc, 'SELECT 2'], "-- x\n$proc;\nSELECT 2;");
    }

    public function testMysqlTriggerAndEvent(): void
    {
        $trg = "CREATE TRIGGER trg BEFORE INSERT ON t FOR EACH ROW\nBEGIN\n  IF NEW.a IS NULL THEN SET NEW.a = 0; END IF;\n  SET NEW.b = CASE WHEN NEW.a > 0 THEN 'x;' END;\nEND";
        $this->assertBlocks([$trg, 'INSERT INTO t VALUES (1)'], "$trg;\nINSERT INTO t VALUES (1);");
        // single-statement trigger body: ends at the first ";"
        $this->assertBlocks(['CREATE TRIGGER t2 AFTER DELETE ON t FOR EACH ROW DELETE FROM u WHERE id = OLD.id', 'SELECT 1'],
            'CREATE TRIGGER t2 AFTER DELETE ON t FOR EACH ROW DELETE FROM u WHERE id = OLD.id; SELECT 1');
        $ev = "CREATE EVENT IF NOT EXISTS ev ON SCHEDULE EVERY 1 DAY DO BEGIN DELETE FROM log WHERE d < NOW() - INTERVAL 7 DAY; OPTIMIZE TABLE log; END";
        $this->assertBlocks([$ev, 'SHOW EVENTS'], "$ev;\nSHOW EVENTS;");
        $ev2 = 'CREATE DEFINER=`u`@`%` EVENT e2 ON SCHEDULE AT CURRENT_TIMESTAMP DO UPDATE t SET a = 1';
        $this->assertBlocks([$ev2, 'SELECT 1'], "$ev2; SELECT 1");
    }

    public function testMariadbBeginNotAtomic(): void
    {
        $b = 'BEGIN NOT ATOMIC DECLARE x INT DEFAULT 1; IF x THEN SELECT 1; END IF; END';
        $this->assertBlocks([$b, 'SELECT 2'], "$b;\nSELECT 2");
        // transaction statements stay separate
        $this->assertBlocks(['BEGIN', 'INSERT INTO t VALUES (1)', 'COMMIT'], 'BEGIN; INSERT INTO t VALUES (1); COMMIT;');
        $this->assertBlocks(['BEGIN WORK', 'SELECT 1', 'COMMIT'], 'BEGIN WORK; SELECT 1; COMMIT;');
    }

    public function testKeywordsInLiteralsAndCommentsAreIgnored(): void
    {
        $proc = "CREATE PROCEDURE p() BEGIN SELECT 'END;' AS `end`, \"begin\"; /* END; */ -- END;\n SELECT t.end, @end FROM t; # END;\nEND";
        $this->assertBlocks([$proc, 'SELECT 1'], "$proc;SELECT 1");
        // a comment between END and IF
        $p2 = 'CREATE PROCEDURE p2() BEGIN IF 1 THEN SELECT 1; END /* c */ IF; SELECT 2; END';
        $this->assertBlocks([$p2, 'SELECT 3'], "$p2;SELECT 3");
    }

    public function testOrdinaryStatementsUnchanged(): void
    {
        $this->assertBlocks(['SELECT CASE WHEN a THEN 1 END FROM t', 'SELECT 2'], 'SELECT CASE WHEN a THEN 1 END FROM t; SELECT 2');
        $this->assertBlocks(['SELECT CASE WHEN a THEN 1 END', 'SELECT 2'], 'SELECT CASE WHEN a THEN 1 END; SELECT 2', 'pgsql');
        $this->assertBlocks(['UPDATE t SET `begin` = 1, a = IF(b, 1, 2)', 'SELECT 2'], 'UPDATE t SET `begin` = 1, a = IF(b, 1, 2); SELECT 2');
        $this->assertBlocks(['CREATE TABLE trigger_log (a INT)', 'CREATE VIEW v AS SELECT 1', 'SELECT 2'],
            'CREATE TABLE trigger_log (a INT); CREATE VIEW v AS SELECT 1; SELECT 2');
        $this->assertBlocks(['BEGIN', 'SELECT 1', 'END'], 'BEGIN; SELECT 1; END;', 'pgsql');
        $this->assertBlocks(['BEGIN TRANSACTION', 'SELECT 1', 'END'], 'BEGIN TRANSACTION; SELECT 1; END;', 'sqlite');
        $this->assertBlocks(['CREATE FUNCTION f() RETURNS int AS \'SELECT 1; SELECT 2\' LANGUAGE sql', 'SELECT 1'],
            "CREATE FUNCTION f() RETURNS int AS 'SELECT 1; SELECT 2' LANGUAGE sql; SELECT 1", 'pgsql');
    }

    public function testDelimiterScriptsUnchanged(): void
    {
        $sql = "DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN IF 1 THEN SELECT 1; END IF; END\$\$\n"
            . "CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; END\$\$\nDELIMITER ;\nSELECT 1; SELECT 2;";
        $this->assertBlocks([
            'CREATE PROCEDURE p() BEGIN IF 1 THEN SELECT 1; END IF; END',
            'CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; END',
            'SELECT 1',
            'SELECT 2',
        ], $sql);
        // after DELIMITER ; routines are tracked again
        $this->assertBlocks(['SELECT 1', 'CREATE PROCEDURE q() BEGIN SELECT 1; END'], "DELIMITER //\nSELECT 1//\nDELIMITER ;\nCREATE PROCEDURE q() BEGIN SELECT 1; END;");
    }

    public function testUnbalancedBodyKeepsAllText(): void
    {
        $sql = "CREATE PROCEDURE p() BEGIN SELECT 1; IF x THEN SELECT 2;\nSELECT 3";
        $this->assertBlocks([$sql], $sql);
        $this->assertBlocks(["CREATE PROCEDURE p() BEGIN SELECT 'x"], "CREATE PROCEDURE p() BEGIN SELECT 'x");
    }

    public function testPostgresBeginAtomic(): void
    {
        $f = "CREATE OR REPLACE FUNCTION add1(a integer) RETURNS integer\nLANGUAGE sql IMMUTABLE\nBEGIN ATOMIC\n  SELECT a + 1;\nEND";
        $this->assertBlocks([$f, 'SELECT add1(1)'], "$f;\nSELECT add1(1);", 'pgsql');
        $p = "CREATE PROCEDURE ins(x int) LANGUAGE sql BEGIN ATOMIC INSERT INTO t VALUES (x); "
            . "INSERT INTO t VALUES (CASE WHEN x > 0 THEN x ELSE 0 END); END";
        $this->assertBlocks([$p, 'CALL ins(1)'], "$p; CALL ins(1)", 'pgsql');
        // dollar quoting unchanged
        $d = 'CREATE FUNCTION g() RETURNS trigger AS $$ BEGIN IF x THEN RETURN NEW; END IF; END; $$ LANGUAGE plpgsql';
        $this->assertBlocks([$d, 'SELECT 1'], "$d; SELECT 1", 'pgsql');
        // RETURN expression body (no BEGIN)
        $r = 'CREATE FUNCTION h(a int) RETURNS int LANGUAGE sql RETURN CASE WHEN a > 0 THEN 1 ELSE 0 END';
        $this->assertBlocks([$r, 'SELECT h(1)'], "$r; SELECT h(1)", 'pgsql');
    }

    public function testSqliteTrigger(): void
    {
        $t = "CREATE TRIGGER IF NOT EXISTS upd AFTER UPDATE ON t\nFOR EACH ROW WHEN NEW.a <> OLD.a\nBEGIN\n"
            . "  INSERT INTO log VALUES (NEW.id, CASE WHEN NEW.a > 0 THEN 'up;' ELSE 'down' END);\n  UPDATE t2 SET n = n + 1;\nEND";
        $this->assertBlocks([$t, 'SELECT 1'], "$t;\nSELECT 1;", 'sqlite');
        $t2 = 'CREATE TEMP TRIGGER tt BEFORE DELETE ON [x] BEGIN SELECT RAISE(ABORT, \'no;\'); END';
        $this->assertBlocks([$t2, 'DELETE FROM x'], "$t2; DELETE FROM x", 'sqlite');
    }

    public function testSqlServerProcedureWithTryCatch(): void
    {
        $p = "CREATE OR ALTER PROCEDURE dbo.p @x INT AS\nBEGIN\n  SET NOCOUNT ON;\n  BEGIN TRY\n    BEGIN TRAN;\n"
            . "    UPDATE t SET a = @x;\n    IF @@ROWCOUNT = 0 BEGIN RAISERROR('none;', 16, 1); END;\n    COMMIT TRAN;\n  END TRY\n"
            . "  BEGIN CATCH\n    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;\n    THROW;\n  END CATCH;\nEND";
        $this->assertBlocks([$p, 'EXEC dbo.p 1'], "$p;\nEXEC dbo.p 1;", 'sqlsrv');
        $f = 'CREATE FUNCTION dbo.f(@a INT) RETURNS INT AS BEGIN DECLARE @b INT; SET @b = CASE WHEN @a > 0 THEN 1 ELSE 0 END; RETURN @b; END';
        $this->assertBlocks([$f, 'SELECT dbo.f(1)'], "$f; SELECT dbo.f(1)", 'sqlsrv');
        $tr = 'ALTER TRIGGER trg ON t AFTER INSERT AS BEGIN SET NOCOUNT ON; INSERT INTO log SELECT id FROM inserted; END';
        $this->assertBlocks([$tr, 'SELECT 1'], "$tr; SELECT 1", 'sqlsrv');
        $proc = 'CREATE PROC p2 AS BEGIN SELECT 1; END';
        $this->assertBlocks([$proc, 'SELECT 1'], "$proc; SELECT 1", 'sqlsrv');
    }

    public function testSqlServerBlocksOutsideRoutines(): void
    {
        $this->assertBlocks([
            "IF OBJECT_ID('t') IS NULL BEGIN CREATE TABLE t (a INT); INSERT INTO t VALUES (1); END",
            'BEGIN TRANSACTION',
            'UPDATE t SET a = 2',
            'COMMIT',
            'BEGIN TRY SELECT 1/0; END TRY BEGIN CATCH SELECT ERROR_MESSAGE(); END CATCH',
            'SELECT CASE WHEN 1 = 1 THEN 1 END',
            'SELECT @begin',
        ], "IF OBJECT_ID('t') IS NULL BEGIN CREATE TABLE t (a INT); INSERT INTO t VALUES (1); END;\n"
            . "BEGIN TRANSACTION; UPDATE t SET a = 2; COMMIT;\n"
            . "BEGIN TRY SELECT 1/0; END TRY BEGIN CATCH SELECT ERROR_MESSAGE(); END CATCH;\n"
            . 'SELECT CASE WHEN 1 = 1 THEN 1 END; SELECT @begin', 'sqlsrv');
        $this->assertBlocks(['BEGIN DISTRIBUTED TRANSACTION', 'SELECT 1'], 'BEGIN DISTRIBUTED TRANSACTION; SELECT 1', 'sqlsrv');
    }

    public function testSqlServerGoBatchesAreWhole(): void
    {
        $sql = "CREATE TABLE t (a INT);\nINSERT INTO t VALUES (1);\nGO\n"
            . "CREATE PROCEDURE p AS\nSET NOCOUNT ON;\nSELECT a FROM t;\nGO\n"
            . "-- c\nEXEC p; SELECT 2\n";
        $expected = ["CREATE TABLE t (a INT);\nINSERT INTO t VALUES (1);", "CREATE PROCEDURE p AS\nSET NOCOUNT ON;\nSELECT a FROM t;", 'EXEC p; SELECT 2'];
        $this->assertBlocks($expected, $sql, 'sqlsrv', false);
        // the importer keeps splitting at ";" inside batches (bounded memory, per-statement errors)
        $imp = self::IMPORT;
        $imp['go'] = true;
        $imp['wholeBatches'] = false;
        $this->assertSame(['CREATE TABLE t (a INT)', 'INSERT INTO t VALUES (1)', "CREATE PROCEDURE p AS\nSET NOCOUNT ON", 'SELECT a FROM t', "-- c\nEXEC p", 'SELECT 2'],
            SqlSplitter::splitSql($sql, 'sqlsrv', $imp));
        // ... but keeps module bodies with BEGIN … END together
        $seed = "CREATE PROCEDURE sp AS\nBEGIN\n  UPDATE o SET n = 1;\n  SELECT 1;\nEND;\nGO\nSELECT 2;\n";
        $this->assertSame(["CREATE PROCEDURE sp AS\nBEGIN\n  UPDATE o SET n = 1;\n  SELECT 1;\nEND", 'SELECT 2'], SqlSplitter::splitSql($seed, 'sqlsrv', $imp));
        // an unbalanced batch ends at GO
        $this->assertBlocks(['IF 1 = 1 BEGIN SELECT 1;', 'SELECT 2'], "IF 1 = 1 BEGIN SELECT 1;\nGO\nSELECT 2", 'sqlsrv', false);
        $this->assertBlocks([], "GO\n-- only\nGO\n", 'sqlsrv', false);
    }

    public function testIsRoutineDefinition(): void
    {
        $tok = static function (string $sql): array {
            $out = [];
            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*|`[^`]*`|\S/', $sql, $m);
            foreach ($m[0] as $t) {
                $out[] = ctype_alpha($t[0]) || $t[0] === '_' ? ['w', strtoupper($t)] : ($t[0] === '`' ? ['q', $t] : ['p', $t]);
            }
            return $out;
        };
        $this->assertTrue(SqlSplitter::isRoutineDefinition($tok('CREATE DEFINER=`a`@`b` PROCEDURE p() BEGIN END'), 'mysql'));
        $this->assertTrue(SqlSplitter::isRoutineDefinition($tok('BEGIN NOT ATOMIC SELECT 1; END'), 'mysql'));
        $this->assertTrue(SqlSplitter::isRoutineDefinition($tok('CREATE OR ALTER PROC p AS SELECT 1'), 'sqlsrv'));
        $this->assertFalse(SqlSplitter::isRoutineDefinition($tok('CREATE TABLE procedure_log (a INT)'), 'mysql'));
        $this->assertFalse(SqlSplitter::isRoutineDefinition($tok('CREATE TRIGGER t'), 'pgsql'));
    }
}
