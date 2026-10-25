<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** Database settings on MySql; env LDB_TEST_MYSQL_DSN / _USER / _PASS (see MySqlDatabaseSettingsTestCase). */
final class MySqlDatabaseSettingsTest extends MySqlDatabaseSettingsTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MYSQL';
    }
}
