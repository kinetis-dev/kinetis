<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use Kinetis\McpDocs\DocsApplication;
use Kinetis\McpDocs\DocsCatalogue;
use Kinetis\McpDocs\DocsFetcher;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\StdioLoop;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The documentation catalogue as a real MCP server: driven through the
 * shared stdio loop, so what is asserted is the bytes a client reads.
 * The protocol's own rules are proved in kinetis/mcp-protocol; what
 * belongs here is this server's catalogue, its window tool, its fetch
 * failures, and its diagnostic policy.
 */
final class DocsApplicationTest extends TestCase
{
    public function test_initialize_answers_the_one_supported_revision_and_this_servers_identity(): void
    {
        $result = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25",'
            . '"capabilities":{},"clientInfo":{"name":"claude-code","version":"2.1.273"}}}',
        ])[0]['result'];

        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame(DocsApplication::SERVER_NAME, $result['serverInfo']['name']);
        self::assertSame(DocsApplication::SERVER_VERSION, $result['serverInfo']['version']);
        self::assertStringContainsString('kinetis://docs/agent-workflow', $result['instructions']);
    }

    /**
     * The server publishes the window tool and the catalogue, and
     * nothing else: no prompts and no subscriptions to invite.
     */
    public function test_initialize_advertises_tools_and_resources_and_nothing_else(): void
    {
        $result = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
            . '"capabilities":{},"clientInfo":{"name":"codex-mcp-client","version":"0.154.0"}}}',
        ])[0]['result'];

        self::assertSame(['tools', 'resources'], array_keys($result['capabilities']));
    }

    public function test_resources_list_carries_every_catalogue_page(): void
    {
        $resources = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"resources/list"}'])[0]['result']['resources'];

        self::assertCount(count(DocsCatalogue::pages()), $resources);
        self::assertSame('kinetis://docs/index', $resources[0]['uri']);
        self::assertSame(DocsCatalogue::MIME_TYPE, $resources[0]['mimeType']);
    }

    public function test_a_page_is_read_as_one_text_content_under_its_own_uri(): void
    {
        $contents = $this->frames(
            ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/index"}}'],
            new MockHttpClient(new MockResponse("# Index\n\nline two\n")),
        )[0]['result']['contents'];

        self::assertSame([[
            'uri' => 'kinetis://docs/index',
            'mimeType' => DocsCatalogue::MIME_TYPE,
            'text' => "# Index\n\nline two\n",
        ]], $contents);
    }

    public function test_an_unknown_uri_uses_the_resource_not_found_code(): void
    {
        $error = $this->frames(
            ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/nope"}}'],
        )[0]['error'];

        self::assertSame(-32002, $error['code']);
        self::assertSame(['uri' => 'kinetis://docs/nope'], $error['data']);
    }

    /**
     * A fetch failure's real reason — the URL and the transport's own
     * message — is a diagnostic for the process that runs this server,
     * never part of the protocol response a client reads.
     */
    public function test_a_failed_fetch_answers_generically_and_reports_the_reason_to_stderr(): void
    {
        $diagnostics = fopen('php://memory', 'r+');
        self::assertIsResource($diagnostics);

        $frames = $this->frames(
            ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/index"}}'],
            new MockHttpClient(new MockResponse('nope', ['http_code' => 404])),
            $diagnostics,
        );

        self::assertSame(-32603, $frames[0]['error']['code']);
        self::assertSame('Could not read "kinetis://docs/index".', $frames[0]['error']['message']);

        rewind($diagnostics);
        $reported = (string) stream_get_contents($diagnostics);
        self::assertStringContainsString(DocsApplication::SERVER_NAME, $reported);
        self::assertStringContainsString('got 404', $reported);
    }

    /**
     * A page that is not valid UTF-8 cannot go into a JSON frame at all,
     * so it is refused before the response is declared successful rather
     * than left to break the encode.
     */
    public function test_a_page_that_is_not_valid_utf8_is_refused(): void
    {
        $diagnostics = fopen('php://memory', 'r+');
        self::assertIsResource($diagnostics);

        $frames = $this->frames(
            ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/index"}}'],
            new MockHttpClient(new MockResponse("\xC3\x28")),
            $diagnostics,
        );

        self::assertSame(-32603, $frames[0]['error']['code']);

        rewind($diagnostics);
        self::assertStringContainsString('is not valid UTF-8', (string) stream_get_contents($diagnostics));
    }

    public function test_the_window_tool_is_published_with_its_bounds_and_annotations(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];

        self::assertCount(1, $tools);
        self::assertSame(DocsApplication::READ_TOOL, $tools[0]['name']);
        self::assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ], $tools[0]['annotations']);

        $schema = $tools[0]['inputSchema'];
        self::assertSame(['uri'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['uri', 'startLine', 'lineCount'], array_keys($schema['properties']));
        self::assertSame(DocsApplication::MAX_LINE_COUNT, $schema['properties']['lineCount']['maximum']);
    }

    /**
     * No window asked for: the first line, and as many as the published
     * default admits.
     */
    public function test_a_window_defaults_to_the_first_page_of_lines(): void
    {
        $page = self::numberedLines(250);
        $document = $this->window(['uri' => 'kinetis://docs/index'], $page);

        self::assertSame('ok', $document['status']);
        self::assertSame('kinetis://docs/index', $document['uri']);
        self::assertSame(1, $document['startLine']);
        self::assertSame(DocsApplication::MAX_LINE_COUNT, $document['endLine']);
        self::assertTrue($document['hasMore']);
        self::assertSame(self::numberedLines(DocsApplication::MAX_LINE_COUNT), $document['content']);
    }

    /**
     * `endLine` and `hasMore` describe the lines actually returned, so a
     * window running off the end of the page reports where it stopped
     * and that there is nothing after it.
     */
    public function test_a_window_at_the_end_of_a_page_reports_the_last_line_and_no_more(): void
    {
        $document = $this->window(
            ['uri' => 'kinetis://docs/index', 'startLine' => 2, 'lineCount' => 50],
            "one\ntwo\nthree\n",
        );

        self::assertSame(2, $document['startLine']);
        self::assertSame(3, $document['endLine']);
        self::assertFalse($document['hasMore']);
        self::assertSame("two\nthree\n", $document['content']);
    }

    /**
     * A line terminator belongs to the line it ends, and the last line
     * of a page that has none keeps none — which is what lets successive
     * windows rebuild the page byte for byte.
     */
    public function test_a_window_preserves_crlf_blank_lines_and_a_missing_final_newline(): void
    {
        $page = "first\r\nsecond\n\nno trailing newline";
        $document = $this->window(['uri' => 'kinetis://docs/index'], $page);

        self::assertSame(4, $document['endLine']);
        self::assertFalse($document['hasMore']);
        self::assertSame($page, $document['content']);
    }

    /**
     * The distinguishing case: 200 lines are asked for and the byte
     * ceiling is what ends the window, well short of them. The line that
     * would have passed the ceiling is left for the next call rather
     * than cut in half.
     */
    public function test_a_page_of_long_lines_stops_at_the_byte_ceiling_below_the_line_limit(): void
    {
        $page = self::longLines(20, 5000);
        $document = $this->window(['uri' => 'kinetis://docs/index'], $page);

        self::assertSame(6, $document['endLine']);
        self::assertLessThan(DocsApplication::MAX_LINE_COUNT, $document['endLine']);
        self::assertSame(30000, strlen($document['content']));
        self::assertLessThanOrEqual(DocsApplication::MAX_CONTENT_BYTES, strlen($document['content']));
        self::assertTrue($document['hasMore']);
    }

    /**
     * Continuation is the whole point of the bound: following `endLine`
     * to the end of a page that no single window can carry reconstructs
     * it exactly, with nothing dropped, repeated or re-encoded.
     */
    public function test_successive_windows_reconstruct_a_long_lined_page_exactly(): void
    {
        $page = self::longLines(20, 5000);
        $rebuilt = '';
        $startLine = 1;
        $windows = 0;

        do {
            $document = $this->window(['uri' => 'kinetis://docs/index', 'startLine' => $startLine], $page);
            $rebuilt .= $document['content'];
            $startLine = $document['endLine'] + 1;
            $windows++;

            self::assertLessThan(10, $windows, 'The continuation did not terminate.');
        } while ($document['hasMore'] === true);

        self::assertSame(4, $windows);
        self::assertSame($page, $rebuilt);
    }

    /**
     * The one case a window passes the ceiling. Returning nothing would
     * leave the caller no line to continue from, so the line is served
     * whole and the next call resumes after it.
     */
    public function test_a_single_line_larger_than_the_ceiling_is_returned_whole(): void
    {
        $page = str_repeat('x', 40000) . "\ntail\n";
        $first = $this->window(['uri' => 'kinetis://docs/index'], $page);

        self::assertSame(1, $first['endLine']);
        self::assertSame(40001, strlen($first['content']));
        self::assertGreaterThan(DocsApplication::MAX_CONTENT_BYTES, strlen($first['content']));
        self::assertTrue($first['hasMore']);

        $second = $this->window(['uri' => 'kinetis://docs/index', 'startLine' => 2], $page);

        self::assertSame("tail\n", $second['content']);
        self::assertFalse($second['hasMore']);
        self::assertSame($page, $first['content'] . $second['content']);
    }

    /**
     * A well-typed URI the catalogue does not carry is a tool that ran
     * and refused, so the code stays readable to the caller. No fetch is
     * made for it.
     */
    public function test_an_unknown_uri_is_a_failed_tool_document(): void
    {
        $frames = $this->frames([self::call(['uri' => 'kinetis://docs/nope'])]);

        self::assertArrayNotHasKey('error', $frames[0]);
        self::assertTrue($frames[0]['result']['isError']);
        self::assertSame(['status' => 'error', 'code' => 'resource_unknown'], self::document($frames[0]));
    }

    public function test_a_start_line_past_the_end_of_the_page_is_a_failed_tool_document(): void
    {
        $frames = $this->frames(
            [self::call(['uri' => 'kinetis://docs/index', 'startLine' => 4])],
            self::pageClient("one\ntwo\nthree\n"),
        );

        self::assertTrue($frames[0]['result']['isError']);
        self::assertSame(['status' => 'error', 'code' => 'line_out_of_range'], self::document($frames[0]));
    }

    /**
     * An argument the published schema has no reading of is a malformed
     * call, not a refusal a model should try to correct from a document:
     * every one of these is `-32602` before any page is fetched.
     */
    public function test_arguments_outside_the_published_schema_are_invalid_params(): void
    {
        $cases = [
            'no uri' => ['startLine' => 1],
            'empty uri' => ['uri' => ''],
            'uri of the wrong type' => ['uri' => 7],
            'unknown member' => ['uri' => 'kinetis://docs/index', 'lines' => 5],
            'start line below one' => ['uri' => 'kinetis://docs/index', 'startLine' => 0],
            'start line of the wrong type' => ['uri' => 'kinetis://docs/index', 'startLine' => '2'],
            'line count below one' => ['uri' => 'kinetis://docs/index', 'lineCount' => 0],
            'line count past the maximum' => [
                'uri' => 'kinetis://docs/index',
                'lineCount' => DocsApplication::MAX_LINE_COUNT + 1,
            ],
        ];

        foreach ($cases as $label => $arguments) {
            $frames = $this->frames([self::call($arguments)]);

            self::assertArrayNotHasKey('result', $frames[0], $label);
            self::assertSame(-32602, $frames[0]['error']['code'], $label);
        }
    }

    /**
     * A window read fetches the page the same way a resource read does,
     * so it answers a failure the same way: a generic error to the
     * client, the URL and the real reason to this server's own stream.
     */
    public function test_a_failed_fetch_under_the_window_tool_answers_generically(): void
    {
        $diagnostics = fopen('php://memory', 'r+');
        self::assertIsResource($diagnostics);

        $frames = $this->frames(
            [self::call(['uri' => 'kinetis://docs/index'])],
            new MockHttpClient(new MockResponse('nope', ['http_code' => 404])),
            $diagnostics,
        );

        self::assertSame(-32603, $frames[0]['error']['code']);
        self::assertSame('Could not read "kinetis://docs/index".', $frames[0]['error']['message']);

        rewind($diagnostics);
        $reported = (string) stream_get_contents($diagnostics);
        self::assertStringContainsString('got 404', $reported);
        self::assertStringNotContainsString('404', $frames[0]['error']['message']);
    }

    public function test_a_page_that_is_not_valid_utf8_is_refused_under_the_window_tool_too(): void
    {
        $diagnostics = fopen('php://memory', 'r+');
        self::assertIsResource($diagnostics);

        $frames = $this->frames(
            [self::call(['uri' => 'kinetis://docs/index'])],
            new MockHttpClient(new MockResponse("\xC3\x28")),
            $diagnostics,
        );

        self::assertSame(-32603, $frames[0]['error']['code']);

        rewind($diagnostics);
        self::assertStringContainsString('is not valid UTF-8', (string) stream_get_contents($diagnostics));
    }

    /**
     * The handshake a real client opens with: the notification in the
     * middle is answered with nothing, so two frames come back for three
     * messages.
     */
    public function test_the_initialized_notification_is_answered_with_nothing(): void
    {
        $frames = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
            . '"capabilities":{},"clientInfo":{"name":"probe","version":"1.0"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"resources/list"}',
        ]);

        self::assertCount(2, $frames);
        self::assertSame(1, $frames[0]['id']);
        self::assertSame(2, $frames[1]['id']);
    }

    /**
     * The window one call returns, for a catalogue page whose markdown
     * is $page.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function window(array $arguments, string $page): array
    {
        $frames = $this->frames([self::call($arguments)], self::pageClient($page));

        self::assertFalse($frames[0]['result']['isError']);

        return self::document($frames[0]);
    }

    /**
     * One `tools/call` message for the window tool.
     *
     * @param array<string, mixed> $arguments
     */
    private static function call(array $arguments): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => DocsApplication::READ_TOOL, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The one text content block a result carries, decoded.
     *
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    private static function document(array $frame): array
    {
        $content = $frame['result']['content'];
        self::assertCount(1, $content);
        self::assertSame('text', $content[0]['type']);

        /** @var array<string, mixed> */
        return json_decode($content[0]['text'], associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A client serving $page to every request. Each read fetches the
     * page again and a MockResponse is consumed once, so a continuation
     * needs a fresh one per call rather than a single shared response.
     */
    private static function pageClient(string $page): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse($page));
    }

    /** $count lines, each naming its own number. */
    private static function numberedLines(int $count): string
    {
        $lines = '';

        for ($line = 1; $line <= $count; $line++) {
            $lines .= "line {$line}\n";
        }

        return $lines;
    }

    /** $count lines of exactly $bytes bytes each, terminator included. */
    private static function longLines(int $count, int $bytes): string
    {
        return str_repeat(str_repeat('x', $bytes - 1) . "\n", $count);
    }

    /**
     * @param list<string> $messages
     * @param resource|null $diagnostics
     * @return list<array<string, mixed>>
     */
    private function frames(array $messages, ?MockHttpClient $client = null, $diagnostics = null): array
    {
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, implode("\n", $messages) . "\n");
        rewind($input);

        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $application = new DocsApplication(
            $client === null ? null : new DocsFetcher($client),
            $diagnostics,
        );

        new StdioLoop()->run(new McpServer(DocsApplication::serverInfo(), $application), $input, $output);

        rewind($output);
        $decoded = [];

        foreach (explode("\n", (string) stream_get_contents($output)) as $line) {
            if ($line !== '') {
                $decoded[] = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
            }
        }

        /** @var list<array<string, mixed>> */
        return $decoded;
    }
}
