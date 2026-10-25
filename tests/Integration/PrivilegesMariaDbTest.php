<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MariaDB privilege manager; env LDB_TEST_MARIADB_DSN / LDB_TEST_MARIADB_USER / LDB_TEST_MARIADB_PASS. */
final class PrivilegesMariaDbTest extends PrivilegesMySqlTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MARIADB';
    }
}
