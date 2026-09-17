<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Tests;

use Kinetis\McpProtocol\JsonObject;
use Kinetis\McpProtocol\JsonRpcCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The envelope rules every transport shares, and the object-versus-list
 * fidelity the rest of the protocol depends on.
 */
final class JsonRpcCodecTest extends TestCase
{
    public function test_malformed_json_is_a_parse_error_under_a_null_id(): void
    {
        $decoded = JsonRpcCodec::decode('{"jsonrpc":');

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']],
            $decoded['errorResponse'],
        );
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_an_invalid_envelope_is_rejected(string $raw, string|int|null $expectedId): void
    {
        $decoded = JsonRpcCodec::decode($raw);

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32600, $decoded['errorResponse']['error']['code']);
        self::assertSame($expectedId, $decoded['errorResponse']['id']);
    }

    /**
     * @return array<string, array{string, string|int|null}>
     */
    public static function invalidEnvelopes(): array
    {
        return [
            'a scalar' => ['42', null],
            'a bare string' => ['"ping"', null],
            'null' => ['null', null],
            'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]', null],
            'the wrong jsonrpc version' => ['{"jsonrpc":"1.0","id":3,"method":"ping"}', 3],
            'a missing method' => ['{"jsonrpc":"2.0","id":3}', 3],
            'an empty method' => ['{"jsonrpc":"2.0","id":3,"method":""}', 3],
            'a non-string method' => ['{"jsonrpc":"2.0","id":3,"method":5}', 3],
            'a null id' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}', null],
            'a boolean id' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}', null],
            'a float id' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}', null],
            'an object id' => ['{"jsonrpc":"2.0","id":{},"method":"ping"}', null],
            'a response carrying both result and error' => ['{"jsonrpc":"2.0","id":1,"result":{},"error":{}}', 1],
            'a response carrying neither' => ['{"jsonrpc":"2.0","id":1}', 1],
        ];
    }

    #[DataProvider('ignoredMessages')]
    public function test_a_message_this_server_neither_dispatches_nor_answers_is_ignored(string $raw): void
    {
        self::assertSame(['ignored' => true], JsonRpcCodec::decode($raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ignoredMessages(): array
    {
        return [
            'a client result' => ['{"jsonrpc":"2.0","id":1,"result":{"ok":true}}'],
            'a client error' => ['{"jsonrpc":"2.0","id":"a","error":{"code":-1,"message":"no"}}'],
            'a notification with malformed params' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":[]}'],
        ];
    }

    public function test_a_request_with_malformed_params_is_answered(): void
    {
        $decoded = JsonRpcCodec::decode('{"jsonrpc":"2.0","id":1,"method":"ping","params":[]}');

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32602, $decoded['errorResponse']['error']['code']);
    }

    public function test_a_decoded_message_keeps_its_params_unflattened(): void
    {
        $decoded = JsonRpcCodec::decode('{"jsonrpc":"2.0","id":1,"method":"ping","params":{"a":{},"b":[]}}');

        self::assertArrayHasKey('message', $decoded);
        $params = $decoded['message']['params'];
        self::assertInstanceOf(stdClass::class, $params);
        self::assertInstanceOf(stdClass::class, $params->a);
        self::assertSame([], $params->b);
    }

    public function test_an_empty_object_and_an_empty_list_are_not_the_same_value(): void
    {
        self::assertTrue(JsonRpcCodec::isJsonObject(new stdClass()));
        self::assertTrue(JsonRpcCodec::isJsonObject(new JsonObject()));
        self::assertTrue(JsonRpcCodec::isJsonObject(['a' => 1]));
        self::assertFalse(JsonRpcCodec::isJsonObject([]));
        self::assertFalse(JsonRpcCodec::isJsonObject(['a', 'b']));
        self::assertFalse(JsonRpcCodec::isJsonObject(null));
        self::assertFalse(JsonRpcCodec::isJsonObject('{}'));
    }

    public function test_the_object_tree_conversion_restores_the_wire_shape_at_every_depth(): void
    {
        $converted = JsonRpcCodec::toObjectTree(new JsonObject([
            'empty' => new JsonObject(),
            'list' => [new JsonObject(['deep' => 1]), []],
            'scalar' => 'text',
        ]));

        self::assertSame(
            '{"empty":{},"list":[{"deep":1},[]],"scalar":"text"}',
            json_encode($converted, JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_present_but_null_member_is_still_present(): void
    {
        $decoded = json_decode('{"params":null}');

        self::assertTrue(JsonRpcCodec::objectHas($decoded, 'params'));
        self::assertTrue(JsonRpcCodec::objectHas(['params' => null], 'params'));
        self::assertTrue(JsonRpcCodec::objectHas(new JsonObject(['params' => null]), 'params'));
        self::assertFalse(JsonRpcCodec::objectHas($decoded, 'other'));
        self::assertFalse(JsonRpcCodec::objectHas('not a node', 'params'));
        self::assertNull(JsonRpcCodec::objectGet('not a node', 'params'));
    }

    public function test_the_id_domain_excludes_null(): void
    {
        self::assertTrue(JsonRpcCodec::isValidId('a'));
        self::assertTrue(JsonRpcCodec::isValidId(0));
        self::assertFalse(JsonRpcCodec::isValidId(null));
        self::assertFalse(JsonRpcCodec::isValidId(1.0));
        self::assertFalse(JsonRpcCodec::isValidId(true));
    }
}
