<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit\Diff;

use Ldb\Diff\Normalizer;
use Ldb\Diff\SchemaComparer;
use PHPUnit\Framework\TestCase;

final class SchemaComparerTest extends TestCase
{
    use SnapshotFixtures;

    /** @return array<string,array> */
    private static function byId(array $changes): array
    {
        $out = [];
        foreach ($changes as $c) {
            $out[$c['id']] = $c;
        }
        return $out;
    }

    public function testDetectsAllKindsOfChanges(): void
    {
        $changes = self::byId((new SchemaComparer())->compare(self::source(), self::target()));
        $this->assertEqualsCanonicalizing([
            'table_added:tags:tags',
            'table_removed:obsolete:obsolete',
            'column_changed:authors:name',
            'column_added:authors:bio',
            'column_removed:authors:legacy',
            'index_added:authors:uq_email',
            'column_changed:books:title',
            'column_changed:books:price',
            'index_added:books:ix_title',
            'index_removed:books:ix_old',
            'fk_changed:books:fk_books_author',
            'check_added:books:chk_price',
            'view_changed:v_books',
            'view_removed:v_old',
            'routine_added:PROCEDURE:p_count',
            'routine_removed:FUNCTION:f_old',
            'trigger_added:books.trg',
        ], array_keys($changes));

        $this->assertSame(['type'], $changes['column_changed:authors:name']['fields']);
        $this->assertFalse($changes['column_changed:authors:name']['destructive'], 'varchar(100) → varchar(200) widens');
        $this->assertTrue($changes['column_changed:books:title']['destructive'], 'varchar(200) → varchar(100) narrows');
        $this->assertSame(['narrowing'], $changes['column_changed:books:title']['warnings']);
        $this->assertEqualsCanonicalizing(['nullable', 'default'], $changes['column_changed:books:price']['fields']);
        $this->assertSame(['not_null'], $changes['column_changed:books:price']['warnings']);
        $this->assertTrue($changes['table_removed:obsolete:obsolete']['destructive']);
        $this->assertTrue($changes['column_removed:authors:legacy']['destructive']);
        $this->assertFalse($changes['column_added:authors:bio']['destructive']);
        $this->assertSame('table', $changes['column_added:authors:bio']['object_type']);
        $this->assertSame('authors', $changes['column_added:authors:bio']['table']);
        $this->assertSame(4, $changes['column_added:authors:bio']['after']['position']);
        $this->assertSame('CASCADE', $changes['fk_changed:books:fk_books_author']['after']['on_delete']);
        $this->assertNull($changes['fk_changed:books:fk_books_author']['before']['on_delete']);
    }

    public function testIntegerDisplayWidthDefinerQualifierAndEventStartsAreIgnored(): void
    {
        $ids = array_column((new SchemaComparer())->compare(self::source(), self::target()), 'id');
        $this->assertNotContains('column_changed:authors:id', $ids, 'int(11) = int');
        $this->assertNotContains('event_changed:ev', $ids, 'DEFINER and STARTS differ only');
    }

    public function testDefinerIsComparedWhenNotIgnored(): void
    {
        $ids = array_column((new SchemaComparer())->compare(self::source(), self::target(), ['ignore' => ['definer' => false]]), 'id');
        $this->assertContains('event_changed:ev', $ids);
    }

    public function testSummary(): void
    {
        $sum = SchemaComparer::summary((new SchemaComparer())->compare(self::source(), self::target()));
        $this->assertSame(['added' => 1, 'removed' => 1, 'changed' => 2], $sum['tables']);
        $this->assertSame(['added' => 0, 'removed' => 1, 'changed' => 1], $sum['views']);
        $this->assertSame(['added' => 1, 'removed' => 1, 'changed' => 0], $sum['routines']);
        $this->assertSame(['added' => 1, 'removed' => 0, 'changed' => 0], $sum['triggers']);
        $this->assertSame(17, $sum['total']);
        $this->assertSame(3, $sum['destructive']);
    }

