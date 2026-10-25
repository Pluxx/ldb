<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use Ldb\Routine\MySqlRoutineEditor;
use Ldb\Routine\RoutineEditor;
use PHPUnit\Framework\TestCase;

/**
 * Routine / trigger / event editor against a real MySQL-family server. Subclasses name the env prefix
 * (LDB_TEST_MYSQL_* / LDB_TEST_MARIADB_*, see MySqlDriverTestCase). A throwaway database "ldb_it_<random>"
 * with a small table "items" is created and dropped.
 */
abstract class RoutineEditorMySqlTestCase extends TestCase
{
    protected static ?MySqlDriver $d = null;
    protected static string $db = '';

    abstract protected static function envPrefix(): string;

    public static function setUpBeforeClass(): void
    {
        self::$d = null;
        $dsn = getenv(static::envPrefix() . '_DSN');
        if (!$dsn || !extension_loaded('pdo_mysql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $d = new MySqlDriver();
        $d->connect([
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => (string) getenv(static::envPrefix() . '_USER'),
            'password' => (string) getenv(static::envPrefix() . '_PASS'),
            'database' => null,
            'options' => [],
        ]);
        self::$db = 'ldb_it_' . bin2hex(random_bytes(4));
        $d->createDatabase(self::$db, 'utf8mb4_unicode_ci');
        $d->select(self::$db);
        $d->query('CREATE TABLE items (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(50) NOT NULL)', 1);
        $d->query("INSERT INTO items (label) VALUES ('one'), ('two'), ('three')", 1);
        self::$d = $d;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d !== null) {
            self::$d->dropDatabase(self::$db);
            self::$d = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set ' . static::envPrefix() . '_DSN/USER/PASS to run these integration tests');
        }
    }

    private function editor(): MySqlRoutineEditor
    {
        $e = RoutineEditor::forDriver(self::$d);
        $this->assertInstanceOf(MySqlRoutineEditor::class, $e);
        return $e;
    }

    /** @param 'routine'|'trigger'|'event' $kind */
    private function savePlan(MySqlRoutineEditor $e, string $kind, array $plan, ?array $original): array
    {
        $plan = $e->prepareSave($kind, $plan, $original);
        $e->executePlan($plan);
        return $plan;
    }

    private function assertNotFound(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected not_found');
        } catch (ApiException $ex) {
            $this->assertSame('not_found', $ex->getErrorCode());
        }
    }

    private function itemsProc(string $name, string $tag = 'second'): array
    {
        return [
            'type' => 'PROCEDURE',
            'name' => $name,
            'parameters' => [
                ['mode' => 'IN', 'name' => 'min_id', 'type' => 'INT'],
                ['mode' => 'OUT', 'name' => 'total', 'type' => 'INT'],
            ],
            'deterministic' => false,
            'data_access' => 'READS SQL DATA',
            'security' => 'INVOKER',
            'comment' => "it's a test",
            'body' => "BEGIN\n  SELECT id, label FROM items WHERE id >= min_id ORDER BY id;\n"
                . "  SELECT '" . $tag . "' AS tag, COUNT(*) AS n FROM items;\n"
                . "  SELECT COUNT(*) INTO total FROM items WHERE id >= min_id;\nEND",
        ];
    }

