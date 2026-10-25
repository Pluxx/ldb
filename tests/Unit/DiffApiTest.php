<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\SessionManager;
use Ldb\Config;
use Ldb\Http\Request;
use Ldb\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * diff.compare / diff.plan / diff.apply through the Kernel with two SQLite logins in one session:
 * stale-plan refusal, read-only targets, conn/target mismatch and cross-driver refusal.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiffApiTest extends TestCase
{
    private string $dir;
    private ?string $csrf = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite missing');
        }
        $this->dir = sys_get_temp_dir() . '/ldb_diff_api_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/db', 0700, true);
        (new \PDO('sqlite:' . $this->dir . '/db/src.sqlite'))->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL, added TEXT)');
        (new \PDO('sqlite:' . $this->dir . '/db/dst.sqlite'))->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL); CREATE TABLE old (x INTEGER)');
        $_SESSION = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function config(): Config
    {
        return new Config([
            'sqlite_paths' => [$this->dir . '/db'],
            'sqlite_allow_no_password' => true,
            'rate_limit_dir' => $this->dir . '/rl',
            'login_max_attempts' => 50,
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(string $action, array $params = []): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '') {
            $_COOKIE[session_name()] = session_id();
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->csrf !== null) {
            $headers['X-CSRF-Token'] = $this->csrf;
        }
        $r = (new Kernel($this->config()))->handle(new Request('POST', ['action' => $action], $params, $headers, [], ['REMOTE_ADDR' => '203.0.113.7']));
        $body = json_decode((string) $r->body(), true);
        if (isset($body['data']['csrf'])) {
            $this->csrf = $body['data']['csrf'];
        }
        return [$r->status(), $body];
    }

    /** @return array{0:string,1:string} source and target connection ids */
    private function loginBoth(bool $targetReadOnly = false): array
    {
        $this->call('auth.status');
        [, $a] = $this->call('auth.login', ['driver' => 'sqlite', 'file' => $this->dir . '/db/src.sqlite']);
        [, $b] = $this->call('auth.login', ['driver' => 'sqlite', 'file' => $this->dir . '/db/dst.sqlite', 'read_only' => $targetReadOnly]);
        $this->assertArrayHasKey('conn', $a['data'], json_encode($a));
        return [(string) $a['data']['conn'], (string) $b['data']['conn']];
    }

    public function testComparePlanApplyRoundTrip(): void
    {
        [$src, $dst] = $this->loginBoth();
        $sides = ['source' => ['conn' => $src, 'db' => 'main'], 'target' => ['conn' => $dst, 'db' => 'main']];

        [$status, $body] = $this->call('diff.compare', $sides);
        $this->assertSame(200, $status, json_encode($body));
        $ids = array_column($body['data']['changes'], 'id');
        $this->assertEqualsCanonicalizing(['column_added:t:added', 'table_removed:old:old'], $ids);
        $this->assertSame(1, $body['data']['summary']['destructive']);

        [, $plan] = $this->call('diff.plan', $sides + ['selected' => ['column_added:t:added']]);
        $this->assertSame(['ALTER TABLE "t" ADD COLUMN "added" TEXT DEFAULT NULL'], array_values(array_filter($plan['data']['sql'], static fn (string $s): bool => strpos($s, 'PRAGMA') !== 0)));
        $hash = $plan['data']['hash'];

        [$status, $stale] = $this->call('diff.apply', $sides + ['conn' => $dst, 'selected' => ['column_added:t:added'], 'hash' => str_repeat('0', 64)]);
        $this->assertSame(409, $status);
        $this->assertSame('stale', $stale['error']['code']);

        [$status, $mismatch] = $this->call('diff.apply', $sides + ['conn' => $src, 'selected' => ['column_added:t:added'], 'hash' => $hash]);
        $this->assertSame(400, $status, 'conn must be the target');

        [$status, $applied] = $this->call('diff.apply', $sides + ['conn' => $dst, 'selected' => ['column_added:t:added'], 'hash' => $hash, 'tx' => 'atomic']);
        $this->assertSame(200, $status, json_encode($applied));
        $this->assertFalse($applied['data']['failed']);

        [, $again] = $this->call('diff.compare', $sides);
        $this->assertSame(['table_removed:old:old'], array_column($again['data']['changes'], 'id'));
    }

    public function testReadOnlyTargetIsRefused(): void
    {
        [$src, $dst] = $this->loginBoth(true);
        $sides = ['source' => ['conn' => $src, 'db' => 'main'], 'target' => ['conn' => $dst, 'db' => 'main']];
        [$status] = $this->call('diff.compare', $sides);
        $this->assertSame(200, $status, 'comparing is a read');
        [, $plan] = $this->call('diff.plan', $sides);
        [$status, $body] = $this->call('diff.apply', $sides + ['conn' => $dst, 'hash' => $plan['data']['hash']]);
        $this->assertSame(403, $status);
        $this->assertSame('read_only', $body['error']['code']);
    }

    public function testDifferentDriversAndForeignConnectionsAreRefused(): void
    {
        [$src] = $this->loginBoth();
        // a PostgreSQL connection in the same session (never connected: the driver check comes first)
        $session = new SessionManager($this->config(), new Request('POST', [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7']));
        $session->start();
        $pg = $session->addConnection(['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'user' => 'u', 'password' => 'p'], ['driver' => 'pgsql', 'host' => 'db', 'user' => 'u']);
        session_write_close();

        [$status, $body] = $this->call('diff.compare', ['source' => ['conn' => $pg, 'db' => 'x'], 'target' => ['conn' => $src, 'db' => 'main']]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('different database engines', $body['error']['message']);

        [$status, $body] = $this->call('diff.compare', ['source' => ['conn' => 'nope', 'db' => 'x'], 'target' => ['conn' => $src, 'db' => 'main']]);
        $this->assertSame(400, $status, 'a stale connection id must not end the session');
        $this->assertStringContainsString('source connection', $body['error']['message']);
    }
}
