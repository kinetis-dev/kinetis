<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * Thrown by {@see \Kinetis\Broadcasting\PusherProtocol}'s `assert*()`
 * methods — never echoes the value that failed validation, since a
 * socket ID, channel name, or presence identity is caller-supplied data
 * that can legitimately be a secret an application chose to leak into
 * one of these fields by mistake.
 */
final class InvalidPusherProtocolValueException extends InvalidArgumentException
{
    public static function invalidChannelName(): self
    {
        return new self(
            'channel_name must match the Pusher protocol channel-name grammar: '
                . 'an optional leading "#", then one or more of "-", "a"-"z", "A"-"Z", "0"-"9", '
                . '"_", "=", "@", ",", ".", ";".',
        );
    }

    public static function invalidSocketId(): self
    {
        return new self('socket_id must match the Pusher protocol socket-id grammar: digits, ".", digits.');
    }

    public static function invalidPresenceUserId(): self
    {
        return new self('Presence channel data must include a non-empty string "user_id" of at most 128 bytes.');
    }

    public static function presenceDataTooLarge(): self
    {
        return new self('Presence channel data must encode to at most 1024 bytes of JSON.');
    }

    /**
     * $previous carries whatever `json_encode()` itself reported (a
     * `JsonException`, e.g. "Malformed UTF-8 characters", "Type is not
     * supported", "Recursion detected", "Inf and NaN cannot be JSON
     * encoded") for diagnostics — never surfaced in this exception's own
     * public message, which stays generic and stable regardless of which
     * of those triggered it.
     */
    public static function presenceDataNotEncodable(?Throwable $previous = null): self
    {
        return new self('Presence channel data could not be encoded as JSON.', previous: $previous);
    }
}
