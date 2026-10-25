<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Http\ApiException;

final class ServerController extends AbstractController
{
    /** @return array<string,mixed> */
    public function overview(): array
    {
        $db = $this->db(false);
        return [
            'info' => $db->serverInfo(),
            'capabilities' => $db->capabilities(),
            'databases' => $db->listDatabases(),
            'read_only' => $this->isReadOnly(),
        ];
    }

    /** @return array<int,array{name:string,value:mixed}> */
    public function variables(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'variables');
        return $db->variables($this->request->optString('filter'));
    }

    /** @return array<int,array{name:string,value:mixed}> */
    public function status(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'status');
        return $db->status();
    }

    /** @return array{columns:array,rows:array} */
    public function processes(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'processes');
        return $db->processes();
    }

    public function kill(): bool
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'kill');
        $id = $this->request->string('id');
        if (!preg_match('/^\d+$/', $id)) {
            throw ApiException::badRequest('Invalid process id');
        }
        $db->kill($id);
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function users(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'users');
        return $db->users();
    }

    /** @return string[] */
    public function userGrants(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'users');
        return $db->userGrants($this->request->string('user'), $this->request->optString('host'));
    }

    public function userSave(): bool
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'users');
        $user = $this->request->string('user');
        $host = $this->request->optString('host');
        $grants = $this->stringList('grants', false);

        if ($this->request->has('is_new')) {
            $isNew = $this->request->bool('is_new');
        } else {
            $isNew = true;
            foreach ($db->users() as $u) {
                if ((string) ($u['user'] ?? '') === $user
                    && ($host === null || !isset($u['host']) || (string) $u['host'] === $host)) {
                    $isNew = false;
                    break;
                }
            }
        }
        $password = $this->request->param('password');
        $db->saveUser($user, $host, is_string($password) && $password !== '' ? $password : null, $grants, $isNew);
        return true;
    }

    public function userDrop(): bool
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'users');
        $db->dropUser($this->request->string('user'), $this->request->optString('host'));
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function collations(): array
    {
        return $this->db(false)->collations();
    }

    /** @return array<int,array<string,mixed>> */
    public function charsets(): array
    {
        return $this->db(false)->charsets();
    }

    /** @return array<int,array<string,mixed>> */
    public function engines(): array
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'engines');
        return $db->engines();
    }

    /** Grouped column types for the table editor (not in the original action table). */
    public function dataTypes(): array
    {
        return $this->db(false)->dataTypes();
    }
}
