<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Auth\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableAndStored(): void
    {
        $storage = [];
        $csrf = new Csrf($storage);
        $token = $csrf->token();
        $this->assertNotSame('', $token);
        $this->assertSame($token, $csrf->token());
        $this->assertSame($token, $storage['csrf']);
    }

    public function testValidate(): void
    {
        $storage = [];
        $csrf = new Csrf($storage);
        $token = $csrf->token();
        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate($token . 'x'));
        $this->assertFalse($csrf->validate(''));
        $this->assertFalse($csrf->validate(null));
    }

    public function testValidateWithoutIssuedTokenFails(): void
    {
        $storage = [];
        $csrf = new Csrf($storage);
        $this->assertFalse($csrf->validate(''));
        $this->assertFalse($csrf->validate('anything'));
    }

    public function testRegenerateInvalidatesOldToken(): void
    {
        $storage = [];
        $csrf = new Csrf($storage);
        $old = $csrf->token();
        $new = $csrf->regenerate();
        $this->assertNotSame($old, $new);
        $this->assertFalse($csrf->validate($old));
        $this->assertTrue($csrf->validate($new));
    }

    public function testSharedStorageAcrossInstances(): void
    {
        $storage = [];
        $token = (new Csrf($storage))->token();
        $this->assertTrue((new Csrf($storage))->validate($token));
    }
}
