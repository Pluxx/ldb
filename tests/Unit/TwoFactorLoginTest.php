<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\LoginService;
use Ldb\Auth\SessionManager;
use Ldb\Auth\Totp;
use Ldb\Auth\TwoFactor;
use Ldb\Auth\TwoFactorStore;
use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Http\ApiException;
use Ldb\Http\Request;
use Ldb\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end 2FA login through the Kernel with a real PHP session and a SQLite "server", so no
 * database container is needed. Separate processes: sessions refuse to start (and ids to be
 * regenerated) once PHPUnit has printed output.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TwoFactorLoginTest extends TestCase
{
    private const KEY = 'login-test-secret-key-0123456789abcdef';

    private string $dir;
    private string $db;
    private ?string $csrf = null;
    private string $log = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite missing');
        }
        $this->dir = sys_get_temp_dir() . '/ldb_2fa_login_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/db', 0700, true);
        $this->db = $this->dir . '/db/app.sqlite';
        (new \PDO('sqlite:' . $this->db))->exec('CREATE TABLE t (id INTEGER)');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $_COOKIE = [];
        $this->log = (string) ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        $_COOKIE = [];
        ini_set('error_log', $this->log);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function config(array $extra = []): Config
    {
        return new Config($extra + [
            'sqlite_paths' => [$this->dir . '/db'],
            'sqlite_allow_no_password' => true,
            'secret_key' => self::KEY,
            'data_dir' => $this->dir . '/data',
            'rate_limit_dir' => $this->dir . '/rl',
            'login_max_attempts' => 50,
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>} [status, envelope] */
    private function call(Config $config, string $action, array $params = []): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '') {
            // The "browser" sends the session cookie back.
            $_COOKIE[session_name()] = session_id();
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->csrf !== null) {
            $headers['X-CSRF-Token'] = $this->csrf;
        }
        $r = (new Kernel($config))->handle(new Request('POST', ['action' => $action], $params, $headers, [], ['REMOTE_ADDR' => '203.0.113.9']));
        $body = json_decode((string) $r->body(), true);
        if (isset($body['data']['csrf'])) {
            $this->csrf = $body['data']['csrf'];
        }
        return [$r->status(), $body];
    }

    private function login(Config $config): array
    {
        $this->call($config, 'auth.status');
        return $this->call($config, 'auth.login', ['driver' => 'sqlite', 'file' => $this->db]);
    }

    private function identity(): string
    {
        return TwoFactor::identity(['driver' => 'sqlite', 'file' => (string) realpath($this->db)]);
    }

    /** Enroll directly in the store; returns [secret, recovery codes]. */
    private function enroll(Config $config): array
    {
        $secret = Totp::generateSecret();
        $tf = new TwoFactor($config, null, static fn (): int => time() - 30);
        $codes = $tf->enroll($this->identity(), $secret, Totp::code($secret, time() - 30), 'test');
        $this->assertIsArray($codes);
        return [$secret, $codes];
    }

    public function testLoginWithoutTwoFactorIsUnchanged(): void
    {
        [$status, $body] = $this->login($this->config());
        $this->assertSame(200, $status, json_encode($body));
        $this->assertArrayHasKey('conn', $body['data']);
        $this->assertArrayNotHasKey('twofa_required', $body['data']);
        $this->assertTrue($body['data']['authenticated']);
    }

    public function testCodeStepWrongThenRightCode(): void
    {
        $config = $this->config();
        [$secret] = $this->enroll($config);

        [$status, $body] = $this->login($config);
        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['twofa_required']);
        $this->assertFalse($body['data']['authenticated'], 'no connection before the second factor');
        $this->assertSame([], $body['data']['connections']);
        $this->assertTrue($body['data']['twofa_pending']);
        $this->assertArrayNotHasKey('conn', $body['data']);

        // Still not logged in: other actions need auth.
        [$status, $body] = $this->call($config, 'db.list');
        $this->assertSame(401, $status);

        // CSRF is required for the second step.
        $csrf = $this->csrf;
        $this->csrf = 'bogus';
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => Totp::code($secret, time())]);
        $this->assertSame('csrf', $body['error']['code']);
        $this->csrf = $csrf;

        $good = Totp::code($secret, time());
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => $good === '000000' ? '000001' : '000000']);
        $this->assertSame(401, $status);
        $this->assertSame('twofa_invalid', $body['error']['code']);
        $this->assertSame(4, $body['error']['details']['remaining']);

        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => $good]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['data']['authenticated']);
        $this->assertFalse($body['data']['twofa_pending']);
        $conn = $body['data']['conn'];
        [$status] = $this->call($config, 'db.list', ['conn' => $conn]);
        $this->assertSame(200, $status);

        // Replay: the same code cannot complete a second login.
        $this->login($config);
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => $good]);
        $this->assertSame('twofa_invalid', $body['error']['code']);
    }

    public function testRecoveryCodeLogin(): void
    {
        $config = $this->config();
        [, $codes] = $this->enroll($config);
        $this->login($config);
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['recovery_code' => $codes[0]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['data']['authenticated']);
        $this->call($config, 'auth.logout');
        $this->login($config);
        [, $body] = $this->call($config, 'auth.verify2fa', ['recovery_code' => $codes[0]]);
        $this->assertSame('twofa_invalid', $body['error']['code'], 'recovery code is single-use');
    }

    public function testPendingLoginAttemptLimit(): void
    {
        $config = $this->config();
        [$secret] = $this->enroll($config);
        $this->login($config);
        $wrong = Totp::code($secret, time()) === '000000' ? '000001' : '000000';
        for ($i = 4; $i >= 1; $i--) {
            [, $body] = $this->call($config, 'auth.verify2fa', ['code' => $wrong]);
            $this->assertSame($i, $body['error']['details']['remaining']);
        }
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => $wrong]);
        $this->assertSame('twofa_expired', $body['error']['code']);
        // Discarded: even the right code does not help now.
        [, $body] = $this->call($config, 'auth.verify2fa', ['code' => Totp::code($secret, time())]);
        $this->assertSame('twofa_expired', $body['error']['code']);
    }

    public function testPendingLoginExpires(): void
    {
        $config = $this->config();
        [$secret] = $this->enroll($config);
        $this->login($config);
        $this->assertArrayHasKey(LoginService::PENDING, $_SESSION['ldb']['transient']);
        $this->assertStringNotContainsString('sqlite', (string) $_SESSION['ldb']['transient'][LoginService::PENDING]['secret'], 'pending login is encrypted');
        $_SESSION['ldb']['transient'][LoginService::PENDING]['expires'] = time() - 1;
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => Totp::code($secret, time())]);
        $this->assertSame(401, $status);
        $this->assertSame('twofa_expired', $body['error']['code']);
    }

    public function testPerIdentityLockoutAndIpLimiter(): void
    {
        $config = $this->config(['twofa_max_failures' => 3]);
        [$secret] = $this->enroll($config);
        $wrong = Totp::code($secret, time()) === '000000' ? '000001' : '000000';
        $this->login($config);
        for ($i = 0; $i < 3; $i++) {
            $this->call($config, 'auth.verify2fa', ['code' => $wrong]);
        }
        [$status, $body] = $this->call($config, 'auth.verify2fa', ['code' => Totp::code($secret, time())]);
        $this->assertSame(429, $status, 'identity locked out even with the right code');
        $this->assertSame('rate_limited', $body['error']['code']);

        // Wrong codes count toward the per-IP login limiter as well.
        $ipConfig = $this->config(['login_max_attempts' => 2, 'rate_limit_dir' => $this->dir . '/rl2']);
        $this->login($ipConfig);
        $this->call($ipConfig, 'auth.verify2fa', ['code' => $wrong]);
        $this->call($ipConfig, 'auth.verify2fa', ['code' => $wrong]);
        [$status, $body] = $this->login($ipConfig);
        $this->assertSame('rate_limited', $body['error']['code']);
    }

    public function testPolicyForcesEnrollment(): void
    {
        $config = $this->config(['twofa_required' => true]);
        [$status, $body] = $this->login($config);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['data']['twofa_enroll']);
        $conn = $body['data']['conn'];
        $this->assertTrue($body['data']['connections'][0]['twofa_enroll']);

        foreach (['db.list', 'server.overview', 'sql.execute', 'twofa.disable'] as $action) {
            [$status, $body] = $this->call($config, $action, ['conn' => $conn, 'sql' => 'SELECT 1']);
            $this->assertSame(403, $status, $action);
            $this->assertSame('twofa_enroll_required', $body['error']['code'], $action);
        }
        [$status, $body] = $this->call($config, 'twofa.status', ['conn' => $conn]);
        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['enroll_required']);
        $this->assertTrue($body['data']['required']);
        $this->assertFalse($body['data']['enabled']);

        [$status, $body] = $this->call($config, 'twofa.enrollStart', ['conn' => $conn]);
        $this->assertSame(200, $status);
        $secret = $body['data']['secret'];
        $this->assertStringStartsWith('otpauth://totp/LDB:', $body['data']['uri']);
        [, $body] = $this->call($config, 'twofa.enrollConfirm', ['conn' => $conn, 'code' => '00000x']);
        $this->assertSame('twofa_invalid', $body['error']['code']);
        [$status, $body] = $this->call($config, 'twofa.enrollConfirm', ['conn' => $conn, 'code' => Totp::code($secret, time())]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(10, $body['data']['recovery_codes']);

        [$status] = $this->call($config, 'db.list', ['conn' => $conn]);
        $this->assertSame(200, $status, 'unlocked after enrollment');
        // Required by policy → cannot be disabled by the user.
        [$status, $body] = $this->call($config, 'twofa.disable', ['conn' => $conn, 'recovery_code' => $body['data']['recovery_codes'][0] ?? '']);
        $this->assertSame('twofa_policy', $body['error']['code']);
    }

    public function testPolicyWithoutSecretKeyRefusesLogin(): void
    {
        $config = $this->config(['twofa_required' => true, 'secret_key' => '']);
        [$status, $body] = $this->login($config);
        $this->assertSame(503, $status);
        $this->assertSame('twofa_unavailable', $body['error']['code']);
        $this->assertFalse($body['ok']);
    }

    public function testSelfServiceDisableNeedsCode(): void
    {
        $config = $this->config();
        [, $body] = $this->login($config);
        $conn = $body['data']['conn'];
        [, $body] = $this->call($config, 'twofa.enrollStart', ['conn' => $conn]);
        $secret = $body['data']['secret'];
        [$status] = $this->call($config, 'twofa.enrollConfirm', ['conn' => $conn, 'code' => Totp::code($secret, time() - 30)]);
        $this->assertSame(200, $status);
        [, $body] = $this->call($config, 'twofa.status', ['conn' => $conn]);
        $this->assertTrue($body['data']['enabled']);
        $this->assertSame(10, $body['data']['recovery_remaining']);

        [, $body] = $this->call($config, 'twofa.disable', ['conn' => $conn]);
        $this->assertSame('twofa_invalid', $body['error']['code']);
        [$status, $body] = $this->call($config, 'twofa.disable', ['conn' => $conn, 'code' => Totp::code($secret, time())]);
        $this->assertSame(200, $status, json_encode($body));
        [, $body] = $this->call($config, 'twofa.status', ['conn' => $conn]);
        $this->assertFalse($body['data']['enabled']);
    }

    public function testSsoBypassRule(): void
    {
        $config = $this->config();
        $this->enroll($config);
        $session = new SessionManager($config, new Request('GET'));
        $input = ['driver' => 'sqlite', 'file' => $this->db];
        $service = new LoginService($config, $session, new DriverFactory($config));
        try {
            $service->login($input, 'sso|test', true, true);
            $this->fail('SSO login of a 2FA-enabled identity accepted');
        } catch (ApiException $e) {
            $this->assertSame('twofa_required', $e->getErrorCode());
        }
        $this->assertSame([], $session->connectionIds());

        $bypass = $this->config(['sso_bypass_2fa' => true]);
        $result = (new LoginService($bypass, $session, new DriverFactory($bypass)))->login($input, 'sso|test', true, true);
        $this->assertArrayHasKey('conn', $result);
        $this->assertArrayNotHasKey('twofa_required', $result);

        // The bypass is for SSO only: the login form still asks for the code.
        $form = (new LoginService($bypass, $session, new DriverFactory($bypass)))->login($input, 'login|test');
        $this->assertTrue($form['twofa_required']);
    }

    public function testStoreLocationFromConfig(): void
    {
        $this->assertSame($this->dir . '/data/twofa', TwoFactorStore::recordDir($this->config()));
        $this->assertStringContainsString('ldb_data_', TwoFactorStore::recordDir(new Config()));
    }
}
