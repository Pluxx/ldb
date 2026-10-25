<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Db\DriverInterface;
use Ldb\Http\ApiException;
use Ldb\Routine\RoutineEditor;
use Ldb\Sql\ReadOnlyGuard;

/**
 * Stored routines, triggers and events editor (routine.* / trigger.* / event.*), see
 * ARCHITECTURE.md "Routines, triggers, events". Save actions take `definition`, `original`
 * ({name,type?,signature?,table?} of the object being edited; omitted for new objects) and
 * `preview` (true: only render the SQL). Bodies are user SQL, passed verbatim.
 */
final class RoutineController extends AbstractController
{
    private const MAX_LIMIT = 100000;

    private function editor(DriverInterface $db, string $cap): RoutineEditor
    {
        $this->requireCapability($db, $cap);
        return RoutineEditor::forDriver($db);
    }

    // ------------------------------------------------------------------ routines

    /** @return array<string,mixed> RoutineDefinition + {sql} */
    public function routineInfo(): array
    {
        $db = $this->dbWithDatabase();
        return $this->editor($db, 'routines')->routineInfo(
            $this->request->string('name'),
            $this->request->optString('type'),
            $this->signature()
        );
    }

    /** @return array{sql:string[],warnings:string[],executed:bool,script:string} */
    public function routineSave(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'routines');
        $def = $this->request->arr('definition', true);
        $original = $this->original();
        if ($original !== null) {
            // Re-read from the catalog: the plan relies on the real current shape (signature, comment …).
            $original = $ed->routineInfo((string) ($original['name'] ?? ''), self::optType($original), self::optSig($original));
        }
        return $this->runPlan($ed, 'routine', $ed->buildRoutineSave($def, $original), $original);
    }

    /** @return array{sql:string[],warnings:string[],executed:bool,script:string} */
    public function routineDrop(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'routines');
        $info = $ed->routineInfo($this->request->string('name'), $this->request->optString('type'), $this->signature());
        return $this->runPlan($ed, 'routine', $ed->buildRoutineDrop($info['name'], $info['type'], $info['signature'] ?? null), null);
    }

    /**
     * routine.execute: `args` = [{value, null?, default?}] by parameter position. Procedures are
     * refused on read-only connections; functions run through ReadOnlyGuard + a read-only session.
     * @return array{results:array,out:array,sql:string}
     */
    public function routineExecute(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'routines');
        $info = $ed->routineInfo($this->request->string('name'), $this->request->optString('type'), $this->signature());
        $readOnly = $this->isReadOnly();
        if ($readOnly) {
            if ($info['type'] !== 'FUNCTION') {
                throw new ApiException('This connection is read-only: procedures cannot be called', 'read_only', 403);
            }
            $guard = new ReadOnlyGuard($db::id());
            foreach ($ed->buildExecute($info, $this->request->arr('args'))['steps'] as $step) {
                $guard->assertReadOnly($step['sql']);
            }
            $db->setReadOnly(true);
        }
        $limit = min(self::MAX_LIMIT, max(1, $this->request->int('limit', 1000)));
        @set_time_limit(0);
        return $ed->execute($info, $this->request->arr('args'), $limit, $readOnly);
    }

    // ------------------------------------------------------------------ triggers

    public function triggerInfo(): array
    {
        $db = $this->dbWithDatabase();
        return $this->editor($db, 'triggers')->triggerInfo($this->request->string('name'), $this->request->optString('table'));
    }

    public function triggerSave(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'triggers');
        $def = $this->request->arr('definition', true);
        $original = $this->original();
        if ($original !== null) {
            $original = $ed->triggerInfo((string) ($original['name'] ?? ''), isset($original['table']) && is_string($original['table']) ? $original['table'] : null);
        }
        return $this->runPlan($ed, 'trigger', $ed->buildTriggerSave($def, $original), $original);
    }

    public function triggerDrop(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'triggers');
        $info = $ed->triggerInfo($this->request->string('name'), $this->request->optString('table'));
        return $this->runPlan($ed, 'trigger', $ed->buildTriggerDrop($info['name'], $info['table'] ?? null), null);
    }

    // ------------------------------------------------------------------ events

    public function eventInfo(): array
    {
        $db = $this->dbWithDatabase();
        return $this->editor($db, 'events')->eventInfo($this->request->string('name'));
    }

    public function eventSave(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'events');
        $def = $this->request->arr('definition', true);
        $original = $this->original();
        if ($original !== null) {
            $original = $ed->eventInfo((string) ($original['name'] ?? ''));
        }
        return $this->runPlan($ed, 'event', $ed->buildEventSave($def, $original), $original);
    }

    public function eventDrop(): array
    {
        $db = $this->dbWithDatabase();
        $ed = $this->editor($db, 'events');
        $info = $ed->eventInfo($this->request->string('name'));
        return $this->runPlan($ed, 'event', $ed->buildEventDrop($info['name']), null);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{sql:string[],warnings:string[],executed:bool,script:string} */
    private function runPlan(RoutineEditor $ed, string $kind, array $plan, ?array $original): array
    {
        $preview = $this->request->bool('preview');
        if (!$preview) {
            $plan = $ed->prepareSave($kind, $plan, $original);
            $ed->executePlan($plan);
        }
        return ['sql' => $plan['sql'], 'warnings' => $plan['warnings'], 'executed' => !$preview, 'script' => $ed->script($plan)];
    }

    private function original(): ?array
    {
        $o = $this->request->param('original');
        if ($o === null || $o === '' || $o === []) {
            return null;
        }
        if (!is_array($o) || !isset($o['name']) || !is_string($o['name']) || $o['name'] === '') {
            throw ApiException::badRequest('Invalid parameter: original');
        }
        return $o;
    }

    private function signature(): ?string
    {
        return $this->request->has('signature') ? (string) $this->request->param('signature') : null;
    }

    private static function optType(array $o): ?string
    {
        return isset($o['type']) && is_string($o['type']) && $o['type'] !== '' ? $o['type'] : null;
    }

    private static function optSig(array $o): ?string
    {
        return isset($o['signature']) && is_string($o['signature']) ? $o['signature'] : null;
    }
}
