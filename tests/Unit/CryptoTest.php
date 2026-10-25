<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $key = Crypto::generateKey();
        $this->assertSame(32, strlen($key));
        $secret = '{"password":"pä$$wörd 日本"}';
        $enc = Crypto::encrypt($secret, $key, 'conn1');
        $this->assertNotSame($secret, $enc);
        $this->assertSame($secret, Crypto::decrypt($enc, $key, 'conn1'));
    }

    public function testRandomIv(): void
    {
        $key = Crypto::generateKey();
        $this->assertNotSame(Crypto::encrypt('x', $key), Crypto::encrypt('x', $key));
    }

    public function testWrongKeyOrAadFails(): void
    {
        $key = Crypto::generateKey();
        $enc = Crypto::encrypt('secret', $key, 'a');
        $this->assertNull(Crypto::decrypt($enc, Crypto::generateKey(), 'a'));
        $this->assertNull(Crypto::decrypt($enc, $key, 'b'));
        $this->assertNull(Crypto::decrypt($enc, 'short', 'a'));
    }

    public function testTamperingIsDetected(): void
    {
        $key = Crypto::generateKey();
        $raw = base64_decode(Crypto::encrypt('secret value', $key), true);
        $this->assertIsString($raw);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertNull(Crypto::decrypt(base64_encode($raw), $key));
        $this->assertNull(Crypto::decrypt('not base64 !!!', $key));
        $this->assertNull(Crypto::decrypt(base64_encode('tiny'), $key));
    }

    public function testInvalidKeyLengthOnEncrypt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crypto::encrypt('x', 'too short');
    }

    public function testRandomToken(): void
    {
        $t = Crypto::randomToken(32);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $t);
        $this->assertNotSame($t, Crypto::randomToken(32));
    }
}
