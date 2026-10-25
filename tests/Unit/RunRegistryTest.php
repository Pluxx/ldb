<?php

declare(strict_types=1);

namespace Ldb\Tests\Unit;

use Ldb\Sql\RunRegistry;
use PHPUnit\Framework\TestCase;

final class RunRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oph_runs_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir) && !is_link($this->dir)) {
            foreach ((array) glob($this->dir . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->dir);
        } elseif (is_link($this->dir)) {
            @unlink($this->dir);
        }
    }

    private function entry(): array
    {
        return ['driver' => 'mysql', 'backend' => '42', 'conn' => 'c1'];
    }

    public function testRegisterLookupRemove(): void
    {
        $r = new RunRegistry($this->dir, 86400, 1);
        $this->assertNull($r->lookup('sess', 'run_abcdef'));
        $this->assertTrue($r->register('sess', 'run_abcdef', $this->entry()));
        $e = $r->lookup('sess', 'run_abcdef');
        $this->assertNotNull($e);
        $this->assertSame('42', $e['backend']);
        $this->assertSame('c1', $e['conn']);
        $this->assertSame('mysql', $e['driver']);
        $this->assertFalse($e['cancelled']);
        $r->remove('sess', 'run_abcdef');
        $this->assertNull($r->lookup('sess', 'run_abcdef'));
    }

    public function testDirectoryIsPrivateAndFilesHideSessionId(): void
    {
        $r = new RunRegistry($this->dir);
        $r->register('secret-session-id', 'run_abcdef', $this->entry());
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0700, fileperms($this->dir) & 0777);
        }
        $files = (array) glob($this->dir . '/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.json$/', basename((string) $files[0]));
        $this->assertStringNotContainsString('secret-session-id', (string) file_get_contents((string) $files[0]));
    }

    public function testEntriesAreBoundToTheSession(): void
    {
        $r = new RunRegistry($this->dir);
        $r->register('alice', 'run_abcdef', $this->entry());
        $this->assertNull($r->lookup('bob', 'run_abcdef'));
        $this->assertFalse($r->markCancelled('bob', 'run_abcdef'));
        $this->assertFalse($r->isCancelled('alice', 'run_abcdef'));
    }

    public function testMarkCancelled(): void
    {
        $r = new RunRegistry($this->dir);
        $r->register('s', 'run_abcdef', $this->entry());
        $this->assertTrue($r->markCancelled('s', 'run_abcdef'));
        $this->assertTrue($r->isCancelled('s', 'run_abcdef'));
        $this->assertSame('42', $r->lookup('s', 'run_abcdef')['backend']);
        // a finished run is never re-created by a late cancel
        $r->remove('s', 'run_abcdef');
        $this->assertFalse($r->markCancelled('s', 'run_abcdef'));
        $this->assertSame([], (array) glob($this->dir . '/*'));
    }

    public function testInvalidRunIdsAreRejected(): void
    {
        $r = new RunRegistry($this->dir);
        foreach (['short', str_repeat('a', 65), '../../etc/x', 'run id with spaces', "run\0abcdef"] as $bad) {
            $this->assertFalse(RunRegistry::isValidRunId($bad), $bad);
            $this->assertFalse($r->register('s', $bad, $this->entry()));
            $this->assertNull($r->lookup('s', $bad));
        }
        $this->assertTrue(RunRegistry::isValidRunId('Ab3_-xyz'));
        $this->assertTrue(RunRegistry::isValidRunId(str_repeat('z', 64)));
    }

    public function testStaleEntriesAreIgnoredAndCollected(): void
    {
        $r = new RunRegistry($this->dir, 60, 1000000);
        $r->register('s', 'run_old_one', $this->entry());
        $r->register('s', 'run_new_one', $this->entry());
        $files = (array) glob($this->dir . '/*.json');
        $this->assertCount(2, $files);
        // age one entry: content timestamp and mtime
        $old = $this->dir . '/' . hash('sha256', 's|run_old_one') . '.json';
        $data = json_decode((string) file_get_contents($old), true);
        $data['started'] = microtime(true) - 3600;
        file_put_contents($old, json_encode($data));
        touch($old, time() - 3600);
        $this->assertNull($r->lookup('s', 'run_old_one'));
        $this->assertSame(1, $r->gc());
        $this->assertFileDoesNotExist($old);
        $this->assertNotNull($r->lookup('s', 'run_new_one'));
    }

    public function testUnsafeDirectoryFailsOpen(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions only');
        }
        mkdir($this->dir, 0777);
        chmod($this->dir, 0777);
        $r = new RunRegistry($this->dir);
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse($r->register('s', 'run_abcdef', $this->entry()));
            $this->assertNull($r->lookup('s', 'run_abcdef'));
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertSame([], (array) glob($this->dir . '/*'));
    }

    public function testSymlinkedDirectoryIsRefused(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX only');
        }
        $target = $this->dir . '_target';
        mkdir($target, 0700);
        symlink($target, $this->dir);
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse((new RunRegistry($this->dir))->register('s', 'run_abcdef', $this->entry()));
        } finally {
            ini_set('error_log', (string) $log);
            @rmdir($target);
        }
    }
}
