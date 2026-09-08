<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use Kinetis\McpDocs\DocsCatalogue;
use Kinetis\McpDocs\DocsFetcher;
use Kinetis\McpDocs\JsonRpcEnvelope;
use Kinetis\McpDocs\JsonRpcRequest;
use Kinetis\McpDocs\McpDocsServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpDocsServerTest extends TestCase
{
    // --- initialize ---

    #[DataProvider('supportedProtocolVersionProvider')]
    public function test_a_supported_protocol_version_is_echoed_back(string $version): void
    {
        $result = self::resultOf(self::server(), 'initialize', ['protocolVersion' => $version]);

        self::assertSame($version, $result->protocolVersion);
    }

    public static function supportedProtocolVersionProvider(): iterable
    {
        foreach (McpDocsServer::PROTOCOL_VERSIONS as $version) {
            yield $version => [$version];
        }
    }

    public function test_an_unsupported_protocol_version_is_answered_with_the_newest_supported_one(): void
    {
        $result = self::resultOf(self::server(), 'initialize', ['protocolVersion' => '2001-01-01']);

        self::assertSame('2025-11-25', $result->protocolVersion);
    }

    public function test_initialize_declares_the_resources_capability_and_names_the_server(): void
    {
        $result = self::resultOf(self::server(), 'initialize', ['protocolVersion' => '2025-06-18']);

        self::assertSame([], (array) $result->capabilities->resources);
        self::assertSame('kinetis-mcp-docs', $result->serverInfo->name);
        self::assertSame(McpDocsServer::SERVER_VERSION, $result->serverInfo->version);
        self::assertNotSame('', $result->instructions);
    }

    public static function missingProtocolVersionProvider(): iterable
    {
        yield 'no params at all' => [null];
        yield 'no protocolVersion member' => [[]];
        yield 'a non-string version' => [['protocolVersion' => 3]];
        yield 'an empty version' => [['protocolVersion' => '']];
    }

    #[DataProvider('missingProtocolVersionProvider')]
    public function test_initialize_without_a_usable_protocol_version_is_invalid_params(?array $params): void
    {
        $response = self::server()->handle(self::request('initialize', $params));

        self::assertSame(-32602, $response['error']['code']);
    }

    // --- ping ---

    public function test_ping_answers_with_an_empty_result_object(): void
    {
        $response = self::server()->handle(self::request('ping'));

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', json_encode($response));
    }

    // --- notifications ---

    public function test_a_notification_gets_no_response(): void
    {
        $server = self::server();

        self::assertNull($server->handle(new JsonRpcRequest('notifications/initialized', false, null, null)));
        self::assertNull($server->handle(new JsonRpcRequest('resources/list', false, null, null)));
        self::assertNull($server->handle(new JsonRpcRequest('nonsense/method', false, null, null)));
    }

    public function test_a_notification_method_sent_as_a_request_is_method_not_found(): void
    {
        $response = self::server()->handle(self::request('notifications/initialized'));

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_an_unknown_method_is_method_not_found(): void
    {
        $response = self::server()->handle(self::request('tools/list'));

        self::assertSame(-32601, $response['error']['code']);
        self::assertStringContainsString('tools/list', $response['error']['message']);
    }

    // --- resources/list ---

    public function test_resources_list_returns_the_whole_catalogue(): void
    {
        $result = self::resultOf(self::server(), 'resources/list');

        self::assertCount(count(DocsCatalogue::pages()), $result->resources);

        $first = $result->resources[0];

        self::assertSame('kinetis://docs/index', $first['uri']);
        self::assertSame('text/markdown', $first['mimeType']);
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('description', $first);
    }

    public function test_resources_list_rejects_a_cursor_it_never_issued(): void
    {
        $response = self::server()->handle(self::request('resources/list', ['cursor' => 'page-2']));

        self::assertSame(-32602, $response['error']['code']);
    }

    // --- resources/read ---

    public function test_resources_read_returns_the_page_markdown(): void
    {
        $client = new MockHttpClient(new MockResponse("# Tutorial\n\nStart here.\n"));
        $result = self::resultOf(
            self::server($client),
            'resources/read',
            ['uri' => 'kinetis://docs/tutorial'],
        );

        self::assertCount(1, $result->contents);
        self::assertSame('kinetis://docs/tutorial', $result->contents[0]['uri']);
        self::assertSame('text/markdown', $result->contents[0]['mimeType']);
        self::assertSame("# Tutorial\n\nStart here.\n", $result->contents[0]['text']);
    }

    public function test_resources_read_fetches_the_raw_github_url_for_that_page(): void
    {
        $response = new MockResponse('page');
        $client = new MockHttpClient($response);

        self::resultOf(self::server($client), 'resources/read', ['uri' => 'kinetis://docs/mcp-docs']);

        self::assertSame(
            'https://raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/mcp-docs.md',
            $response->getRequestUrl(),
        );
    }

    public function test_an_unknown_resource_uri_is_a_resource_not_found_error(): void
    {
        $response = self::server()->handle(self::request('resources/read', ['uri' => 'kinetis://docs/nope']));

        self::assertSame(-32002, $response['error']['code']);
        self::assertSame(['uri' => 'kinetis://docs/nope'], $response['error']['data']);
    }

    public static function unusableUriProvider(): iterable
    {
        yield 'no params at all' => [null];
        yield 'no uri member' => [[]];
        yield 'a non-string uri' => [['uri' => 12]];
        yield 'an empty uri' => [['uri' => '']];
    }

    #[DataProvider('unusableUriProvider')]
    public function test_resources_read_without_a_usable_uri_is_invalid_params(?array $params): void
    {
        $response = self::server()->handle(self::request('resources/read', $params));

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_failed_fetch_is_an_internal_error_whose_detail_goes_to_the_diagnostics_stream(): void
    {
        $diagnostics = fopen('php://memory', 'w+');
        self::assertIsResource($diagnostics);

        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        $server = new McpDocsServer(new DocsFetcher($client), $diagnostics);

        $response = $server->handle(self::request('resources/read', ['uri' => 'kinetis://docs/index']));

        self::assertSame(-32603, $response['error']['code']);
        self::assertStringNotContainsString('raw.githubusercontent.com', $response['error']['message']);

        rewind($diagnostics);
        $written = (string) stream_get_contents($diagnostics);

        self::assertStringContainsString('raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/index.md', $written);
        self::assertStringContainsString('500', $written);
    }

    public function test_a_page_that_is_not_valid_utf8_is_an_internal_error(): void
    {
        $client = new MockHttpClient(new MockResponse("\xB1\x31"));
        $response = self::server($client)->handle(self::request('resources/read', ['uri' => 'kinetis://docs/index']));

        self::assertSame(-32603, $response['error']['code']);
    }

    public function test_a_failure_without_a_diagnostics_stream_is_still_an_internal_error(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));
        $response = self::server($client)->handle(self::request('resources/read', ['uri' => 'kinetis://docs/index']));

        self::assertSame(-32603, $response['error']['code']);
    }

    // --- helpers ---

    private static function server(?MockHttpClient $client = null): McpDocsServer
    {
        return new McpDocsServer(new DocsFetcher($client ?? new MockHttpClient(new MockResponse('page'))));
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private static function request(string $method, ?array $params = null): JsonRpcRequest
    {
        return new JsonRpcRequest($method, true, 1, $params === null ? null : (object) $params);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private static function resultOf(McpDocsServer $server, string $method, ?array $params = null): object
    {
        $response = $server->handle(self::request($method, $params));

        self::assertIsArray($response);
        self::assertArrayNotHasKey('error', $response, json_encode($response));
        self::assertIsObject($response['result']);

        return $response['result'];
    }

    public function test_the_error_envelope_carries_the_requests_own_id(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","id":"nine","method":"tools/call"}');

        self::assertArrayHasKey('request', $decoded);

        $response = self::server()->handle($decoded['request']);

        self::assertSame('nine', $response['id']);
        self::assertSame('2.0', $response['jsonrpc']);
    }
}
