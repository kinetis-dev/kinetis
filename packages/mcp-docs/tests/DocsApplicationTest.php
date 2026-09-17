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
 * belongs here is this server's catalogue, its fetch failures, and its
 * diagnostic policy.
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
     * The catalogue has no tools, so the handshake must not advertise a
     * `tools` capability a client would then call into.
     */
    public function test_initialize_advertises_resources_and_nothing_else(): void
    {
        $result = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
            . '"capabilities":{},"clientInfo":{"name":"codex-mcp-client","version":"0.154.0"}}}',
        ])[0]['result'];

        self::assertSame(['resources'], array_keys($result['capabilities']));
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
