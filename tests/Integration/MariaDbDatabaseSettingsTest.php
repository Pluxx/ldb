<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

/** Database settings on MariaDb; env LDB_TEST_MARIADB_DSN / _USER / _PASS (see MySqlDatabaseSettingsTestCase). */
final class MariaDbDatabaseSettingsTest extends MySqlDatabaseSettingsTestCase
{
    protected static function envPrefix(): string
    {
        return 'LDB_TEST_MARIADB';
    }
}