    public function testProcedureLifecycle(): void
    {
        $e = $this->editor();
        $def = $this->itemsProc('p_items');

        // create
        $plan = $e->buildRoutineSave($def, null);
        $this->assertCount(1, $plan['sql']);
        $this->savePlan($e, 'routine', $plan, null);

        // round trip
        $info = $e->routineInfo('p_items', 'PROCEDURE');
        $this->assertSame('p_items', $info['name']);
        $this->assertSame('PROCEDURE', $info['type']);
        $this->assertNull($info['returns']);
        $this->assertCount(2, $info['parameters']);
        $this->assertSame(['IN', 'min_id'], [$info['parameters'][0]['mode'], $info['parameters'][0]['name']]);
        $this->assertSame(['OUT', 'total'], [$info['parameters'][1]['mode'], $info['parameters'][1]['name']]);
        $this->assertMatchesRegularExpression('/^int(\(\d+\))?$/', $info['parameters'][0]['type']); // MariaDB reports int(11)
        $this->assertMatchesRegularExpression('/^int(\(\d+\))?$/', $info['parameters'][1]['type']);
        $this->assertSame($def['body'], $info['body']);
        $this->assertFalse($info['deterministic']);
        $this->assertSame('READS SQL DATA', $info['data_access']);
        $this->assertSame('INVOKER', $info['security']);
        $this->assertSame("it's a test", $info['comment']);
        $this->assertStringContainsString('p_items', (string) $info['sql']);

        // execute: two result sets and the OUT value
        $res = $e->execute($info, [['value' => '2'], ['value' => '']], 100, false);
        $this->assertCount(1, $res['results']);
        $first = $res['results'][0];
        $this->assertTrue($first['is_result_set']);
        $this->assertSame(['id', 'label'], array_column($first['columns'], 'name'));
        $this->assertCount(2, $first['rows']);
        $this->assertSame('two', $first['rows'][0][1]);
        $this->assertCount(1, $first['more_results']);
        $second = $first['more_results'][0];
        $this->assertSame(['tag', 'n'], array_column($second['columns'], 'name'));
        $this->assertSame('second', $second['rows'][0][0]);
        $this->assertEquals(3, $second['rows'][0][1]);
        $this->assertCount(1, $res['out']);
        $this->assertSame('total', $res['out'][0]['name']);
        $this->assertEquals(2, $res['out'][0]['value']);
        $this->assertStringContainsString('CALL', $res['sql']);

        // edit: change the body
        $edit = $this->itemsProc('p_items', 'changed');
        $plan = $e->buildRoutineSave($edit, ['name' => 'p_items', 'type' => 'PROCEDURE']);
        if ($e->canReplaceRoutine()) {
            $this->assertCount(1, $plan['sql']);
            $this->assertStringStartsWith('CREATE OR REPLACE PROCEDURE', $plan['sql'][0]);
            $this->assertNotContains('not_atomic', $plan['warnings']);
        } else {
            $this->assertCount(2, $plan['sql']);
            $this->assertContains('not_atomic', $plan['warnings']);
        }
        $ran = $this->savePlan($e, 'routine', $plan, ['name' => 'p_items', 'type' => 'PROCEDURE']);
        if (!$e->canReplaceRoutine()) {
            $this->assertCount(1, $ran['restore']); // captured before the DROP
            $this->assertStringContainsString('CREATE', $ran['restore'][0]);
        }
        $info = $e->routineInfo('p_items');
        $res = $e->execute($info, [['value' => '1'], ['value' => '']], 100, false);
        $this->assertSame('changed', $res['results'][0]['more_results'][0]['rows'][0][0]);
        $this->assertEquals(3, $res['out'][0]['value']);

        // failed edit keeps (MySQL: restores) the original
        $bad = $edit;
        $bad['body'] = 'BEGIN SELECT FROM WHERE; END';
        $plan = $e->buildRoutineSave($bad, ['name' => 'p_items', 'type' => 'PROCEDURE']);
        try {
            $this->savePlan($e, 'routine', $plan, ['name' => 'p_items', 'type' => 'PROCEDURE']);
            $this->fail('Expected the CREATE to fail');
        } catch (ApiException $ex) {
            $this->assertSame('db_error', $ex->getErrorCode());
            $this->assertNotNull($ex->getSql());
            if (!$e->canReplaceRoutine()) {
                $this->assertStringContainsString('restored', $ex->getMessage());
            }
        }
        $info = $e->routineInfo('p_items');
        $res = $e->execute($info, [['value' => '1'], ['value' => '']], 100, false);
        $this->assertSame('changed', $res['results'][0]['more_results'][0]['rows'][0][0]);

        // rename
        $renamed = $this->itemsProc('p_items2', 'renamed');
        $plan = $e->buildRoutineSave($renamed, ['name' => 'p_items', 'type' => 'PROCEDURE']);
        $this->assertContains('grants_lost', $plan['warnings']);
        $this->savePlan($e, 'routine', $plan, ['name' => 'p_items', 'type' => 'PROCEDURE']);
        $this->assertNotFound(fn () => $e->routineInfo('p_items'));
        $info = $e->routineInfo('p_items2', 'PROCEDURE');
        $res = $e->execute($info, [['value' => '3'], ['value' => '']], 100, false);
        $this->assertSame('renamed', $res['results'][0]['more_results'][0]['rows'][0][0]);

        // drop
        $this->savePlan($e, 'routine', $e->buildRoutineDrop('p_items2', 'PROCEDURE'), null);
        $this->assertNotFound(fn () => $e->routineInfo('p_items2'));
    }

