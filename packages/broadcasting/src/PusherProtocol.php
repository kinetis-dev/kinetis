<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use JsonException;
use Kinetis\Broadcasting\Exception\InvalidPusherProtocolValueException;

/**
 * The one place this package validates a value against the Pusher
 * Channels wire protocol's own grammar — used by both
 * `Kinetis\Broadcasting\Http\BroadcastAuthController` (rejecting a
 * malformed client request with a `422` before any application
 * authorizer runs) and `Kinetis\Broadcasting\Driver\PusherBroadcaster`'s
 * own public signing methods (rejecting a malformed direct call with a
 * thrown `InvalidPusherProtocolValueException`), so a caller reaching
 * either boundary is held to the identical rule regardless of which one
 * it goes through.
 *
 * `CHANNEL_NAME_PATTERN`/`SOCKET_ID_PATTERN` are copied verbatim from
 * `pusher/pusher-php-server`'s own validation, not reconstructed from
 * documentation — the same "check the real source" discipline this
 * package's own HMAC signing algorithm was verified against. Both
 * `$channelName` checks apply to the **full** channel name, including
 * its `private-`/`presence-` prefix — that's what the real Pusher
 * protocol validates against, and it's also the exact string this
 * package signs over.
 */
final class PusherProtocol
{
    private const string CHANNEL_NAME_PATTERN = '/\A#?[-a-zA-Z0-9_=@,.;]+\z/';

    private const string SOCKET_ID_PATTERN = '/\A\d+\.\d+\z/';

    private const int MAX_PRESENCE_USER_ID_BYTES = 128;

    private const int MAX_PRESENCE_CHANNEL_DATA_BYTES = 1024;

    public static function isValidChannelName(string $channelName): bool
    {
        return preg_match(self::CHANNEL_NAME_PATTERN, $channelName) === 1;
    }

    public static function isValidSocketId(string $socketId): bool
    {
        return preg_match(self::SOCKET_ID_PATTERN, $socketId) === 1;
    }

    public static function assertValidChannelName(string $channelName): void
    {
        if (!self::isValidChannelName($channelName)) {
            throw InvalidPusherProtocolValueException::invalidChannelName();
        }
    }

    public static function assertValidSocketId(string $socketId): void
    {
        if (!self::isValidSocketId($socketId)) {
            throw InvalidPusherProtocolValueException::invalidSocketId();
        }
    }

    /**
     * Validates a presence channel's authorization data and returns the
     * exact JSON bytes it must be signed over and returned to the
     * client as `channel_data` — one canonical encoding, so a caller
     * can never sign over a different byte sequence than the one it
     * sends back (a client-side signature verification failure that
     * would otherwise be silent and confusing to diagnose).
     *
     * A `user_id` that's missing, not a string, empty, or over 128
     * bytes is rejected — this also rejects a list-shaped `$channelData`
     * outright, since a list has no string keys and so can never carry
     * one. `user_info` and any other key are passed through unvalidated
     * and unrequired, per the protocol's own "conventional, not
     * required" treatment of everything beyond `user_id` — but every
     * value anywhere in `$channelData` must still be genuinely
     * JSON-encodable: invalid UTF-8, a resource, a recursive structure,
     * or `NAN`/`INF` all make `json_encode()` itself throw, which is
     * caught here and reclassified into this class's own exception
     * type, never left to escape as a raw `JsonException` a caller of
     * this method has no reason to expect or catch for.
     *
     * @param array<array-key, mixed> $channelData
     */
    public static function assertValidPresenceData(array $channelData): string
    {
        $userId = $channelData['user_id'] ?? null;

        if (!is_string($userId) || $userId === '' || strlen($userId) > self::MAX_PRESENCE_USER_ID_BYTES) {
            throw InvalidPusherProtocolValueException::invalidPresenceUserId();
        }

        try {
            $json = json_encode($channelData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidPusherProtocolValueException::presenceDataNotEncodable($e);
        }

        if (strlen($json) > self::MAX_PRESENCE_CHANNEL_DATA_BYTES) {
            throw InvalidPusherProtocolValueException::presenceDataTooLarge();
        }

        return $json;
    }
}
