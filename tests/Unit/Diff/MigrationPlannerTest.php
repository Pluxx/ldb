<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Diff;

use Ldb\Db\MySqlDriver;
use Ldb\Db\PgSqlDriver;
use Ldb\Diff\MigrationPlanner;
use Ldb\Diff\SchemaComparer;
use Ldb\Diff\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Planner tests with hand-built snapshots and unconnected drivers (MySQL/PostgreSQL builders only
 * need a connection for PostgreSQL ALTER COLUMN / DROP INDEX, which these tests avoid there).
 */
final class MigrationPlannerTest extends TestCase
{
    use SnapshotFixtures;

    private static function plan(SchemaSnapshot $s, SchemaSnapshot $t, array $options = [], ?array $selected = null, $driver = null): array
    {
        $changes = (new SchemaComparer())->compare($s, $t, $options);
        return (new MigrationPlanner($driver ?? new MySqlDriver(), $s, $t, $options))->plan($changes, $selected);
    }

    private static function phases(array $plan): array
    {
        $out = [];
        foreach ($plan['statements'] as $st) {
            if (end($out) !== $st['phase']) {
                $out[] = $st['phase'];
            }
        }
        return $out;
    }

    private static function indexOf(array $plan, string $needle): int
    {
        foreach ($plan['sql'] as $i => $sql) {
            if (strpos($sql, $needle) !== false) {
                return $i;
            }
        }
        return -1;
    }

    public function testPhaseOrder(): void
    {
        $plan = self::plan(self::source(), self::target());
        $this->assertSame([
            'drop_fk', 'drop_routine', 'drop_view', 'drop_table', 'create_table', 'alter_table', 'add_check', 'add_fk',
            'create_view', 'create_routine', 'create_trigger',
        ], self::phases($plan));
        $sql = implode(";\n", $plan['sql']);
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_books_author`', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT `fk_books_author` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('DROP FUNCTION `f_old`', $sql);
        $this->assertStringContainsString('DROP VIEW `v_old`', $sql);
        $this->assertStringContainsString('DROP TABLE `obsolete`', $sql);
        $this->assertStringContainsString('CREATE TABLE `tags`', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT `chk_price` CHECK ((`price` >= 0))', $sql);
        $this->assertStringContainsString('ADD UNIQUE INDEX `uq_email` (`email`)', $sql);
        $this->assertStringContainsString('DROP INDEX `ix_old`', $sql);
        $this->assertStringContainsString("CHANGE COLUMN `price` `price` DECIMAL(10,2) NOT NULL DEFAULT '0.00'", $sql);
        $this->assertStringContainsString('ADD COLUMN `bio` TEXT COLLATE utf8mb4_unicode_ci NULL,', $sql);
        $this->assertStringContainsString('DROP COLUMN `legacy`', $sql);
    }

    public function testObjectDdlIsAdaptedToTheTarget(): void
    {
        $plan = self::plan(self::source(), self::target());
        $view = $plan['sql'][self::indexOf($plan, 'AS select')];
        $this->assertStringNotContainsString('DEFINER=', $view, 'DEFINER stripped (ignore.definer)');
        $this->assertStringNotContainsString('`app`.', $view, 'source database qualifier rewritten');
        $this->assertStringContainsString('`prod`.`books`', $view);
        $proc = $plan['sql'][self::indexOf($plan, 'PROCEDURE `p_count`')];
        $this->assertStringStartsWith('CREATE PROCEDURE `p_count`()', $proc);
        $this->assertStringContainsString('BEGIN SELECT COUNT(*) FROM books; END', $proc, 'compound body kept in one statement');

        $keep = self::plan(self::source(), self::target(), ['ignore' => ['definer' => false]]);
        $this->assertStringContainsString('DEFINER=`root`@`%`', $keep['sql'][self::indexOf($keep, 'AS select')]);
    }

    public function testDestructiveWarningsAndExclusion(): void
    {
        $plan = self::plan(self::source(), self::target());
        $codes = array_column($plan['warnings'], 'code');
        $this->assertContains('drop_table', $codes);
        $this->assertContains('drop_column', $codes);
        $this->assertContains('narrowing', $codes);
        $this->assertContains('not_null', $codes, 'NULL count unknown → warned');
        $drop = $plan['statements'][self::indexOf($plan, 'DROP TABLE `obsolete`')];
        $this->assertTrue($drop['destructive']);

        $safe = self::plan(self::source(), self::target(), ['exclude_destructive' => true]);
        $sql = implode("\n", $safe['sql']);
        $this->assertStringNotContainsString('DROP TABLE `obsolete`', $sql);
        $this->assertStringNotContainsString('DROP COLUMN `legacy`', $sql);
        $this->assertStringNotContainsString('`title` VARCHAR(100)', $sql);
        $this->assertContains('table_removed:obsolete:obsolete', $safe['excluded']);
        $this->assertStringContainsString('CREATE TABLE `tags`', $sql);
    }

    public function testSelectionAndNullRows(): void
    {
        $s = self::source();
        $t = self::target();
        $changes = (new SchemaComparer())->compare($s, $t);
        foreach ($changes as &$c) {
            if ($c['id'] === 'column_changed:books:price') {
                $c['null_rows'] = 0;
            }
        }
        unset($c);
        $plan = (new MigrationPlanner(new MySqlDriver(), $s, $t))->plan($changes, ['column_changed:books:price', 'view_removed:v_old']);
        $this->assertSame(['DROP VIEW `v_old`', "ALTER TABLE `books`\n  CHANGE COLUMN `price` `price` DECIMAL(10,2) NOT NULL DEFAULT '0.00'"], $plan['sql']);
        $this->assertNotContains('not_null', array_column($plan['warnings'], 'code'), 'no NULLs: no warning');
        $this->assertSame([], (new MigrationPlanner(new MySqlDriver(), $s, $t))->plan($changes, [])['statements']);
    }

    public function testForeignKeysAroundColumnTypeChanges(): void
    {
        $s = self::snap([
            self::table('parent', [self::col('id', 'bigint')], [self::pk(['id'])]),
            self::table('child', [self::col('id', 'int'), self::col('pid', 'bigint')], [self::pk(['id']), self::ix('fk_child_parent', ['pid'])], [self::fk('fk_child_parent', ['pid'], 'parent', ['id'])]),
        ]);
        $t = self::snap([
            self::table('parent', [self::col('id', 'int')], [self::pk(['id'])]),
            self::table('child', [self::col('id', 'int'), self::col('pid', 'int')], [self::pk(['id']), self::ix('fk_child_parent', ['pid'])], [self::fk('fk_child_parent', ['pid'], 'parent', ['id'])]),
        ]);
        $plan = self::plan($s, $t);
        $this->assertSame(['drop_fk', 'alter_table', 'add_fk'], self::phases($plan));
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_child_parent`', $plan['sql'][0]);
        $this->assertStringContainsString('ADD CONSTRAINT `fk_child_parent`', end($plan['sql']));
        $this->assertContains('fk_recreated', array_column($plan['warnings'], 'code'));
    }

