<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * SuperglobalsBridge::handle() against a real PHP built-in server, not a
 * simulation — request_parse_body()'s failure modes (a malformed body,
 * or an exceeded post_max_size/upload_max_filesize) only reproduce
 * against a real SAPI request context; a bare CLI script setting
 * $_SERVER['CONTENT_TYPE'] by hand never triggers RequestParseBodyException
 * at all, confirmed directly before writing this test.
 */
final class SuperglobalsBridgeHandleTest extends TestCase
{
    private const string HOST = '127.0.0.1:8101';

    /** @var resource */
    private static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        self::$serverProcess = proc_open(
            [
                'php',
                '-d', 'post_max_size=200',
                '-d', 'upload_max_filesize=200',
                '-S', self::HOST,
                __DIR__ . '/Fixtures/superglobals-bridge-server.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::waitForServerReady(self::HOST);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
    }

    /**
     * `php -S` gives no fixed readiness signal of its own — a real TCP
     * connect attempt, polled with a bounded deadline, in place of a
     * fixed sleep that can race the server's own startup and lose on a
     * slower or more loaded runner.
     */
    private static function waitForServerReady(string $host): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://{$host}", timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        self::fail("The fixture server at {$host} never started accepting connections.");
    }

    public function test_a_normal_request_still_reaches_the_handler(): void
    {
        $response = file_get_contents('http://' . self::HOST . '/');

        self::assertSame('{"ok":true}', $response);
    }

    /**
     * A PUT body exceeding post_max_size makes request_parse_body()
     * throw RequestParseBodyException. Uncaught, this used to be a raw,
     * unhandled fatal error escaping to the client with a full stack
     * trace — reproduced directly against the pre-fix call shape before
     * writing this assertion. handle() now converts it to a clean JSON
     * 400, matching this framework's own error-response policy instead
     * of bypassing it.
     */
    public function test_a_body_exceeding_the_configured_limit_gets_a_clean_400_not_a_fatal_error(): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => "Content-Type: multipart/form-data; boundary=----XYZ\r\n",
            'content' => '------XYZ' . str_repeat('A', 5_000) . '------XYZ--',
            'ignore_errors' => true,
        ]]);

        $body = file_get_contents('http://' . self::HOST . '/', false, $context);

        self::assertIsString($body);
        self::assertStringNotContainsString('Fatal error', $body);
        self::assertStringNotContainsString('RequestParseBodyException', $body);

        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        self::assertNotSame([], $responseHeaders);
        self::assertStringContainsString('400', $responseHeaders[0]);

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('The request body could not be parsed.', $decoded['error'] ?? null);
    }
}
