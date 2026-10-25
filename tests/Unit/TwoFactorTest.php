<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\Totp;
use Ldb\Auth\TwoFactor;
use Ldb\Auth\TwoFactorStore;
use Ldb\Config;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class TwoFactorTest extends TestCase
{
    private const KEY = 'test-secret-key-0123456789abcdef0123456789';

    private string $dir;
    private int $now = 1700000000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ldb_2fa_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files($this->dir)) as $f) {
            is_dir($f) && !is_link($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return string[] */
    private function files(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            $out[] = $f;
            if (is_dir($f) && !is_link($f)) {
                $out = array_merge($out, $this->files($f));
            }
        }
        return $out;
    }

    private function store(string $key = self::KEY): TwoFactorStore
    {
        return new TwoFactorStore($this->dir . '/data/twofa', $key);
    }

    private function config(array $extra = []): Config
    {
        return new Config($extra + ['secret_key' => self::KEY, 'data_dir' => $this->dir . '/data']);
    }

    private function tf(array $extra = []): TwoFactor
    {
        $config = $this->config($extra);
        return new TwoFactor($config, TwoFactorStore::fromConfig($config), fn (): int => $this->now);
    }

    private const PARAMS = ['driver' => 'mysql', 'preset' => 'mariadb', 'user' => 'root', 'host' => 'mariadb', 'port' => 3306];

    /** Enroll PARAMS at $this->now - 30 s (so the current step is still unused). @return array{0:string,1:string[]} */
    private function enroll(TwoFactor $tf): array
    {
        $secret = Totp::generateSecret();
        $this->now -= 30;
        $codes = $tf->enroll(TwoFactor::identity(self::PARAMS), $secret, Totp::code($secret, $this->now), 'root@mariadb');
        $this->now += 30;
        $this->assertIsArray($codes);
        return [$secret, $codes];
    }

    // ------------------------------------------------------------------ identity

    public function testIdentityDistinguishesServersAndUsers(): void
    {
        $ids = [
            TwoFactor::identity(self::PARAMS),
            TwoFactor::identity(['preset' => 'mariadb', 'user' => 'app'] + self::PARAMS),
            TwoFactor::identity(['host' => 'mysql'] + self::PARAMS),
            TwoFactor::identity(['port' => 3307] + self::PARAMS),
            TwoFactor::identity(['preset' => null, 'driver' => 'pgsql'] + self::PARAMS),
            TwoFactor::identity(['driver' => 'sqlite', 'file' => '/data/a.sqlite']),
            TwoFactor::identity(['driver' => 'sqlite', 'file' => '/data/b.sqlite']),
            // Separator tricks must not collide.
            TwoFactor::identity(['host' => 'a|b', 'user' => 'c'] + self::PARAMS),
            TwoFactor::identity(['host' => 'a', 'user' => 'b|c'] + self::PARAMS),
        ];
        $this->assertCount(count($ids), array_unique($ids));
        // Host case does not matter; the password is not part of the identity.
        $this->assertSame(
            TwoFactor::identity(['preset' => null, 'host' => 'DB.example'] + self::PARAMS),
            TwoFactor::identity(['preset' => null, 'host' => 'db.example', 'password' => 'x'] + self::PARAMS)
        );
        // A preset and free host entry to the same server are the same login (no 2FA bypass).
        $this->assertSame(TwoFactor::identity(self::PARAMS), TwoFactor::identity(['preset' => null] + self::PARAMS));
        $this->assertSame(TwoFactor::identity(self::PARAMS), TwoFactor::identity(['preset' => 'other', 'port' => null] + self::PARAMS));
        $this->assertSame('root @ mysql://mariadb:3306', TwoFactor::describe(TwoFactor::identity(self::PARAMS)));
    }

    // ------------------------------------------------------------------ storage

    public function testStoreEncryptsAndRoundTrips(): void
    {
        $store = $this->store();
        $store->save('id-1', ['secret' => 'JBSWY3DPEHPK3PXP', 'recovery' => ['h1']]);
        $this->assertSame('JBSWY3DPEHPK3PXP', $store->load('id-1')['secret']);
        $this->assertNull($store->load('id-2'));
        $file = $this->dir . '/data/twofa/' . hash('sha256', 'id-1') . '.json';
        $this->assertFileExists($file);
        $raw = (string) file_get_contents($file);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw);
        $this->assertStringNotContainsString('id-1', $raw);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, fileperms($file) & 0777);
            $this->assertSame(0700, fileperms($this->dir . '/data/twofa') & 0777);
        }
        $this->assertSame([], glob($this->dir . '/data/twofa/*.tmp'), 'no temp files left behind');
        $this->assertTrue($store->delete('id-1'));
        $this->assertNull($store->load('id-1'));
    }

    public function testTamperedRecordRejected(): void
    {
        $store = $this->store();
        $store->save('id-1', ['secret' => 'AAAA']);
        $file = $this->dir . '/data/twofa/' . hash('sha256', 'id-1') . '.json';
        $outer = json_decode((string) file_get_contents($file), true);
        $raw = base64_decode($outer['data']);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        file_put_contents($file, json_encode(['v' => 1, 'data' => base64_encode($raw)]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed authentication');
        $store->load('id-1');
    }

    public function testRecordCannotBeMovedToAnotherIdentity(): void
    {
        $store = $this->store();
        $store->save('victim', ['secret' => 'AAAA']);
        $store->save('attacker', ['secret' => 'BBBB']);
        $d = $this->dir . '/data/twofa/';
        copy($d . hash('sha256', 'attacker') . '.json', $d . hash('sha256', 'victim') . '.json');
        $this->expectException(\RuntimeException::class);
        $store->load('victim');
    }

    public function testWrongKeyRejected(): void
    {
        $this->store()->save('id-1', ['secret' => 'AAAA']);
        $this->expectException(\RuntimeException::class);
        $this->store(str_repeat('k', 32))->load('id-1');
    }

    public function testShortKeyRefused(): void
    {
        $this->assertNull(TwoFactorStore::secretKey(new Config(['secret_key' => str_repeat('x', 31)])));
        $this->assertNull(TwoFactorStore::fromConfig(new Config()));
        $this->expectException(\InvalidArgumentException::class);
        new TwoFactorStore($this->dir, 'short');
    }

    public function testUnsafeDirectoryFailsClosed(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid')) {
            $this->markTestSkipped('POSIX permissions required');
        }
        mkdir($this->dir . '/open', 0777);
        chmod($this->dir . '/open', 0777);
        $store = new TwoFactorStore($this->dir . '/open', self::KEY);
        $log = ini_set('error_log', '/dev/null');
        try {
            $store->exists('x');
            $this->fail('unsafe directory accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not private', $e->getMessage());
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    // ------------------------------------------------------------------ verification

    public function testCodeVerificationAndReplay(): void
    {
        $tf = $this->tf();
        [$secret] = $this->enroll($tf);
        $id = TwoFactor::identity(self::PARAMS);
        $this->assertTrue($tf->enabled($id));
        $code = Totp::code($secret, $this->now);
        $this->assertNull($tf->verify($id, '000000' === $code ? '111111' : '000000', null));
        $this->assertSame('totp', $tf->verify($id, $code, null));
        $this->assertNull($tf->verify($id, $code, null), 'replay of the same code');
        // The enrollment code (previous step) is not accepted again either.
        $this->assertNull($tf->verify($id, Totp::code($secret, $this->now - 30), null));
        $this->now += 30;
        $this->assertSame('totp', $tf->verify($id, Totp::code($secret, $this->now), null));
    }

    public function testRecoveryCodesAreSingleUse(): void
    {
        $tf = $this->tf();
        [, $codes] = $this->enroll($tf);
        $id = TwoFactor::identity(self::PARAMS);
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c);
        }
        $raw = (string) file_get_contents($this->dir . '/data/twofa/' . hash('sha256', $id) . '.json');
        $this->assertStringNotContainsString(str_replace('-', '', $codes[3]), $raw);

        $this->assertSame('recovery', $tf->verify($id, null, strtoupper(str_replace('-', ' ', $codes[3]))));
        $this->assertSame(9, $tf->recoveryRemaining($id));
        $this->assertNull($tf->verify($id, null, $codes[3]), 'recovery code reused');
        $this->assertNull($tf->verify($id, null, 'aaaaa-aaaaa'));
        $this->assertNull($tf->verify($id, null, ''));
        $this->assertSame('recovery', $tf->verify($id, null, $codes[9]));
        $this->assertSame(8, $tf->recoveryRemaining($id));

        $new = $tf->regenerateRecovery($id);
        $this->assertSame(10, $tf->recoveryRemaining($id));
        $this->assertNull($tf->verify($id, null, $codes[0]), 'old codes invalid after regeneration');
        $this->assertSame('recovery', $tf->verify($id, null, $new[0]));
    }

    public function testEnrollNeedsValidCodeAndOnlyOnce(): void
    {
        $tf = $this->tf();
        $id = TwoFactor::identity(self::PARAMS);
        $secret = Totp::generateSecret();
        $this->assertNull($tf->enroll($id, $secret, '12345x', 'l'));
        $this->assertNull($tf->enroll($id, $secret, Totp::code($secret, $this->now + 90), 'l'));
        $this->assertFalse($tf->enabled($id));
        $this->enroll($tf);
        $this->expectException(ApiException::class);
        $tf->enroll($id, $secret, Totp::code($secret, $this->now), 'l');
    }

    // ------------------------------------------------------------------ login policy

    public function testLoginStates(): void
    {
        $this->assertSame(TwoFactor::OFF, $this->tf()->loginState(self::PARAMS));
        $this->assertSame(TwoFactor::ENROLL, $this->tf(['twofa_required' => true])->loginState(self::PARAMS));
        $this->assertSame(TwoFactor::ENROLL, $this->tf(['twofa_required' => ['mariadb']])->loginState(self::PARAMS));
        $this->assertSame(TwoFactor::OFF, $this->tf(['twofa_required' => ['prod']])->loginState(self::PARAMS));
        // A listed preset also covers free host entry to its server, but not other servers.
        $servers = ['servers' => [['id' => 'mariadb', 'driver' => 'mysql', 'host' => 'MariaDB'], ['id' => 'prod', 'driver' => 'mysql', 'host' => 'db.prod']]];
        $this->assertSame(TwoFactor::ENROLL, $this->tf(['twofa_required' => ['mariadb']] + $servers)->loginState(['preset' => null] + self::PARAMS));
        $this->assertSame(TwoFactor::OFF, $this->tf(['twofa_required' => ['prod']] + $servers)->loginState(['preset' => null] + self::PARAMS));
        $this->assertSame(TwoFactor::OFF, $this->tf(['twofa_required' => ['mariadb']] + $servers)->loginState(['preset' => null, 'port' => 3307] + self::PARAMS));
        $tf = $this->tf();
        $this->enroll($tf);
        $this->assertSame(TwoFactor::ENABLED, $tf->loginState(self::PARAMS));
        $this->assertSame(TwoFactor::OFF, $tf->loginState(['user' => 'other'] + self::PARAMS));
    }

    public function testMissingSecretKeyFailsClosed(): void
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            // Policy requires 2FA but it cannot work → refuse.
            $noKey = new TwoFactor(new Config(['data_dir' => $this->dir . '/data', 'twofa_required' => true]));
            $this->assertFalse($noKey->available());
            try {
                $noKey->loginState(self::PARAMS);
                $this->fail('login allowed although 2FA is required and unavailable');
            } catch (ApiException $e) {
                $this->assertSame('twofa_unavailable', $e->getErrorCode());
            }
            // An enrolled login stays protected when secret_key is removed.
            $this->enroll($this->tf());
            $noKey = new TwoFactor(new Config(['data_dir' => $this->dir . '/data']));
            try {
                $noKey->loginState(self::PARAMS);
                $this->fail('enrolled login allowed without secret_key');
            } catch (ApiException $e) {
                $this->assertSame('twofa_unavailable', $e->getErrorCode());
            }
            // And when the key changed (record undecryptable), verification fails closed too.
            $other = $this->tf(['secret_key' => str_repeat('z', 40)]);
            try {
                $other->verify(TwoFactor::identity(self::PARAMS), '123456', null);
                $this->fail('undecryptable record accepted');
            } catch (ApiException $e) {
                $this->assertSame('twofa_unavailable', $e->getErrorCode());
            }
            $this->assertSame(TwoFactor::OFF, $noKey->loginState(['user' => 'nobody'] + self::PARAMS));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }
}
