<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MySQL 8.x integration tests; env LDB_TEST_MYSQL_DSN / LDB_TEST_MYSQL_USER / LDB_TEST_MYSQL_PASS (see MySqlDriverTestCase). */
final class MySqlDriverTest extends MySqlDriverTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MYSQL';
    }
}
