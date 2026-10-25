<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Support;

use Ldb\Db\DriverInterface;
use PDO;

/**
 * Minimal in-memory SQLite DriverInterface double for export/import tests.
 * Only the methods used by the tests are implemented; the rest throw.
 */
final class FakeSqliteDriver implements DriverInterface
{
    /** @var PDO */
    private $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function id(): string
    {
        return 'sqlite';
    }

    public static function label(): string
    {
        return 'SQLite (fake)';
    }

    public static function isAvailable(): bool
    {
        return true;
    }

    public static function defaultPort(): ?int
    {
        return null;
    }

    public function connect(array $params): void
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function currentDatabase(): ?string
    {
        return 'main';
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function quoteTable(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    public function capabilities(): array
    {
        return ['routines' => false, 'triggers' => false, 'events' => false];
    }

    public function serverInfo(): array
    {
        return ['name' => 'SQLite', 'version' => '3'];
    }

    public function listTables(): array
    {
        return $this->pdo->query("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
    }

    public function listRoutines(): array
    {
        return [];
    }

    public function listTriggers(): array
    {
        return [];
    }

    public function listEvents(): array
    {
        return [];
    }

    public function createStatement(string $name, string $type = 'table'): string
    {
        $st = $this->pdo->prepare('SELECT sql FROM sqlite_master WHERE name = ?');
        $st->execute([$name]);
        return (string) $st->fetchColumn();
    }

    public function tableInfo(string $table): array
    {
        $cols = [];
        foreach ($this->pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')')->fetchAll() as $c) {
            $cols[] = [
                'name' => $c['name'], 'type' => $c['type'], 'full_type' => $c['type'],
                'nullable' => !$c['notnull'], 'primary' => (bool) $c['pk'], 'default' => $c['dflt_value'],
                'auto_increment' => false,
            ];
        }
        return ['name' => $table, 'type' => 'table', 'columns' => $cols];
    }

    public function truncateTable(string $table): void
    {
        $this->pdo->exec('DELETE FROM ' . $this->quoteTable($table));
    }

    public function select(?string $database, ?string $schema = null): void
    {
        throw new \BadMethodCallException('select not supported by FakeSqliteDriver');
    }

    public function currentSchema(): ?string
    {
        throw new \BadMethodCallException('currentSchema not supported by FakeSqliteDriver');
    }

    public function listDatabases(): array
    {
        throw new \BadMethodCallException('listDatabases not supported by FakeSqliteDriver');
    }

    public function createDatabase(string $name, ?string $collation = null): void
    {
        throw new \BadMethodCallException('createDatabase not supported by FakeSqliteDriver');
    }

    public function dropDatabase(string $name): void
    {
        throw new \BadMethodCallException('dropDatabase not supported by FakeSqliteDriver');
    }

    public function databaseInfo(string $name): array
    {
        throw new \BadMethodCallException('databaseInfo not supported by FakeSqliteDriver');
    }

    public function buildAlterDatabase(string $name, array $changes): array
    {
        throw new \BadMethodCallException('buildAlterDatabase not supported by FakeSqliteDriver');
    }

    public function alterDatabase(string $name, array $changes): array
    {
        throw new \BadMethodCallException('alterDatabase not supported by FakeSqliteDriver');
    }

    public function copyDatabase(string $source, string $target, array $opts = []): array
    {
        throw new \BadMethodCallException('copyDatabase not supported by FakeSqliteDriver');
    }

    public function listSchemas(): array
    {
        throw new \BadMethodCallException('listSchemas not supported by FakeSqliteDriver');
    }

    public function browse(string $table, array $opts): array
    {
        throw new \BadMethodCallException('browse not supported by FakeSqliteDriver');
    }

    public function insertRow(string $table, array $values): array
    {
        throw new \BadMethodCallException('insertRow not supported by FakeSqliteDriver');
    }

    public function updateRow(string $table, array $key, array $values): int
    {
        throw new \BadMethodCallException('updateRow not supported by FakeSqliteDriver');
    }

    public function deleteRows(string $table, array $keys): int
    {
        throw new \BadMethodCallException('deleteRows not supported by FakeSqliteDriver');
    }

    public function buildCreateTable(array $definition): array
    {
        throw new \BadMethodCallException('buildCreateTable not supported by FakeSqliteDriver');
    }

    public function buildAlterTable(string $table, array $spec): array
    {
        throw new \BadMethodCallException('buildAlterTable not supported by FakeSqliteDriver');
    }

    public function dropTable(string $table, bool $isView = false): void
    {
        throw new \BadMethodCallException('dropTable not supported by FakeSqliteDriver');
    }

    public function renameTable(string $table, string $newName): void
    {
        throw new \BadMethodCallException('renameTable not supported by FakeSqliteDriver');
    }

    public function copyTable(string $table, string $newName, bool $withData, ?string $targetDb = null): void
    {
        throw new \BadMethodCallException('copyTable not supported by FakeSqliteDriver');
    }

    public function maintenance(array $tables, string $op): array
    {
        throw new \BadMethodCallException('maintenance not supported by FakeSqliteDriver');
    }

    public function query(string $sql, int $limit): array
    {
        throw new \BadMethodCallException('query not supported by FakeSqliteDriver');
    }

    public function explain(string $sql): array
    {
        throw new \BadMethodCallException('explain not supported by FakeSqliteDriver');
    }

    public function variables(?string $filter = null): array
    {
        throw new \BadMethodCallException('variables not supported by FakeSqliteDriver');
    }

    public function status(): array
    {
        throw new \BadMethodCallException('status not supported by FakeSqliteDriver');
    }

    public function processes(): array
    {
        throw new \BadMethodCallException('processes not supported by FakeSqliteDriver');
    }

    public function kill(string $id): void
    {
        throw new \BadMethodCallException('kill not supported by FakeSqliteDriver');
    }

    public function backendId(): ?string
    {
        return null;
    }

    public function cancelBackend(string $id): bool
    {
        return false;
    }

    public function users(): array
    {
        throw new \BadMethodCallException('users not supported by FakeSqliteDriver');
    }

    public function userGrants(string $user, ?string $host): array
    {
        throw new \BadMethodCallException('userGrants not supported by FakeSqliteDriver');
    }

    public function saveUser(string $user, ?string $host, ?string $password, array $grants, bool $isNew): void
    {
        throw new \BadMethodCallException('saveUser not supported by FakeSqliteDriver');
    }

    public function dropUser(string $user, ?string $host): void
    {
        throw new \BadMethodCallException('dropUser not supported by FakeSqliteDriver');
    }

    public function collations(): array
    {
        throw new \BadMethodCallException('collations not supported by FakeSqliteDriver');
    }

    public function charsets(): array
    {
        throw new \BadMethodCallException('charsets not supported by FakeSqliteDriver');
    }

    public function engines(): array
    {
        throw new \BadMethodCallException('engines not supported by FakeSqliteDriver');
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->pdo->exec('PRAGMA query_only = ' . ($readOnly ? 'ON' : 'OFF'));
    }

    public function dataTypes(): array
    {
        throw new \BadMethodCallException('dataTypes not supported by FakeSqliteDriver');
    }

    public function completionWords(): array
    {
        throw new \BadMethodCallException('completionWords not supported by FakeSqliteDriver');
    }

    public function normalizeValue($value, ?string $type = null)
    {
        throw new \BadMethodCallException('normalizeValue not supported by FakeSqliteDriver');
    }
}
