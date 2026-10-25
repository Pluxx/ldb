<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Http\ApiException;
use Ldb\Routine\MySqlRoutineEditor;
use Ldb\Routine\PgSqlRoutineEditor;
use Ldb\Routine\RoutineEditor;
use Ldb\Routine\SqliteRoutineEditor;
use Ldb\Routine\SqlSrvRoutineEditor;
use PHPUnit\Framework\TestCase;

/** SQL generation of the routine / trigger / event editors; no database connection needed. */
final class RoutineSqlTest extends TestCase
{
    // ------------------------------------------------------------------ helpers

    private function my(array $server = []): MySqlRoutineEditor
    {
        return new MySqlRoutineEditor(null, $server + ['version' => '8.0.36', 'mariadb' => false, 'database' => 'shop', 'current_user' => 'root@%', 'sql_mode' => '']);
    }

    private function maria(string $version): MySqlRoutineEditor
    {
        return $this->my(['version' => $version, 'mariadb' => true]);
    }

    private function pg(int $version = 170000): PgSqlRoutineEditor
    {
        return new PgSqlRoutineEditor(null, ['pg_version' => $version, 'schema' => 'public', 'current_user' => 'postgres']);
    }

    private function mssql(string $version = '16.0.1000.6'): SqlSrvRoutineEditor
    {
        return new SqlSrvRoutineEditor(null, ['version' => $version, 'schema' => 'dbo']);
    }

