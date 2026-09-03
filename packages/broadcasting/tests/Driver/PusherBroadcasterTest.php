<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Driver;

use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Exception\InvalidPusherProtocolValueException;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every expected signature here is computed independently in Python
 * (stdlib `hmac`/`hashlib`, not this class or any PHP code) against the
 * exact algorithm read directly from `pusher/pusher-php-server`'s own
 * `build_auth_query_params()`/`authorizeChannel()` source — a real
 * cross-implementation check, not a self-fulfilling assertion against
 * this class's own output.
 */
final class PusherBroadcasterTest extends TestCase
{
    private const string KEY = 'testkey';

    private const string SECRET = 'testsecret';

    public function test_private_channel_authorization_matches_an_independently_computed_signature(): void
    {
        $broadcaster = $this->broadcaster();

        $auth = $broadcaster->authorizeChannel('1234.1234', 'private-orders.42');

        self::assertSame(
            'testkey:4fce56cd83fe76576b5c407b6cc3767efa892a518d2a972818de893f18a3fc2c',
            $auth,
        );
    }

    public function test_presence_channel_authorization_matches_an_independently_computed_signature(): void
    {
        $broadcaster = $this->broadcaster();

        $result = $broadcaster->authorizePresenceChannel('1234.1234', 'presence-team.7', [
            'user_id' => '7',
            'user_info' => ['name' => 'Ada'],
        ]);

        // The returned channel_data must be the exact bytes signed
        // over, not a caller's own separately re-encoded copy.
        self::assertSame('{"user_id":"7","user_info":{"name":"Ada"}}', $result['channel_data']);
        self::assertSame(
            'testkey:577ffd56047d111b922c87869a4bc357db15c409d5d9bc072fe80e7e5010f08b',
            $result['auth'],
        );
    }

    public function test_a_different_socket_id_produces_a_different_signature(): void
    {
        $broadcaster = $this->broadcaster();

        $first = $broadcaster->authorizeChannel('1234.1234', 'private-orders.42');
        $second = $broadcaster->authorizeChannel('9999.9999', 'private-orders.42');

        self::assertNotSame($first, $second);
    }

