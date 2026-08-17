<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\FileSessionStore;
use PHPUnit\Framework\TestCase;

final class FileSessionStoreTest extends TestCase
{
    private string $directory;

    private FileSessionStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/kinetis-session-test-' . \bin2hex(\random_bytes(6));
        $this->store = new FileSessionStore($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);
    }

    private static function id(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    public function test_round_trip(): void
    {
        $id = self::id();
        $this->store->write($id, ['user' => 42, 'nested' => ['a' => true]], 60);

        self::assertSame(['user' => 42, 'nested' => ['a' => true]], $this->store->read($id));
    }

    public function test_unknown_id_reads_null(): void
    {
        self::assertNull($this->store->read(self::id()));
    }

    public function test_an_expired_session_reads_null_and_its_file_is_removed(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], -1);

        self::assertNull($this->store->read($id));
        self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
    }

    public function test_destroy_removes_the_file(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], 60);
        $this->store->destroy($id);

        self::assertNull($this->store->read($id));
    }

    public function test_gc_sweeps_expired_files_keeps_live_ones_and_reports_the_count(): void
    {
        $live = self::id();
        $dead = self::id();
        $this->store->write($live, ['keep' => true], 60);
        $this->store->write($dead, ['gone' => true], -1);

        self::assertSame(1, $this->store->gc());
        self::assertSame(['keep' => true], $this->store->read($live));
        self::assertCount(1, \glob($this->directory . '/sess_*') ?: []);
    }

    public function test_a_malformed_id_never_becomes_a_path(): void
    {
        $this->expectException(SessionException::class);

        $this->store->read('../../etc/passwd');
    }

    public function test_a_corrupt_file_reads_null(): void
    {
        $id = self::id();
        \file_put_contents($this->directory . '/sess_' . $id, 'not json at all');

        self::assertNull($this->store->read($id));
    }
}
