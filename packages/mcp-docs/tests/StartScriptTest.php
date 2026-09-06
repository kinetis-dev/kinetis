<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * start.sh is what setup.sh registers with the MCP client, so one copy
 * of it runs per client session, all against a single install
 * directory. These tests pin the ordering that makes that safe: one
 * update at a time, no server started out of a vendor tree being
 * replaced, and neither a failed nor a killed update leaving a lock
 * behind or moving the timestamp.
 *
 * The script is driven with stand-ins for `composer` and `php` on its
 * PATH, so nothing here reaches the network, Composer or the real
 * server. Each stand-in appends one line to a file, and the order of
 * those lines is what the assertions read.
 */
final class StartScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../start.sh';

    private const string SERVER_LINE = 'server vendor/bin/kinetis-mcp-docs';

    /** A lock the kernel failed to drop would otherwise hang the suite outright. */
    private const int TIMEOUT_SECONDS = 15;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis-mcp-docs-start-' . bin2hex(random_bytes(8));

        mkdir($this->directory . '/stubs', 0o755, true);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->directory);
    }

    public function test_a_spawn_arriving_during_an_update_waits_for_it_and_does_not_update_again(): void
    {
        $this->stub('composer', 'echo update-start >> "$EVENTS"; sleep 1; echo update-end >> "$EVENTS"');
        $this->stub('php', 'echo "server $1" >> "$EVENTS"');

        self::assertSame([0, 0], $this->await([$this->launch(), $this->launch()]));
        self::assertSame(
            ['update-start', 'update-end', self::SERVER_LINE, self::SERVER_LINE],
            $this->events(),
        );
    }

    public function test_a_running_server_holds_no_lock_against_the_next_spawn(): void
    {
        file_put_contents($this->directory . '/.last-update-check', (string) time());

        $this->stub('composer', 'echo update >> "$EVENTS"');
        $this->stub('php', 'echo server-start >> "$EVENTS"; sleep 1; echo server-end >> "$EVENTS"');

        self::assertSame([0, 0], $this->await([$this->launch(), $this->launch()]));
        self::assertSame(
            ['server-start', 'server-start', 'server-end', 'server-end'],
            $this->events(),
        );
    }

    public function test_a_failed_update_starts_the_installed_server_and_leaves_the_timestamp_alone(): void
    {
        file_put_contents($this->directory . '/.last-update-check', "0\n");

        $this->stub('composer', 'echo update-attempted >> "$EVENTS"; exit 1');
        $this->stub('php', 'echo "server $1" >> "$EVENTS"');

        self::assertSame([0], $this->await([$this->launch()]));
        self::assertSame(['update-attempted', self::SERVER_LINE], $this->events());
        self::assertSame("0\n", file_get_contents($this->directory . '/.last-update-check'));
    }

    public function test_an_update_killed_part_way_holds_no_lock_and_moves_no_timestamp(): void
    {
        $this->stub('composer', 'echo update-killed >> "$EVENTS"; kill -9 "$PPID"');
        $this->stub('php', 'echo "server $1" >> "$EVENTS"');

        $this->await([$this->launch()]);

        self::assertSame(['update-killed'], $this->events());
        self::assertFileDoesNotExist($this->directory . '/.last-update-check');

        $this->stub('composer', 'echo update-retried >> "$EVENTS"');

        self::assertSame([0], $this->await([$this->launch()]));
        self::assertSame(['update-killed', 'update-retried', self::SERVER_LINE], $this->events());
    }

    private function stub(string $name, string $body): void
    {
        $path = $this->directory . '/stubs/' . $name;

        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0o755);
    }

    /**
     * @return resource
     */
    private function launch(): mixed
    {
        $process = proc_open(
            ['sh', self::SCRIPT],
            [
                ['file', '/dev/null', 'r'],
                ['file', $this->directory . '/stdout', 'a'],
                ['file', $this->directory . '/stderr', 'a'],
            ],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . '/stubs:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'EVENTS' => $this->directory . '/events',
            ],
        );

        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param list<resource> $processes
     *
     * @return list<int> one exit status per process, in launch order
     */
    private function await(array $processes): array
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $statuses = [];

        foreach ($processes as $process) {
            $status = proc_get_status($process);

            while ($status['running'] === true) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    proc_close($process);

                    self::fail('start.sh did not finish within ' . self::TIMEOUT_SECONDS . ' seconds.');
                }

                usleep(10_000);
                $status = proc_get_status($process);
            }

            proc_close($process);
            $statuses[] = $status['exitcode'];
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        $path = $this->directory . '/events';

        if (!is_file($path)) {
            return [];
        }

        $contents = rtrim((string) file_get_contents($path), "\n");

        return $contents === '' ? [] : explode("\n", $contents);
    }
}
