<?php

declare(strict_types=1);

namespace Ldb;

final class Version
{
    public const VERSION = '0.1.0';

    public static function get(): string
    {
        return self::VERSION;
    }
}
