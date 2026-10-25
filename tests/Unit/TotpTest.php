<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B shared secret for SHA1 (ASCII "12345678901234567890"). */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string,array{0:int,1:string}> time → last 6 digits of the 8-digit RFC value */
    public function rfcVectors(): array
    {
        return [
            '59' => [59, '287082'],                 // 94287082
            '1111111109' => [1111111109, '081804'], // 07081804
            '1111111111' => [1111111111, '050471'], // 14050471
            '1234567890' => [1234567890, '005924'], // 89005924
            '2000000000' => [2000000000, '279037'], // 69279037
            '20000000000' => [20000000000, '353130'], // 65353130
        ];
    }

    /** @dataProvider rfcVectors */
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame($expected, Totp::code(self::RFC_SECRET, $time));
        $this->assertSame(Totp::step($time), Totp::verify(self::RFC_SECRET, $expected, $time));
    }

    public function testEightDigitHotpMatchesRfc(): void
    {
        $this->assertSame('94287082', Totp::hotp('12345678901234567890', 1, 8));
        $this->assertSame('65353130', Totp::hotp('12345678901234567890', intdiv(20000000000, 30), 8));
    }

    public function testRfc4226HotpVectors(): void
    {
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp('12345678901234567890', $counter));
        }
    }

    public function testWindowAllowsOneStepOfSkew(): void
    {
        $t = 1111111111;
        $code = Totp::code(self::RFC_SECRET, $t);
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, $t + 30));
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, $t - 30));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $t + 60));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $t - 60));
        $this->assertSame(Totp::step($t), Totp::verify(self::RFC_SECRET, $code, $t + 30));
    }

    public function testReplayOfSameOrOlderStepRejected(): void
    {
        $t = 1234567890;
        $code = Totp::code(self::RFC_SECRET, $t);
        $step = Totp::verify(self::RFC_SECRET, $code, $t);
        $this->assertNotNull($step);
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $t, $step), 'same step twice');
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $t - 30), $t, $step), 'older step after newer');
        $this->assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $t + 30), $t, $step));
    }

    public function testInputNormalisationAndGarbage(): void
    {
        $t = 1111111109;
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, ' 081 804 ', $t));
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, '081-804', $t));
        foreach (['', '08180', '0818045', 'abcdef', '08180x', "081804\n1"] as $bad) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, $bad, $t), var_export($bad, true));
        }
        $this->assertNull(Totp::verify('not base32!', '081804', $t));
        $this->assertNull(Totp::verify('', '081804', $t));
    }

    public function testSecretGenerationAndBase32RoundTrip(): void
    {
        $s = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $s);
        $this->assertSame(20, strlen((string) Totp::base32Decode($s)));
        $this->assertNotSame($s, Totp::generateSecret());
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', random_bytes(37)] as $raw) {
            $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar')); // RFC 4648 (unpadded)
        $this->assertSame('foobar', Totp::base32Decode('mzxw6ytboi======'));
    }

    public function testUri(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'root@mariadb', 'LDB');
        $this->assertSame('otpauth://totp/LDB:root%40mariadb?secret=JBSWY3DPEHPK3PXP&issuer=LDB&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
