<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A browser that leaves mid-answer, against Fixtures/browser/index.php
 * served by a real SAPI, with the stand-in provider on another host.
 *
 * Runs only with KINETIS_NEURON_SAPI (`frankenphp` or `fpm`),
 * KINETIS_NEURON_SAPI_HOST and KINETIS_NEURON_STATE_DIR, a directory
 * the SAPI, the provider and this test share at the same path.
 */
final class BrowserDisconnectTest extends TestCase
{
    public function test_a_browser_that_leaves_stops_the_answer_and_the_provider_connection(): void
    {
        $sapi = getenv('KINETIS_NEURON_SAPI');
        $host = getenv('KINETIS_NEURON_SAPI_HOST');
        $stateDir = getenv('KINETIS_NEURON_STATE_DIR');

        if (!is_string($sapi) || !is_string($host) || !is_string($stateDir)) {
            self::markTestSkipped('Needs a SAPI serving tests/Fixtures/browser/index.php.');
        }

        $mark = $stateDir . '/' . bin2hex(random_bytes(8));
        $socket = stream_socket_client('tcp://' . $host, $code, $message, 5.0);
        self::assertNotFalse($socket, "No connection to {$host}: {$message}");
        stream_set_timeout($socket, 5);

        fwrite($socket, 'GET /?mark=' . rawurlencode($mark) . " HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

        $received = '';

        while (substr_count($received, '"type":"text-delta"') < 3 && !feof($socket)) {
            $received .= (string) fread($socket, 8192);
        }

        fclose($socket);

        self::assertStringStartsWith('HTTP/1.1 200', $received);
        self::assertStringContainsStringIgnoringCase("x-vercel-ai-ui-message-stream: v1\r\n", $received);
        self::assertSame(3, substr_count($received, '"type":"text-delta"'));

        // The provider writes for five seconds unless its connection is
        // cancelled, and records which happened.
        $upstream = self::await($mark, 2.0);
        self::assertMatchesRegularExpression('/^aborted after \d+ events$/m', $upstream);

        $server = self::await($mark . '.server', 1.0);

        if ($sapi === 'frankenphp') {
            // The worker saw the client leave at a flush, Neuron stopped
            // the answer, and the emitter returned normally.
            self::assertSame("emitter returned aborted=1 stop=stopped\n", $server);
        } else {
            // PHP-FPM ends the script at the first write after nginx drops
            // the connection, so the emitter never returns.
            self::assertSame('', $server);
        }
    }

    /** What $file holds once it is non-empty, or '' after $seconds. */
    private static function await(string $file, float $seconds): string
    {
        $deadline = microtime(true) + $seconds;

        do {
            $content = (string) @file_get_contents($file);

            if ($content !== '') {
                return $content;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return '';
    }
}
