<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\Exception\InvalidPusherProtocolValueException;
use Kinetis\Broadcasting\PusherProtocol;
use PHPUnit\Framework\TestCase;

final class PusherProtocolTest extends TestCase
{
    public function test_a_socket_id_at_the_valid_shape_is_accepted(): void
    {
        self::assertTrue(PusherProtocol::isValidSocketId('1234.5678'));
    }

    public function test_a_socket_id_missing_the_second_segment_is_rejected(): void
    {
        self::assertFalse(PusherProtocol::isValidSocketId('1234'));
    }

    public function test_a_channel_name_with_every_allowed_character_is_accepted(): void
    {
        self::assertTrue(PusherProtocol::isValidChannelName('#private-a_b=c@d,e.f;g-h'));
    }

    public function test_a_channel_name_with_a_control_character_is_rejected(): void
    {
        self::assertFalse(PusherProtocol::isValidChannelName("private-orders\x00.42"));
    }

    public function test_assert_valid_channel_name_throws_for_an_invalid_value(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('channel_name must match');

        PusherProtocol::assertValidChannelName('bad channel');
    }

    public function test_assert_valid_socket_id_throws_for_an_invalid_value(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('socket_id must match');

        PusherProtocol::assertValidSocketId('not-a-socket-id');
    }

    /**
     * A `user_id` at exactly the 128-byte limit is still valid — only
     * strictly over the limit is rejected.
     */
    public function test_a_user_id_at_exactly_the_byte_limit_is_accepted(): void
    {
        $userId = str_repeat('x', 128);

        $json = PusherProtocol::assertValidPresenceData(['user_id' => $userId]);

        self::assertSame(json_encode(['user_id' => $userId], JSON_THROW_ON_ERROR), $json);
    }

    public function test_a_user_id_one_byte_over_the_limit_is_rejected(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('at most 128 bytes');

        PusherProtocol::assertValidPresenceData(['user_id' => str_repeat('x', 129)]);
    }

    public function test_a_missing_user_id_is_rejected(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);

        PusherProtocol::assertValidPresenceData(['user_info' => ['name' => 'Ada']]);
    }

    public function test_a_non_string_user_id_is_rejected(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);

        PusherProtocol::assertValidPresenceData(['user_id' => 7]);
    }

    public function test_an_empty_user_id_is_rejected(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);

        PusherProtocol::assertValidPresenceData(['user_id' => '']);
    }

    /**
     * A list — `[]` included — has no string keys at all, so it can
     * never carry a `user_id`; the missing-user_id check alone is what
     * rejects list-shaped data, with no separate shape check needed.
     */
    public function test_list_shaped_data_is_rejected(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);

        PusherProtocol::assertValidPresenceData(['a', 'b']);
    }

    /**
     * Encoded channel data at exactly the 1024-byte limit is still
     * valid — computed by padding to exactly that size rather than
     * hardcoding a byte count, so the test fails loudly if the padding
     * arithmetic itself is ever wrong.
     */
    public function test_encoded_channel_data_at_exactly_the_byte_limit_is_accepted(): void
    {
        $overhead = strlen(json_encode(['user_id' => '7', 'pad' => ''], JSON_THROW_ON_ERROR));
        $data = ['user_id' => '7', 'pad' => str_repeat('x', 1024 - $overhead)];

        $json = PusherProtocol::assertValidPresenceData($data);

        self::assertSame(1024, strlen($json));
    }

    public function test_encoded_channel_data_one_byte_over_the_limit_is_rejected(): void
    {
        $overhead = strlen(json_encode(['user_id' => '7', 'pad' => ''], JSON_THROW_ON_ERROR));
        $data = ['user_id' => '7', 'pad' => str_repeat('x', 1024 - $overhead + 1)];

        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('at most 1024 bytes');

        PusherProtocol::assertValidPresenceData($data);
    }

    /**
     * A `user_id` that passes its own check can still sit alongside a
     * value elsewhere in the array that `json_encode()` itself cannot
     * encode — invalid UTF-8 here, a resource and recursive data in the
     * two tests below — and none of those may escape as a raw
     * `JsonException`; each is reclassified into this class's own
     * exception type instead.
     */
    public function test_invalid_utf8_is_rejected_without_leaking_a_raw_json_exception(): void
    {
        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('could not be encoded');

        PusherProtocol::assertValidPresenceData(['user_id' => '7', 'bad' => "\xB1\x31"]);
    }

    public function test_a_resource_value_is_rejected_without_leaking_a_raw_json_exception(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(InvalidPusherProtocolValueException::class);
            $this->expectExceptionMessage('could not be encoded');

            PusherProtocol::assertValidPresenceData(['user_id' => '7', 'bad' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function test_recursive_data_is_rejected_without_leaking_a_raw_json_exception(): void
    {
        $data = ['user_id' => '7'];
        $data['self'] = &$data;

        $this->expectException(InvalidPusherProtocolValueException::class);
        $this->expectExceptionMessage('could not be encoded');

        PusherProtocol::assertValidPresenceData($data);
    }

    /**
     * The original `JsonException` is preserved as `previous`, useful
     * for diagnostics, but never surfaces in the public message.
     */
    public function test_the_original_json_exception_is_preserved_as_previous(): void
    {
        try {
            PusherProtocol::assertValidPresenceData(['user_id' => '7', 'bad' => NAN]);

            self::fail('Expected InvalidPusherProtocolValueException.');
        } catch (InvalidPusherProtocolValueException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}
