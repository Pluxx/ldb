<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\Sso;
use Ldb\Auth\SsoException;
use Ldb\Auth\SsoNonceStore;
use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class SsoTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef-0123456789';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oph_sso_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            @chmod($this->dir, 0700);
            foreach ((array) glob($this->dir . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->dir);
        }
    }

    private function store(): SsoNonceStore
    {
        return new SsoNonceStore($this->dir);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function claims(array $extra = []): array
    {
        return $extra + ['preset' => 'mariadb', 'user' => 'root', 'password' => 'pw'];
    }

    /** @return array<string,mixed> */
    private function verify(string $token, int $ttl = 60, ?int $now = null): array
    {
        return Sso::verify($token, self::SECRET, $ttl, $this->store(), $now);
    }

    private function expectRejected(callable $fn, string $reason): void
    {
        try {
            $fn();
        } catch (SsoException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
            return;
        }
        $this->fail('Expected SsoException: ' . $reason);
    }

    // ------------------------------------------------------------------ tokens

    public function testRoundTrip(): void
    {
        $token = Sso::createToken(self::claims(['database' => 'shop', 'read_only' => true]), self::SECRET);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $this->assertStringNotContainsString('root', (string) base64_decode(strtr($token, '-_', '+/')));
        $claims = $this->verify($token);
        $this->assertSame('root', $claims['user']);
        $this->assertSame('pw', $claims['password']);
        $this->assertSame('shop', $claims['database']);
        $this->assertTrue($claims['read_only']);
        $this->assertSame(Sso::DEFAULT_TTL, $claims['exp'] - $claims['iat']);
        $this->assertMatchesRegularExpression(Sso::NONCE_PATTERN, $claims['nonce']);
    }

    public function testCreateUrl(): void
    {
        $url = Sso::createUrl('https://db.example.com/ldb/', self::claims(), self::SECRET);
        $this->assertMatchesRegularExpression('~^https://db\.example\.com/ldb/sso\.php\?token=[A-Za-z0-9_-]+$~', $url);
        $this->assertStringStartsWith('https://x/sso.php?token=', Sso::createUrl('https://x/sso.php', self::claims(), self::SECRET));
    }

    /** Independent implementation of the documented format (docs/sso.md) must be accepted. */
    public function testDocumentedFormat(): void
    {
        $now = time();
        $json = (string) json_encode(self::claims(['iat' => $now, 'exp' => $now + 30, 'nonce' => 'abcdefghijklmnopqrstuv']));
        $key = hash_hkdf('sha256', self::SECRET, 32, 'ldb-sso v1 aes-256-gcm', '');
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "\x01", 16);
        $token = rtrim(strtr(base64_encode("\x01" . $iv . $ct . $tag), '+/', '-_'), '=');
        $this->assertSame('abcdefghijklmnopqrstuv', $this->verify($token)['nonce']);
    }

    public function testTamperedByteRejected(): void
    {
        $raw = (string) base64_decode(strtr(Sso::createToken(self::claims(), self::SECRET), '-_', '+/'));
        // Every region: iv, ciphertext, tag.
        foreach ([1, 5, 13, 20, strlen($raw) - 1] as $pos) {
            $t = $raw;
            $t[$pos] = chr(ord($t[$pos]) ^ 0x01);
            $this->expectRejected(fn () => $this->verify(Sso::b64urlEncode($t)), 'authentication failed');
        }
        // Truncated tag.
        $this->expectRejected(fn () => $this->verify(Sso::b64urlEncode(substr($raw, 0, -1))), 'authentication failed');
    }

    public function testWrongSecretRejected(): void
    {
        $token = Sso::createToken(self::claims(), self::SECRET);
        $this->expectRejected(
            fn () => Sso::verify($token, self::SECRET . 'x', 60, $this->store()),
            'authentication failed'
        );
    }

    public function testWrongVersionRejected(): void
    {
        $raw = (string) base64_decode(strtr(Sso::createToken(self::claims(), self::SECRET), '-_', '+/'));
        $raw[0] = "\x02";
        $this->expectRejected(fn () => $this->verify(Sso::b64urlEncode($raw)), 'unsupported token version 2');
    }

    public function testMalformedEncodingRejected(): void
    {
        $token = Sso::createToken(self::claims(), self::SECRET);
        $this->expectRejected(fn () => $this->verify($token . '='), 'malformed');
        $this->expectRejected(fn () => $this->verify(strtr($token, '-_', '+/') . '+/'), 'malformed');
        $this->expectRejected(fn () => $this->verify('a'), 'malformed');
        $this->expectRejected(fn () => $this->verify(''), 'malformed');
        $this->expectRejected(fn () => $this->verify(str_repeat('A', Sso::MAX_TOKEN_LENGTH + 1)), 'malformed');
    }

    public function testExpiredRejected(): void
    {
        $now = time();
        $token = Sso::createToken(self::claims(['iat' => $now - 70, 'exp' => $now - 10]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($token), 'expired');
    }

    public function testFutureIatRejected(): void
    {
        $now = time();
        $ok = Sso::createToken(self::claims(['iat' => $now + 20, 'exp' => $now + 50]), self::SECRET);
        $this->assertSame('root', $this->verify($ok, 60, $now)['user']); // within clock skew
        $bad = Sso::createToken(self::claims(['iat' => $now + 31 + 1, 'exp' => $now + 60]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($bad, 60, $now), 'future');
    }

    public function testTtlExceededRejected(): void
    {
        $now = time();
        $token = Sso::createToken(self::claims(['iat' => $now, 'exp' => $now + 61]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($token, 60), 'exceeds sso_ttl');
        // sso_ttl itself is capped at MAX_TTL.
        $long = Sso::createToken(self::claims(['iat' => $now, 'exp' => $now + 3600]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($long, 100000), 'exceeds sso_ttl');
        $this->assertSame(300, Sso::ttl(new Config(['sso_ttl' => 100000])));
        $this->assertSame(60, Sso::ttl(new Config()));
        $inverted = Sso::createToken(self::claims(['iat' => $now, 'exp' => $now]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($inverted), 'not after iat');
    }

    public function testReplayRejected(): void
    {
        $token = Sso::createToken(self::claims(), self::SECRET);
        $this->verify($token);
        $this->expectRejected(fn () => $this->verify($token), 'replay');
        // Re-encoding the same bytes differently does not help: the nonce is inside the ciphertext.
        $raw = (string) base64_decode(strtr($token, '-_', '+/'));
        $this->expectRejected(fn () => $this->verify(Sso::b64urlEncode($raw)), 'replay');
    }

    public function testRejectedTokenDoesNotBurnNonce(): void
    {
        $now = time();
        $token = Sso::createToken(self::claims(['iat' => $now, 'exp' => $now + 60]), self::SECRET);
        $this->expectRejected(fn () => $this->verify($token, 30, $now), 'exceeds sso_ttl');
        $this->assertSame('root', $this->verify($token, 60, $now)['user']);
    }

    public function testNonceStoreFailsClosedOnUnsafeDir(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions only');
        }
        mkdir($this->dir, 0700);
        chmod($this->dir, 0777);
        $token = Sso::createToken(self::claims(), self::SECRET);
        $prev = ini_set('error_log', '/dev/null');
        try {
            $this->expectRejected(fn () => $this->verify($token), 'unavailable');
        } finally {
            ini_set('error_log', (string) $prev);
        }
    }

    public function testNonceStoreGarbageCollects(): void
    {
        $store = $this->store();
        $now = time();
        $this->assertTrue($store->consume('old-nonce-abcdefgh', $now - SsoNonceStore::RETENTION - 10));
        $this->assertFalse($store->consume('old-nonce-abcdefgh', $now - SsoNonceStore::RETENTION - 5));
        $this->assertTrue($store->consume('new-nonce-abcdefgh', $now));
        $this->assertCount(1, (array) glob($this->dir . '/*.nonce'));
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertGreaterThan(Sso::MAX_TTL + Sso::CLOCK_SKEW, SsoNonceStore::RETENTION);
    }

    public function testClaimTypesChecked(): void
    {
        $now = time();
        foreach ([
            ['port' => '3306'], ['read_only' => 'yes'], ['user' => ['x']], ['nonce' => 'short'],
            ['iat' => (string) $now], ['redirect' => 5],
        ] as $bad) {
            $this->expectRejected(fn () => Sso::createToken(self::claims($bad), self::SECRET), 'must');
        }
        $this->expectRejected(
            fn () => Sso::createToken(['user' => 'root'], self::SECRET),
            'need "preset" or "driver"'
        );
    }

    // ------------------------------------------------------------------ redirect

    /** @return array<string,array{0:string}> */
    public function badRedirects(): array
    {
        return [
            'absolute' => ['https://evil.example/'],
            'protocol-relative' => ['//evil.example/'],
            'hash protocol-relative' => ['#//evil.example/'],
            'javascript' => ['javascript:alert(1)'],
            'hash javascript' => ['#javascript:alert(1)'],
            'CRLF' => ["#/c/{conn}\r\nSet-Cookie: x=1"],
            'LF at end' => ["#/c/{conn}\n"],
            'backslash' => ['#/\\evil.example'],
            'space' => ['#/c/{conn}/db/a b'],
            'quote' => ['#/c/"x"'],
            'angle' => ['#/<script>'],
            'bad escape' => ['#/c/%zz'],
            'path' => ['/c/{conn}'],
            'relative file' => ['api.php?action=auth.logout'],
            'empty hash' => ['#'],
        ];
    }

    /** @dataProvider badRedirects */
    public function testBadRedirectRejected(string $redirect): void
    {
        $this->expectRejected(fn () => Sso::redirectFragment(['redirect' => $redirect], 'abc'), 'invalid redirect');
        // …and already at token creation/verification, before the nonce is consumed.
        $this->expectRejected(fn () => Sso::createToken(self::claims(['redirect' => $redirect]), self::SECRET), 'invalid redirect');
    }

    public function testRedirectRoutes(): void
    {
        $this->assertSame('#/c/abc', Sso::redirectFragment([], 'abc'));
        $this->assertSame('#/c/abc/sql?db=shop', Sso::redirectFragment(['redirect' => '#/c/{conn}/sql?db=shop'], 'abc'));
        $this->assertSame('#/c/abc/db/my%20db%40x', Sso::redirectFragment(['database' => 'my db@x'], 'abc'));
        $this->assertSame('#/c/abc/db/app@public', Sso::redirectFragment(['database' => 'app', 'schema' => 'public'], 'abc'));
        $this->assertSame(
            '#/c/abc/db/shop/t/order%2Fitems/browse',
            Sso::redirectFragment(['database' => 'ignored', 'redirect' => ['db' => 'shop', 'table' => 'order/items']], 'abc')
        );
        $this->expectRejected(fn () => Sso::redirectFragment(['redirect' => ['db' => ['x']]], 'abc'), 'must be a string');
    }

    // ------------------------------------------------------------------ policy

    private static function config(array $extra = []): Config
    {
        return new Config($extra + [
            'sso_secret' => self::SECRET,
            'servers' => [
                ['id' => 'mariadb', 'driver' => 'mysql', 'host' => 'mariadb', 'port' => 3306],
                ['id' => 'prod', 'driver' => 'mysql', 'host' => 'db.prod', 'read_only' => true],
            ],
        ]);
    }

    public function testDisabledWithoutUsableSecret(): void
    {
        $this->assertNull(Sso::secret(new Config()));
        $this->assertNull(Sso::secret(new Config(['sso_secret' => ''])));
        $this->assertNull(Sso::secret(new Config(['sso_secret' => str_repeat('x', 31)])));
        $this->assertNull(Sso::secret(new Config(['sso_secret' => ['array']])));
        $this->assertSame(str_repeat('x', 32), Sso::secret(new Config(['sso_secret' => str_repeat('x', 32)])));
        $this->expectRejected(fn () => Sso::verify('abc', str_repeat('x', 31), 60, $this->store()), 'too short');
        $this->expectException(\InvalidArgumentException::class);
        Sso::createToken(self::claims(), str_repeat('x', 31));
    }

    public function testPresetRestriction(): void
    {
        [$input, $arb] = Sso::loginInput(self::claims(), self::config());
        $this->assertSame('mariadb', $input['preset']);
        $this->assertFalse($arb);

        $restricted = self::config(['sso_allowed_presets' => ['prod']]);
        $this->expectRejected(fn () => Sso::loginInput(self::claims(), $restricted), 'not in sso_allowed_presets');
        [$input] = Sso::loginInput(self::claims(['preset' => 'prod']), $restricted);
        $this->assertSame('prod', $input['preset']);

        $this->expectRejected(
            fn () => Sso::loginInput(self::claims(['host' => '10.0.0.1']), self::config()),
            'combine "preset" with "host"'
        );
    }

    public function testArbitraryServerNeedsOptIn(): void
    {
        $claims = ['driver' => 'mysql', 'host' => '10.0.0.1', 'port' => 3307, 'user' => 'u'];
        // allow_arbitrary_server (login page) does not open SSO to free hosts.
        $this->expectRejected(
            fn () => Sso::loginInput($claims, self::config(['allow_arbitrary_server' => true])),
            'sso_allow_arbitrary_server is off'
        );
        // only_presets always wins.
        $this->expectRejected(
            fn () => Sso::loginInput($claims, self::config(['sso_allow_arbitrary_server' => true, 'only_presets' => true])),
            'sso_allow_arbitrary_server is off'
        );
        $config = self::config(['sso_allow_arbitrary_server' => true]);
        [$input, $arb] = Sso::loginInput($claims, $config);
        $this->assertTrue($arb);
        $params = (new DriverFactory($config))->resolveLogin($input, $arb);
        $this->assertSame('10.0.0.1:3307', $params['server']);
        // The login page itself still refuses free hosts in that config.
        $this->expectException(ApiException::class);
        (new DriverFactory($config))->resolveLogin($input);
    }

    public function testSharedLoginPolicyStillApplies(): void
    {
        $config = self::config(['sso_allow_arbitrary_server' => true]);
        $factory = new DriverFactory($config);

        // Preset read_only cannot be removed by the token.
        [$input, $arb] = Sso::loginInput(self::claims(['preset' => 'prod', 'read_only' => false]), $config);
        $this->assertTrue($factory->resolveLogin($input, $arb)['read_only']);
        // Global read_only too.
        $ro = self::config(['read_only' => true]);
        [$input, $arb] = Sso::loginInput(self::claims(), $ro);
        $this->assertTrue((new DriverFactory($ro))->resolveLogin($input, $arb)['read_only']);

        // DSN injection guard.
        [$input, $arb] = Sso::loginInput(['driver' => 'mysql', 'host' => 'a;host=evil', 'user' => 'u'], $config);
        try {
            $factory->resolveLogin($input, $arb);
            $this->fail('host with ";" accepted');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Invalid host', $e->getMessage());
        }

        // SQLite: sqlite_paths are never bypassed.
        mkdir($this->dir, 0700);
        $sqlite = self::config(['sso_allow_arbitrary_server' => true, 'sqlite_paths' => [$this->dir], 'sqlite_allow_no_password' => true]);
        [$input, $arb] = Sso::loginInput(['driver' => 'sqlite', 'database' => '/etc/passwd', 'user' => ''], $sqlite);
        $this->expectExceptionMessage('outside the allowed directories');
        (new DriverFactory($sqlite))->resolveLogin($input, $arb);
    }
}
