<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** Schema diff round trip; env LDB_TEST_MYSQL_DSN / LDB_TEST_MYSQL_USER / LDB_TEST_MYSQL_PASS. */
final class MySqlSchemaDiffTest extends MySqlSchemaDiffTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MYSQL';
    }
}
