<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\SqliteDriver;
use Ldb\Http\ApiException;
use Ldb\Routine\RoutineEditor;
use Ldb\Routine\SqliteRoutineEditor;
use PHPUnit\Framework\TestCase;

/** SQLite trigger editor against a temporary database file (always runs when pdo_sqlite exists). */
final class RoutineEditorSqliteTest extends TestCase
{
    private ?SqliteDriver $d = null;
    private string $file = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->file = (string) tempnam(sys_get_temp_dir(), 'ldb_rt_sqlite_');
        $this->d = new SqliteDriver();
        $this->d->connect(['driver' => 'sqlite', 'file' => $this->file, 'options' => []]);
        $this->d->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, n INTEGER DEFAULT 0)', 1);
        $this->d->query('CREATE TABLE audit (msg TEXT)', 1);
    }

    protected function tearDown(): void
    {
        $this->d = null;
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }
    }

    private function editor(): SqliteRoutineEditor
    {
        $e = RoutineEditor::forDriver($this->d);
        $this->assertInstanceOf(SqliteRoutineEditor::class, $e);
        return $e;
    }

    private function sqlite(string $sql, array $params = []): array
    {
        $stmt = $this->d->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->columnCount() > 0 ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function assertApiError(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected ApiException ' . $code);
        } catch (ApiException $ex) {
            $this->assertSame($code, $ex->getErrorCode());
        }
    }

    public function testTriggerLifecycle(): void
    {
        $e = $this->editor();
        $def = [
            'name' => 'trg_items_bu', 'table' => 'items', 'timing' => 'AFTER', 'events' => ['UPDATE'], 'columns' => ['label'],
            'when' => 'NEW.label <> OLD.label',
            'body' => "INSERT INTO audit (msg) VALUES (OLD.label || ' -> ' || NEW.label)",
        ];
        $plan = $e->buildTriggerSave($def, null);
        $this->assertCount(1, $plan['sql']);
        $e->executePlan($plan);

        $info = $e->triggerInfo('trg_items_bu');
        $this->assertSame('trg_items_bu', $info['name']);
        $this->assertSame('items', $info['table']);
        $this->assertSame('AFTER', $info['timing']);
        $this->assertSame(['UPDATE'], $info['events']);
        $this->assertSame(['label'], $info['columns']);
        $this->assertSame('NEW.label <> OLD.label', $info['when']);
        $this->assertSame("INSERT INTO audit (msg) VALUES (OLD.label || ' -> ' || NEW.label);", $info['body']);
        $this->assertStringContainsString('CREATE TRIGGER', (string) $info['sql']);

        // effect
        $this->sqlite("INSERT INTO items (label) VALUES ('a')");
        $this->sqlite("UPDATE items SET label = 'b' WHERE id = 1");
        $this->sqlite("UPDATE items SET label = 'b' WHERE id = 1"); // unchanged: WHEN is false
        $this->sqlite('UPDATE items SET n = 5 WHERE id = 1');       // other column
        $this->assertSame([['msg' => 'a -> b']], $this->sqlite('SELECT msg FROM audit'));

        // edit + rename: transactional DROP + CREATE
        $edit = $info;
        $edit['name'] = 'trg_items_bu2';
        $edit['body'] = "INSERT INTO audit (msg) VALUES ('changed ' || NEW.label)";
        $plan = $e->buildTriggerSave($edit, $info);
        $this->assertTrue($plan['transactional']);
        $this->assertSame('DROP TRIGGER "trg_items_bu"', $plan['sql'][0]);
        $e->executePlan($plan);
        $this->assertApiError('not_found', fn () => $e->triggerInfo('trg_items_bu'));
        $info = $e->triggerInfo('trg_items_bu2');
        $this->assertSame("INSERT INTO audit (msg) VALUES ('changed ' || NEW.label);", $info['body']);
        $this->sqlite('DELETE FROM audit');
        $this->sqlite("UPDATE items SET label = 'c' WHERE id = 1");
        $this->assertSame([['msg' => 'changed c']], $this->sqlite('SELECT msg FROM audit'));

        // failing edit rolls back the DROP
        $bad = $info;
        $bad['body'] = 'SELECT FROM WHERE';
        $bad['events'] = ['DELETE'];
        $bad['columns'] = [];
        $bad['timing'] = 'BEFORE';
        $bad['when'] = null;
        $this->assertApiError('db_error', fn () => $e->executePlan($e->buildTriggerSave($bad, $info)));
        $this->assertSame('trg_items_bu2', $e->triggerInfo('trg_items_bu2')['name']);

        // drop
        $e->executePlan($e->buildTriggerDrop('trg_items_bu2'));
        $this->assertApiError('not_found', fn () => $e->triggerInfo('trg_items_bu2'));
        $this->sqlite('DELETE FROM audit');
        $this->sqlite("UPDATE items SET label = 'd' WHERE id = 1");
        $this->assertSame([], $this->sqlite('SELECT msg FROM audit'));
    }

    public function testTriggerWithoutWhenAndBeforeTiming(): void
    {
        $e = $this->editor();
        $e->executePlan($e->buildTriggerSave([
            'name' => 'trg bi "x"', 'table' => 'items', 'timing' => 'BEFORE', 'events' => ['INSERT'],
            'body' => "INSERT INTO audit (msg) VALUES ('ins ' || NEW.label);\nINSERT INTO audit (msg) VALUES ('second');",
        ], null));
        $info = $e->triggerInfo('trg bi "x"');
        $this->assertSame('BEFORE', $info['timing']);
        $this->assertSame(['INSERT'], $info['events']);
        $this->assertSame([], $info['columns']);
        $this->assertNull($info['when']);
        $this->assertSame("INSERT INTO audit (msg) VALUES ('ins ' || NEW.label);\nINSERT INTO audit (msg) VALUES ('second');", $info['body']);
        $this->sqlite("INSERT INTO items (label) VALUES ('q')");
        $this->assertSame([['msg' => 'ins q'], ['msg' => 'second']], $this->sqlite('SELECT msg FROM audit ORDER BY rowid'));
        $e->executePlan($e->buildTriggerDrop('trg bi "x"'));
    }

    public function testInsteadOfTriggerOnViewIsParsed(): void
    {
        $e = $this->editor();
        $this->d->query('CREATE VIEW v_items AS SELECT id, label FROM items', 1);
        $e->executePlan($e->buildTriggerSave([
            'name' => 'trg_v', 'table' => 'v_items', 'timing' => 'INSTEAD OF', 'events' => ['INSERT'],
            'body' => 'INSERT INTO items (label) VALUES (NEW.label)',
        ], null));
        $info = $e->triggerInfo('trg_v');
        $this->assertSame('INSTEAD OF', $info['timing']);
        $this->assertSame('v_items', $info['table']);
        $this->sqlite("INSERT INTO v_items (label) VALUES ('via view')");
        $this->assertSame([['label' => 'via view']], $this->sqlite('SELECT label FROM items'));
    }

    public function testExistingTriggerWithBeginInStringLiteralIsRead(): void
    {
        $e = $this->editor();
        $this->d->query("CREATE TRIGGER IF NOT EXISTS t_raw AFTER INSERT ON items WHEN NEW.label = 'begin' BEGIN INSERT INTO audit (msg) VALUES ('end'); END", 1);
        $info = $e->triggerInfo('t_raw');
        $this->assertSame("NEW.label = 'begin'", $info['when']);
        $this->assertSame("INSERT INTO audit (msg) VALUES ('end');", $info['body']);
        // re-save unchanged definition
        $e->executePlan($e->buildTriggerSave($info, $info));
        $this->sqlite("INSERT INTO items (label) VALUES ('begin')");
        $this->assertSame([['msg' => 'end']], $this->sqlite('SELECT msg FROM audit'));
    }

    public function testTriggerNotFoundAndUnsupportedFeatures(): void
    {
        $e = $this->editor();
        $this->assertApiError('not_found', fn () => $e->triggerInfo('nope'));
        $this->assertApiError('unsupported', fn () => $e->routineInfo('x'));
        $this->assertApiError('unsupported', fn () => $e->routineInfo('x', 'PROCEDURE'));
        $this->assertApiError('unsupported', fn () => $e->buildRoutineSave([], null));
        $this->assertApiError('unsupported', fn () => $e->eventInfo('x'));
        $this->assertApiError('unsupported', fn () => $e->buildExecute([], []));
    }
}
