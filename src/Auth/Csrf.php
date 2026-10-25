<?php

declare(strict_types=1);

namespace Ldb\Auth;

/**
 * Synchronizer-token CSRF protection. The token lives in a caller-supplied storage array
 * (normally a slot in $_SESSION) and is compared in constant time.
 */
final class Csrf
{
    private const KEY = 'csrf';

    /** @var array<string,mixed> */
    private array $storage;

    /** @param array<string,mixed> $storage passed by reference */
    public function __construct(array &$storage)
    {
        $this->storage = &$storage;
    }

    public function token(): string
    {
        $t = $this->storage[self::KEY] ?? null;
        if (!is_string($t) || $t === '') {
            $t = Crypto::randomToken(32);
            $this->storage[self::KEY] = $t;
        }
        return $t;
    }

    public function validate(?string $token): bool
    {
        $expected = $this->storage[self::KEY] ?? null;
        if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public function regenerate(): string
    {
        unset($this->storage[self::KEY]);
        return $this->token();
    }
}
