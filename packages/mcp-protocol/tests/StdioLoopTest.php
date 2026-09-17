<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Tests;

use Kinetis\McpProtocol\Exception\StdioWriteException;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\StdioLoop;
use Kinetis\McpProtocol\Tests\Fixtures\ProbeApplication;
use Kinetis\McpProtocol\Tests\Fixtures\RecordingHandler;
use Kinetis\McpProtocol\Tests\Fixtures\WriteControllableStreamWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Byte-level framing: what the loop reads, what it writes, and what it
 * refuses to write once the stream is no longer trustworthy.
 */
final class StdioLoopTest extends TestCase
{
    public function test_one_message_per_line_answers_one_frame_per_line(): void
    {
        $frames = self::framesFor(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n"
            . '{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n",
        );

        self::assertSame([
            '{"jsonrpc":"2.0","id":1,"result":{}}',
            '{"jsonrpc":"2.0","id":2,"result":{}}',
        ], $frames);
    }

    public function test_a_final_line_at_eof_without_a_terminator_is_a_complete_message(): void
    {
        self::assertSame(
            ['{"jsonrpc":"2.0","id":1,"result":{}}'],
            self::framesFor('{"jsonrpc":"2.0","id":1,"method":"ping"}'),
        );
    }

    public function test_crlf_framing_and_blank_lines_are_framing_only(): void
    {
        $frames = self::framesFor(
            "\r\n" . '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\r\n" . "  \t  \n",
        );

        self::assertSame(['{"jsonrpc":"2.0","id":1,"result":{}}'], $frames);
    }

