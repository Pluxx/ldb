<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MariaDB integration tests; env LDB_TEST_MARIADB_DSN / LDB_TEST_MARIADB_USER / LDB_TEST_MARIADB_PASS (see MySqlDriverTestCase). */
final class MariaDbDriverTest extends MySqlDriverTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MARIADB';
    }
}
