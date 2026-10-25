<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** MySQL routine editor integration tests; env LDB_TEST_MYSQL_DSN / LDB_TEST_MYSQL_USER / LDB_TEST_MYSQL_PASS. */
final class RoutineEditorMySqlTest extends RoutineEditorMySqlTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MYSQL';
    }
}
