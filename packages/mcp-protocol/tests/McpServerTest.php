<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Tests;

use Kinetis\McpProtocol\JsonObject;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\Tests\Fixtures\ProbeApplication;
use Kinetis\McpProtocol\ToolDescription;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The 2025-06-18 wire contract, asserted on the encoded frame wherever a
 * JSON type is part of the claim: `{}` and `[]` are the same PHP array
 * once decoded associatively, so only the encoded bytes can show which
 * one the server actually sent.
 */
final class McpServerTest extends TestCase
{
    public function test_initialize_selects_this_revision_for_a_client_that_asked_for_another(): void
    {
        $response = self::server()->handle(self::request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new JsonObject(['roots' => new JsonObject(['listChanged' => true])]),
            'clientInfo' => new JsonObject(['name' => 'claude-code', 'version' => '2.1.273']),
        ]));

        self::assertNotNull($response);
        self::assertSame('2025-06-18', $response['result']['protocolVersion']);
        self::assertSame(['name' => 'kinetis-probe', 'version' => '9.9.9'], $response['result']['serverInfo']);
    }

    public function test_initialize_is_deterministic_and_carries_no_state_into_the_next_one(): void
    {
        $server = self::server();
        $params = [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new JsonObject(),
            'clientInfo' => new JsonObject(['name' => 'codex-mcp-client', 'version' => '0.154.0']),
        ];

        $first = $server->handle(self::request('initialize', $params));
        $second = $server->handle(self::request('initialize', $params, 7));

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(
            json_encode($first['result'], JSON_THROW_ON_ERROR),
            json_encode($second['result'], JSON_THROW_ON_ERROR),
        );
    }

    public function test_initialize_advertises_only_the_features_the_consumer_publishes(): void
    {
        $resourcesOnly = new McpServer(
            new ServerInfo('docs', '1.0.0'),
            new ProbeApplication(tools: [], resources: [
                new ResourceDescription('probe://text', 'Text', 'A page.', 'text/markdown'),
            ]),
        );

        $frame = json_encode($resourcesOnly->handle(self::initialize()), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"capabilities":{"resources":{}}', $frame);
    }

    public function test_initialize_advertises_no_capability_object_at_all_when_nothing_is_published(): void
    {
        $empty = new McpServer(
            new ServerInfo('empty', '1.0.0'),
            new ProbeApplication(tools: [], resources: []),
        );

        $frame = json_encode($empty->handle(self::initialize()), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"capabilities":{}', $frame);
    }

    public function test_initialize_carries_instructions_only_when_the_consumer_gave_some(): void
    {
        $withInstructions = new McpServer(
            new ServerInfo('probe', '1.0.0', 'Read the docs first.'),
            new ProbeApplication(),
        );

        $response = $withInstructions->handle(self::initialize());
        $without = self::server()->handle(self::initialize());

        self::assertNotNull($response);
        self::assertNotNull($without);
        self::assertSame('Read the docs first.', $response['result']['instructions']);
        self::assertArrayNotHasKey('instructions', $without['result']);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedInitializeParams')]
    public function test_initialize_rejects_a_malformed_handshake(array $params): void
    {
        $response = self::server()->handle(self::request('initialize', $params));

        self::assertNotNull($response);
        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedInitializeParams(): array
    {
        $info = new JsonObject(['name' => 'c', 'version' => '1']);

        return [
            'no version' => [['capabilities' => new JsonObject(), 'clientInfo' => $info]],
            'empty version' => [['protocolVersion' => '', 'capabilities' => new JsonObject(), 'clientInfo' => $info]],
            'non-string version' => [['protocolVersion' => 1, 'capabilities' => new JsonObject(), 'clientInfo' => $info]],
            'no capabilities' => [['protocolVersion' => '2025-06-18', 'clientInfo' => $info]],
            'list capabilities' => [['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => $info]],
            'no clientInfo' => [['protocolVersion' => '2025-06-18', 'capabilities' => new JsonObject()]],
            'clientInfo without a name' => [[
                'protocolVersion' => '2025-06-18',
                'capabilities' => new JsonObject(),
                'clientInfo' => new JsonObject(['version' => '1']),
            ]],
            'clientInfo with an empty version' => [[
                'protocolVersion' => '2025-06-18',
                'capabilities' => new JsonObject(),
                'clientInfo' => new JsonObject(['name' => 'c', 'version' => '']),
            ]],
        ];
    }

    public function test_ping_answers_an_empty_json_object(): void
    {
        $frame = json_encode(self::server()->handle(self::request('ping')), JSON_THROW_ON_ERROR);

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $frame);
    }

    public function test_a_list_is_answered_before_initialize(): void
    {
        $response = self::server()->handle(self::request('tools/list'));

        self::assertNotNull($response);
        self::assertCount(12, $response['result']['tools']);
    }

    public function test_tools_list_publishes_schemas_as_objects_and_annotations_only_where_declared(): void
    {
        $frame = json_encode(self::server()->handle(self::request('tools/list')), JSON_THROW_ON_ERROR);

        // The `progress` tool declares an empty schema array, which must
        // still reach the wire as a JSON object.
        self::assertStringContainsString('"name":"progress","description":"Reports progress, then finishes.","inputSchema":{}', $frame);
        self::assertStringContainsString(
            '"annotations":{"readOnlyHint":false,"destructiveHint":true,"idempotentHint":false,"openWorldHint":false}',
            $frame,
        );
        self::assertSame(1, substr_count($frame, '"annotations"'));
    }

    public function test_a_list_rejects_a_cursor_it_never_issued(): void
    {
        foreach (['tools/list', 'resources/list'] as $method) {
            $response = self::server()->handle(self::request($method, ['cursor' => 'abc']));

            self::assertNotNull($response);
            self::assertSame(-32602, $response['error']['code'], $method);
        }
    }

    public function test_a_tool_call_keeps_its_arguments_object_and_list_shapes(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);

        $decoded = json_decode(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":'
            . '{"object":{},"list":[],"nested":{"keys":{"0":"a","1":"b"},"array":["a","b"]}}}}',
        );
        self::assertInstanceOf(stdClass::class, $decoded);

        $response = $server->handle((array) $decoded);

        self::assertNotNull($response);
        self::assertFalse($response['result']['isError']);
        self::assertSame(
            '{"object":{},"list":[],"nested":{"keys":{"0":"a","1":"b"},"array":["a","b"]}}',
            $response['result']['content'][0]['text'],
        );
    }

    public function test_a_directly_built_empty_object_argument_is_not_a_list(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);

        $response = $server->handle(self::request('tools/call', [
            'name' => 'echo',
            'arguments' => new JsonObject(['inner' => new JsonObject()]),
        ]));

        self::assertNotNull($response);
        self::assertSame('{"inner":{}}', $response['result']['content'][0]['text']);
        self::assertInstanceOf(stdClass::class, $application->calls[0]['arguments']);
    }

    public function test_an_absent_arguments_member_becomes_an_empty_object(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);

        $response = $server->handle(self::request('tools/call', ['name' => 'echo']));

        self::assertNotNull($response);
        self::assertSame('{}', $response['result']['content'][0]['text']);
        self::assertEquals(new stdClass(), $application->calls[0]['arguments']);
    }

    public function test_a_present_non_object_arguments_member_is_rejected(): void
    {
        $response = self::server()->handle(self::request('tools/call', ['name' => 'echo', 'arguments' => []]));

        self::assertNotNull($response);
        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_tool_that_ran_and_refused_is_a_result_not_a_transport_error(): void
    {
        $response = self::server()->handle(self::request('tools/call', ['name' => 'refuse']));

        self::assertNotNull($response);
        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('Refused.', $response['result']['content'][0]['text']);
    }

    public function test_a_text_result_carries_no_structured_content(): void
    {
        $success = self::server()->handle(self::request('tools/call', ['name' => 'echo']));
        $refusal = self::server()->handle(self::request('tools/call', ['name' => 'refuse']));

        self::assertNotNull($success);
        self::assertNotNull($refusal);
        self::assertArrayNotHasKey('structuredContent', $success['result']);
        self::assertArrayNotHasKey('structuredContent', $refusal['result']);
    }

    /**
     * The object travels beside the text block, never instead of it: a
     * client that reads no structured output still gets the same
     * conclusion, and `isError` is the one the tool chose.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('structuredResults')]
    public function test_a_structured_result_carries_the_object_beside_the_unchanged_text(
        string $tool,
        string $expectedResult,
    ): void {
        $response = self::server()->handle(self::request('tools/call', ['name' => $tool]));

        self::assertNotNull($response);
        self::assertSame($expectedResult, json_encode($response['result'], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function structuredResults(): array
    {
        return [
            'a success' => [
                'structured',
                '{"content":[{"type":"text","text":"{\"status\":\"ok\",\"matches\":[]}"}],"isError":false,'
                . '"structuredContent":{"status":"ok","matches":[]}}',
            ],
            'a refusal' => [
                'structured_refusal',
                '{"content":[{"type":"text","text":"{\"status\":\"error\",\"code\":\"probe_refused\"}"}],'
                . '"isError":true,"structuredContent":{"status":"error","code":"probe_refused"}}',
            ],
            'an empty document, still an object' => [
                'structured_empty',
                '{"content":[{"type":"text","text":"{}"}],"isError":false,"structuredContent":{}}',
            ],
        ];
    }

    public function test_a_consumer_protocol_error_reaches_the_client_and_an_unexpected_one_does_not(): void
    {
        $server = self::server();

        $protocol = $server->handle(self::request('tools/call', ['name' => 'protocol_failure']));
        $unexpected = $server->handle(self::request('tools/call', ['name' => 'explode']));

        self::assertNotNull($protocol);
        self::assertNotNull($unexpected);
        self::assertSame(-32602, $protocol['error']['code']);
        self::assertSame('The tool says these params are wrong.', $protocol['error']['message']);
        self::assertSame(-32603, $unexpected['error']['code']);
        self::assertSame('Internal error.', $unexpected['error']['message']);
        self::assertStringNotContainsString('passwd', json_encode($unexpected, JSON_THROW_ON_ERROR));
    }

    /**
     * A consumer chooses the bytes in a result, a resource's text and a
     * protocol error's own message, and any of them can be a valid PHP
     * string JSON refuses. Encoding those where the frame is written ends
     * a persistent process on one bad message, so every envelope is
     * settled here instead — as the generic internal error, never the
     * bytes and never a repaired version of them.
     *
     * @param array<string, mixed> $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unencodableResponses')]
    public function test_a_response_that_cannot_be_encoded_becomes_the_generic_internal_error(array $message): void
    {
        $response = self::server()->handle($message);

        self::assertNotNull($response);
        self::assertSame(3, $response['id']);
        self::assertSame(-32603, $response['error']['code']);
        self::assertSame('Internal error.', $response['error']['message']);

        $frame = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-marker', $frame);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unencodableResponses(): array
    {
        return [
            'a tool result' => [self::request('tools/call', ['name' => 'unencodable'], 3)],
            'a structured tool result' => [self::request('tools/call', ['name' => 'structured_unencodable'], 3)],
            'a protocol error a tool raised' => [self::request('tools/call', ['name' => 'unencodable_error'], 3)],
            'a resource read' => [self::request('resources/read', ['uri' => 'probe://unencodable'], 3)],
        ];
    }

    /**
     * The one case the generic error cannot survive on its own: an id is
     * echoed verbatim, and a caller building a message in PHP can supply
     * one JSON refuses. A decoded message never can — JSON input is valid
     * UTF-8 by definition — so this is reachable only from a direct
     * caller, and it answers under a null id rather than a frame nothing
     * can write.
     */
    public function test_an_unencodable_request_id_still_produces_a_sendable_frame(): void
    {
        $response = self::server()->handle([
            'jsonrpc' => '2.0',
            'id' => "\xFF",
            'method' => 'tools/call',
            'params' => new JsonObject(['name' => 'unencodable']),
        ]);

        self::assertNotNull($response);
        self::assertNull($response['id']);
        self::assertSame(-32603, $response['error']['code']);
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error."}}',
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The structural check answers before any consumer is reached, and
     * its envelope echoes the id it could read — so that path needs the
     * same guard the dispatched ones have. A direct caller can supply an
     * id that is a valid PHP string and not encodable JSON, and the
     * rejection would otherwise carry those bytes to a transport that
     * throws on them.
     */
    public function test_a_rejected_envelope_carrying_an_unencodable_id_is_still_sendable(): void
    {
        $response = self::server()->handle([
            'jsonrpc' => '1.0',
            'id' => "\xFF secret-marker",
            'method' => 'ping',
        ]);

        self::assertNotNull($response);
        self::assertNull($response['id']);
        self::assertSame(-32603, $response['error']['code']);
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error."}}',
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    public function test_an_unknown_tool_and_an_unknown_resource_use_their_own_codes(): void
    {
        $server = self::server();

        $tool = $server->handle(self::request('tools/call', ['name' => 'nope']));
        $resource = $server->handle(self::request('resources/read', ['uri' => 'probe://nope']));

        self::assertNotNull($tool);
        self::assertNotNull($resource);
        self::assertSame(-32602, $tool['error']['code']);
        self::assertSame(-32002, $resource['error']['code']);
        self::assertSame(['uri' => 'probe://nope'], $resource['error']['data']);
    }

    public function test_an_unknown_name_never_reaches_the_consumer(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);

        $server->handle(self::request('tools/call', ['name' => 'nope']));
        $server->handle(self::request('resources/read', ['uri' => 'probe://nope']));

        self::assertSame([], $application->calls);
        self::assertSame([], $application->reads);
    }

    public function test_an_unknown_method_is_method_not_found(): void
    {
        $response = self::server()->handle(self::request('prompts/list'));

        self::assertNotNull($response);
        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_resources_read_returns_typed_text_contents(): void
    {
        $response = self::server()->handle(self::request('resources/read', ['uri' => 'probe://text']));

        self::assertNotNull($response);
        self::assertSame(
            [['uri' => 'probe://text', 'mimeType' => 'text/markdown', 'text' => "# Probe\n"]],
            $response['result']['contents'],
        );
    }

    public function test_progress_is_emitted_in_order_with_optional_members_omitted(): void
    {
        $emitted = [];
        $emit = static function (array $notification) use (&$emitted): void {
            $emitted[] = $notification;
        };

        $response = self::server()->handle(
            self::request('tools/call', ['name' => 'progress', '_meta' => new JsonObject(['progressToken' => 'p1'])]),
            $emit,
        );

        self::assertNotNull($response);
        self::assertSame([
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                'progressToken' => 'p1',
                'progress' => 1,
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                'progressToken' => 'p1',
                'progress' => 2,
                'total' => 10,
                'message' => 'halfway',
            ]],
        ], $emitted);
    }

    public function test_a_call_without_a_token_emits_nothing(): void
    {
        $emitted = [];
        $emit = static function (array $notification) use (&$emitted): void {
            $emitted[] = $notification;
        };

        self::server()->handle(self::request('tools/call', ['name' => 'progress']), $emit);

        self::assertSame([], $emitted);
    }

    public function test_a_present_progress_token_of_the_wrong_type_is_rejected(): void
    {
        $response = self::server()->handle(self::request('tools/call', [
            'name' => 'progress',
            '_meta' => new JsonObject(['progressToken' => 1.5]),
        ]));

        self::assertNotNull($response);
        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_the_opaque_context_reaches_the_consumer_untouched(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);
        $context = new stdClass();

        $server->handle(self::request('tools/call', ['name' => 'echo']), null, $context);
        $server->handle(self::request('resources/read', ['uri' => 'probe://text']), null, $context);

        self::assertSame($context, $application->calls[0]['context']);
        self::assertSame($context, $application->reads[0]['context']);
    }

    public function test_the_server_retains_nothing_between_messages(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);
        $first = new stdClass();
        $second = new stdClass();

        $server->handle(self::request('tools/call', ['name' => 'echo', 'arguments' => new JsonObject(['a' => 1])]), null, $first);
        $server->handle(self::request('tools/call', ['name' => 'echo'], 2), null, $second);

        self::assertSame($first, $application->calls[0]['context']);
        self::assertSame($second, $application->calls[1]['context']);
        self::assertEquals(new stdClass(), $application->calls[1]['arguments']);
    }

    public function test_a_notification_is_never_answered_and_never_invokes_a_tool(): void
    {
        $application = new ProbeApplication();
        $server = new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), $application);

        foreach ([
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => new JsonObject(['requestId' => 1])],
            ['jsonrpc' => '2.0', 'method' => 'unknown/notification'],
            ['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => new JsonObject(['name' => 'echo'])],
        ] as $notification) {
            self::assertNull($server->handle($notification));
        }

        self::assertSame([], $application->calls);
    }

    public function test_a_client_response_message_is_ignored_rather_than_answered(): void
    {
        $server = self::server();

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'id' => 4, 'result' => new JsonObject()]));
        self::assertNull($server->handle(['jsonrpc' => '2.0', 'id' => 4, 'error' => new JsonObject(['code' => -1])]));
    }

    public function test_a_malformed_envelope_is_answered_even_without_an_id(): void
    {
        $response = self::server()->handle(['jsonrpc' => '1.0', 'method' => 'ping']);

        self::assertNotNull($response);
        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    public function test_a_null_request_id_is_not_a_request_this_revision_admits(): void
    {
        $response = self::server()->handle(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping']);

        self::assertNotNull($response);
        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    private static function server(): McpServer
    {
        return new McpServer(new ServerInfo('kinetis-probe', '9.9.9'), new ProbeApplication());
    }

    /**
     * @return array<string, mixed>
     */
    private static function initialize(): array
    {
        return self::request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new JsonObject(),
            'clientInfo' => new JsonObject(['name' => 'probe', 'version' => '1.0']),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function request(string $method, array $params = [], string|int $id = 1): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            ...($params === [] ? [] : ['params' => new JsonObject($params)]),
        ];
    }
}
