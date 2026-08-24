<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Exception;

use RuntimeException;

final class BroadcastingException extends RuntimeException
{
    public static function unknownDriver(string $driver): self
    {
        return new self("Unknown BROADCAST_DRIVER \"{$driver}\". Supported: \"null\", \"pusher\".");
    }

    /**
     * {@see \Kinetis\Broadcasting\Http\BroadcastAuthController} signs
     * with the Pusher-protocol algorithm specifically — thrown when the
     * bound driver isn't {@see \Kinetis\Broadcasting\Driver\PusherBroadcaster},
     * most commonly a `BROADCAST_DRIVER=null` deployment that still
     * routes real client subscription requests at `/broadcasting/auth`.
     */
    public static function authNotSupported(string $driverClass): self
    {
        return new self(
            "The bound broadcaster ({$driverClass}) does not support channel authorization. "
                . 'Set BROADCAST_DRIVER=pusher to use /broadcasting/auth.',
        );
    }
}
