<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Diff;

use Ldb\Diff\SchemaSnapshot;

/** Hand-built snapshot helpers for the diff unit tests. */
trait SnapshotFixtures
{
    private static function col(string $name, string $type, ?string $length = null, array $o = []): array
    {
        $full = strtolower($type) . ($length !== null ? '(' . $length . ')' : '') . (!empty($o['unsigned']) ? ' unsigned' : '');
        return $o + [
            'name' => $name,
            'type' => strtolower($type),
            'full_type' => $full,
            'length' => $length,
            'unsigned' => false,
            'nullable' => false,
            'default' => null,
            'default_kind' => 'none',
            'auto_increment' => false,
            'primary' => false,
            'comment' => null,
            'collation' => null,
            'extra' => null,
            'generated' => null,
        ];
    }

    private static function pk(array $cols, string $name = 'PRIMARY'): array
    {
        return ['name' => $name, 'columns' => $cols, 'unique' => true, 'primary' => true, 'type' => 'BTREE'];
    }

    private static function ix(string $name, array $cols, bool $unique = false, ?string $type = 'BTREE'): array
    {
        return ['name' => $name, 'columns' => $cols, 'unique' => $unique, 'primary' => false, 'type' => $type];
    }

    private static function fk(string $name, array $cols, string $refTable, array $refCols, ?string $onDelete = null): array
    {
        return ['name' => $name, 'columns' => $cols, 'ref_schema' => null, 'ref_table' => $refTable, 'ref_columns' => $refCols, 'on_delete' => $onDelete, 'on_update' => null];
    }

    private static function table(string $name, array $columns, array $indexes = [], array $fks = [], array $options = [], array $checks = []): array
    {
        return [
            'name' => $name,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $fks,
            'checks' => $checks,
            'options' => $options + ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'comment' => null, 'auto_increment' => null],
        ];
    }

    private static function snap(array $tables, array $extra = [], string $driver = 'mysql', string $db = 'app'): SchemaSnapshot
    {
        $t = [];
        foreach ($tables as $tbl) {
            $t[$tbl['name']] = $tbl;
        }
        return new SchemaSnapshot($extra + ['driver' => $driver, 'database' => $db, 'schema' => $driver === 'pgsql' ? 'public' : null, 'tables' => $t]);
    }

    /** The "desired" schema used by most tests. */
    private static function source(): SchemaSnapshot
    {
        return self::snap([
            self::table('authors', [
                self::col('id', 'int', null, ['auto_increment' => true, 'primary' => true]),
                self::col('name', 'varchar', '200', ['collation' => 'utf8mb4_unicode_ci']),
                self::col('email', 'varchar', '255', ['nullable' => true, 'default_kind' => 'null', 'collation' => 'utf8mb4_unicode_ci']),
                self::col('bio', 'text', null, ['nullable' => true, 'collation' => 'utf8mb4_unicode_ci']),
            ], [self::pk(['id']), self::ix('uq_email', ['email'], true)]),
            self::table('books', [
                self::col('id', 'int', null, ['auto_increment' => true, 'primary' => true]),
                self::col('author_id', 'int'),
                self::col('title', 'varchar', '100', ['collation' => 'utf8mb4_unicode_ci']),
                self::col('price', 'decimal', '10,2', ['default_kind' => 'value', 'default' => '0.00']),
            ], [self::pk(['id']), self::ix('ix_title', ['title'])], [self::fk('fk_books_author', ['author_id'], 'authors', ['id'], 'CASCADE')], [], [
                ['name' => 'chk_price', 'definition' => 'CHECK ((`price` >= 0))'],
            ]),
            self::table('tags', [self::col('id', 'int', null, ['primary' => true]), self::col('label', 'varchar', '50')], [self::pk(['id'])]),
        ], [
            'views' => ['v_books' => ['name' => 'v_books', 'ddl' => 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `app`.`v_books` AS select `app`.`books`.`id` AS `id`, `app`.`books`.`title` AS `title` from `app`.`books`']],
            'routines' => ['PROCEDURE:p_count' => ['name' => 'p_count', 'type' => 'PROCEDURE', 'ddl' => "CREATE DEFINER=`root`@`%` PROCEDURE `p_count`()\nBEGIN SELECT COUNT(*) FROM books; END"]],
            'triggers' => ['books.trg' => ['name' => 'trg', 'table' => 'books', 'ddl' => 'CREATE DEFINER=`root`@`%` TRIGGER `trg` BEFORE INSERT ON `books` FOR EACH ROW SET NEW.title = TRIM(NEW.title)']],
            'events' => ['ev' => ['name' => 'ev', 'ddl' => "CREATE DEFINER=`root`@`%` EVENT `ev` ON SCHEDULE EVERY 1 DAY STARTS '2024-01-01 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM tags"]],
        ]);
    }

    /** The database to migrate. */
    private static function target(): SchemaSnapshot
    {
        return self::snap([
            self::table('authors', [
                self::col('id', 'int', '11', ['auto_increment' => true, 'primary' => true]),
                self::col('name', 'varchar', '100', ['collation' => 'utf8mb4_unicode_ci']),
                self::col('email', 'varchar', '255', ['nullable' => true, 'default_kind' => 'null', 'collation' => 'utf8mb4_unicode_ci']),
                self::col('legacy', 'int', null, ['nullable' => true, 'default_kind' => 'null']),
            ], [self::pk(['id'])]),
            self::table('books', [
                self::col('id', 'int', null, ['auto_increment' => true, 'primary' => true]),
                self::col('author_id', 'int'),
                self::col('title', 'varchar', '200', ['collation' => 'utf8mb4_unicode_ci']),
                self::col('price', 'decimal', '10,2', ['nullable' => true, 'default_kind' => 'null']),
            ], [self::pk(['id']), self::ix('ix_old', ['price'])], [self::fk('fk_books_author', ['author_id'], 'authors', ['id'])]),
            self::table('obsolete', [self::col('id', 'int', null, ['primary' => true])], [self::pk(['id'])], [self::fk('fk_obs_book', ['id'], 'books', ['id'])]),
        ], [
            'database' => 'prod',
            'views' => [
                'v_books' => ['name' => 'v_books', 'ddl' => 'CREATE ALGORITHM=UNDEFINED DEFINER=`admin`@`localhost` SQL SECURITY DEFINER VIEW `prod`.`v_books` AS select `prod`.`books`.`id` AS `id` from `prod`.`books`'],
                'v_old' => ['name' => 'v_old', 'ddl' => 'CREATE VIEW `v_old` AS select 1 AS `x`'],
            ],
            'routines' => ['FUNCTION:f_old' => ['name' => 'f_old', 'type' => 'FUNCTION', 'ddl' => 'CREATE FUNCTION `f_old`() RETURNS int RETURN 1']],
            'triggers' => [],
            'events' => ['ev' => ['name' => 'ev', 'ddl' => "CREATE DEFINER=`x`@`%` EVENT `ev` ON SCHEDULE EVERY 1 DAY STARTS '2025-06-01 12:00:00' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM tags"]],
        ]);
    }
}
