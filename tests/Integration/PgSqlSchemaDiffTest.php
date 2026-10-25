<?php

declare(strict_types=1);

namespace Ldb\Tests\Integration;

use Ldb\Db\PgSqlDriver;
use PHPUnit\Framework\TestCase;

/** Schema diff round trip on two throwaway PostgreSQL databases; env LDB_TEST_PGSQL_DSN/USER/PASS. */
final class PgSqlSchemaDiffTest extends TestCase
{
    use SchemaDiffAssertions;

    private ?PgSqlDriver $admin = null;
    private ?PgSqlDriver $src = null;
    private ?PgSqlDriver $tgt = null;
    private string $srcDb = '';
    private string $tgtDb = '';

    protected function setUp(): void
    {
        $dsn = getenv('LDB_TEST_PGSQL_DSN');
        if (!$dsn || !extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('Set LDB_TEST_PGSQL_DSN/USER/PASS to run these integration tests');
        }
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^pgsql:/', '', (string) $dsn)) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[strtolower(trim($k))] = trim($v);
            }
        }
        $params = [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => (string) getenv('LDB_TEST_PGSQL_USER'),
            'password' => (string) getenv('LDB_TEST_PGSQL_PASS'),
            'database' => $parts['dbname'] ?? 'postgres',
            'options' => [],
        ];
        $suffix = bin2hex(random_bytes(3));
        $this->srcDb = 'ldb_diff_src_' . $suffix;
        $this->tgtDb = 'ldb_diff_tgt_' . $suffix;
        $this->admin = new PgSqlDriver();
        $this->admin->connect($params);
        $this->admin->createDatabase($this->srcDb);
        $this->admin->createDatabase($this->tgtDb);
        $this->src = new PgSqlDriver();
        $this->src->connect($params);
        $this->src->select($this->srcDb, 'public');
        $this->tgt = new PgSqlDriver();
        $this->tgt->connect($params);
        $this->tgt->select($this->tgtDb, 'public');

        foreach ([
            'CREATE TABLE authors (id serial PRIMARY KEY, name varchar(200) NOT NULL, email varchar(255), bio text, CONSTRAINT uq_email UNIQUE (email))',
            'CREATE TABLE books (id serial PRIMARY KEY, author_id integer NOT NULL REFERENCES authors(id) ON DELETE CASCADE, title varchar(300) NOT NULL, '
                . 'price numeric(10,2) NOT NULL DEFAULT 0, published date, CONSTRAINT chk_price CHECK (price >= 0))',
            'CREATE INDEX ix_title ON books (lower(title))',
            "COMMENT ON TABLE books IS 'All books'",
            'CREATE TABLE tags (id integer PRIMARY KEY, label varchar(50) NOT NULL, book_id integer REFERENCES books(id))',
            'CREATE VIEW v_books AS SELECT b.id, b.title, a.name FROM books b JOIN authors a ON a.id = b.author_id',
            'CREATE VIEW v_prices AS SELECT id, price FROM books',
            'CREATE FUNCTION f_count() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM books $$',
            'CREATE FUNCTION trg_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.title := trim(NEW.title); RETURN NEW; END $$',
            'CREATE TRIGGER trg_books_bi BEFORE INSERT ON books FOR EACH ROW EXECUTE FUNCTION trg_fn()',
        ] as $sql) {
            $this->src->pdo()->exec($sql);
        }
        foreach ([
            'CREATE TABLE authors (id serial PRIMARY KEY, name varchar(100) NOT NULL, email varchar(255), legacy integer)',
            'CREATE TABLE books (id serial PRIMARY KEY, author_id integer NOT NULL REFERENCES authors(id), title varchar(200) NOT NULL, price numeric(8,2), '
                . 'CONSTRAINT books_old_check CHECK (price > -1))',
            'CREATE INDEX ix_old ON books (price)',
            'CREATE TABLE obsolete (id integer PRIMARY KEY)',
            'CREATE VIEW v_books AS SELECT b.id, b.title FROM books b',
            // unchanged view on a column whose type changes: must be dropped and recreated
            'CREATE VIEW v_prices AS SELECT id, price FROM books',
            'CREATE VIEW v_old AS SELECT 1 AS x',
            'CREATE FUNCTION f_old() RETURNS integer LANGUAGE sql AS $$ SELECT 1 $$',
            "INSERT INTO authors (id, name, email) VALUES (1, 'Ann', 'ann@example.com'), (2, 'Bob', NULL)",
            "INSERT INTO books (id, author_id, title, price) VALUES (1, 1, 'A', 1.5), (2, 2, 'B', 2)",
        ] as $sql) {
            $this->tgt->pdo()->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->src = null;
        $this->tgt = null;
        if ($this->admin !== null) {
            foreach ([$this->srcDb, $this->tgtDb] as $db) {
                try {
                    $this->admin->pdo()->exec('DROP DATABASE IF EXISTS "' . $db . '" WITH (FORCE)');
                } catch (\Throwable $e) {
                    try {
                        $this->admin->dropDatabase($db);
                    } catch (\Throwable $e2) {
                    }
                }
            }
        }
        $this->admin = null;
    }

    /** @return string[] */
    private static function expected(): array
    {
        return [
            'table_added:tags:tags',
            'table_removed:obsolete:obsolete',
            'column_added:authors:bio',
            'column_removed:authors:legacy',
            'column_changed:authors:name',
            'column_changed:books:price',
            'index_added:authors:uq_email',
            'index_added:books:ix_title',
            'index_removed:books:ix_old',
            'check_added:books:chk_price',
            'option_changed:books:books',
            'view_changed:v_books',
            'view_removed:v_old',
            'routine_added:FUNCTION:f_count()',
            'routine_removed:FUNCTION:f_old()',
            'trigger_added:books.trg_books_bi',
        ];
    }

    public function testRoundTripAtomic(): void
    {
        $r = $this->roundTrip($this->src, $this->tgt, self::expected(), 'atomic');
        $codes = array_column($r['plan']['warnings'], 'code');
        $this->assertContains('view_recreated', $codes, 'v_prices depends on books.price');
        $this->assertSame(2, (int) $this->tgt->pdo()->query('SELECT count(*) FROM books')->fetchColumn());
        $this->assertSame(2, (int) $this->tgt->pdo()->query('SELECT f_count()')->fetchColumn());
    }

    public function testAtomicFailureRollsBackEverything(): void
    {
        // a NULL price makes SET NOT NULL fail: nothing must be left half-applied
        $this->tgt->pdo()->exec("INSERT INTO books (id, author_id, title, price) VALUES (3, 1, 'C', NULL)");
        $s = (new \Ldb\Diff\SchemaReader($this->src))->read();
        $t = (new \Ldb\Diff\SchemaReader($this->tgt))->read();
        $changes = (new \Ldb\Diff\SchemaComparer())->compare($s, $t);
        $plan = (new \Ldb\Diff\MigrationPlanner($this->tgt, $s, $t))->plan($changes);
        $res = \Ldb\Diff\PlanExecutor::run($this->tgt, $plan['statements'], 'atomic');
        $this->assertTrue($res['failed']);
        $this->assertTrue($res['rolled_back']);
        $this->assertCount(count($changes), $this->diff($this->src, $this->tgt), 'target unchanged');
    }

    public function testRoundTripAutoCommitWithExclusion(): void
    {
        $this->roundTrip($this->src, $this->tgt, self::expected(), 'none', ['routine_removed:FUNCTION:f_old()', 'index_removed:books:ix_old']);
    }
}
