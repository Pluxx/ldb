<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\MySqlDriver;
use Ldb\Http\ApiException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Database settings (db.info / db.alter / db.copy) against MySQL-family servers. Env prefix per
 * subclass (<PREFIX>_DSN / _USER / _PASS, see MySqlDriverTestCase). Every test works on its own
 * throwaway databases "ldb_ds_<random>[_copy|_moved]" and drops them again.
 */
abstract class MySqlDatabaseSettingsTestCase extends TestCase
{
    private ?MySqlDriver $d = null;
    private string $db = '';
    /** @var string[] */
    private array $cleanup = [];

    abstract protected static function envPrefix(): string;

    protected function setUp(): void
    {
        $dsn = getenv(static::envPrefix() . '_DSN');
        if (!$dsn || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Set ' . static::envPrefix() . '_DSN/USER/PASS to run these integration tests');
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', (string) $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $this->d = new MySqlDriver();
        $this->d->connect([
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => (string) getenv(static::envPrefix() . '_USER'),
            'password' => (string) getenv(static::envPrefix() . '_PASS'),
            'database' => null,
            'options' => [],
        ]);
        $this->db = 'ldb_ds_' . bin2hex(random_bytes(4));
        $this->cleanup = [$this->db, $this->db . '_copy', $this->db . '_moved'];
        $this->d->createDatabase($this->db, 'utf8mb4_unicode_ci');
        $q = '`' . $this->db . '`';
        $pdo = $this->d->pdo();
        $pdo->exec("CREATE TABLE $q.authors (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50) NOT NULL, code VARCHAR(10) COLLATE utf8mb4_bin) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE $q.books (id INT PRIMARY KEY AUTO_INCREMENT, author_id INT, title VARCHAR(100), title_len INT AS (CHAR_LENGTH(title)) VIRTUAL,
            CONSTRAINT fk_b_a FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE CASCADE) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE $q.legacy (id INT PRIMARY KEY, note VARCHAR(20)) CHARACTER SET latin1 COLLATE latin1_swedish_ci");
        $pdo->exec("INSERT INTO $q.authors (name, code) VALUES ('Ann', 'A'), ('Bob', 'B')");
        $pdo->exec("INSERT INTO $q.books (author_id, title) VALUES (1, 'Ännchen'), (1, 'Zwei'), (2, '日本語')");
        $pdo->exec("INSERT INTO $q.legacy VALUES (1, 'x')");
        $pdo->exec("CREATE VIEW $q.v_books AS SELECT b.title, a.name FROM $q.books b JOIN $q.authors a ON a.id = b.author_id");
        $pdo->exec("CREATE VIEW $q.v_count AS SELECT COUNT(*) AS n FROM $q.v_books");
        $pdo->exec("CREATE FUNCTION $q.twice(x INT) RETURNS INT DETERMINISTIC RETURN x * 2");
        $pdo->exec("CREATE TRIGGER $q.trg_books BEFORE INSERT ON $q.books FOR EACH ROW SET NEW.title = TRIM(NEW.title)");
    }

    protected function tearDown(): void
    {
        if ($this->d !== null) {
            foreach ($this->cleanup as $db) {
                $this->d->pdo()->exec('DROP DATABASE IF EXISTS `' . $db . '`');
            }
            $this->d = null;
        }
    }

    private function value(string $sql, array $params = [])
    {
        $st = $this->d->pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        $st->closeCursor();
        return $v;
    }

    public function testInfoReportsDefaultsAndDifferingTables(): void
    {
        $info = $this->d->databaseInfo($this->db);
        $this->assertSame('utf8mb4', $info['settings']['charset']);
        $this->assertSame('utf8mb4_unicode_ci', $info['settings']['collation']);
        $this->assertSame(3, $info['table_count']);
        $this->assertSame(2, $info['view_count']);
        $byName = array_column($info['tables'], null, 'name');
        $this->assertTrue($byName['legacy']['differs']);
        $this->assertTrue($byName['authors']['differs'], 'utf8mb4_bin column differs from the default');
        $this->assertSame(1, $byName['authors']['columns_differing']);
        $this->assertFalse($byName['books']['differs']);
        $this->assertSame('view', $byName['v_books']['type']);
        $this->assertSame(1, $info['objects']['routines']);
        $this->assertSame(1, $info['objects']['triggers']);
        $this->expectException(ApiException::class);
        $this->d->databaseInfo($this->db . '_missing');
    }

    public function testChangeDefaultCollationAndConvertTables(): void
    {
        $changes = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'convert_tables' => true];
        $plan = $this->d->buildAlterDatabase($this->db, $changes);
        $this->assertCount(4, $plan['sql'], 'ALTER DATABASE + 3 base tables (views skipped)');
        $this->assertSame(['convert_rewrites_tables'], $plan['warnings']);
        $this->assertSame('utf8mb4_unicode_ci', $this->value('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->db]), 'plan does not execute');

        $this->d->alterDatabase($this->db, $changes);
        $this->assertSame('utf8mb4_general_ci', $this->value('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->db]));
        $this->assertSame('0', (string) $this->value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_COLLATION <> 'utf8mb4_general_ci'",
            [$this->db]
        ));
        $this->assertSame('0', (string) $this->value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_general_ci'",
            [$this->db]
        ));
        $this->assertSame('x', $this->value('SELECT note FROM `' . $this->db . '`.legacy WHERE id = 1'));
        $info = $this->d->databaseInfo($this->db);
        $this->assertSame(0, $info['differing_tables']);
    }

    public function testConvertOnlySelectedTables(): void
    {
        $this->d->alterDatabase($this->db, ['convert_tables' => true, 'tables' => ['legacy']]);
        $this->assertSame('utf8mb4_unicode_ci', $this->value('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->db, 'legacy']));
        $this->assertSame('utf8mb4_bin', $this->value('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->db, 'authors', 'code']), 'not selected → untouched');
        $this->expectException(ApiException::class);
        $this->d->buildAlterDatabase($this->db, ['convert_tables' => true, 'tables' => ['v_books']]);
    }

    public function testCopyDatabaseWithDataAndObjects(): void
    {
        $target = $this->db . '_copy';
        $preview = $this->d->copyDatabase($this->db, $target, ['preview' => true]);
        $this->assertFalse($preview['executed']);
        $this->assertStringStartsWith('CREATE DATABASE `' . $target . '`', $preview['sql'][0]);
        $this->assertFalse($this->value('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]), 'preview creates nothing');

        $res = $this->d->copyDatabase($this->db, $target);
        $this->assertTrue($res['executed']);
        $this->assertSame([], $res['skipped']);
        $this->assertSame(['tables' => 3, 'views' => 2, 'routines' => 1, 'triggers' => 1, 'events' => 0], $res['copied']);
        $this->assertFalse($res['dropped']);
        $qt = '`' . $target . '`';
        $this->assertSame('utf8mb4_unicode_ci', $this->value('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]));
        $this->assertSame('3', (string) $this->value("SELECT COUNT(*) FROM $qt.books"));
        $this->assertSame('日本語', $this->value("SELECT title FROM $qt.books WHERE author_id = 2"));
        $this->assertSame('7', (string) $this->value("SELECT title_len FROM $qt.books WHERE title = 'Ännchen'"), 'generated column recomputed');
        $this->assertSame('fk_b_a', $this->value('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$target]));
        $this->assertSame('3', (string) $this->value("SELECT n FROM $qt.v_count"));
        $def = (string) $this->value('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$target, 'v_books']);
        $this->assertStringNotContainsString('`' . $this->db . '`', $def, 'view points at the copy');
        $this->assertSame('8', (string) $this->value("SELECT $qt.twice(4)"));
        $this->d->pdo()->exec("INSERT INTO $qt.books (author_id, title) VALUES (2, '  pad  ')");
        $this->assertSame('pad', $this->value("SELECT title FROM $qt.books WHERE author_id = 2 ORDER BY id DESC LIMIT 1"), 'trigger copied');
        $this->assertSame('3', (string) $this->value('SELECT COUNT(*) FROM `' . $this->db . '`.books'), 'source untouched');

        $this->expectException(ApiException::class);
        $this->d->copyDatabase($this->db, $target);
    }

    public function testMoveDatabaseDropsSource(): void
    {
        $target = $this->db . '_moved';
        $this->d->select($this->db);
        $res = $this->d->copyDatabase($this->db, $target, ['drop_source' => true]);
        $this->assertTrue($res['dropped']);
        $this->assertFalse($this->value('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->db]));
        $this->assertSame('2', (string) $this->value('SELECT COUNT(*) FROM `' . $target . '`.authors'));
        $this->assertSame(2, (int) $this->value('SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?', [$target]));
    }
}