    public function testInoutParameterAndNullArgument(): void
    {
        $e = $this->editor();
        $def = [
            'type' => 'PROCEDURE', 'name' => 'p_inout',
            'parameters' => [['mode' => 'INOUT', 'name' => 'x', 'type' => 'INT'], ['mode' => 'IN', 'name' => 'y', 'type' => 'VARCHAR(10)']],
            'body' => 'BEGIN SET x = IFNULL(x, 0) + 10; SELECT y IS NULL AS y_null; END',
        ];
        $this->savePlan($e, 'routine', $e->buildRoutineSave($def, null), null);
        $info = $e->routineInfo('p_inout');
        $this->assertSame('INOUT', $info['parameters'][0]['mode']);
        $res = $e->execute($info, [['value' => '5'], ['value' => 'ignored', 'null' => true]], 10, false);
        $this->assertEquals(15, $res['out'][0]['value']);
        $this->assertEquals(1, $res['results'][0]['rows'][0][0]);
        $this->savePlan($e, 'routine', $e->buildRoutineDrop('p_inout', 'PROCEDURE'), null);
    }

    public function testFunctionLifecycleAndReadOnlyExecute(): void
    {
        $e = $this->editor();
        $def = [
            'type' => 'FUNCTION', 'name' => 'fn_double', 'returns' => 'INT',
            'parameters' => [['name' => 'x', 'type' => 'INT']],
            'deterministic' => true, 'data_access' => 'NO SQL', 'body' => 'RETURN x * 2',
        ];
        $this->savePlan($e, 'routine', $e->buildRoutineSave($def, null), null);

        $info = $e->routineInfo('fn_double', 'FUNCTION');
        $this->assertSame('FUNCTION', $info['type']);
        $this->assertMatchesRegularExpression('/^int(\(\d+\))?$/', $info['returns']);
        $this->assertCount(1, $info['parameters']);
        $this->assertSame(['IN', 'x'], [$info['parameters'][0]['mode'], $info['parameters'][0]['name']]);
        $this->assertTrue($info['deterministic']);
        $this->assertSame('NO SQL', $info['data_access']);
        $this->assertSame('RETURN x * 2', $info['body']);

        $res = $e->execute($info, [['value' => '21']], 10, false);
        $this->assertEquals(42, $res['results'][0]['rows'][0][0]);
        $this->assertSame([], $res['out']);

        // read-only session: a function call is a plain SELECT
        try {
            self::$d->setReadOnly(true);
            $res = $e->execute($info, [['value' => '4']], 10, true);
            $this->assertEquals(8, $res['results'][0]['rows'][0][0]);
            $this->assertNull($res['results'][0]['error']);
        } finally {
            self::$d->setReadOnly(false);
        }

        // edit and a type check of the returned value
        $def['body'] = 'RETURN x * 3';
        $this->savePlan($e, 'routine', $e->buildRoutineSave($def, ['name' => 'fn_double', 'type' => 'FUNCTION']), ['name' => 'fn_double', 'type' => 'FUNCTION']);
        $res = $e->execute($e->routineInfo('fn_double'), [['value' => '2']], 10, false);
        $this->assertEquals(6, $res['results'][0]['rows'][0][0]);

        $this->savePlan($e, 'routine', $e->buildRoutineDrop('fn_double', 'FUNCTION'), null);
        $this->assertNotFound(fn () => $e->routineInfo('fn_double', 'FUNCTION'));
    }

