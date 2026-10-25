<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\MySqlDriver;
use PHPUnit\Framework\TestCase;

/**
 * Schema diff round trip on two throwaway MySQL-family databases. Subclasses name the env prefix
 * (LDB_TEST_MYSQL_* / LDB_TEST_MARIADB_*, see MySqlDriverTestCase).
 */
abstract class MySqlSchemaDiffTestCase extends TestCase
{
    use SchemaDiffAssertions;

    private ?MySqlDriver $src = null;
    private ?MySqlDriver $tgt = null;
    private string $srcDb = '';
    private string $tgtDb = '';

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
        $params = [
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => (string) getenv(static::envPrefix() . '_USER'),
            'password' => (string) getenv(static::envPrefix() . '_PASS'),
            'database' => null,
            'options' => [],
        ];
        $suffix = bin2hex(random_bytes(3));
        $this->srcDb = 'ldb_diff_src_' . $suffix;
        $this->tgtDb = 'ldb_diff_tgt_' . $suffix;
        $this->src = new MySqlDriver();
        $this->src->connect($params);
        $this->src->createDatabase($this->srcDb, 'utf8mb4_unicode_ci');
        $this->src->createDatabase($this->tgtDb, 'utf8mb4_unicode_ci');
        $this->src->select($this->srcDb);
        $this->tgt = new MySqlDriver();
        $this->tgt->connect($params);
        $this->tgt->select($this->tgtDb);

        foreach ([
            'CREATE TABLE authors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, email VARCHAR(255) NULL, bio TEXT NULL, UNIQUE KEY uq_email (email)) ENGINE=InnoDB',
            'CREATE TABLE books (id INT AUTO_INCREMENT PRIMARY KEY, author_id INT NOT NULL, title VARCHAR(300) NOT NULL, price DECIMAL(10,2) NOT NULL DEFAULT 0, published DATE NULL, '
                . 'INDEX ix_title (title), CONSTRAINT fk_books_author FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE CASCADE, CONSTRAINT chk_price CHECK (price >= 0)) ENGINE=InnoDB',
            'CREATE TABLE tags (id INT NOT NULL PRIMARY KEY, label VARCHAR(50) NOT NULL, book_id INT NULL, CONSTRAINT fk_tags_book FOREIGN KEY (book_id) REFERENCES books (id)) ENGINE=InnoDB',
            'CREATE VIEW v_books AS SELECT b.id, b.title, a.name FROM books b JOIN authors a ON a.id = b.author_id',
            'CREATE VIEW v_titles AS SELECT title FROM v_books',
            'CREATE PROCEDURE p_count() BEGIN SELECT COUNT(*) FROM books; END',
            'CREATE TRIGGER trg_books_bi BEFORE INSERT ON books FOR EACH ROW SET NEW.title = TRIM(NEW.title)',
        ] as $sql) {
            $this->src->query($sql, 1);
        }
        foreach ([
            'CREATE TABLE authors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(255) NULL, legacy INT NULL) ENGINE=InnoDB',
            'CREATE TABLE books (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, author_id INT NOT NULL, price DECIMAL(8,2) NULL, '
                . 'INDEX ix_old (price), CONSTRAINT fk_books_author FOREIGN KEY (author_id) REFERENCES authors (id)) ENGINE=InnoDB',
            'CREATE TABLE obsolete (id INT PRIMARY KEY) ENGINE=InnoDB',
            'CREATE VIEW v_books AS SELECT b.id, b.title FROM books b',
            'CREATE VIEW v_old AS SELECT 1 AS x',
            'CREATE FUNCTION f_old() RETURNS INT DETERMINISTIC RETURN 1',
            "INSERT INTO authors (id, name, email) VALUES (1, 'Ann', 'ann@example.com'), (2, 'Bob', NULL)",
            "INSERT INTO books (id, author_id, title, price) VALUES (1, 1, 'A', 1.50), (2, 2, 'B', 2)",
        ] as $sql) {
            $this->tgt->query($sql, 1);
        }
    }

    protected function tearDown(): void
    {
        if ($this->src !== null) {
            $this->src->select(null);
            foreach ([$this->srcDb, $this->tgtDb] as $db) {
                try {
                    $this->src->dropDatabase($db);
                } catch (\Throwable $e) {
                }
            }
        }
        $this->src = null;
        $this->tgt = null;
    }

    public function testRoundTrip(): void
    {
        $r = $this->roundTrip($this->src, $this->tgt, [
            'table_added:tags:tags',
            'table_removed:obsolete:obsolete',
            'column_added:authors:bio',
            'column_removed:authors:legacy',
            'column_changed:authors:name',
            'column_changed:books:price',
            'column_changed:books:title',
            'index_added:authors:uq_email',
            'index_added:books:ix_title',
            'index_removed:books:ix_old',
            'fk_changed:books:fk_books_author',
            'check_added:books:chk_price',
            'view_changed:v_books',
            'view_added:v_titles',
            'view_removed:v_old',
            'routine_added:PROCEDURE:p_count',
            'routine_removed:FUNCTION:f_old',
            'trigger_added:books.trg_books_bi',
        ], 'none');
        $byId = [];
        foreach ($r['changes'] as $c) {
            $byId[$c['id']] = $c;
        }
        $moved = array_merge($byId['column_changed:books:title']['fields'], $byId['column_changed:books:author_id']['fields'] ?? []);
        $this->assertContains('position', $moved, 'title/author_id swapped');
        $this->assertContains('not_null', $byId['column_changed:books:price']['warnings']);
        $this->assertTrue($byId['table_removed:obsolete:obsolete']['destructive']);
        $this->assertSame(2, (int) $this->tgt->pdo()->query('SELECT COUNT(*) FROM books')->fetchColumn());
        // the procedure works in the target database
        $res = $this->tgt->query('CALL p_count()', 10);
        $this->assertSame(2, (int) $res['rows'][0][0]);
    }

    public function testExcludedChangeRemainsAndAtomicMode(): void
    {
        $r = $this->roundTrip($this->src, $this->tgt, ['column_removed:authors:legacy'], 'atomic', ['column_removed:authors:legacy', 'table_removed:obsolete:obsolete']);
        $this->assertNotEmpty($r['plan']['excluded']);
    }

    public function testIgnoreOptions(): void
    {
        $this->src->query("ALTER TABLE authors COMMENT = 'people'", 1);
        $this->src->query("ALTER TABLE authors MODIFY email VARCHAR(255) NULL COMMENT 'mail'", 1);
        $all = array_column($this->diff($this->src, $this->tgt), 'id');
        $this->assertContains('option_changed:authors:authors', $all);
        $quiet = array_column($this->diff($this->src, $this->tgt, ['ignore' => ['comments' => true]]), 'id');
        $this->assertNotContains('option_changed:authors:authors', $quiet);
        $this->assertNotContains('column_changed:authors:email', $quiet);
    }
}
