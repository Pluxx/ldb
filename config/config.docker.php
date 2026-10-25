<?php

declare(strict_types=1);

/* Config for the docker dev stack; mounted as config/config.php inside the app container. */
return [
    'debug' => false,
    'servers' => [
        ['id' => 'mariadb', 'label' => 'MariaDB 11 (docker)', 'driver' => 'mysql', 'host' => 'mariadb', 'port' => 3306],
        ['id' => 'mysql', 'label' => 'MySQL 8.0 (docker)', 'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306],
        ['id' => 'pgsql', 'label' => 'PostgreSQL 17 (docker)', 'driver' => 'pgsql', 'host' => 'postgres', 'port' => 5432, 'database' => 'ldb_demo'],
        ['id' => 'mssql', 'label' => 'SQL Server 2022 (docker)', 'driver' => 'sqlsrv', 'host' => 'mssql', 'port' => 1433],
    ],
    'only_presets' => false,
    'allow_arbitrary_server' => true,
    'sqlite_paths' => ['/var/www/data'],
    // Dev only: the SQLite login password is "sqlite".
    'sqlite_password_hash' => '$2y$10$lAtUykxduQgtXThpga8HSOM4z1IQDVcmfpc/4IzK0jeZH/adQVhA2',
    // Dev stack only (public, never reuse): SSO links, e.g.
    //   docker compose exec app php bin/sso-link --preset=mariadb --user=root --password-stdin <<< root
    'sso_secret' => 'ldb-dev-only-sso-secret-0123456789abcdef',
    // Dev stack only (public, never reuse): enables two-factor authentication (Account security).
    // Reset a lost dev 2FA: docker compose exec -u www-data app php bin/twofa reset --preset=mariadb --user=root
    'secret_key' => 'ldb-dev-only-2fa-secret-key-0123456789abcdef',
    'data_dir' => '/tmp/ldb-data', // dev only; use a persistent path in production
];