    public function testTriggerLifecycle(): void
    {
        $e = $this->editor();
        $pdo = self::$d->pdo();
        $def = ['name' => 'trg_items_bi', 'table' => 'items', 'timing' => 'BEFORE', 'events' => ['INSERT'], 'body' => 'SET NEW.label = UPPER(NEW.label);'];
        $plan = $e->buildTriggerSave($def, null);
        $this->assertCount(1, $plan['sql']);
        $this->savePlan($e, 'trigger', $plan, null);

        $info = $e->triggerInfo('trg_items_bi');
        $this->assertSame('items', $info['table']);
        $this->assertSame('BEFORE', $info['timing']);
        $this->assertSame(['INSERT'], $info['events']);
        $this->assertStringContainsString('UPPER(NEW.label)', $info['body']);

        $pdo->exec("INSERT INTO items (label) VALUES ('abc')");
        $this->assertSame('ABC', $pdo->query("SELECT label FROM items ORDER BY id DESC LIMIT 1")->fetchColumn());

        // edit in place
        $def['body'] = "SET NEW.label = CONCAT(NEW.label, '!');";
        $plan = $e->buildTriggerSave($def, ['name' => 'trg_items_bi', 'table' => 'items']);
        $ran = $this->savePlan($e, 'trigger', $plan, ['name' => 'trg_items_bi', 'table' => 'items']);
        if (!$e->canReplaceTrigger()) {
            $this->assertContains('not_atomic', $plan['warnings']);
            $this->assertCount(1, $ran['restore']);
        }
        $pdo->exec("INSERT INTO items (label) VALUES ('xyz')");
        $this->assertSame('xyz!', $pdo->query("SELECT label FROM items ORDER BY id DESC LIMIT 1")->fetchColumn());

        // a failing edit leaves the working trigger
        $bad = $def;
        $bad['body'] = 'SET NEW.nope = 1;';
        try {
            $this->savePlan($e, 'trigger', $e->buildTriggerSave($bad, ['name' => 'trg_items_bi', 'table' => 'items']), ['name' => 'trg_items_bi', 'table' => 'items']);
            $this->fail('Expected the CREATE TRIGGER to fail');
        } catch (ApiException $ex) {
            $this->assertSame('db_error', $ex->getErrorCode());
        }
        $pdo->exec("INSERT INTO items (label) VALUES ('still')");
        $this->assertSame('still!', $pdo->query("SELECT label FROM items ORDER BY id DESC LIMIT 1")->fetchColumn());

        // rename + switch to AFTER UPDATE
        $def2 = ['name' => 'trg_items_au', 'table' => 'items', 'timing' => 'AFTER', 'events' => ['UPDATE'], 'body' => "SET @ldb_trg_seen = NEW.label;"];
        $this->savePlan($e, 'trigger', $e->buildTriggerSave($def2, ['name' => 'trg_items_bi', 'table' => 'items']), ['name' => 'trg_items_bi', 'table' => 'items']);
        $this->assertNotFound(fn () => $e->triggerInfo('trg_items_bi'));
        $info = $e->triggerInfo('trg_items_au');
        $this->assertSame('AFTER', $info['timing']);
        $this->assertSame(['UPDATE'], $info['events']);
        $pdo->exec("UPDATE items SET label = 'upd' WHERE id = 1");
        $this->assertSame('upd', $pdo->query('SELECT @ldb_trg_seen')->fetchColumn());
        $pdo->exec("INSERT INTO items (label) VALUES ('plain')");
        $this->assertSame('plain', $pdo->query("SELECT label FROM items ORDER BY id DESC LIMIT 1")->fetchColumn());

        // drop
        $this->savePlan($e, 'trigger', $e->buildTriggerDrop('trg_items_au'), null);
        $this->assertNotFound(fn () => $e->triggerInfo('trg_items_au'));
    }