    public function test_a_line_that_is_only_valid_json_without_its_nul_bytes_is_still_rejected(): void
    {
        $frames = self::framesFor("\x00" . '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\x00\n");

        self::assertCount(1, $frames);
        self::assertStringContainsString('-32700', $frames[0]);
    }

    public function test_an_oversized_line_produces_exactly_one_error_and_the_next_frame_still_runs(): void
    {
        $oversized = '{"jsonrpc":"2.0","id":1,"method":"ping","pad":"'
            . str_repeat('x', StdioLoop::MAX_PAYLOAD_BYTES) . '"}';

        $frames = self::framesFor($oversized . "\n" . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n");

        self::assertCount(2, $frames);
        self::assertStringContainsString('"code":-32700', $frames[0]);
        self::assertNull(json_decode($frames[0], true)['id']);
        self::assertSame('{"jsonrpc":"2.0","id":2,"result":{}}', $frames[1]);
    }

    public function test_an_oversized_line_running_to_eof_still_produces_exactly_one_error(): void
    {
        $frames = self::framesFor(str_repeat('x', StdioLoop::MAX_PAYLOAD_BYTES + 10));

        self::assertCount(1, $frames);
        self::assertStringContainsString('"code":-32700', $frames[0]);
    }

    public function test_a_payload_exactly_at_the_cap_is_read_rather_than_drained(): void
    {
        $padding = StdioLoop::MAX_PAYLOAD_BYTES - strlen('{"jsonrpc":"2.0","id":1,"method":"ping","pad":""}');
        $payload = '{"jsonrpc":"2.0","id":1,"method":"ping","pad":"' . str_repeat('x', $padding) . '"}';

        self::assertSame(StdioLoop::MAX_PAYLOAD_BYTES, strlen($payload));
        self::assertSame(['{"jsonrpc":"2.0","id":1,"result":{}}'], self::framesFor($payload . "\n"));
    }

    public function test_a_client_response_is_read_and_answered_with_nothing(): void
    {
        self::assertSame([], self::framesFor('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}' . "\n"));
    }

    public function test_progress_notifications_precede_the_final_response(): void
    {
        $frames = self::framesFor(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":'
            . '{"name":"progress","_meta":{"progressToken":7}}}' . "\n",
        );

        self::assertCount(3, $frames);
        self::assertSame(
            '{"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":7,"progress":1}}',
            $frames[0],
        );
        self::assertStringContainsString('"progress":2,"total":10,"message":"halfway"', $frames[1]);
        self::assertStringContainsString('"id":1,"result"', $frames[2]);
    }

    /**
     * A consumer result carrying bytes JSON refuses must not reach
     * `writeFrame()`: a `JsonException` there escapes `run()` and ends the
     * process, losing every message queued behind the bad one. The server
     * settles encodability first, so the bad message is answered and the
     * next one is still processed.
     */
    public function test_an_unencodable_result_is_answered_and_the_next_message_still_runs(): void
    {
        $frames = self::framesFor(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"unencodable"}}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n",
        );

        self::assertCount(2, $frames);
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Internal error."}}',
            $frames[0],
        );
        self::assertSame('{"jsonrpc":"2.0","id":2,"result":{}}', $frames[1]);
    }

    public function test_each_message_is_decoded_and_forwarded_on_its_own(): void
    {
        $handler = new RecordingHandler([['jsonrpc' => '2.0', 'id' => 1, 'result' => []], null]);

        self::assertCount(1, self::runAgainst(
            $handler,
            '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n" . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n",
        ));
        self::assertSame([
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 2],
        ], $handler->messages);
    }

    /**
     * The first frame is left truncated, so the second message's frame is
     * never attempted: a line with no terminator would corrupt the framing
     * of everything after it.
     */
    public function test_a_partial_frame_ends_the_loop_before_the_next_one(): void
    {
        $handler = new RecordingHandler([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => []],
        ]);
        $output = self::stream([5, 0, false]);
        $input = self::input(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n" . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n",
        );

        try {
            new StdioLoop()->run($handler, $input, $output);
            self::fail('The loop should have reported the partial frame.');
        } catch (StdioWriteException $e) {
            self::assertStringContainsString('Wrote only 5 of 37 bytes', $e->getMessage());
        }

        rewind($output);
        self::assertSame('{"jso', (string) stream_get_contents($output));
        self::assertCount(1, $handler->messages);
    }

    public function test_a_short_write_is_completed_rather_than_treated_as_a_failure(): void
    {
        $frames = self::runAgainst(
            new RecordingHandler([['jsonrpc' => '2.0', 'id' => 1, 'result' => []]]),
            '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n",
            [5, 7],
        );

        self::assertSame(['{"jsonrpc":"2.0","id":1,"result":[]}'], $frames);
    }

    public function test_a_zero_length_write_is_terminal_rather_than_retried(): void
    {
        $this->expectException(StdioWriteException::class);

        self::runAgainst(
            new RecordingHandler([['jsonrpc' => '2.0', 'id' => 1, 'result' => []]]),
            '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n",
            [0],
        );
    }

    /**
     * A failed progress write must not be reported as a tool failure and
     * then written into the same broken stream: the stash is re-thrown
     * before any final response is attempted, and the later notification
     * never reaches the stream either.
     */
    public function test_a_failed_progress_write_stops_before_the_final_response(): void
    {
        $handler = new RecordingHandler(
            [['jsonrpc' => '2.0', 'id' => 1, 'result' => ['final' => true]]],
            [
                ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progress' => 1]],
                ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progress' => 2]],
            ],
        );

        $output = self::stream([false]);
        $input = self::input('{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");

        try {
            new StdioLoop()->run($handler, $input, $output);
            self::fail('The loop should have re-thrown the stashed progress-write failure.');
        } catch (StdioWriteException) {
            // Expected.
        }

        rewind($output);
        self::assertSame('', (string) stream_get_contents($output));
    }

    /**
     * @return list<string>
     */
    private static function framesFor(string $input): array
    {
        return self::runAgainst(
            new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), new ProbeApplication()),
            $input,
        );
    }

    /**
     * @param list<int|false> $writeReturns
     * @return list<string>
     */
    private static function runAgainst(
        \Kinetis\McpProtocol\MessageHandler $handler,
        string $input,
        array $writeReturns = [],
    ): array {
        $output = self::stream($writeReturns);

        new StdioLoop()->run($handler, self::input($input), $output);

        rewind($output);
        $written = (string) stream_get_contents($output);

        return array_values(array_filter(explode("\n", $written), static fn (string $l): bool => $l !== ''));
    }

    /** @return resource */
    private static function input(string $contents)
    {
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, $contents);
        rewind($input);

        return $input;
    }

    /**
     * @param list<int|false> $writeReturns
     * @return resource
     */
    private static function stream(array $writeReturns)
    {
        if ($writeReturns === []) {
            $output = fopen('php://memory', 'r+');
            self::assertIsResource($output);

            return $output;
        }

        WriteControllableStreamWrapper::register();

        $output = fopen(
            WriteControllableStreamWrapper::PROTOCOL . '://out',
            'r+',
            context: stream_context_create([
                WriteControllableStreamWrapper::PROTOCOL => ['writeReturns' => $writeReturns],
            ]),
        );
        self::assertIsResource($output);

        return $output;
    }
}
