<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MariaDB routine editor integration tests; env LDB_TEST_MARIADB_DSN / LDB_TEST_MARIADB_USER / LDB_TEST_MARIADB_PASS. */
final class RoutineEditorMariaDbTest extends RoutineEditorMySqlTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MARIADB';
    }
}
