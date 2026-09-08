<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use Kinetis\McpDocs\DocsFetcher;
use Kinetis\McpDocs\Exception\StdioWriteException;
use Kinetis\McpDocs\McpDocsServer;
use Kinetis\McpDocs\StdioLoop;
use Kinetis\McpDocs\Tests\Fixtures\WriteControllableStreamWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StdioLoopTest extends TestCase
{
    public function test_each_line_of_input_produces_one_response_line(): void
    {
        $lines = $this->drive([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}',
            '{"jsonrpc":"2.0","id":2,"method":"ping"}',
        ]);

        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertSame(1, $first['id']);
        self::assertSame('2025-11-25', $first['result']['protocolVersion']);
        self::assertSame(2, $second['id']);
    }

    public function test_a_notification_produces_no_line_at_all(): void
    {
        $lines = $this->drive([
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ]);

        self::assertCount(1, $lines);
        self::assertSame(1, json_decode($lines[0], true)['id']);
    }

    public static function unansweredNotificationProvider(): iterable
    {
        yield 'array params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":[1,2]}'];
        yield 'empty array params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":[]}'];
        yield 'string params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":"nope"}'];
        yield 'number params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":7}'];
        yield 'boolean params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":true}'];
        yield 'null params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":null}'];
        yield 'an unknown method' => ['{"jsonrpc":"2.0","method":"notifications/cancelled"}'];
        yield 'an unknown method with array params' => ['{"jsonrpc":"2.0","method":"notifications/cancelled","params":[]}'];
    }

    #[DataProvider('unansweredNotificationProvider')]
    public function test_a_notification_this_server_cannot_act_on_produces_no_line_either(string $raw): void
    {
        $lines = $this->drive([$raw, '{"jsonrpc":"2.0","id":1,"method":"ping"}']);

        self::assertCount(1, $lines);
        self::assertSame(1, json_decode($lines[0], true)['id']);
    }

    public static function requestWithUnusableParamsProvider(): iterable
    {
        yield 'array params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":[1,2]}'];
        yield 'empty array params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":[]}'];
        yield 'string params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":"nope"}'];
        yield 'number params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":7}'];
        yield 'boolean params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":true}'];
        yield 'null params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":null}'];
    }

    #[DataProvider('requestWithUnusableParamsProvider')]
    public function test_the_same_params_on_a_request_are_answered_with_invalid_params(string $raw): void
    {
        $lines = $this->drive([$raw]);

        self::assertCount(1, $lines);

        $response = json_decode($lines[0], true);

        self::assertSame(-32602, $response['error']['code']);
        self::assertSame(1, $response['id']);
    }

    public function test_a_message_with_no_id_whose_envelope_is_broken_is_still_answered(): void
    {
        $lines = $this->drive(['{"jsonrpc":"2.0","method":42,"params":[]}']);

        self::assertCount(1, $lines);

        $response = json_decode($lines[0], true);

        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    public function test_blank_lines_between_messages_are_skipped(): void
    {
        $lines = $this->drive(['', '   ', "\t", '{"jsonrpc":"2.0","id":1,"method":"ping"}', '']);

        self::assertCount(1, $lines);
    }

    public function test_a_line_that_is_not_json_is_answered_as_a_parse_error(): void
    {
        $lines = $this->drive(['{oops']);

        self::assertCount(1, $lines);
        self::assertSame(-32700, json_decode($lines[0], true)['error']['code']);
    }

    public function test_a_null_wrapped_line_is_a_parse_error_rather_than_silently_normalized(): void
    {
        $lines = $this->drive(["\0" . '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\0"]);

        self::assertCount(1, $lines);
        self::assertSame(-32700, json_decode($lines[0], true)['error']['code']);
    }

    public function test_carriage_returns_in_the_framing_are_stripped(): void
    {
        $input = $this->stream("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\r\n");
        $output = $this->stream('');

        new StdioLoop()->run($this->server(), $input, $output);

        self::assertSame("{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n", $this->contents($output));
    }

    public function test_end_of_input_ends_the_loop_without_a_failure(): void
    {
        $lines = $this->drive(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

        self::assertCount(1, $lines);
    }

    public function test_a_frame_accepted_in_pieces_is_written_in_full(): void
    {
        WriteControllableStreamWrapper::register();

        $output = fopen(
            WriteControllableStreamWrapper::PROTOCOL . '://out',
            'w+',
            false,
            stream_context_create([WriteControllableStreamWrapper::PROTOCOL => ['writeReturns' => [5, 0]]]),
        );

        self::assertIsResource($output);

        new StdioLoop()->run(
            $this->server(),
            $this->stream("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n"),
            $output,
        );

        self::assertSame("{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n", $this->contents($output));
    }

    public function test_a_stream_that_stops_accepting_data_ends_the_loop_with_the_byte_counts(): void
    {
        WriteControllableStreamWrapper::register();

        $output = fopen(
            WriteControllableStreamWrapper::PROTOCOL . '://out',
            'w+',
            false,
            stream_context_create([WriteControllableStreamWrapper::PROTOCOL => ['writeReturns' => [false]]]),
        );

        self::assertIsResource($output);

        $this->expectException(StdioWriteException::class);
        $this->expectExceptionMessage('Wrote only 0 of 37 bytes');

        new StdioLoop()->run(
            $this->server(),
            $this->stream("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n"),
            $output,
        );
    }

    public function test_a_resource_read_travels_as_one_line_of_json(): void
    {
        $server = new McpDocsServer(
            new DocsFetcher(new MockHttpClient(new MockResponse("# Index\n\nline two\n"))),
        );

        $input = $this->stream("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"resources/read\",\"params\":{\"uri\":\"kinetis://docs/index\"}}\n");
        $output = $this->stream('');

        new StdioLoop()->run($server, $input, $output);

        $written = $this->contents($output);

        self::assertSame(1, substr_count($written, "\n"));
        self::assertSame("# Index\n\nline two\n", json_decode(trim($written), true)['result']['contents'][0]['text']);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function drive(array $lines): array
    {
        $input = $this->stream(implode("\n", $lines) . "\n");
        $output = $this->stream('');

        new StdioLoop()->run($this->server(), $input, $output);

        $written = $this->contents($output);

        return $written === '' ? [] : explode("\n", rtrim($written, "\n"));
    }

    private function server(): McpDocsServer
    {
        return new McpDocsServer(new DocsFetcher(new MockHttpClient(new MockResponse('page'))));
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
