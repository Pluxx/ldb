<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MySQL privilege manager; env LDB_TEST_MYSQL_DSN / LDB_TEST_MYSQL_USER / LDB_TEST_MYSQL_PASS. */
final class PrivilegesMySqlTest extends PrivilegesMySqlTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MYSQL';
    }
}
