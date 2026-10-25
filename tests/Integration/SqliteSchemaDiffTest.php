<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\SqliteDriver;
use PHPUnit\Framework\TestCase;

/** Schema diff round trip on two temporary SQLite files (always runs when pdo_sqlite exists). */
final class SqliteSchemaDiffTest extends TestCase
{
    use SchemaDiffAssertions;

    private ?SqliteDriver $src = null;
    private ?SqliteDriver $tgt = null;
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->src = $this->open([
            'CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT, bio TEXT, UNIQUE (email))',
            'CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL REFERENCES authors(id) ON DELETE CASCADE, title VARCHAR(300) NOT NULL, price REAL NOT NULL DEFAULT 0, published DATE)',
            'CREATE INDEX ix_title ON books (title)',
            'CREATE INDEX ix_lower_title ON books (lower(title))',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT NOT NULL, book_id INTEGER REFERENCES books(id))',
            'CREATE VIEW v_books AS SELECT b.id, b.title, a.name FROM books b JOIN authors a ON a.id = b.author_id',
            'CREATE TRIGGER trg_books_ai AFTER INSERT ON books BEGIN UPDATE books SET title = trim(title) WHERE id = NEW.id; END',
        ]);
        $this->tgt = $this->open([
            'CREATE TABLE authors (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL, email TEXT, legacy INTEGER)',
            'CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL REFERENCES authors(id), title VARCHAR(200) NOT NULL, price REAL)',
            'CREATE INDEX ix_old ON books (price)',
            'CREATE TABLE obsolete (id INTEGER PRIMARY KEY)',
            'CREATE VIEW v_books AS SELECT b.id, b.title FROM books b',
            'CREATE VIEW v_old AS SELECT 1 AS x',
            "INSERT INTO authors (id, name, email) VALUES (1, 'Ann', 'ann@example.com'), (2, 'Bob', NULL)",
            "INSERT INTO books (id, author_id, title, price) VALUES (1, 1, 'A', 1.5), (2, 2, 'B', 2)",
        ]);
    }

    protected function tearDown(): void
    {
        $this->src = null;
        $this->tgt = null;
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function open(array $sqls): SqliteDriver
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ldb_diff_');
        $this->files[] = $file;
        $d = new SqliteDriver();
        $d->connect(['driver' => 'sqlite', 'file' => $file, 'options' => []]);
        foreach ($sqls as $sql) {
            $d->query($sql, 1);
        }
        return $d;
    }

    public function testRoundTripAutoCommit(): void
    {
        $r = $this->roundTrip($this->src, $this->tgt, [
            'table_added:tags:tags',
            'table_removed:obsolete:obsolete',
            'column_added:authors:bio',
            'column_removed:authors:legacy',
            'column_changed:authors:name',
            'column_changed:books:price',
            'index_added:books:ix_title',
            'index_removed:books:ix_old',
            'view_changed:v_books',
            'view_removed:v_old',
            'trigger_added:books.trg_books_ai',
        ], 'none');
        $this->assertSame(2, (int) $this->tgt->pdo()->query('SELECT COUNT(*) FROM books')->fetchColumn(), 'rows survive the rebuild');
        $codes = array_column($r['plan']['warnings'], 'code');
        $this->assertContains('sqlite_rebuild', $codes);
        $this->assertContains('drop_table', $codes);
    }

    public function testRoundTripAtomicWithExcludedChange(): void
    {
        $this->roundTrip($this->src, $this->tgt, ['view_removed:v_old'], 'atomic', ['view_removed:v_old']);
        $this->assertSame('1', (string) $this->tgt->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testRenameDetection(): void
    {
        $src = $this->open(['CREATE TABLE t (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, n INTEGER)']);
        $tgt = $this->open(['CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL, n INTEGER)', "INSERT INTO t VALUES (1, 'x', 2)"]);
        $plain = $this->diff($src, $tgt);
        $this->assertSame(['column_added:t:full_name', 'column_removed:t:name'], array_column($plain, 'id'));
        $this->roundTrip($src, $tgt, ['column_renamed:t:full_name'], 'atomic', [], ['detect_renames' => true]);
        $this->assertSame('x', (string) $tgt->pdo()->query('SELECT full_name FROM t')->fetchColumn(), 'renamed, not dropped');
    }
}