    public function testIncludeOptionsLimitObjectTypes(): void
    {
        $changes = (new SchemaComparer())->compare(self::source(), self::target(), ['include' => ['tables' => false, 'routines' => false]]);
        $types = array_values(array_unique(array_column($changes, 'object_type')));
        sort($types);
        $this->assertSame(['trigger', 'view'], $types);
    }

    public function testIgnoreCommentsCollationAutoIncrement(): void
    {
        $s = self::snap([self::table('t', [
            self::col('a', 'varchar', '10', ['comment' => 'new', 'collation' => 'utf8mb4_bin']),
        ], [], [], ['comment' => 'x', 'auto_increment' => 10, 'collation' => 'utf8mb4_bin'])]);
        $t = self::snap([self::table('t', [
            self::col('a', 'varchar', '10', ['comment' => 'old', 'collation' => 'utf8mb4_general_ci']),
        ], [], [], ['comment' => 'y', 'auto_increment' => 99, 'collation' => 'utf8mb4_general_ci'])]);
        $cmp = new SchemaComparer();

        $all = self::byId($cmp->compare($s, $t, ['ignore' => ['auto_increment' => false]]));
        $this->assertEqualsCanonicalizing(['collation', 'comment'], $all['column_changed:t:a']['fields']);
        $this->assertEqualsCanonicalizing(['collation', 'comment', 'auto_increment'], $all['option_changed:t:t']['fields']);

        $this->assertSame([], $cmp->compare($s, $t, ['ignore' => ['comments' => true, 'collation' => true, 'auto_increment' => true]]));
        $default = self::byId($cmp->compare($s, $t));
        $this->assertNotContains('auto_increment', $default['option_changed:t:t']['fields'], 'auto_increment ignored by default');
    }

    public function testColumnOrder(): void
    {
        $s = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int'), self::col('c', 'int')])]);
        $t = self::snap([self::table('t', [self::col('a', 'int'), self::col('c', 'int'), self::col('b', 'int')])]);
        $changes = (new SchemaComparer())->compare($s, $t);
        $this->assertCount(1, $changes);
        $this->assertSame(['position'], $changes[0]['fields']);
        $this->assertSame([], (new SchemaComparer())->compare($s, $t, ['ignore' => ['column_order' => true]]));

        // PostgreSQL cannot reorder columns: order never counts
        $sp = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')])], [], 'pgsql');
        $tp = self::snap([self::table('t', [self::col('b', 'int'), self::col('a', 'int')])], [], 'pgsql');
        $this->assertSame([], (new SchemaComparer())->compare($sp, $tp));

        // an added column in the middle does not move the others
        $s2 = self::snap([self::table('t', [self::col('a', 'int'), self::col('n', 'int'), self::col('b', 'int')])]);
        $t2 = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')])]);
        $this->assertSame(['column_added:t:n'], array_column((new SchemaComparer())->compare($s2, $t2), 'id'));
    }

    public function testRenameHeuristicIsOffByDefault(): void
    {
        $s = self::snap([self::table('t', [self::col('id', 'int'), self::col('full_name', 'varchar', '50'), self::col('x', 'int')])]);
        $t = self::snap([self::table('t', [self::col('id', 'int'), self::col('name', 'varchar', '50'), self::col('x', 'int')])]);
        $this->assertEqualsCanonicalizing(['column_added:t:full_name', 'column_removed:t:name'], array_column((new SchemaComparer())->compare($s, $t), 'id'));

        $changes = (new SchemaComparer())->compare($s, $t, ['detect_renames' => true]);
        $this->assertSame(['column_renamed:t:full_name'], array_column($changes, 'id'));
        $this->assertSame('name', $changes[0]['before']['name']);
        $this->assertFalse($changes[0]['destructive']);

        // different definition or position: no rename
        $s2 = self::snap([self::table('t', [self::col('id', 'int'), self::col('full_name', 'varchar', '60'), self::col('x', 'int')])]);
        $this->assertCount(2, (new SchemaComparer())->compare($s2, $t, ['detect_renames' => true]));
        $s3 = self::snap([self::table('t', [self::col('full_name', 'varchar', '50'), self::col('id', 'int'), self::col('x', 'int')])]);
        $this->assertNotContains('column_renamed:t:full_name', array_column((new SchemaComparer())->compare($s3, $t, ['detect_renames' => true]), 'id'));
    }

