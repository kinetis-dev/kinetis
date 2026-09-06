<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use Kinetis\McpDocs\JsonRpcEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonRpcEnvelopeTest extends TestCase
{
    public function test_malformed_json_is_a_parse_error_under_a_null_id(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":');

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32700, $decoded['errorResponse']['error']['code']);
        self::assertNull($decoded['errorResponse']['id']);
    }

    public static function nonObjectProvider(): iterable
    {
        yield 'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'];
        yield 'an empty batch' => ['[]'];
        yield 'a bare string' => ['"ping"'];
        yield 'a bare number' => ['7'];
        yield 'null' => ['null'];
    }

    #[DataProvider('nonObjectProvider')]
    public function test_json_that_is_not_an_object_is_an_invalid_request(string $raw): void
    {
        $decoded = JsonRpcEnvelope::decode($raw);

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32600, $decoded['errorResponse']['error']['code']);
        self::assertNull($decoded['errorResponse']['id']);
    }

    public static function malformedEnvelopeProvider(): iterable
    {
        yield 'no jsonrpc member' => ['{"id":1,"method":"ping"}'];
        yield 'the wrong jsonrpc version' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}'];
        yield 'no method member' => ['{"jsonrpc":"2.0","id":1}'];
        yield 'a non-string method' => ['{"jsonrpc":"2.0","id":1,"method":42}'];
    }

    #[DataProvider('malformedEnvelopeProvider')]
    public function test_a_malformed_envelope_is_an_invalid_request_that_still_echoes_a_usable_id(string $raw): void
    {
        $decoded = JsonRpcEnvelope::decode($raw);

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32600, $decoded['errorResponse']['error']['code']);
        self::assertSame(1, $decoded['errorResponse']['id']);
    }

    public static function unusableIdProvider(): iterable
    {
        yield 'a float' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}'];
        yield 'a boolean' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}'];
        yield 'an object' => ['{"jsonrpc":"2.0","id":{},"method":"ping"}'];
        yield 'an array' => ['{"jsonrpc":"2.0","id":[],"method":"ping"}'];
    }

    #[DataProvider('unusableIdProvider')]
    public function test_an_id_outside_the_supported_domain_is_answered_under_a_null_id(string $raw): void
    {
        $decoded = JsonRpcEnvelope::decode($raw);

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32600, $decoded['errorResponse']['error']['code']);
        self::assertNull($decoded['errorResponse']['id']);
    }

    public static function malformedParamsProvider(): iterable
    {
        yield 'an array' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":[1,2]}'];
        yield 'a string' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":"nope"}'];
        yield 'an explicit null' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":null}'];
    }

    #[DataProvider('malformedParamsProvider')]
    public function test_params_that_are_not_an_object_are_invalid_params(string $raw): void
    {
        $decoded = JsonRpcEnvelope::decode($raw);

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32602, $decoded['errorResponse']['error']['code']);
        self::assertSame(1, $decoded['errorResponse']['id']);
    }

    public function test_a_valid_request_decodes_with_its_id_and_params(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","id":"abc","method":"resources/read","params":{"uri":"x"}}');

        self::assertArrayHasKey('request', $decoded);

        $request = $decoded['request'];

        self::assertSame('resources/read', $request->method);
        self::assertTrue($request->isRequest);
        self::assertSame('abc', $request->id);
        self::assertSame('x', $request->params?->uri);
    }

    public function test_a_message_without_an_id_decodes_as_a_notification(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        self::assertArrayHasKey('request', $decoded);
        self::assertFalse($decoded['request']->isRequest);
        self::assertNull($decoded['request']->params);
    }

    public static function notificationWithMalformedParamsProvider(): iterable
    {
        yield 'an array' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":[1,2]}'];
        yield 'an empty array' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":[]}'];
        yield 'a string' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":"nope"}'];
        yield 'a number' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":7}'];
        yield 'a boolean' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":true}'];
        yield 'an explicit null' => ['{"jsonrpc":"2.0","method":"notifications/initialized","params":null}'];
    }

    #[DataProvider('notificationWithMalformedParamsProvider')]
    public function test_params_this_server_cannot_use_still_decode_a_notification_as_one(string $raw): void
    {
        $decoded = JsonRpcEnvelope::decode($raw);

        self::assertArrayHasKey('request', $decoded);
        self::assertFalse($decoded['request']->isRequest);
        self::assertSame('notifications/initialized', $decoded['request']->method);
        self::assertNull($decoded['request']->id);
        self::assertNull($decoded['request']->params);
    }

    public function test_a_notification_naming_an_unknown_method_decodes_rather_than_erroring(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","method":"notifications/cancelled","params":[]}');

        self::assertArrayHasKey('request', $decoded);
        self::assertFalse($decoded['request']->isRequest);
        self::assertSame('notifications/cancelled', $decoded['request']->method);
    }

    public function test_a_notification_keeps_params_it_carries_as_an_object(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","method":"notifications/initialized","params":{"a":1}}');

        self::assertArrayHasKey('request', $decoded);
        self::assertFalse($decoded['request']->isRequest);
        self::assertSame(1, $decoded['request']->params?->a);
    }

    public function test_a_present_null_id_with_unusable_params_is_still_invalid_params(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","id":null,"method":"ping","params":[]}');

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32602, $decoded['errorResponse']['error']['code']);
        self::assertNull($decoded['errorResponse']['id']);
    }

    public function test_an_envelope_that_cannot_be_read_is_answered_even_without_an_id(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","method":42,"params":[]}');

        self::assertArrayHasKey('errorResponse', $decoded);
        self::assertSame(-32600, $decoded['errorResponse']['error']['code']);
        self::assertNull($decoded['errorResponse']['id']);
    }

    public function test_an_explicit_null_id_is_a_request_rather_than_a_notification(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","id":null,"method":"ping"}');

        self::assertArrayHasKey('request', $decoded);
        self::assertTrue($decoded['request']->isRequest);
        self::assertNull($decoded['request']->id);
    }

    public function test_an_empty_params_object_stays_an_object(): void
    {
        $decoded = JsonRpcEnvelope::decode('{"jsonrpc":"2.0","id":1,"method":"resources/list","params":{}}');

        self::assertArrayHasKey('request', $decoded);
        self::assertNotNull($decoded['request']->params);
        self::assertSame([], (array) $decoded['request']->params);
    }
}