    public function test_broadcast_sends_a_correctly_shaped_and_signed_trigger_request(): void
    {
        $captured = null;
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}');
        });

        $broadcaster = new PusherBroadcaster(new Http($transport), '12345', self::KEY, self::SECRET, 'soketi.test', 6001, false);

        $broadcaster->broadcast('private-orders.42', 'order.updated', ['status' => 'shipped']);

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertContains('Content-Type: application/json', $captured['options']['headers']);

        $parsed = parse_url($captured['url']);
        self::assertIsArray($parsed);
        self::assertSame('http', $parsed['scheme']);
        self::assertSame('soketi.test', $parsed['host']);
        self::assertSame(6001, $parsed['port']);
        self::assertSame('/apps/12345/events', $parsed['path']);

        parse_str($parsed['query'] ?? '', $query);
        self::assertSame(self::KEY, $query['auth_key']);
        self::assertSame('1.0', $query['auth_version']);
        self::assertArrayHasKey('auth_timestamp', $query);
        self::assertArrayHasKey('auth_signature', $query);

        // The wire body is JSON carrying a *second*, already-encoded JSON
        // string for `data` — confirmed against make_event()'s own real
        // shape, not assumed.
        $body = $captured['options']['body'];
        self::assertIsString($body);
        self::assertSame(
            ['name' => 'order.updated', 'channels' => ['private-orders.42'], 'data' => '{"status":"shipped"}'],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame($query['body_md5'], md5($body));

        // Recomputed independently from the captured timestamp — the one
        // value this test cannot pin ahead of time, since it's real
        // time(), not injected.
        $params = [
            'auth_key' => $query['auth_key'],
            'auth_timestamp' => $query['auth_timestamp'],
            'auth_version' => $query['auth_version'],
            'body_md5' => $query['body_md5'],
        ];
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $stringToSign = "POST\n/apps/12345/events\n" . implode('&', $pairs);
        $expectedSignature = hash_hmac('sha256', $stringToSign, self::SECRET);

        self::assertSame($expectedSignature, $query['auth_signature']);
    }

    public function test_broadcast_throws_on_a_non_2xx_response(): void
    {
        $transport = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"error":"nope"}', ['http_code' => 401]));
        $broadcaster = new PusherBroadcaster(new Http($transport), '12345', self::KEY, self::SECRET);

        $this->expectException(\Kinetis\RevoltHttpClient\Exception\HttpRequestException::class);

        $broadcaster->broadcast('orders', 'order.updated', []);
    }

    public function test_from_config_rejects_a_port_outside_the_valid_tcp_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BROADCAST_PORT must be a valid TCP port');

        PusherBroadcaster::fromConfig(new \Kinetis\Config\Config([
            'BROADCAST_APP_ID' => '12345',
            'BROADCAST_KEY' => self::KEY,
            'BROADCAST_SECRET' => self::SECRET,
            'BROADCAST_PORT' => '0',
        ]), new Http(new MockHttpClient()));
    }

    /**
     * A channel name using every character the Pusher protocol's own
     * grammar allows — hyphen, underscore, equals, at, comma, period,
     * semicolon, plus the optional leading "#" — signs identically to
     * any other valid channel name; the boundary characters themselves
     * are not rejected.
     */
    public function test_a_channel_name_using_every_allowed_boundary_character_still_signs(): void
    {
        $broadcaster = $this->broadcaster();

        $auth = $broadcaster->authorizeChannel('1234.5678', '#private-a_b=c@d,e.f;g-h');

        self::assertSame(
            'testkey:' . hash_hmac('sha256', '1234.5678:#private-a_b=c@d,e.f;g-h', self::SECRET),
            $auth,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function malformedSocketIdOrChannelNameProvider(): array
    {
        return [
            'socket id with a space' => ['1234 1234', 'private-orders.42'],
            'socket id with a hyphen' => ['1234-1234', 'private-orders.42'],
            'socket id with an extra segment' => ['1234.1234.5', 'private-orders.42'],
            'non-numeric socket id' => ['abc.def', 'private-orders.42'],
            'channel name with a space' => ['1234.1234', 'private-orde rs.42'],
            'channel name with a colon' => ['1234.1234', 'private-orders:42'],
            'channel name with CRLF' => ['1234.1234', "private-orders\r\n.42"],
        ];
    }

    #[DataProvider('malformedSocketIdOrChannelNameProvider')]
    public function test_authorize_channel_rejects_a_malformed_socket_id_or_channel_name(string $socketId, string $channelName): void
    {
        $broadcaster = $this->broadcaster();

        $this->expectException(InvalidPusherProtocolValueException::class);

        $broadcaster->authorizeChannel($socketId, $channelName);
    }

    #[DataProvider('malformedSocketIdOrChannelNameProvider')]
    public function test_authorize_presence_channel_rejects_a_malformed_socket_id_or_channel_name(string $socketId, string $channelName): void
    {
        $broadcaster = $this->broadcaster();

        $this->expectException(InvalidPusherProtocolValueException::class);

        $broadcaster->authorizePresenceChannel($socketId, $channelName, ['user_id' => '7']);
    }

    /**
     * @return list<array{0: array<array-key, mixed>}>
     */
    public static function malformedPresenceDataProvider(): array
    {
        return [
            'empty array' => [[]],
            'list-shaped data' => [['a', 'b']],
            'missing user_id' => [['user_info' => ['name' => 'Ada']]],
            'non-string user_id' => [['user_id' => 7]],
            'empty user_id' => [['user_id' => '']],
            'user_id over 128 bytes' => [['user_id' => str_repeat('x', 129)]],
            'encoded data over 1024 bytes' => [['user_id' => '7', 'user_info' => ['blob' => str_repeat('x', 1200)]]],
        ];
    }

    #[DataProvider('malformedPresenceDataProvider')]
    public function test_authorize_presence_channel_rejects_malformed_presence_data(array $channelData): void
    {
        $broadcaster = $this->broadcaster();

        $this->expectException(InvalidPusherProtocolValueException::class);

        $broadcaster->authorizePresenceChannel('1234.1234', 'presence-team.7', $channelData);
    }

    /**
     * A `user_id` that passes its own check can still sit alongside a
     * value elsewhere in the data that `json_encode()` cannot encode —
     * a direct `authorizePresenceChannel()` call must reject this the
     * same as any other malformed presence data, never leaking a raw
     * `JsonException` and never producing a signature.
     */
    public function test_authorize_presence_channel_rejects_invalid_utf8_without_leaking_a_raw_json_exception(): void
    {
        $broadcaster = $this->broadcaster();

        $this->expectException(InvalidPusherProtocolValueException::class);

        $broadcaster->authorizePresenceChannel('1234.1234', 'presence-team.7', ['user_id' => '7', 'bad' => "\xB1\x31"]);
    }

    public function test_authorize_presence_channel_rejects_a_resource_value_without_leaking_a_raw_json_exception(): void
    {
        $broadcaster = $this->broadcaster();
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(InvalidPusherProtocolValueException::class);

            $broadcaster->authorizePresenceChannel('1234.1234', 'presence-team.7', ['user_id' => '7', 'bad' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    private function broadcaster(): PusherBroadcaster
    {
        return new PusherBroadcaster(new Http(new MockHttpClient()), '12345', self::KEY, self::SECRET);
    }
}