    public function testPrimaryKeyChanges(): void
    {
        $s = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::pk(['a', 'b'])])]);
        $t = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::pk(['a'])])]);
        $u = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')])]);
        $this->assertSame(['index_changed:t:PRIMARY'], array_column((new SchemaComparer())->compare($s, $t), 'id'));
        $this->assertSame(['index_added:t:PRIMARY'], array_column((new SchemaComparer())->compare($s, $u), 'id'));
        $this->assertSame(['index_removed:t:PRIMARY'], array_column((new SchemaComparer())->compare($u, $s), 'id'));
    }

    public function testIndexesMatchedByDefinitionWhenNamesAreGenerated(): void
    {
        // SQLite autoindex vs an explicit unique index on the same column: equal
        $s = self::snap([self::table('t', [self::col('a', 'text')], [['name' => 'sqlite_autoindex_t_1', 'columns' => ['a'], 'unique' => true, 'primary' => false, 'type' => null, 'auto_name' => true]])], [], 'sqlite');
        $t = self::snap([self::table('t', [self::col('a', 'text')], [self::ix('idx_t_a', ['a'], true, null)])], [], 'sqlite');
        $this->assertSame([], (new SchemaComparer())->compare($s, $t));
        // explicit names that differ: reported as a renamed (changed) index
        $s2 = self::snap([self::table('t', [self::col('a', 'int')], [self::ix('ix_new', ['a'])])]);
        $t2 = self::snap([self::table('t', [self::col('a', 'int')], [self::ix('ix_old', ['a'])])]);
        $c = (new SchemaComparer())->compare($s2, $t2);
        $this->assertSame(['index_changed:t:ix_new'], array_column($c, 'id'));
        $this->assertSame(['name'], $c[0]['fields']);
        // same name, different columns / uniqueness
        $s3 = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::ix('ix', ['a', 'b'], true)])]);
        $t3 = self::snap([self::table('t', [self::col('a', 'int'), self::col('b', 'int')], [self::ix('ix', ['a'])])]);
        $this->assertSame(['index_changed:t:ix'], array_column((new SchemaComparer())->compare($s3, $t3), 'id'));
    }

    public function testForeignKeysWithGeneratedNamesAndOwnSchemaQualifier(): void
    {
        $fkS = self::fk('t_ibfk_1', ['p'], 'parent', ['id']);
        $fkS['ref_schema'] = 'app';
        $fkT = self::fk('t_ibfk_3', ['p'], 'parent', ['id'], 'RESTRICT');
        $fkT['ref_schema'] = 'prod';
        $s = self::snap([self::table('t', [self::col('p', 'int')], [], [$fkS])]);
        $t = self::snap([self::table('t', [self::col('p', 'int')], [], [$fkT])], ['database' => 'prod']);
        $this->assertSame([], (new SchemaComparer())->compare($s, $t), 'generated names, own database, RESTRICT = NO ACTION');
    }

    public function testChecks(): void
    {
        $s = self::snap([self::table('t', [self::col('p', 'int')], [], [], [], [['name' => 'c1', 'definition' => 'CHECK ((`p` > 0))'], ['name' => 't_chk_1', 'definition' => 'CHECK (`p` < 9)']])]);
        $t = self::snap([self::table('t', [self::col('p', 'int')], [], [], [], [['name' => 'c1', 'definition' => 'CHECK (`p` > 1)'], ['name' => 't_chk_2', 'definition' => 'CHECK ( `p` < 9 )']])]);
        $this->assertSame(['check_changed:t:c1'], array_column((new SchemaComparer())->compare($s, $t), 'id'));
    }

    public function testDefaultsAndNullability(): void
    {
        $s = self::snap([self::table('t', [
            self::col('a', 'timestamp', null, ['default_kind' => 'expression', 'default' => 'CURRENT_TIMESTAMP']),
            self::col('b', 'int', null, ['nullable' => true, 'default_kind' => 'none']),
        ])]);
        $t = self::snap([self::table('t', [
            self::col('a', 'timestamp', null, ['default_kind' => 'expression', 'default' => 'current_timestamp()']),
            self::col('b', 'int', null, ['nullable' => true, 'default_kind' => 'null']),
        ])]);
        $this->assertSame([], (new SchemaComparer())->compare($s, $t));
    }

    /** @dataProvider narrowingCases */
    public function testNarrowing(string $from, string $to, bool $narrowing, string $driver = 'mysql'): void
    {
        $this->assertSame($narrowing, SchemaComparer::isNarrowing($from, $to, $driver), "$from → $to");
    }

    public function narrowingCases(): array
    {
        return [
            ['int', 'bigint', false],
            ['bigint', 'int', true],
            ['int', 'int unsigned', true],
            ['int unsigned', 'bigint', false],
            ['varchar(10)', 'varchar(20)', false],
            ['varchar(20)', 'varchar(10)', true],
            ['varchar(255)', 'text', false],
            ['text', 'varchar(255)', true],
            ['decimal(10,2)', 'decimal(12,2)', false],
            ['decimal(10,2)', 'decimal(10,1)', true],
            ["enum('a','b')", "enum('a','b','c')", false],
            ["enum('a','b','c')", "enum('a','b')", true],
            ['varchar(20)', 'int', true],
            ['int', 'longtext', false],
            ['date', 'datetime', false],
            ['datetime(6)', 'datetime', true],
            ['character varying(50)', 'text', false, 'pgsql'],
            ['integer', 'smallint', true, 'pgsql'],
            ['varchar(10)', 'varchar(5)', false, 'sqlite'],
        ];
    }

    public function testNormalizer(): void
    {
        $this->assertSame('int unsigned', Normalizer::type(['full_type' => 'INT(10) UNSIGNED'], 'mysql'));
        $this->assertSame('tinyint(1)', Normalizer::type(['full_type' => 'tinyint(1)'], 'mysql'));
        $this->assertSame("enum('A','b')", Normalizer::type(['full_type' => "ENUM('A','b')"], 'mysql'));
        $this->assertSame('numeric(10,2)', Normalizer::type(['type' => 'numeric', 'length' => '10, 2'], 'pgsql'));
        $this->assertSame('e:current_timestamp', Normalizer::defaultValue(['default_kind' => 'expression', 'default' => '(now())', 'nullable' => false]));
        $this->assertSame(
            'CREATE SQL SECURITY DEFINER VIEW `v` AS select `t`.`a` from `t`',
            Normalizer::ddl("CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `app`.`v` AS select `app`.`t`.`a`\n from `app`.`t`;", 'mysql', 'app', null, true)
        );
        $this->assertSame('CREATE VIEW v AS SELECT x FROM t', Normalizer::ddl('CREATE VIEW "public".v AS SELECT x FROM public.t', 'pgsql', null, 'public', true));
        $this->assertSame('SELECT * FROM `prod`.`t`', Normalizer::retarget('SELECT * FROM `app`.`t`', 'mysql', 'app', null, 'prod', null));
        $this->assertSame('CREATE TRIGGER `prod`.`x`', Normalizer::retarget('CREATE TRIGGER app.`x`', 'mysql', 'app', null, 'prod', null));
        $this->assertSame('ON "s2".t', Normalizer::retarget('ON s1.t', 'pgsql', null, 's1', null, 's2'));
        $this->assertTrue(Normalizer::isAutoName('books_author_id_fkey', 'books', 'pgsql', 'fk'));
        $this->assertTrue(Normalizer::isAutoName('books_ibfk_2', 'books', 'mysql', 'fk'));
        $this->assertFalse(Normalizer::isAutoName('fk_books_author', 'books', 'mysql', 'fk'));
    }
}
