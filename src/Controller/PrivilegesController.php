<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Http\ApiException;
use Ldb\Privileges\PrivilegeManager;

/**
 * Structured privileges editor (server.privileges*, server.role*). The client only sends a
 * PrivilegeChanges spec; SQL is always generated (and re-generated on apply) on the server.
 */
final class PrivilegesController extends AbstractController
{
    private function manager(): PrivilegeManager
    {
        $db = $this->db();
        $this->requireCapability($db, 'users');
        return PrivilegeManager::for($db);
    }

    private function host(): ?string
    {
        $h = $this->request->optString('host');
        return $h === '' ? null : $h;
    }

    private function changes(): array
    {
        $c = $this->request->param('changes');
        if (is_string($c)) {
            $c = json_decode($c, true);
        }
        if (!is_array($c)) {
            throw ApiException::badRequest('Missing parameter: changes');
        }
        return $c;
    }

    /** PrivilegeState of one account (db: object-level privileges of that database). */
    public function privileges(): array
    {
        $m = $this->manager();
        $state = $m->read($this->request->string('user'), $this->host(), $this->request->optString('db'));
        if (empty($state['account']['exists'])) {
            throw ApiException::notFound('Account not found');
        }
        return $state;
    }

    /** {db, schemas:[name], tables:[{schema, name, kind, columns:[name]}]} */
    public function objects(): array
    {
        return $this->manager()->objects($this->request->string('db'));
    }

    /** {statements:[{sql, db}], warnings, self_lockout:[code], self} — nothing is executed. */
    public function plan(): array
    {
        return $this->manager()->plan($this->request->string('user'), $this->host(), $this->changes());
    }

    /** WRITE: re-plan and execute; {results:[{sql, db, ok, error}], executed, failed, warnings}. */
    public function apply(): array
    {
        return $this->manager()->apply(
            $this->request->string('user'),
            $this->host(),
            $this->changes(),
            $this->request->bool('confirm_self'),
            $this->request->bool('stop_on_error')
        );
    }

    /** WRITE: CREATE ROLE (preview → statements only). */
    public function roleCreate(): array
    {
        $m = $this->manager();
        $sql = $m->createRole($this->request->string('name'), $this->host());
        return $this->runOrPreview($m, $sql);
    }

    /** WRITE: DROP ROLE (preview → statements only). */
    public function roleDrop(): array
    {
        $m = $this->manager();
        $sql = $m->dropRole($this->request->string('name'), $this->host());
        return $this->runOrPreview($m, $sql);
    }

    private function runOrPreview(PrivilegeManager $m, array $statements): array
    {
        if ($this->request->bool('preview')) {
            return ['statements' => $statements, 'executed' => 0, 'failed' => 0, 'results' => []];
        }
        $out = $m->run($statements, true);
        if ($out['failed'] > 0) {
            $last = end($out['results']);
            throw ApiException::dbError((string) $last['error'], (string) $last['sql']);
        }
        return $out + ['statements' => $statements];
    }
}
