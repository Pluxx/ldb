<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** Schema diff round trip; env LDB_TEST_MARIADB_DSN / LDB_TEST_MARIADB_USER / LDB_TEST_MARIADB_PASS. */
final class MariaDbSchemaDiffTest extends MySqlSchemaDiffTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MARIADB';
    }
}