    public function testEventLifecycle(): void
    {
        $e = $this->editor();
        $def = [
            'name' => 'ev_it',
            'schedule' => ['type' => 'every', 'interval_value' => '1', 'interval_field' => 'DAY', 'starts' => '2037-01-01T00:00'],
            'preserve' => false,
            'status' => 'DISABLE',
            'comment' => "it's",
            'body' => 'DELETE FROM items WHERE id < 0',
        ];
        $plan = $e->buildEventSave($def, null);
        $this->assertStringContainsString("EVERY 1 DAY STARTS '2037-01-01 00:00:00'", $plan['sql'][0]);
        $this->savePlan($e, 'event', $plan, null);

        $info = $e->eventInfo('ev_it');
        $this->assertSame('ev_it', $info['name']);
        $this->assertSame('every', $info['schedule']['type']);
        $this->assertSame('1', $info['schedule']['interval_value']);
        $this->assertSame('DAY', $info['schedule']['interval_field']);
        $this->assertSame('2037-01-01 00:00:00', $info['schedule']['starts']);
        $this->assertNull($info['schedule']['ends']);
        $this->assertFalse($info['preserve']);
        $this->assertSame('DISABLE', $info['status']);
        $this->assertSame("it's", $info['comment']);
        $this->assertSame('DELETE FROM items WHERE id < 0', $info['body']);
        $this->assertStringContainsString('ev_it', (string) $info['sql']);

        // ALTER with rename, compound interval and preserve
        $def2 = $def;
        $def2['name'] = 'ev_it2';
        $def2['schedule'] = ['type' => 'every', 'interval_value' => '1:30', 'interval_field' => 'HOUR_MINUTE', 'starts' => '2037-01-01 00:00:00', 'ends' => '2038-01-01 00:00:00'];
        $def2['preserve'] = true;
        $def2['status'] = 'ENABLE';
        $plan = $e->buildEventSave($def2, ['name' => 'ev_it']);
        $this->assertStringStartsWith('ALTER EVENT', $plan['sql'][0]);
        $this->assertStringContainsString('RENAME TO', $plan['sql'][0]);
        $this->savePlan($e, 'event', $plan, ['name' => 'ev_it']);
        $this->assertNotFound(fn () => $e->eventInfo('ev_it'));
        $info = $e->eventInfo('ev_it2');
        $this->assertTrue($info['preserve']);
        $this->assertSame('ENABLE', $info['status']);
        $this->assertSame('2038-01-01 00:00:00', $info['schedule']['ends']);
        $this->assertContains($info['schedule']['interval_field'], ['HOUR_MINUTE']);
        // the servers report compound interval values quoted ('1:30'); eventInfo() strips the quotes
        $this->assertSame('1:30', $info['schedule']['interval_value']);
        // loading and saving unchanged round-trips
        $this->assertStringContainsString("EVERY '1:30' HOUR_MINUTE", $e->buildEventSave($info, $info)['sql'][0]);

        // one-time schedule
        $def3 = $def2;
        $def3['schedule'] = ['type' => 'at', 'at' => '2037-06-01T12:00'];
        $this->savePlan($e, 'event', $e->buildEventSave($def3, ['name' => 'ev_it2']), ['name' => 'ev_it2']);
        $info = $e->eventInfo('ev_it2');
        $this->assertSame('at', $info['schedule']['type']);
        $this->assertSame('2037-06-01 12:00:00', $info['schedule']['at']);

        $this->savePlan($e, 'event', $e->buildEventDrop('ev_it2'), null);
        $this->assertNotFound(fn () => $e->eventInfo('ev_it2'));
    }
}