    public function testForeignKeysToDroppedTablesAreDroppedFirst(): void
    {
        $s = self::snap([self::table('child', [self::col('id', 'int'), self::col('pid', 'int')])]);
        $t = self::snap([
            self::table('parent', [self::col('id', 'int')], [self::pk(['id'])]),
            self::table('child', [self::col('id', 'int'), self::col('pid', 'int')], [], [self::fk('fk_cp', ['pid'], 'parent', ['id'])]),
        ]);
        // fk_removed deselected: the FK still has to go before DROP TABLE parent
        $changes = (new SchemaComparer())->compare($s, $t);
        $plan = (new MigrationPlanner(new MySqlDriver(), $s, $t))->plan($changes, ['table_removed:parent:parent']);
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_cp`', $plan['sql'][0]);
        $this->assertSame('DROP TABLE `parent`', $plan['sql'][1]);
    }

    public function testRenameAndMovePositions(): void
    {
        $s = self::snap([self::table('t', [self::col('id', 'int'), self::col('n', 'int'), self::col('c', 'int'), self::col('full', 'varchar', '9'), self::col('b', 'int')])]);
        $t = self::snap([self::table('t', [self::col('id', 'int'), self::col('b', 'int'), self::col('c', 'int'), self::col('name', 'varchar', '9')])]);
        $plan = self::plan($s, $t, ['detect_renames' => true]);
        $sql = $plan['sql'][0];
        $this->assertStringContainsString('CHANGE COLUMN `name` `full` VARCHAR(9) NOT NULL', $sql);
        $this->assertStringContainsString('CHANGE COLUMN `b` `b` INT NOT NULL AFTER `full`', $sql);
        $this->assertStringContainsString('ADD COLUMN `n` INT NOT NULL AFTER `id`', $sql);
        $this->assertLessThan(strpos($sql, 'ADD COLUMN'), strpos($sql, 'CHANGE COLUMN `b`'));

        $noOrder = self::plan($s, $t, ['detect_renames' => true, 'ignore' => ['column_order' => true]]);
        $this->assertStringNotContainsString('AFTER', implode("\n", $noOrder['sql']));
    }

    public function testIgnoredCollationKeepsTargetCollation(): void
    {
        $s = self::snap([self::table('t', [self::col('a', 'varchar', '20', ['collation' => 'utf8mb4_0900_ai_ci'])])]);
        $t = self::snap([self::table('t', [self::col('a', 'varchar', '10', ['collation' => 'utf8mb4_uca1400_ai_ci'])])]);
        $plan = self::plan($s, $t, ['ignore' => ['collation' => true]]);
        $this->assertStringContainsString('VARCHAR(20) COLLATE utf8mb4_uca1400_ai_ci NOT NULL', $plan['sql'][0]);
    }

    public function testPrimaryKeyChange(): void
    {
        $s = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::pk(['a', 'b'])])]);
        $t = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::pk(['a'])])]);
        $plan = self::plan($s, $t);
        $this->assertSame(["ALTER TABLE `t`\n  DROP PRIMARY KEY,\n  ADD PRIMARY KEY (`a`, `b`)"], $plan['sql']);
    }

    public function testPostgresCreateTableIndexesAndForeignKeys(): void
    {
        $s = self::snap([
            self::table('authors', [self::col('id', 'integer')], [self::pk(['id'], 'authors_pkey')], [], ['engine' => null, 'collation' => null]),
            self::table('books', [self::col('id', 'integer'), self::col('author_id', 'integer'), self::col('title', 'text')], [
                self::pk(['id'], 'books_pkey'),
                ['name' => 'ix_lower', 'columns' => ['lower(title)'], 'unique' => false, 'primary' => false, 'type' => 'BTREE', 'ddl' => 'CREATE INDEX ix_lower ON public.books USING btree (lower(title))'],
            ], [['name' => 'books_author_id_fkey', 'columns' => ['author_id'], 'ref_schema' => 'public', 'ref_table' => 'authors', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => 'NO ACTION']],
            ['engine' => null, 'collation' => null, 'comment' => 'Books'], [['name' => 'books_title_check', 'definition' => 'CHECK (length(title) > 0)']]),
        ], [], 'pgsql', 'src');
        $t = self::snap([], [], 'pgsql', 'dst');
        $plan = self::plan($s, $t, [], null, new PgSqlDriver());
        $this->assertSame(['create_table', 'add_index', 'add_check', 'add_fk'], self::phases($plan));
        $sql = implode(";\n", $plan['sql']);
        $this->assertStringContainsString('CREATE INDEX ix_lower ON public.books USING btree (lower(title))', $sql);
        $this->assertStringContainsString('ALTER TABLE "public"."books" ADD CONSTRAINT "books_title_check" CHECK (length(title) > 0)', $sql);
        $this->assertStringContainsString('REFERENCES "public"."authors" ("id") ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('COMMENT ON TABLE "public"."books" IS \'Books\'', $sql);
        $this->assertLessThan(self::indexOf($plan, 'CREATE TABLE "public"."books"'), self::indexOf($plan, 'CREATE TABLE "public"."authors"'));
    }

    public function testPostgresViewsDependingOnChangedColumnsAreRecreated(): void
    {
        $base = static fn (string $type): array => self::table('t', [self::col('id', 'integer'), self::col('v', $type)], [], [], ['engine' => null, 'collation' => null]);
        $view = ['v_t' => ['name' => 'v_t', 'ddl' => "CREATE OR REPLACE VIEW \"public\".\"v_t\" AS\n SELECT id, v FROM t;"],
                 'v_top' => ['name' => 'v_top', 'ddl' => "CREATE OR REPLACE VIEW \"public\".\"v_top\" AS\n SELECT id FROM v_t;"]];
        $s = self::snap([$base('bigint')], ['views' => $view], 'pgsql');
        $t = self::snap([$base('integer')], ['views' => $view], 'pgsql');
        $changes = (new SchemaComparer())->compare($s, $t);
        $this->assertSame(['column_changed:t:v'], array_column($changes, 'id'));
        // only the view phases (the PostgreSQL ALTER COLUMN builder needs a live connection)
        $planner = new MigrationPlanner(new PgSqlDriver(), $s, $t);
        $ref = new \ReflectionMethod($planner, 'dropViews');
        $ref->setAccessible(true);
        $idx = new \ReflectionMethod($planner, 'index');
        $idx->setAccessible(true);
        $idx->invoke($planner, $changes);
        $recreate = $ref->invoke($planner);
        $this->assertSame(['v_t', 'v_top'], array_keys($recreate));
        $out = new \ReflectionProperty($planner, 'out');
        $out->setAccessible(true);
        $this->assertSame(['DROP VIEW "public"."v_top"', 'DROP VIEW "public"."v_t"'], array_column($out->getValue($planner), 'sql'), 'dependents dropped first');
    }
}
