<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\PgSqlDriver;
use Ldb\Http\ApiException;
use Ldb\Routine\PgSqlRoutineEditor;
use Ldb\Routine\RoutineEditor;
use PHPUnit\Framework\TestCase;

/**
 * Routine / trigger editor against a real PostgreSQL server. Skipped unless these env vars are set:
 *   LDB_TEST_PGSQL_DSN, LDB_TEST_PGSQL_USER, LDB_TEST_PGSQL_PASS (see PgSqlDriverTest).
 * A throwaway database "ldb_it_<random>" with a table "items" is created and dropped.
 */
final class RoutineEditorPgSqlTest extends TestCase
{
    private static ?PgSqlDriver $d = null;
    private static string $db = '';
    private static array $params = [];

    public static function setUpBeforeClass(): void
    {
        self::$d = null;
        $dsn = getenv('LDB_TEST_PGSQL_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql')) {
            return;
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^pgsql:/', '', $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        self::$params = [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => (string) getenv('LDB_TEST_PGSQL_USER'),
            'password' => (string) getenv('LDB_TEST_PGSQL_PASS'),
            'database' => $parts['dbname'] ?? 'postgres',
            'options' => [],
        ];
        $d = new PgSqlDriver();
        $d->connect(self::$params);
        self::$db = 'ldb_it_' . bin2hex(random_bytes(4));
        $d->createDatabase(self::$db);
        $d->select(self::$db, 'public');
        $d->pdo()->exec('CREATE TABLE items (id serial PRIMARY KEY, label text NOT NULL)');
        self::$d = $d;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$d !== null) {
            $d = new PgSqlDriver();
            $d->connect(self::$params);
            self::$d = null; // close the connection to the test database first
            gc_collect_cycles();
            $d->pdo()->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
                . $d->pdo()->quote(self::$db) . ' AND pid <> pg_backend_pid()');
            $d->dropDatabase(self::$db);
        }
    }

    protected function setUp(): void
    {
        if (self::$d === null) {
            $this->markTestSkipped('Set LDB_TEST_PGSQL_DSN/USER/PASS to run PostgreSQL integration tests');
        }
    }

    private function editor(): PgSqlRoutineEditor
    {
        $e = RoutineEditor::forDriver(self::$d);
        $this->assertInstanceOf(PgSqlRoutineEditor::class, $e);
        return $e;
    }

    private function save(PgSqlRoutineEditor $e, array $plan): void
    {
        $e->executePlan($e->prepareSave('routine', $plan, null));
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

    private function fn(string $name, string $type, string $body, string $returns, array $over = []): array
    {
        return $over + [
            'type' => 'FUNCTION', 'name' => $name, 'language' => 'sql', 'returns' => $returns,
            'parameters' => [['mode' => 'IN', 'name' => 'a', 'type' => $type]],
            'body' => $body,
        ];
    }

    public function testOverloadsAreEditedBySignature(): void
    {
        $e = $this->editor();
        $this->save($e, $e->buildRoutineSave($this->fn('ov', 'integer', 'SELECT a * 2', 'integer', ['comment' => 'int version']), null));
        $this->save($e, $e->buildRoutineSave($this->fn('ov', 'text', "SELECT a || '!'", 'text'), null));

        // lookup by signature
        $this->assertSame(2, $e->routineInfo('ov')['overloads']);
        $int = $e->routineInfo('ov', 'FUNCTION', 'a integer');
        $txt = $e->routineInfo('ov', 'FUNCTION', 'a text');
        $this->assertSame('a integer', $int['signature']);
        $this->assertSame('integer', $int['returns']);
        $this->assertSame('SELECT a * 2', $int['body']);
        $this->assertSame('int version', $int['comment']);
        $this->assertSame(1, $int['overloads']);
        $this->assertSame('a text', $txt['signature']);
        $this->assertSame('text', $txt['returns']);
        $this->assertSame('', $txt['comment']);
        $this->assertSame('sql', $int['language']);
        $this->assertSame('VOLATILE', $int['volatility']);
        $this->assertSame([['mode' => 'IN', 'name' => 'a', 'type' => 'integer']], $int['parameters']);
        $this->assertNotNull($int['sql']);
        $this->assertNotFound(fn () => $e->routineInfo('ov', 'FUNCTION', 'a bigint'));
        $this->assertNotFound(fn () => $e->routineInfo('ov', 'PROCEDURE'));

        $res = $e->execute($int, [['value' => '21']], 10, false);
        $this->assertEquals(42, $res['results'][0]['rows'][0][0]);
        $res = $e->execute($txt, [['value' => 'hi']], 10, false);
        $this->assertSame('hi!', $res['results'][0]['rows'][0][0]);

        // same shape: CREATE OR REPLACE (+ COMMENT), the other overload stays untouched
        $def = $int;
        $def['body'] = 'SELECT a * 3';
        $def['comment'] = "it's new";
        $plan = $e->buildRoutineSave($def, $int);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION "public"."ov"(a integer)', $plan['sql'][0]);
        $this->assertSame('COMMENT ON FUNCTION "public"."ov"(integer) IS \'it\'\'s new\'', $plan['sql'][1]);
        $this->assertSame([], $plan['warnings']);
        $this->save($e, $plan);
        $this->assertSame(2, $e->routineInfo('ov')['overloads']);
        $int = $e->routineInfo('ov', 'FUNCTION', 'a integer');
        $this->assertSame('SELECT a * 3', $int['body']);
        $this->assertSame("it's new", $int['comment']);
        $this->assertEquals(9, $e->execute($int, [['value' => '3']], 10, false)['results'][0]['rows'][0][0]);
        $txt2 = $e->routineInfo('ov', 'FUNCTION', 'a text');
        $this->assertSame("SELECT a || '!'", $txt2['body']);
        $this->assertSame('', $txt2['comment']);

        // clear the comment
        $def = $int;
        $def['comment'] = '';
        $plan = $e->buildRoutineSave($def, $int);
        $this->assertSame('COMMENT ON FUNCTION "public"."ov"(integer) IS NULL', $plan['sql'][1]);
        $this->save($e, $plan);
        $this->assertSame('', $e->routineInfo('ov', 'FUNCTION', 'a integer')['comment']);

        // rename (same shape): CREATE OR REPLACE + ALTER ... RENAME TO
        $int = $e->routineInfo('ov', 'FUNCTION', 'a integer');
        $def = $int;
        $def['name'] = 'ov_renamed';
        $plan = $e->buildRoutineSave($def, $int);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION "public"."ov"(', $plan['sql'][0]);
        $this->assertSame('ALTER FUNCTION "public"."ov"(a integer) RENAME TO "ov_renamed"', $plan['sql'][1]);
        $this->save($e, $plan);
        $this->assertNotFound(fn () => $e->routineInfo('ov', 'FUNCTION', 'a integer'));
        $renamed = $e->routineInfo('ov_renamed');
        $this->assertEquals(12, $e->execute($renamed, [['value' => '4']], 10, false)['results'][0]['rows'][0][0]);
        $this->assertSame(1, $e->routineInfo('ov')['overloads']); // only text is left

        // changed parameter type: DROP + CREATE in one transaction, grants lost
        $renamed = $e->routineInfo('ov_renamed', 'FUNCTION', 'a integer');
        $def = $renamed;
        $def['parameters'] = [['mode' => 'IN', 'name' => 'a', 'type' => 'bigint']];
        $def['returns'] = 'bigint';
        $plan = $e->buildRoutineSave($def, $renamed);
        $this->assertSame('DROP FUNCTION "public"."ov_renamed"(a integer)', $plan['sql'][0]);
        $this->assertSame(['grants_lost'], $plan['warnings']);
        $this->assertTrue($plan['transactional']);
        $this->save($e, $plan);
        $this->assertNotFound(fn () => $e->routineInfo('ov_renamed', 'FUNCTION', 'a integer'));
        $big = $e->routineInfo('ov_renamed', 'FUNCTION', 'a bigint');
        $this->assertSame('bigint', $big['returns']);

        // a failing DROP + CREATE rolls back (the DROP is undone)
        $bad = $big;
        $bad['parameters'] = [['mode' => 'IN', 'name' => 'a', 'type' => 'text']];
        $bad['returns'] = 'text';
        $bad['body'] = 'SELECT no_such_column FROM nowhere';
        $plan = $e->buildRoutineSave($bad, $big);
        try {
            $this->save($e, $plan);
            $this->fail('Expected the CREATE to fail');
        } catch (ApiException $ex) {
            $this->assertSame('db_error', $ex->getErrorCode());
        }
        $this->assertSame('a bigint', $e->routineInfo('ov_renamed', 'FUNCTION', 'a bigint')['signature']);

        // drops by signature
        $this->save($e, $e->buildRoutineDrop('ov', 'FUNCTION', 'a text'));
        $this->save($e, $e->buildRoutineDrop('ov_renamed', 'FUNCTION', 'a bigint'));
        $this->assertNotFound(fn () => $e->routineInfo('ov'));
        $this->assertNotFound(fn () => $e->routineInfo('ov_renamed'));
    }

    public function testProcedureWithInoutParameter(): void
    {
        $e = $this->editor();
        $def = [
            'type' => 'PROCEDURE', 'name' => 'p_inout', 'language' => 'plpgsql',
            'parameters' => [['mode' => 'INOUT', 'name' => 'x', 'type' => 'integer'], ['mode' => 'IN', 'name' => 'y', 'type' => 'integer', 'default' => '1']],
            'comment' => 'adds',
            'body' => "BEGIN\n  x := x + y;\nEND",
        ];
        $this->save($e, $e->buildRoutineSave($def, null));

        $info = $e->routineInfo('p_inout', 'PROCEDURE');
        $this->assertSame('PROCEDURE', $info['type']);
        $this->assertNull($info['returns']);
        $this->assertSame('plpgsql', $info['language']);
        $this->assertSame('adds', $info['comment']);
        $this->assertSame('INOUT', $info['parameters'][0]['mode']);
        $this->assertSame('IN', $info['parameters'][1]['mode']);
        $this->assertSame('1', $info['parameters'][1]['default']);
        $this->assertSame($def['body'], $info['body']);

        $res = $e->execute($info, [['value' => '5'], ['value' => '3']], 10, false);
        $this->assertSame([], $res['results']);
        $this->assertCount(1, $res['out']);
        $this->assertSame('x', $res['out'][0]['name']);
        $this->assertEquals(8, $res['out'][0]['value']);
        $this->assertStringContainsString('CALL "public"."p_inout"', $res['sql']);

        // skip the parameter with a default: named notation
        $res = $e->execute($info, [['value' => '5'], ['default' => true]], 10, false);
        $this->assertEquals(6, $res['out'][0]['value']);
        $this->assertStringContainsString('x => CAST(', $res['sql']);
        $this->assertStringNotContainsString('y =>', $res['sql']);

        // edit round trip keeps the shape (CREATE OR REPLACE)
        $edit = $info;
        $edit['body'] = "BEGIN\n  x := x * y;\nEND";
        $plan = $e->buildRoutineSave($edit, $info);
        $this->assertStringStartsWith('CREATE OR REPLACE PROCEDURE', $plan['sql'][0]);
        $this->save($e, $plan);
        $res = $e->execute($e->routineInfo('p_inout'), [['value' => '5'], ['value' => '3']], 10, false);
        $this->assertEquals(15, $res['out'][0]['value']);

        $this->save($e, $e->buildRoutineDrop('p_inout', 'PROCEDURE', $info['signature']));
        $this->assertNotFound(fn () => $e->routineInfo('p_inout'));
    }

    public function testSetReturningFunctions(): void
    {
        $e = $this->editor();
        $def = [
            'type' => 'FUNCTION', 'name' => 'gen', 'language' => 'sql',
            'parameters' => [['mode' => 'IN', 'name' => 'n', 'type' => 'integer']],
            'returns' => 'TABLE(i integer, sq integer)',
            'body' => 'SELECT g, g * g FROM generate_series(1, n) AS g',
        ];
        $this->save($e, $e->buildRoutineSave($def, null));

        $info = $e->routineInfo('gen');
        $this->assertTrue($info['returns_set']);
        $this->assertSame('TABLE(i integer, sq integer)', $info['returns']);
        $this->assertCount(1, $info['parameters']); // result columns are not parameters
        $res = $e->execute($info, [['value' => '2']], 10, false);
        $this->assertSame(['i', 'sq'], array_column($res['results'][0]['columns'], 'name'));
        $this->assertCount(2, $res['results'][0]['rows']);
        $this->assertEquals([1, 1], array_map('intval', $res['results'][0]['rows'][0]));
        $this->assertEquals([2, 4], array_map('intval', $res['results'][0]['rows'][1]));

        // edit through the loaded definition
        $edit = $info;
        $edit['body'] = 'SELECT g, g * 10 FROM generate_series(1, n) AS g';
        $plan = $e->buildRoutineSave($edit, $info);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION', $plan['sql'][0]);
        $this->save($e, $plan);
        $res = $e->execute($e->routineInfo('gen'), [['value' => '2']], 10, false);
        $this->assertEquals(20, $res['results'][0]['rows'][1][1]);

        // SETOF scalar
        $def2 = ['type' => 'FUNCTION', 'name' => 'gen2', 'language' => 'sql', 'returns' => 'SETOF text', 'parameters' => [], 'body' => "VALUES ('a'), ('b')"];
        $this->save($e, $e->buildRoutineSave($def2, null));
        $res = $e->execute($e->routineInfo('gen2'), [], 10, false);
        $this->assertCount(2, $res['results'][0]['rows']);

        $this->save($e, $e->buildRoutineDrop('gen', 'FUNCTION', $info['signature']));
        $this->save($e, $e->buildRoutineDrop('gen2', 'FUNCTION', ''));
    }

    public function testFunctionWithOutParametersAndOptions(): void
    {
        $e = $this->editor();
        $def = [
            'type' => 'FUNCTION', 'name' => 'f_out', 'language' => 'plpgsql', 'returns' => null,
            'parameters' => [['mode' => 'IN', 'name' => 'a', 'type' => 'integer'], ['mode' => 'OUT', 'name' => 'dbl', 'type' => 'integer'], ['mode' => 'OUT', 'name' => 'txt', 'type' => 'text']],
            'volatility' => 'IMMUTABLE', 'strict' => true, 'security' => 'DEFINER', 'parallel' => 'SAFE', 'cost' => 50,
            'config' => ['search_path=public, pg_temp'],
            'body' => "BEGIN\n  dbl := a * 2; txt := a::text;\nEND",
        ];
        $this->save($e, $e->buildRoutineSave($def, null));
        $info = $e->routineInfo('f_out');
        $this->assertNull($info['returns']);
        $this->assertSame('IMMUTABLE', $info['volatility']);
        $this->assertTrue($info['strict']);
        $this->assertSame('DEFINER', $info['security']);
        $this->assertSame('SAFE', $info['parallel']);
        $this->assertEquals(50, $info['cost']);
        $this->assertSame(['search_path=public, pg_temp'], $info['config']);
        $this->assertSame(['IN', 'OUT', 'OUT'], array_column($info['parameters'], 'mode'));

        $res = $e->execute($info, [['value' => '4']], 10, false);
        $this->assertSame(['dbl', 'txt'], array_column($res['results'][0]['columns'], 'name'));
        $this->assertEquals(8, $res['results'][0]['rows'][0][0]);
        $this->assertSame('4', $res['results'][0]['rows'][0][1]);

        // round trip: the loaded definition regenerates an equivalent function
        $plan = $e->buildRoutineSave($info, $info);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION', $plan['sql'][0]);
        $this->save($e, $plan);
        $again = $e->routineInfo('f_out');
        foreach (['volatility', 'strict', 'security', 'parallel', 'cost', 'config', 'parameters', 'body'] as $k) {
            $this->assertEquals($info[$k], $again[$k], $k);
        }
        $this->save($e, $e->buildRoutineDrop('f_out', 'FUNCTION', $info['signature']));
    }

    public function testTriggerFunctionAndTriggerLifecycle(): void
    {
        $e = $this->editor();
        $pdo = self::$d->pdo();
        $this->save($e, $e->buildRoutineSave([
            'type' => 'FUNCTION', 'name' => 'trg_upper', 'language' => 'plpgsql', 'returns' => 'trigger', 'parameters' => [],
            'body' => "BEGIN\n  NEW.label := upper(NEW.label);\n  RETURN NEW;\nEND",
        ], null));
        $this->save($e, $e->buildRoutineSave([
            'type' => 'FUNCTION', 'name' => 'trg_note', 'language' => 'plpgsql', 'returns' => 'trigger', 'parameters' => [],
            'body' => "BEGIN\n  PERFORM set_config('ldb.note', TG_ARGV[0], false);\n  RETURN NULL;\nEND",
        ], null));
        $fnInfo = $e->routineInfo('trg_upper');
        $this->assertSame('trigger', $fnInfo['returns']);
        try {
            $e->execute($fnInfo, [], 10, false);
            $this->fail('Trigger functions cannot be executed');
        } catch (ApiException $ex) {
            $this->assertSame('bad_request', $ex->getErrorCode());
        }

        // create
        $def = ['name' => 'trg_items_bi', 'table' => 'items', 'timing' => 'BEFORE', 'events' => ['INSERT'], 'level' => 'ROW', 'function' => 'trg_upper'];
        $plan = $e->buildTriggerSave($def, null);
        $this->save($e, $plan);
        $info = $e->triggerInfo('trg_items_bi', 'items');
        $this->assertSame('items', $info['table']);
        $this->assertSame('BEFORE', $info['timing']);
        $this->assertSame(['INSERT'], $info['events']);
        $this->assertSame('ROW', $info['level']);
        $this->assertSame('trg_upper', $info['function']);
        $this->assertSame([], $info['arguments']);
        $this->assertNull($info['when']);
        $this->assertTrue($info['enabled']);
        $pdo->exec("INSERT INTO items (label) VALUES ('abc')");
        $this->assertSame('ABC', $pdo->query('SELECT label FROM items ORDER BY id DESC LIMIT 1')->fetchColumn());

        // edit + rename: more events, UPDATE OF column, WHEN condition
        $edit = $info;
        $edit['name'] = 'trg_items_bu';
        $edit['events'] = ['INSERT', 'UPDATE'];
        $edit['columns'] = ['label'];
        $edit['when'] = "NEW.label <> 'skip'";
        $plan = $e->buildTriggerSave($edit, $info);
        $this->assertTrue($plan['transactional']);
        $this->save($e, $plan);
        $this->assertNotFound(fn () => $e->triggerInfo('trg_items_bi', 'items'));
        $info = $e->triggerInfo('trg_items_bu');
        $this->assertSame(['INSERT', 'UPDATE'], $info['events']);
        $this->assertSame(['label'], $info['columns']);
        $this->assertStringContainsString("skip", (string) $info['when']);
        $pdo->exec("INSERT INTO items (label) VALUES ('skip')");
        $this->assertSame('skip', $pdo->query('SELECT label FROM items ORDER BY id DESC LIMIT 1')->fetchColumn());
        $pdo->exec("INSERT INTO items (label) VALUES ('def')");
        $this->assertSame('DEF', $pdo->query('SELECT label FROM items ORDER BY id DESC LIMIT 1')->fetchColumn());
        $pdo->exec("UPDATE items SET label = 'changed' WHERE label = 'skip'");
        $this->assertSame(1, (int) $pdo->query("SELECT count(*) FROM items WHERE label = 'CHANGED'")->fetchColumn());

        // AFTER trigger with a literal argument
        $note = ['name' => 'trg_items_ai', 'table' => 'items', 'timing' => 'AFTER', 'events' => ['INSERT'], 'level' => 'ROW', 'function' => 'trg_note', 'arguments' => ["x'y"]];
        $this->save($e, $e->buildTriggerSave($note, null));
        $info2 = $e->triggerInfo('trg_items_ai');
        $this->assertSame(["x'y"], $info2['arguments']);
        $pdo->exec("INSERT INTO items (label) VALUES ('n')");
        $this->assertSame("x'y", $pdo->query("SELECT current_setting('ldb.note')")->fetchColumn());

        // drops
        $this->save($e, $e->buildTriggerDrop('trg_items_bu', 'items'));
        $this->save($e, $e->buildTriggerDrop('trg_items_ai', 'items'));
        $this->assertNotFound(fn () => $e->triggerInfo('trg_items_bu'));
        $pdo->exec("INSERT INTO items (label) VALUES ('lower')");
        $this->assertSame('lower', $pdo->query('SELECT label FROM items ORDER BY id DESC LIMIT 1')->fetchColumn());

        $this->save($e, $e->buildRoutineDrop('trg_upper', 'FUNCTION', ''));
        $this->save($e, $e->buildRoutineDrop('trg_note', 'FUNCTION', ''));
    }

    public function testEventsAreUnsupported(): void
    {
        try {
            $this->editor()->eventInfo('x');
            $this->fail('Expected unsupported');
        } catch (ApiException $ex) {
            $this->assertSame('unsupported', $ex->getErrorCode());
        }
    }
}
