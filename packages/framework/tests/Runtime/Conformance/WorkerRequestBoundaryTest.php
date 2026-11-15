<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Conformance;

use PHPUnit\Framework\TestCase;

/**
 * A persistent worker runs the event-loop work a request leaves behind
 * once that request ends, not when a later request happens to turn the
 * loop. Runs against Fixtures/index.php served by a worker with one
 * thread, so no other request can turn that worker's loop.
 *
 * Needs KINETIS_CONFORMANCE_HOST, KINETIS_CONFORMANCE_STATE_DIR and
 * KINETIS_CONFORMANCE_PERSISTENT=1.
 */
final class WorkerRequestBoundaryTest extends TestCase
{
    public function test_work_queued_by_a_request_runs_when_it_ends_without_another_request(): void
    {
        $host = getenv('KINETIS_CONFORMANCE_HOST');
        $stateDir = getenv('KINETIS_CONFORMANCE_STATE_DIR');

        if (!is_string($host) || $host === '' || !is_string($stateDir) || $stateDir === '' || getenv('KINETIS_CONFORMANCE_PERSISTENT') !== '1') {
            self::markTestSkipped('Needs a persistent worker serving the conformance fixture.');
        }

        $marker = bin2hex(random_bytes(16));
        $file = "{$stateDir}/{$marker}.chain";

        $socket = stream_socket_client("tcp://{$host}", $code, $message, 5.0);
        self::assertNotFalse($socket, "No connection to {$host}: {$message}");
        stream_set_timeout($socket, 5);
        fwrite($socket, "GET /__conformance/queued-chain?marker={$marker} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $status = (string) fgets($socket);
        fclose($socket);

        self::assertStringStartsWith('HTTP/1.1 204', $status);

        $deadline = microtime(true) + 2.0;

        while (!is_file($file) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        self::assertFileExists($file, 'The chain the request queued never ran after it ended.');
        unlink($file);
    }
}