    private function assertBadRequest(callable $fn, string $contains = ''): void
    {
        try {
            $fn();
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('bad_request', $e->getErrorCode());
            if ($contains !== '') {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    private static function isSingleExpression(string $s): bool
    {
        $m = new \ReflectionMethod(RoutineEditor::class, 'isSingleExpression');
        $m->setAccessible(true);
        return (bool) $m->invoke(null, $s);
    }

    private function proc(array $over = []): array
    {
        return $over + [
            'type' => 'PROCEDURE',
            'name' => 'p',
            'parameters' => [['mode' => 'IN', 'name' => 'a', 'type' => 'INT']],
            'body' => 'BEGIN SELECT a; END',
        ];
    }

    // ------------------------------------------------------------------ MySQL routines

    public function testMysqlProcedureWithParamModesAndQuoting(): void
    {
        $sql = $this->my()->createRoutineSql($this->proc([
            'name' => 'my`proc',
            'parameters' => [
                ['mode' => 'IN', 'name' => 'a', 'type' => 'INT UNSIGNED'],
                ['mode' => 'out', 'name' => 'b`x', 'type' => 'VARCHAR(10)'],
                ['mode' => 'INOUT', 'name' => 'c', 'type' => "ENUM('x','y')"],
                ['name' => 'd', 'type' => 'DECIMAL(12,2)'],
            ],
            'deterministic' => true,
            'data_access' => 'reads sql data',
            'security' => 'invoker',
            'comment' => "it's a\\b",
        ]));
        $this->assertSame(
            "CREATE PROCEDURE `shop`.`my``proc`(IN `a` INT UNSIGNED, OUT `b``x` VARCHAR(10), INOUT `c` ENUM('x','y'), IN `d` DECIMAL(12,2))\n"
            . "COMMENT 'it''s a\\\\b'\nDETERMINISTIC\nREADS SQL DATA\nSQL SECURITY INVOKER\nBEGIN SELECT a; END",
            $sql
        );
    }

    public function testMysqlDefaultCharacteristics(): void
    {
        $sql = $this->my()->createRoutineSql($this->proc(['parameters' => []]));
        $this->assertSame("CREATE PROCEDURE `shop`.`p`()\nNOT DETERMINISTIC\nCONTAINS SQL\nSQL SECURITY DEFINER\nBEGIN SELECT a; END", $sql);
    }

    public function testMysqlFunctionReturnsAndRefusesOutParameters(): void
    {
        $def = ['type' => 'FUNCTION', 'name' => 'f', 'parameters' => [['name' => 'x', 'type' => 'INT']], 'returns' => 'DECIMAL(10,2)', 'body' => 'RETURN x * 2', 'deterministic' => true];
        $this->assertSame(
            "CREATE FUNCTION `shop`.`f`(`x` INT) RETURNS DECIMAL(10,2)\nDETERMINISTIC\nCONTAINS SQL\nSQL SECURITY DEFINER\nRETURN x * 2",
            $this->my()->createRoutineSql($def)
        );
        $def['parameters'][0]['mode'] = 'OUT';
        $this->assertBadRequest(fn () => $this->my()->createRoutineSql($def), 'parameter mode');
        $def['parameters'][0]['mode'] = 'IN';
        $def['returns'] = '';
        $this->assertBadRequest(fn () => $this->my()->createRoutineSql($def), 'return type');
    }

    public function testMysqlRejectsInvalidTypesModesAndDuplicates(): void
    {
        $e = $this->my();
        foreach (['INT; DROP TABLE x', 'INT -- x', 'INT /* x */', "VARCHAR(10) DEFAULT 'a", 'INT)', ''] as $bad) {
            $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['parameters' => [['name' => 'a', 'type' => $bad]]])), 'type');
        }
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['parameters' => [['mode' => 'SIDEWAYS', 'name' => 'a', 'type' => 'INT']]])), 'parameter mode');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['parameters' => [['name' => 'a', 'type' => 'INT'], ['name' => 'A', 'type' => 'INT']]])), 'Duplicate');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['type' => 'TRIGGER'])), 'routine type');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['name' => ''])), 'name');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['body' => " \n "])), 'body');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['data_access' => 'EVERYTHING'])), 'data access');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc(['security' => 'ROOT'])), 'security');
    }

    public function testMysqlDefinerClause(): void
    {
        $e = $this->my();
        foreach ([null, '', 'CURRENT_USER', 'current_user()', 'root@%', "'root'@'%'", '`root`@`%`'] as $same) {
            $this->assertStringNotContainsString('DEFINER =', $e->createRoutineSql($this->proc(['definer' => $same])), (string) $same);
        }
        $this->assertStringStartsWith(
            "CREATE DEFINER = 'app'@'10.0.0.%' PROCEDURE",
            $e->createRoutineSql($this->proc(['definer' => 'app@10.0.0.%']))
        );
    }

    public function testMysqlInvalidDefinerRefused(): void
    {
        $this->assertBadRequest(fn () => $this->my()->createRoutineSql($this->proc(['definer' => 'nohost'])), 'definer');
        $this->assertBadRequest(fn () => $this->my()->createRoutineSql($this->proc(['definer' => "x'; DROP@h"])), 'definer');
    }

    public function testMysqlNoDatabaseSelected(): void
    {
        $e = new MySqlRoutineEditor(null, ['version' => '8.0.36', 'mariadb' => false]);
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->proc()), 'database');
    }

    public function testMysqlCreateNewRoutineIsSingleStatement(): void
    {
        $plan = $this->my()->buildRoutineSave($this->proc(), null);
        $this->assertCount(1, $plan['sql']);
        $this->assertStringStartsWith('CREATE PROCEDURE `shop`.`p`(', $plan['sql'][0]);
        $this->assertSame([], $plan['warnings']);
        $this->assertFalse($plan['transactional']);
        $this->assertSame([], $plan['restore']);
    }

    public function testMysqlEditIsDropAndCreateWithWarnings(): void
    {
        $plan = $this->my()->buildRoutineSave($this->proc(), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertCount(2, $plan['sql']);
        $this->assertSame('DROP PROCEDURE IF EXISTS `shop`.`p`', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE PROCEDURE `shop`.`p`(', $plan['sql'][1]);
        $this->assertEqualsCanonicalizing(['grants_lost', 'not_atomic'], $plan['warnings']);
        $this->assertFalse($plan['transactional']);
    }

    public function testMysqlRenameDropsOldName(): void
    {
        $plan = $this->my()->buildRoutineSave($this->proc(['name' => 'q']), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertSame('DROP PROCEDURE IF EXISTS `shop`.`p`', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE PROCEDURE `shop`.`q`(', $plan['sql'][1]);
    }

    public function testMariaDbReplaceChoiceByVersion(): void
    {
        $plan = $this->maria('10.11.6')->buildRoutineSave($this->proc(), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertCount(1, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE PROCEDURE `shop`.`p`(', $plan['sql'][0]);
        $this->assertSame([], $plan['warnings']);

        $plan = $this->maria('10.0.38')->buildRoutineSave($this->proc(), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertCount(2, $plan['sql']);
        $this->assertSame('DROP PROCEDURE IF EXISTS `shop`.`p`', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE PROCEDURE', $plan['sql'][1]);
        $this->assertEqualsCanonicalizing(['grants_lost', 'not_atomic'], $plan['warnings']);

        $this->assertTrue($this->maria('10.1.3')->canReplaceRoutine());
        $this->assertFalse($this->maria('10.1.2')->canReplaceRoutine());
        $this->assertFalse($this->my()->canReplaceRoutine());
    }

    public function testMariaDbRenameCreatesNewThenDropsOld(): void
    {
        $plan = $this->maria('10.11.6')->buildRoutineSave($this->proc(['name' => 'q']), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringStartsWith('CREATE PROCEDURE `shop`.`q`(', $plan['sql'][0]);
        $this->assertSame('DROP PROCEDURE IF EXISTS `shop`.`p`', $plan['sql'][1]);
        $this->assertSame(['grants_lost'], $plan['warnings']);
    }

    public function testMysqlSqlModeIsReappliedWhenItDiffers(): void
    {
        $def = $this->proc(['sql_mode' => 'ANSI_QUOTES,STRICT_ALL_TABLES']);
        $plan = $this->my()->buildRoutineSave($def, ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertSame("SET SESSION sql_mode = 'ANSI_QUOTES,STRICT_ALL_TABLES'", $plan['sql'][0]);
        $this->assertContains('sql_mode_restored', $plan['warnings']);
        $this->assertCount(3, $plan['sql']);

        // same mode as the session: nothing to do
        $plan = $this->my(['sql_mode' => 'ansi_quotes,strict_all_tables'])->buildRoutineSave($def, ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertCount(2, $plan['sql']);
        $this->assertNotContains('sql_mode_restored', $plan['warnings']);

        $this->assertBadRequest(fn () => $this->my()->buildRoutineSave($this->proc(['sql_mode' => "X'; DROP"]), null), 'sql_mode');
    }

    public function testMysqlDrop(): void
    {
        $this->assertSame(['DROP FUNCTION `shop`.`f`'], $this->my()->buildRoutineDrop('f', 'function')['sql']);
        $this->assertBadRequest(fn () => $this->my()->buildRoutineDrop('f', 'view'));
    }

    public function testMysqlBuildExecute(): void
    {
        $info = [
            'name' => 'p', 'type' => 'PROCEDURE',
            'parameters' => [
                ['mode' => 'IN', 'name' => 'a', 'type' => 'int'],
                ['mode' => 'OUT', 'name' => 'b', 'type' => 'int'],
                ['mode' => 'INOUT', 'name' => 'c', 'type' => 'int'],
            ],
        ];
        $plan = $this->my()->buildExecute($info, [['value' => '1'], ['value' => ''], ['value' => '7']]);
        $this->assertSame([
            ['sql' => 'SET @ldb_p2 = NULL', 'params' => [], 'role' => 'setup'],
            ['sql' => 'SET @ldb_p3 = ?', 'params' => ['7'], 'role' => 'setup'],
            ['sql' => 'CALL `shop`.`p`(?, @ldb_p2, @ldb_p3)', 'params' => ['1'], 'role' => 'call'],
            ['sql' => 'SELECT @ldb_p2 AS `b`, @ldb_p3 AS `c`', 'params' => [], 'role' => 'out'],
        ], $plan['steps']);

        $fn = ['name' => 'f', 'type' => 'FUNCTION', 'parameters' => [['mode' => 'IN', 'name' => 'x', 'type' => 'int']]];
        $this->assertSame(
            [['sql' => 'SELECT `shop`.`f`(?) AS `f`', 'params' => [null], 'role' => 'call']],
            $this->my()->buildExecute($fn, [['value' => 'x', 'null' => true]])['steps']
        );
    }

    // ------------------------------------------------------------------ MySQL triggers

    private function trigger(array $over = []): array
    {
        return $over + ['name' => 'trg', 'table' => 't', 'timing' => 'before', 'events' => ['insert'], 'body' => 'SET NEW.a = 1;'];
    }

    public function testMysqlTriggerCreate(): void
    {
        $this->assertSame(
            "CREATE TRIGGER `shop`.`trg` BEFORE INSERT ON `t`\nFOR EACH ROW\nSET NEW.a = 1;",
            $this->my()->createTriggerSql($this->trigger())
        );
        $sql = $this->my()->createTriggerSql($this->trigger(['order' => ['kind' => 'follows', 'trigger' => 'other']]));
        $this->assertStringContainsString("FOR EACH ROW FOLLOWS `other`\n", $sql);
        $sql = $this->my()->createTriggerSql($this->trigger(['order' => ['kind' => 'PRECEDES', 'trigger' => 'o`x']]));
        $this->assertStringContainsString('PRECEDES `o``x`', $sql);
        $this->assertBadRequest(fn () => $this->my()->createTriggerSql($this->trigger(['order' => ['kind' => 'AFTER', 'trigger' => 'o']])), 'order');
    }

    public function testMysqlTriggerRefusesMultipleEvents(): void
    {
        $this->assertBadRequest(fn () => $this->my()->createTriggerSql($this->trigger(['events' => ['INSERT', 'UPDATE']])), 'exactly one');
        $this->assertBadRequest(fn () => $this->my()->createTriggerSql($this->trigger(['events' => []])), 'exactly one');
        $this->assertBadRequest(fn () => $this->my()->createTriggerSql($this->trigger(['timing' => 'INSTEAD OF'])), 'timing');
    }

    public function testMysqlTriggerEditPlans(): void
    {
        $plan = $this->my()->buildTriggerSave($this->trigger(), ['name' => 'trg', 'table' => 't']);
        $this->assertSame('DROP TRIGGER IF EXISTS `shop`.`trg`', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE TRIGGER `shop`.`trg`', $plan['sql'][1]);
        $this->assertSame(['not_atomic'], $plan['warnings']);

        $plan = $this->maria('10.6.12')->buildTriggerSave($this->trigger(), ['name' => 'trg', 'table' => 't']);
        $this->assertCount(1, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE TRIGGER `shop`.`trg`', $plan['sql'][0]);
        $this->assertSame([], $plan['warnings']);

        $plan = $this->maria('10.6.12')->buildTriggerSave($this->trigger(['name' => 'new']), ['name' => 'trg', 'table' => 't']);
        $this->assertStringStartsWith('CREATE TRIGGER `shop`.`new`', $plan['sql'][0]);
        $this->assertSame('DROP TRIGGER IF EXISTS `shop`.`trg`', $plan['sql'][1]);

        $this->assertSame(['DROP TRIGGER `shop`.`trg`'], $this->my()->buildTriggerDrop('trg')['sql']);
    }

    // ------------------------------------------------------------------ MySQL events

    private function event(array $over = []): array
    {
        return $over + [
            'name' => 'ev',
            'schedule' => ['type' => 'every', 'interval_value' => '1', 'interval_field' => 'day'],
            'body' => 'DELETE FROM t',
        ];
    }

    public function testMysqlEventSchedules(): void
    {
        $e = $this->my();
        $this->assertSame("AT '2030-01-01 10:30:00'", $e->scheduleSql(['type' => 'at', 'at' => '2030-01-01T10:30']));
        $this->assertSame("AT '2030-01-01 10:30:15'", $e->scheduleSql(['type' => 'at', 'at' => '2030-01-01 10:30:15']));
        $this->assertSame("AT '2030-01-01'", $e->scheduleSql(['type' => 'at', 'at' => '2030-01-01']));
        $this->assertSame(
            "EVERY 5 MINUTE STARTS '2030-01-01 00:00:00' ENDS '2031-01-01 12:00:00'",
            $e->scheduleSql(['type' => 'every', 'interval_value' => '5', 'interval_field' => 'minute', 'starts' => '2030-01-01T00:00', 'ends' => '2031-01-01T12:00'])
        );
        $this->assertSame("EVERY '1:30' HOUR_MINUTE", $e->scheduleSql(['type' => 'every', 'interval_value' => '1:30', 'interval_field' => 'HOUR_MINUTE']));
        $this->assertSame("EVERY '1 2:3:4' DAY_SECOND", $e->scheduleSql(['type' => 'every', 'interval_value' => '1 2:3:4', 'interval_field' => 'DAY_SECOND']));
    }

    public function testMysqlEventInvalidSchedules(): void
    {
        $e = $this->my();
        foreach (['abc', '0', '-1', '1.5', '5 MINUTE; DROP', ''] as $bad) {
            $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'every', 'interval_value' => $bad, 'interval_field' => 'DAY']), 'interval');
        }
        $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'every', 'interval_value' => "1:30' x", 'interval_field' => 'HOUR_MINUTE']), 'interval');
        $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'every', 'interval_value' => '1', 'interval_field' => 'FORTNIGHT']), 'interval unit');
        $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'at', 'at' => "2030-01-01'; --"]), 'execution time');
        $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'every', 'interval_value' => '1', 'interval_field' => 'DAY', 'starts' => 'tomorrow']), 'start time');
        $this->assertBadRequest(fn () => $e->scheduleSql(['type' => 'sometimes']), 'schedule type');
        $this->assertBadRequest(fn () => $e->scheduleSql(null), 'schedule');
    }

    public function testMysqlEventCreate(): void
    {
        $this->assertSame(
            "CREATE EVENT `shop`.`ev`\nON SCHEDULE EVERY 1 DAY\nON COMPLETION NOT PRESERVE\nENABLE\nDO DELETE FROM t",
            $this->my()->createEventSql($this->event())
        );
        $sql = $this->my()->createEventSql($this->event(['preserve' => true, 'status' => 'disable', 'comment' => "it's"]));
        $this->assertSame(
            "CREATE EVENT `shop`.`ev`\nON SCHEDULE EVERY 1 DAY\nON COMPLETION PRESERVE\nDISABLE\nCOMMENT 'it''s'\nDO DELETE FROM t",
            $sql
        );
    }

    public function testMysqlEventDisableOnSlaveSpelling(): void
    {
        $def = $this->event(['status' => 'DISABLE ON SLAVE']);
        $this->assertStringContainsString("\nDISABLE ON REPLICA\n", $this->my()->createEventSql($def) . "\n");
        $this->assertStringContainsString("\nDISABLE ON SLAVE\n", $this->maria('10.11.6')->createEventSql($def) . "\n");
        // MySQL before 8.0.22 only knows SLAVE
        $this->assertStringContainsString("\nDISABLE ON SLAVE\n", $this->my(['version' => '5.7.40'])->createEventSql($def) . "\n");
        $this->assertStringContainsString("\nDISABLE ON REPLICA\n", $this->my()->createEventSql($this->event(['status' => 'DISABLE ON REPLICA'])) . "\n");
        $this->assertBadRequest(fn () => $this->my()->createEventSql($this->event(['status' => 'MAYBE'])), 'status');
    }

    public function testMysqlEventEditIsAlterWithRename(): void
    {
        $plan = $this->my()->buildEventSave($this->event(['name' => 'ev2']), ['name' => 'ev']);
        $this->assertCount(1, $plan['sql']);
        $this->assertSame(
            "ALTER EVENT `shop`.`ev`\nON SCHEDULE EVERY 1 DAY\nON COMPLETION NOT PRESERVE\nRENAME TO `shop`.`ev2`\nENABLE\nDO DELETE FROM t",
            $plan['sql'][0]
        );
        $this->assertSame([], $plan['warnings']);
        $plan = $this->my()->buildEventSave($this->event(), ['name' => 'ev']);
        $this->assertStringNotContainsString('RENAME TO', $plan['sql'][0]);

        $this->assertSame(['DROP EVENT `shop`.`ev`'], $this->my()->buildEventDrop('ev')['sql']);
    }

    public function testMysqlEventSqlModeReapplied(): void
    {
        $plan = $this->my()->buildEventSave($this->event(['sql_mode' => 'ANSI_QUOTES']), ['name' => 'ev']);
        $this->assertSame("SET SESSION sql_mode = 'ANSI_QUOTES'", $plan['sql'][0]);
        $this->assertStringStartsWith('ALTER EVENT', $plan['sql'][1]);
        $this->assertSame(['sql_mode_restored'], $plan['warnings']);
    }

    public function testMysqlScriptUsesDelimiterForBodiesWithSemicolons(): void
    {
        $e = $this->my();
        $plan = $e->buildRoutineSave($this->proc(), null);
        $script = $e->script($plan);
        $this->assertStringStartsWith("DELIMITER \$\$\n", $script);
        $this->assertStringEndsWith("\$\$\nDELIMITER ;", $script);
        $this->assertStringContainsString("BEGIN SELECT a; END\$\$", $script);

        $plan = $e->buildRoutineSave($this->proc(['body' => 'SELECT 1']), ['name' => 'p', 'type' => 'PROCEDURE']);
        $this->assertSame(
            "DROP PROCEDURE IF EXISTS `shop`.`p`;\n\nCREATE PROCEDURE `shop`.`p`(IN `a` INT)\nNOT DETERMINISTIC\nCONTAINS SQL\nSQL SECURITY DEFINER\nSELECT 1;",
            $e->script($plan)
        );
    }

    // ------------------------------------------------------------------ PostgreSQL routines

    private function pgFn(array $over = []): array
    {
        return $over + [
            'type' => 'FUNCTION', 'name' => 'f',
            'parameters' => [['mode' => 'IN', 'name' => 'a', 'type' => 'integer']],
            'returns' => 'integer', 'language' => 'sql', 'body' => 'SELECT a',
        ];
    }

    public function testPgFunctionWithAllOptions(): void
    {
        $r = $this->pg()->createRoutineSql([
            'type' => 'function', 'name' => 'add_one',
            'parameters' => [
                ['mode' => 'IN', 'name' => 'p_id', 'type' => 'integer'],
                ['mode' => 'OUT', 'name' => 'Mixed', 'type' => 'text'],
                ['mode' => 'INOUT', 'name' => 'order', 'type' => 'int'],
                ['mode' => 'VARIADIC', 'name' => 'rest', 'type' => 'text[]', 'default' => null],
            ],
            'returns' => 'SETOF record',
            'language' => 'SQL',
            'volatility' => 'immutable',
            'strict' => true,
            'security' => 'definer',
            'parallel' => 'safe',
            'cost' => 10,
            'rows' => 5,
            'returns_set' => true,
            'config' => ['search_path=public, pg_temp'],
            'body' => 'SELECT 1',
        ]);
        $this->assertSame(
            "CREATE FUNCTION \"public\".\"add_one\"(p_id integer, OUT \"Mixed\" text, INOUT \"order\" int, VARIADIC rest text[])\n"
            . " RETURNS SETOF record\n LANGUAGE sql\n IMMUTABLE\n STRICT\n PARALLEL SAFE\n COST 10\n ROWS 5\n SECURITY DEFINER\n SET search_path TO public, pg_temp\n"
            . "AS \$\$\nSELECT 1\n\$\$",
            $r['sql']
        );
        $this->assertSame('FUNCTION', $r['type']);
        $this->assertSame('add_one', $r['name']);
        $this->assertSame('integer, OUT text, INOUT int, VARIADIC text[]', $r['sig']);
    }

    public function testPgReturnsVariants(): void
    {
        $e = $this->pg();
        $r = $e->createRoutineSql($this->pgFn(['returns' => 'TABLE(id integer, "my name" text)']));
        $this->assertStringContainsString("\n RETURNS TABLE(id integer, \"my name\" text)\n", $r['sql']);
        $r = $e->createRoutineSql($this->pgFn(['returns' => 'setof   text']));
        $this->assertStringContainsString("\n RETURNS SETOF text\n", $r['sql']);
        $r = $e->createRoutineSql($this->pgFn(['returns' => 'trigger', 'parameters' => [], 'language' => 'plpgsql']));
        $this->assertStringContainsString("\n RETURNS trigger\n LANGUAGE plpgsql\n", $r['sql']);
        // OUT parameters imply the result type
        $r = $e->createRoutineSql($this->pgFn(['returns' => null, 'parameters' => [['mode' => 'OUT', 'name' => 'o', 'type' => 'int']]]));
        $this->assertStringNotContainsString('RETURNS', $r['sql']);
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['returns' => ''])), 'return type');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['returns' => 'TABLE(id integer; x int)'])), 'Invalid');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['returns' => 'TABLE()'])), 'RETURNS TABLE');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['returns' => 'int; DROP TABLE x'])), 'return type');
    }

    public function testPgDollarQuoting(): void
    {
        $e = $this->pg();
        $r = $e->createRoutineSql($this->pgFn(['body' => 'SELECT $$x$$']));
        $this->assertStringEndsWith("AS \$ldb\$\nSELECT \$\$x\$\$\n\$ldb\$", $r['sql']);
        $r = $e->createRoutineSql($this->pgFn(['body' => 'SELECT $$x$$, $ldb$y$ldb$']));
        $this->assertStringContainsString("\nAS \$ldb1\$\nSELECT \$\$x\$\$, \$ldb\$y\$ldb\$\n\$ldb1\$", $r['sql']);
        $this->assertSame("\$\$\nabc\n\$\$", PgSqlRoutineEditor::dollarQuote('abc'));
    }

    public function testPgParameterNameQuotingAndValidation(): void
    {
        $e = $this->pg();
        $sql = $e->createRoutineSql($this->pgFn(['parameters' => [
            ['name' => 'p_id', 'type' => 'int'],
            ['name' => 'order', 'type' => 'int'],
            ['name' => 'Mixed', 'type' => 'int'],
            ['name' => 'has space', 'type' => 'int'],
            ['name' => '', 'type' => 'int'],
        ]]))['sql'];
        $this->assertStringContainsString('(p_id int, "order" int, "Mixed" int, "has space" int, int)', $sql);

        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['parameters' => [['mode' => 'OUT', 'name' => 'o', 'type' => 'int', 'default' => '1']]])), 'OUT parameters cannot have a default');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['parameters' => [['name' => 'a', 'type' => 'int'], ['name' => 'a', 'type' => 'int']]])), 'Duplicate');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['parameters' => [['mode' => 'BOTH', 'name' => 'a', 'type' => 'int']]])), 'mode');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['parameters' => [['name' => 'a', 'type' => 'int); DROP TABLE x; --']]])), 'type');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['language' => 'c'])), 'language');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['language' => 'sql; drop'])), 'language');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['volatility' => 'MOODY'])), 'volatility');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['cost' => -1])), 'cost');
    }

    public function testPgDefaultsMustBeSingleExpressions(): void
    {
        $e = $this->pg();
        $sql = $e->createRoutineSql($this->pgFn(['parameters' => [['name' => 'a', 'type' => 'text', 'default' => "'a;b'::text"]]]))['sql'];
        $this->assertStringContainsString("(a text DEFAULT 'a;b'::text)", $sql);
        foreach (['1); DROP TABLE x; --', '1 -- c', '1; 2', '(1', '1) x', '/* c */ 1'] as $bad) {
            $this->assertBadRequest(
                fn () => $e->createRoutineSql($this->pgFn(['parameters' => [['name' => 'a', 'type' => 'int', 'default' => $bad]]])),
                'default'
            );
        }
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->pgFn(['config' => ['search_path=public; DROP']])), 'configuration');
    }

    public function testPgConfigValueQuoting(): void
    {
        $e = $this->pg();
        $sql = $e->createRoutineSql($this->pgFn(['config' => ['work_mem = 64MB', 'search_path TO "$user", public', 'application_name=my app']]))['sql'];
        $this->assertStringContainsString("\n SET work_mem TO 64MB\n", $sql);
        $this->assertStringContainsString("\n SET search_path TO \"\$user\", public\n", $sql);
        $this->assertStringContainsString("\n SET application_name TO 'my app'\n", $sql);
    }

    public function testPgProcedure(): void
    {
        $r = $this->pg()->createRoutineSql([
            'type' => 'PROCEDURE', 'name' => 'proc',
            'parameters' => [['mode' => 'INOUT', 'name' => 'x', 'type' => 'int'], ['mode' => 'OUT', 'name' => 'y', 'type' => 'text']],
            'language' => 'plpgsql', 'security' => 'DEFINER', 'body' => 'BEGIN y := x::text; END',
        ]);
        $this->assertSame(
            "CREATE PROCEDURE \"public\".\"proc\"(INOUT x int, OUT y text)\n LANGUAGE plpgsql\n SECURITY DEFINER\nAS \$\$\nBEGIN y := x::text; END\n\$\$",
            $r['sql']
        );
        // OUT in procedures needs PostgreSQL 14
        $this->assertBadRequest(fn () => $this->pg(130000)->createRoutineSql([
            'type' => 'PROCEDURE', 'name' => 'p', 'parameters' => [['mode' => 'OUT', 'name' => 'y', 'type' => 'text']], 'body' => 'x',
        ]), 'OUT');
    }

    public function testPgCreateNewWithComment(): void
    {
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['comment' => "it's"]), null);
        $this->assertCount(2, $plan['sql']);
        $this->assertSame('COMMENT ON FUNCTION "public"."f"(integer) IS \'it\'\'s\'', $plan['sql'][1]);
        $this->assertTrue($plan['transactional']);
        $plan = $this->pg()->buildRoutineSave($this->pgFn(), null);
        $this->assertCount(1, $plan['sql']);
        $this->assertFalse($plan['transactional']);
        // owner differing from the connected user
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['definer' => 'app']), null);
        $this->assertSame('ALTER FUNCTION "public"."f"(integer) OWNER TO "app"', $plan['sql'][1]);
    }

    public function testPgEditSameShapeReplacesRenamesAndComments(): void
    {
        $original = $this->pgFn(['signature' => 'a integer', 'comment' => 'old', 'definer' => 'postgres']);
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['comment' => 'new', 'body' => 'SELECT a + 1']), $original);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION "public"."f"(a integer)', $plan['sql'][0]);
        $this->assertSame('COMMENT ON FUNCTION "public"."f"(integer) IS \'new\'', $plan['sql'][1]);
        $this->assertSame([], $plan['warnings']);
        $this->assertTrue($plan['transactional']);

        $plan = $this->pg()->buildRoutineSave($this->pgFn(['name' => 'g', 'comment' => 'old']), $original);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION "public"."f"(a integer)', $plan['sql'][0]);
        $this->assertSame('ALTER FUNCTION "public"."f"(a integer) RENAME TO "g"', $plan['sql'][1]);
        $this->assertSame([], $plan['warnings']);

        // comment removed
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['comment' => '']), $original);
        $this->assertSame('COMMENT ON FUNCTION "public"."f"(integer) IS NULL', $plan['sql'][1]);

        // unchanged comment: no COMMENT statement
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['comment' => 'old']), $original);
        $this->assertCount(1, $plan['sql']);
    }

    public function testPgEditChangedParameterTypeIsDropAndCreate(): void
    {
        $original = $this->pgFn(['signature' => 'a integer']);
        $def = $this->pgFn(['parameters' => [['mode' => 'IN', 'name' => 'a', 'type' => 'text']]]);
        $plan = $this->pg()->buildRoutineSave($def, $original);
        $this->assertCount(2, $plan['sql']);
        $this->assertSame('DROP FUNCTION "public"."f"(a integer)', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE FUNCTION "public"."f"(a text)', $plan['sql'][1]);
        $this->assertSame(['grants_lost'], $plan['warnings']);
        $this->assertTrue($plan['transactional']);

        // changed return type as well
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['returns' => 'bigint']), $original);
        $this->assertStringStartsWith('DROP FUNCTION', $plan['sql'][0]);
        // changed language
        $plan = $this->pg()->buildRoutineSave($this->pgFn(['language' => 'plpgsql']), $original);
        $this->assertStringStartsWith('DROP FUNCTION', $plan['sql'][0]);
    }

    public function testPgDropAndSignatureValidation(): void
    {
        $this->assertSame(['DROP FUNCTION "public"."f"(integer, text)'], $this->pg()->buildRoutineDrop('f', 'function', 'integer, text')['sql']);
        $this->assertSame(['DROP PROCEDURE "public"."f"()'], $this->pg()->buildRoutineDrop('f', 'PROCEDURE', '')['sql']);
        $this->assertBadRequest(fn () => $this->pg()->buildRoutineDrop('f', 'function', 'integer); DROP TABLE x; --'), 'signature');
        $this->assertBadRequest(fn () => $this->pg()->buildRoutineSave($this->pgFn(), $this->pgFn(['signature' => 'x); DROP TABLE y; --'])), 'signature');
    }

    public function testPgBuildExecute(): void
    {
        $fn = ['name' => 'f', 'type' => 'FUNCTION', 'returns' => 'integer', 'parameters' => [
            ['mode' => 'IN', 'name' => 'a', 'type' => 'integer'],
            ['mode' => 'IN', 'name' => 'b', 'type' => 'character varying(20)'],
        ]];
        $plan = $this->pg()->buildExecute($fn, [['value' => '1'], ['value' => 'x']]);
        $this->assertSame([[
            'sql' => 'SELECT * FROM "public"."f"(CAST(? AS integer), CAST(? AS character varying(20)))',
            'params' => ['1', 'x'], 'role' => 'call',
        ]], $plan['steps']);

        $proc = ['name' => 'p', 'type' => 'PROCEDURE', 'parameters' => [
            ['mode' => 'IN', 'name' => 'a', 'type' => 'integer'],
            ['mode' => 'OUT', 'name' => 'b', 'type' => 'text'],
            ['mode' => 'INOUT', 'name' => 'c', 'type' => 'integer'],
        ]];
        $plan = $this->pg()->buildExecute($proc, [['value' => '1'], ['value' => ''], ['value' => '', 'null' => true]]);
        $this->assertSame([[
            'sql' => 'CALL "public"."p"(CAST(? AS integer), NULL::text, CAST(? AS integer))',
            'params' => ['1', null], 'role' => 'out',
        ]], $plan['steps']);

        // function OUT parameters are result columns, not arguments
        $fnOut = ['name' => 'f', 'type' => 'FUNCTION', 'returns' => null, 'parameters' => [
            ['mode' => 'IN', 'name' => 'a', 'type' => 'integer'], ['mode' => 'OUT', 'name' => 'o', 'type' => 'integer'],
        ]];
        $this->assertSame('SELECT * FROM "public"."f"(CAST(? AS integer))', $this->pg()->buildExecute($fnOut, [['value' => '1']])['steps'][0]['sql']);

        // trigger function cannot be called
        $this->assertBadRequest(fn () => $this->pg()->buildExecute(['name' => 't', 'type' => 'FUNCTION', 'returns' => 'trigger', 'parameters' => []], []), 'Trigger');
    }

    public function testPgBuildExecuteSkippedDefaultUsesNamedNotation(): void
    {
        $info = ['name' => 'f', 'type' => 'FUNCTION', 'returns' => 'integer', 'parameters' => [
            ['mode' => 'IN', 'name' => 'a', 'type' => 'integer'],
            ['mode' => 'IN', 'name' => 'order', 'type' => 'text', 'default' => "'x'"],
        ]];
        $plan = $this->pg()->buildExecute($info, [['value' => '1'], ['default' => true]]);
        $this->assertSame('SELECT * FROM "public"."f"(a => CAST(? AS integer))', $plan['steps'][0]['sql']);
        $this->assertSame(['1'], $plan['steps'][0]['params']);

        $plan = $this->pg()->buildExecute($info, [['value' => '1'], ['value' => 'y']]);
        $this->assertSame('SELECT * FROM "public"."f"(CAST(? AS integer), CAST(? AS text))', $plan['steps'][0]['sql']);

        // unnamed parameters cannot be skipped
        $info['parameters'][0]['name'] = '';
        $this->assertBadRequest(fn () => $this->pg()->buildExecute($info, [['value' => '1'], ['default' => true]]), 'named');
    }

    // ------------------------------------------------------------------ PostgreSQL triggers

    private function pgTrigger(array $over = []): array
    {
        return $over + [
            'name' => 'trg', 'table' => 't', 'timing' => 'before',
            'events' => ['DELETE', 'UPDATE', 'INSERT'], 'columns' => ['a', 'b"c'],
            'level' => 'statement', 'when' => 'NEW.a > 1',
            'function' => 'tf', 'function_schema' => 'public', 'arguments' => ["a'b", 'c'],
        ];
    }

    public function testPgTriggerCreate(): void
    {
        $this->assertSame(
            "CREATE TRIGGER \"trg\" BEFORE INSERT OR UPDATE OF \"a\", \"b\"\"c\" OR DELETE ON \"public\".\"t\"\n"
            . "FOR EACH STATEMENT\nWHEN (NEW.a > 1)\nEXECUTE FUNCTION \"public\".\"tf\"('a''b', 'c')",
            $this->pg()->createTriggerSql($this->pgTrigger())
        );
        $this->assertSame(
            "CREATE TRIGGER \"trg\" INSTEAD OF DELETE ON \"public\".\"t\"\nFOR EACH ROW\nEXECUTE FUNCTION \"tf\"()",
            $this->pg()->createTriggerSql(['name' => 'trg', 'table' => 't', 'timing' => 'INSTEAD OF', 'events' => ['delete'], 'function' => 'tf'])
        );
        $this->assertStringContainsString("\nEXECUTE PROCEDURE ", $this->pg(100000)->createTriggerSql($this->pgTrigger()));
        $this->assertStringContainsString("\nEXECUTE FUNCTION ", $this->pg(110000)->createTriggerSql($this->pgTrigger()));
    }

    public function testPgTriggerValidation(): void
    {
        $e = $this->pg();
        $this->assertBadRequest(fn () => $e->createTriggerSql($this->pgTrigger(['events' => []])), 'event');
        $this->assertBadRequest(fn () => $e->createTriggerSql($this->pgTrigger(['events' => ['MERGE']])), 'event');
        $this->assertBadRequest(fn () => $e->createTriggerSql($this->pgTrigger(['timing' => 'DURING'])), 'timing');
        $this->assertBadRequest(fn () => $e->createTriggerSql($this->pgTrigger(['level' => 'TABLE'])), 'level');
        foreach (['1); DROP TABLE x; --', 'NEW.a > 1 -- x', '(NEW.a > 1', 'NEW.a > 1) OR (1=1'] as $bad) {
            $this->assertBadRequest(fn () => $e->createTriggerSql($this->pgTrigger(['when' => $bad])), 'WHEN');
        }
    }

    public function testPgTriggerEditByVersion(): void
    {
        $orig = ['name' => 'trg', 'table' => 't'];
        $plan = $this->pg(130000)->buildTriggerSave($this->pgTrigger(), $orig);
        $this->assertSame('DROP TRIGGER "trg" ON "public"."t"', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE TRIGGER "trg"', $plan['sql'][1]);
        $this->assertTrue($plan['transactional']);
        $this->assertSame([], $plan['warnings']);

        $plan = $this->pg(170000)->buildTriggerSave($this->pgTrigger(), $orig);
        $this->assertCount(1, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE TRIGGER "trg"', $plan['sql'][0]);

        $plan = $this->pg(170000)->buildTriggerSave($this->pgTrigger(['name' => 'new']), $orig);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR REPLACE TRIGGER "trg" ', $plan['sql'][0]);
        $this->assertSame('ALTER TRIGGER "trg" ON "public"."t" RENAME TO "new"', $plan['sql'][1]);
        $this->assertTrue($plan['transactional']);

        // other table: DROP + CREATE even on 17
        $plan = $this->pg(170000)->buildTriggerSave($this->pgTrigger(['table' => 'u']), $orig);
        $this->assertSame('DROP TRIGGER "trg" ON "public"."t"', $plan['sql'][0]);
        $this->assertStringContainsString('ON "public"."u"', $plan['sql'][1]);

        $this->assertBadRequest(fn () => $this->pg()->buildTriggerSave($this->pgTrigger(), $orig + ['constraint' => true]), 'Constraint');
        $this->assertSame(['DROP TRIGGER "trg" ON "public"."t"'], $this->pg()->buildTriggerDrop('trg', 't')['sql']);
        $this->assertBadRequest(fn () => $this->pg()->buildTriggerDrop('trg'), 'table');
    }

    public function testPgDoesNotSupportEvents(): void
    {
        $this->expectException(ApiException::class);
        $this->pg()->buildEventSave([], null);
    }

    // ------------------------------------------------------------------ SQL Server

    private function msProc(array $over = []): array
    {
        return $over + [
            'type' => 'PROCEDURE', 'name' => 'p',
            'parameters' => [
                ['name' => 'id', 'type' => 'int'],
                ['mode' => 'IN', 'name' => '@name', 'type' => 'nvarchar(50)', 'default' => "N'x'"],
                ['mode' => 'OUT', 'name' => '@res', 'type' => 'int'],
            ],
            'execute_as' => 'owner', 'recompile' => true, 'body' => 'SELECT 1',
        ];
    }

    public function testSqlSrvProcedure(): void
    {
        $this->assertSame(
            "CREATE PROCEDURE [dbo].[p]\n    @id int,\n    @name nvarchar(50) = N'x',\n    @res int OUTPUT\nWITH RECOMPILE, EXECUTE AS OWNER\nAS\nSELECT 1",
            $this->mssql()->createRoutineSql($this->msProc())
        );
        $this->assertSame("CREATE PROCEDURE [dbo].[p]\nAS\nSELECT 1", $this->mssql()->createRoutineSql($this->msProc(['parameters' => [], 'execute_as' => 'CALLER', 'recompile' => false])));
        $this->assertStringContainsString("WITH EXECUTE AS N'app user'\n", $this->mssql()->createRoutineSql($this->msProc(['execute_as' => "'app user'", 'recompile' => false])));
    }

    public function testSqlSrvFunctions(): void
    {
        $e = $this->mssql();
        $scalar = ['type' => 'FUNCTION', 'name' => 'f', 'parameters' => [['name' => '@a', 'type' => 'int']], 'returns' => 'int', 'body' => 'BEGIN RETURN @a END', 'schemabinding' => true];
        $this->assertSame("CREATE FUNCTION [dbo].[f](@a int)\nRETURNS int\nWITH SCHEMABINDING\nAS\nBEGIN RETURN @a END", $e->createRoutineSql($scalar));

        $inline = ['type' => 'FUNCTION', 'name' => 'tf', 'parameters' => [], 'returns' => 'table', 'body' => 'RETURN (SELECT 1 AS x)'];
        $this->assertSame("CREATE FUNCTION [dbo].[tf]()\nRETURNS TABLE\nAS\nRETURN (SELECT 1 AS x)", $e->createRoutineSql($inline));

        $multi = ['type' => 'FUNCTION', 'name' => 'mf', 'parameters' => [], 'returns' => '@t TABLE (id int, name nvarchar(10))', 'body' => 'BEGIN INSERT @t VALUES (1, N\'a\'); RETURN; END'];
        $this->assertSame(
            "CREATE FUNCTION [dbo].[mf]()\nRETURNS @t TABLE (id int, name nvarchar(10))\nAS\nBEGIN INSERT @t VALUES (1, N'a'); RETURN; END",
            $e->createRoutineSql($multi)
        );

        // functions take IN parameters only
        $scalar['parameters'][0]['mode'] = 'OUT';
        $this->assertBadRequest(fn () => $e->createRoutineSql($scalar), 'mode');
    }

    public function testSqlSrvValidation(): void
    {
        $e = $this->mssql();
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->msProc(['parameters' => [['name' => 'a b', 'type' => 'int']]])), 'parameter name');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->msProc(['parameters' => [['name' => 'a', 'type' => 'int'], ['name' => '@A', 'type' => 'int']]])), 'Duplicate');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->msProc(['parameters' => [['name' => 'a', 'type' => 'int; DROP TABLE x']]])), 'type');
        $this->assertBadRequest(fn () => $e->createRoutineSql($this->msProc(['parameters' => [['name' => 'a', 'type' => 'int', 'default' => '1; DROP TABLE x']]])), 'default');
        $this->assertBadRequest(fn () => $e->createRoutineSql(['type' => 'FUNCTION', 'name' => 'f', 'returns' => '@t TABLE (a int); DROP TABLE x --', 'body' => 'x']), 'return type');
    }

    public function testSqlSrvEditUsesCreateOrAlterOrAlterByVersion(): void
    {
        $def = ['type' => 'FUNCTION', 'name' => 'f', 'parameters' => [], 'returns' => 'int', 'body' => 'BEGIN RETURN 1 END'];
        $orig = ['name' => 'f', 'type' => 'FUNCTION', 'function_kind' => 'scalar'];

        $plan = $this->mssql('16.0.1000.6')->buildRoutineSave($def, $orig);
        $this->assertCount(1, $plan['sql']);
        $this->assertStringStartsWith('CREATE OR ALTER FUNCTION [dbo].[f]()', $plan['sql'][0]);
        $this->assertSame([], $plan['warnings']);
        $this->assertFalse($plan['transactional']);

        $plan = $this->mssql('12.0.2000.8')->buildRoutineSave($def, $orig);
        $this->assertStringStartsWith('ALTER FUNCTION [dbo].[f]()', $plan['sql'][0]);
        $this->assertTrue($this->mssql('13.0.4001.0')->canCreateOrAlter());
        $this->assertFalse($this->mssql('13.0.1601.5')->canCreateOrAlter());

        // comment change adds an extended property call
        $plan = $this->mssql()->buildRoutineSave($def + ['comment' => "it's"], $orig + ['comment' => '']);
        $this->assertCount(2, $plan['sql']);
        $this->assertStringContainsString("sys.sp_addextendedproperty @name = N'MS_Description', @value = N'it''s'", $plan['sql'][1]);
        $this->assertTrue($plan['transactional']);
        $plan = $this->mssql()->buildRoutineSave($def + ['comment' => 'new'], $orig + ['comment' => 'old']);
        $this->assertStringContainsString('sp_updateextendedproperty', $plan['sql'][1]);
        $plan = $this->mssql()->buildRoutineSave($def + ['comment' => ''], $orig + ['comment' => 'old']);
        $this->assertStringContainsString('sp_dropextendedproperty', $plan['sql'][1]);
    }

    public function testSqlSrvRenameOrKindChangeIsDropAndCreate(): void
    {
        $def = ['type' => 'FUNCTION', 'name' => 'g', 'parameters' => [], 'returns' => 'int', 'body' => 'BEGIN RETURN 1 END'];
        $plan = $this->mssql()->buildRoutineSave($def, ['name' => 'f', 'type' => 'FUNCTION', 'function_kind' => 'scalar']);
        $this->assertSame('DROP FUNCTION [dbo].[f]', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE FUNCTION [dbo].[g]()', $plan['sql'][1]);
        $this->assertSame(['grants_lost'], $plan['warnings']);
        $this->assertTrue($plan['transactional']);

        // scalar -> inline TVF cannot be ALTERed
        $plan = $this->mssql()->buildRoutineSave(['returns' => 'TABLE', 'name' => 'f'] + $def, ['name' => 'f', 'type' => 'FUNCTION', 'function_kind' => 'scalar']);
        $this->assertSame('DROP FUNCTION [dbo].[f]', $plan['sql'][0]);

        // function -> procedure
        $plan = $this->mssql()->buildRoutineSave($this->msProc(), ['name' => 'p', 'type' => 'FUNCTION', 'function_kind' => 'scalar']);
        $this->assertSame('DROP FUNCTION [dbo].[p]', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE PROCEDURE', $plan['sql'][1]);

        $this->assertSame(['DROP PROCEDURE [dbo].[p]'], $this->mssql()->buildRoutineDrop('p', 'procedure')['sql']);
    }

    public function testSqlSrvParseProcedureDefinition(): void
    {
        $def = "CREATE PROCEDURE [dbo].[p]\n    @id int,\n    @name nvarchar(50) = N'x' OUTPUT\nWITH EXECUTE AS CALLER AS\nSELECT 1;\nSELECT 2";
        $p = SqlSrvRoutineEditor::parseDefinition($def);
        $this->assertNotNull($p);
        $this->assertSame("@id int,\n    @name nvarchar(50) = N'x' OUTPUT", $p['params']);
        $this->assertSame('EXECUTE AS CALLER', $p['options']);
        $this->assertNull($p['returns']);
        $this->assertSame("SELECT 1;\nSELECT 2", $p['body']);

        // parameters in parentheses, comments in the header, no options
        $p = SqlSrvRoutineEditor::parseDefinition("/* hdr AS x */ CREATE  PROC dbo.q (@a int = 5, @b varchar(10))\nAS BEGIN\n SELECT @a\nEND");
        $this->assertNotNull($p);
        $this->assertSame('@a int = 5, @b varchar(10)', $p['params']);
        $this->assertSame('', $p['options']);
        $this->assertSame("BEGIN\n SELECT @a\nEND", $p['body']);

        $this->assertNull(SqlSrvRoutineEditor::parseDefinition('SELECT 1'));
    }

    public function testSqlSrvParseFunctionDefinition(): void
    {
        $p = SqlSrvRoutineEditor::parseDefinition("CREATE FUNCTION dbo.f (@x int)\nRETURNS TABLE\nAS\nRETURN (SELECT @x AS x)");
        $this->assertNotNull($p);
        $this->assertSame('@x int', $p['params']);
        $this->assertSame('TABLE', $p['returns']);
        $this->assertSame('', $p['options']);
        $this->assertSame('RETURN (SELECT @x AS x)', $p['body']);

        $p = SqlSrvRoutineEditor::parseDefinition("CREATE FUNCTION [dbo].[g](@n int = 3) RETURNS @t TABLE (id int) WITH SCHEMABINDING AS BEGIN INSERT @t VALUES (@n); RETURN END");
        $this->assertNotNull($p);
        $this->assertSame('@n int = 3', $p['params']);
        $this->assertSame('@t TABLE (id int)', $p['returns']);
        $this->assertSame('SCHEMABINDING', $p['options']);
        $this->assertSame('BEGIN INSERT @t VALUES (@n); RETURN END', $p['body']);

        $p = SqlSrvRoutineEditor::parseDefinition("CREATE FUNCTION dbo.h() RETURNS int WITH EXECUTE AS OWNER AS BEGIN RETURN 1 END");
        $this->assertNotNull($p);
        $this->assertSame('', $p['params']);
        $this->assertSame('int', $p['returns']);
        $this->assertSame('EXECUTE AS OWNER', $p['options']);
        $this->assertSame('BEGIN RETURN 1 END', $p['body']);
    }

    public function testSqlSrvParseTriggerDefinition(): void
    {
        $p = SqlSrvRoutineEditor::parseDefinition("CREATE TRIGGER [dbo].[trg] ON [dbo].[t]\nAFTER INSERT, UPDATE\nAS\nBEGIN\n SET NOCOUNT ON;\nEND");
        $this->assertNotNull($p);
        $this->assertSame('ON [dbo].[t]
AFTER INSERT, UPDATE', $p['params']);
        $this->assertSame("BEGIN\n SET NOCOUNT ON;\nEND", $p['body']);
    }

    public function testSqlSrvBuildExecuteProcedureWithOutput(): void
    {
        $info = ['name' => 'p', 'type' => 'PROCEDURE', 'parameters' => [
            ['mode' => 'IN', 'name' => '@a', 'type' => 'int'],
            ['mode' => 'OUT', 'name' => '@b', 'type' => 'nvarchar(10)'],
            ['mode' => 'IN', 'name' => '@c', 'type' => 'int READONLY'],
            ['mode' => 'IN', 'name' => '@d', 'type' => 'int', 'default' => '1'],
        ]];
        $plan = $this->mssql()->buildExecute($info, [['value' => '5'], ['value' => 'x'], ['value' => null, 'null' => true], ['default' => true]]);
        $this->assertCount(1, $plan['steps']);
        $step = $plan['steps'][0];
        $this->assertSame('call_out', $step['role']);
        $this->assertSame(
            "SET NOCOUNT ON;\nDECLARE @ldb_rc int;\nDECLARE @ldb_p2 nvarchar(10) = ?;\n"
            . "EXEC @ldb_rc = [dbo].[p] @a = CAST(? AS int), @b = @ldb_p2 OUTPUT, @c = CAST(? AS int);\n"
            . 'SELECT @ldb_rc AS [RETURN_VALUE], @ldb_p2 AS [@b];',
            $step['sql']
        );
        // placeholders occur in text order: DECLARE first, then the EXEC arguments
        $this->assertSame(['x', '5', null], $step['params']);

        $plan = $this->mssql()->buildExecute(['name' => 'p', 'type' => 'PROCEDURE', 'parameters' => [['mode' => 'OUT', 'name' => '@o', 'type' => 'int']]], [['null' => true]]);
        $this->assertStringContainsString('DECLARE @ldb_p1 int = NULL;', $plan['steps'][0]['sql']);
        $this->assertSame([], $plan['steps'][0]['params']);

        $plan = $this->mssql()->buildExecute(['name' => 'p', 'type' => 'PROCEDURE', 'parameters' => []], []);
        $this->assertSame("SET NOCOUNT ON;\nDECLARE @ldb_rc int;\nEXEC @ldb_rc = [dbo].[p];\nSELECT @ldb_rc AS [RETURN_VALUE];", $plan['steps'][0]['sql']);
    }

    public function testSqlSrvBuildExecuteFunctions(): void
    {
        $e = $this->mssql();
        $scalar = ['name' => 'f', 'type' => 'FUNCTION', 'function_kind' => 'scalar', 'parameters' => [['mode' => 'IN', 'name' => '@a', 'type' => 'int'], ['mode' => 'IN', 'name' => '@b', 'type' => 'int', 'default' => '2']]];
        $plan = $e->buildExecute($scalar, [['value' => '1'], ['default' => true]]);
        $this->assertSame([['sql' => 'SELECT [dbo].[f](CAST(? AS int), DEFAULT) AS [f]', 'params' => ['1'], 'role' => 'call']], $plan['steps']);
        $tvf = ['function_kind' => 'inline'] + $scalar;
        $this->assertSame('SELECT * FROM [dbo].[f](CAST(? AS int), CAST(? AS int))', $e->buildExecute($tvf, [['value' => '1'], ['value' => '2']])['steps'][0]['sql']);
    }

    public function testSqlSrvTriggers(): void
    {
        $def = ['name' => 'trg', 'table' => 't', 'timing' => 'for', 'events' => ['update', 'insert'], 'body' => 'SET NOCOUNT ON;'];
        $this->assertSame(
            "CREATE TRIGGER [dbo].[trg] ON [dbo].[t]\nAFTER INSERT, UPDATE\nAS\nSET NOCOUNT ON;",
            $this->mssql()->createTriggerSql($def)
        );
        $this->assertStringStartsWith('CREATE OR ALTER TRIGGER', $this->mssql()->buildTriggerSave($def, ['name' => 'trg', 'table' => 't'])['sql'][0]);
        $this->assertStringStartsWith('ALTER TRIGGER', $this->mssql('12.0.2000.8')->buildTriggerSave($def, ['name' => 'trg', 'table' => 't'])['sql'][0]);
        $plan = $this->mssql()->buildTriggerSave($def, ['name' => 'old', 'table' => 't']);
        $this->assertSame('DROP TRIGGER [dbo].[old]', $plan['sql'][0]);
        $this->assertTrue($plan['transactional']);
        $this->assertBadRequest(fn () => $this->mssql()->createTriggerSql(array_merge($def, ['events' => []])), 'event');
    }

    // ------------------------------------------------------------------ SQLite

    private function lite(): SqliteRoutineEditor
    {
        return new SqliteRoutineEditor(null, []);
    }

    private function liteTrigger(array $over = []): array
    {
        return $over + [
            'name' => 'trg', 'table' => 't', 'timing' => 'before', 'events' => ['update'], 'columns' => ['a'],
            'when' => 'NEW.a > 1', 'body' => 'UPDATE t SET b = 1',
        ];
    }

    public function testSqliteTriggerCreateAndEdit(): void
    {
        $this->assertSame(
            "CREATE TRIGGER \"trg\" BEFORE UPDATE OF \"a\" ON \"t\"\nFOR EACH ROW\nWHEN NEW.a > 1\nBEGIN\nUPDATE t SET b = 1;\nEND",
            $this->lite()->createTriggerSql($this->liteTrigger())
        );
        // semicolon already present: not doubled
        $this->assertStringContainsString("BEGIN\nSELECT 1;\nEND", $this->lite()->createTriggerSql($this->liteTrigger(['body' => "SELECT 1;\n"])));

        $plan = $this->lite()->buildTriggerSave($this->liteTrigger(['name' => 'new']), ['name' => 'trg', 'table' => 't']);
        $this->assertSame('DROP TRIGGER "trg"', $plan['sql'][0]);
        $this->assertStringStartsWith('CREATE TRIGGER "new"', $plan['sql'][1]);
        $this->assertTrue($plan['transactional']);
        $this->assertSame(['DROP TRIGGER "trg"'], $this->lite()->buildTriggerDrop('trg')['sql']);
        $this->assertCount(1, $this->lite()->buildTriggerSave($this->liteTrigger(), null)['sql']);

        $this->assertBadRequest(fn () => $this->lite()->createTriggerSql($this->liteTrigger(['events' => ['INSERT', 'DELETE']])), 'exactly one');
        $this->assertBadRequest(fn () => $this->lite()->createTriggerSql($this->liteTrigger(['when' => '1 BEGIN SELECT 1; END; DROP TABLE x; --'])), 'WHEN');
        $this->assertBadRequest(fn () => $this->lite()->createTriggerSql($this->liteTrigger(['when' => "1) OR (1=1"])), 'WHEN');
    }

    public function testSqliteParseTriggerRoundTrips(): void
    {
        $p = SqliteRoutineEditor::parseTrigger('CREATE TRIGGER IF NOT EXISTS "trg" BEFORE UPDATE OF "a", b ON t FOR EACH ROW WHEN NEW.a > 1 BEGIN UPDATE t SET b = 1; END');
        $this->assertSame(['timing' => 'BEFORE', 'events' => ['UPDATE'], 'columns' => ['a', 'b'], 'level' => 'ROW', 'when' => 'NEW.a > 1', 'body' => 'UPDATE t SET b = 1;'], $p);

        $p = SqliteRoutineEditor::parseTrigger("CREATE TRIGGER main.x INSTEAD OF DELETE ON v\nBEGIN\n DELETE FROM t WHERE id = OLD.id;\nEND");
        $this->assertNotNull($p);
        $this->assertSame('INSTEAD OF', $p['timing']);
        $this->assertSame(['DELETE'], $p['events']);
        $this->assertNull($p['when']);
        $this->assertSame('DELETE FROM t WHERE id = OLD.id;', $p['body']);

        // the word "begin" inside a string literal / quoted identifier does not start the body
        $p = SqliteRoutineEditor::parseTrigger("CREATE TRIGGER y AFTER INSERT ON t WHEN NEW.s = 'begin' AND NEW.\"begin\" = 1 BEGIN SELECT 1; END");
        $this->assertNotNull($p);
        $this->assertSame("NEW.s = 'begin' AND NEW.\"begin\" = 1", $p['when']);
        $this->assertSame('SELECT 1;', $p['body']);
        $this->assertSame('AFTER', $p['timing']);

        // CASE ... END and a comment inside the body
        $p = SqliteRoutineEditor::parseTrigger("CREATE TRIGGER z AFTER DELETE ON t BEGIN\nUPDATE u SET c = CASE WHEN 1 THEN 2 END; -- end\nEND");
        $this->assertNotNull($p);
        $this->assertSame("UPDATE u SET c = CASE WHEN 1 THEN 2 END; -- end", $p['body']);

        // quoted column names
        $p = SqliteRoutineEditor::parseTrigger('CREATE TRIGGER w AFTER UPDATE OF "we""ird", `b` ON t BEGIN SELECT 1; END');
        $this->assertNotNull($p);
        $this->assertSame(['we"ird', 'b'], $p['columns']);

        $this->assertNull(SqliteRoutineEditor::parseTrigger('CREATE TABLE x (a int)'));
        $this->assertNull(SqliteRoutineEditor::parseTrigger('CREATE TRIGGER q AFTER INSERT ON t SELECT 1'));
    }

    public function testSqliteCreatedSqlParsesBackToTheSameDefinition(): void
    {
        $def = $this->liteTrigger(['when' => "NEW.s <> 'a;b'"]);
        $p = SqliteRoutineEditor::parseTrigger($this->lite()->createTriggerSql($def));
        $this->assertNotNull($p);
        $this->assertSame('BEFORE', $p['timing']);
        $this->assertSame(['UPDATE'], $p['events']);
        $this->assertSame(['a'], $p['columns']);
        $this->assertSame("NEW.s <> 'a;b'", $p['when']);
        $this->assertSame('UPDATE t SET b = 1;', $p['body']);
    }

    public function testSqliteRoutinesAndEventsUnsupported(): void
    {
        foreach ([
            fn () => $this->lite()->routineInfo('x'),
            fn () => $this->lite()->buildRoutineSave([], null),
            fn () => $this->lite()->buildEventDrop('x'),
        ] as $fn) {
            try {
                $fn();
                $this->fail('Expected ApiException');
            } catch (ApiException $e) {
                $this->assertSame('unsupported', $e->getErrorCode());
            }
        }
    }

    // ------------------------------------------------------------------ shared helpers

    public function testSplitTopLevel(): void
    {
        $this->assertSame(['a', 'f(b, c)', "'x,y'", '[1,2]', '"q,r"'], RoutineEditor::splitTopLevel("a, f(b, c), 'x,y', [1,2], \"q,r\""));
        $this->assertSame([], RoutineEditor::splitTopLevel(''));
        $this->assertSame(["'it''s, fine'"], RoutineEditor::splitTopLevel("'it''s, fine'"));
        $this->assertSame(['a', 'b'], RoutineEditor::splitTopLevel('a;b', ';'));
        $this->assertSame(['a', 'b'], RoutineEditor::splitTopLevel('a, b,'));
    }

    public function testIsSingleExpression(): void
    {
        foreach (["1", "'a;b'", '"a--b"', '`a#b`', "f(1, (2))", "'it''s'", "'a\\'b;'", '[a;b]', "NEW.a > 1 AND x = 'y'"] as $ok) {
            $this->assertTrue(self::isSingleExpression($ok), $ok);
        }
        foreach (['1); DROP TABLE x; --', 'a;b', 'a -- c', 'a /* c */', 'a # c', 'f(1', 'x)', "'unterminated", '"unterminated', '[unterminated', ') (', ';'] as $bad) {
            $this->assertFalse(self::isSingleExpression($bad), $bad);
        }
    }

    public function testNormalizeArgs(): void
    {
        $this->assertSame([
            ['value' => '1', 'null' => false, 'default' => false],
            ['value' => null, 'null' => true, 'default' => false],
            ['value' => 'x', 'null' => true, 'default' => false],
            ['value' => '1', 'null' => false, 'default' => false],
            ['value' => '0', 'null' => false, 'default' => false],
            ['value' => null, 'null' => true, 'default' => true],
            ['value' => '5', 'null' => false, 'default' => false],
        ], RoutineEditor::normalizeArgs([
            ['value' => '1'], ['value' => null], ['value' => 'x', 'null' => true], ['value' => true], ['value' => false],
            ['default' => true], 5,
        ]));
        $this->assertBadRequest(fn () => RoutineEditor::normalizeArgs([['value' => ['a']]]), 'parameter value');
    }

    public function testScanTokens(): void
    {
        $tok = RoutineEditor::scan("CREATE /* c */ TRIGGER \"a b\" -- x\n (1) 'str' [br]");
        $this->assertSame(
            [['w', 'CREATE'], ['w', 'TRIGGER'], ['q', '"a b"'], ['p', '('], ['w', '1'], ['p', ')'], ['s', "'str'"], ['q', '[br]']],
            array_map(static fn (array $t): array => [$t[0], $t[1]], $tok)
        );
        $this->assertSame([0, 0, 0, 0, 1, 0, 0, 0], array_map(static fn (array $t): int => $t[4], $tok));
        $this->assertSame(['p', '['], array_slice(RoutineEditor::scan('[ident', false)[0], 0, 2));
        $this->assertSame('ab]c', RoutineEditor::unquoteIdent('[ab]]c]'));
        $this->assertSame('a"b', RoutineEditor::unquoteIdent('"a""b"'));
        $this->assertSame('plain', RoutineEditor::unquoteIdent('plain'));
    }

    public function testMysqlEventWithoutScheduleIsRefused(): void
    {
        $this->assertBadRequest(fn () => $this->my()->buildEventSave($this->event(['schedule' => null]), null), 'schedule');
    }
}
