<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * The service an application constructor-injects — autowires with
 * nothing to register beyond whatever {@see PackageBootstrap} already
 * bound `BroadcasterInterface` to, the same "resolves through the
 * container with zero explicit binding" shape a controller constructor-
 * injecting a concrete `EventDispatcher` already has for the identical
 * reason.
 */
final readonly class Broadcaster
{
    public function __construct(
        private BroadcasterInterface $driver,
    ) {}

    /**
     * The raw form — a channel and event name this application chose
     * itself, with no {@see ShouldBroadcast} class involved.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->driver->broadcast($channel, $event, $payload);
    }

    /**
     * Broadcasts $event on every channel it names, using its own event
     * name and payload.
     */
    public function event(ShouldBroadcast $event): void
    {
        foreach ($event->broadcastOn() as $channel) {
            $this->driver->broadcast($channel, $event->broadcastAs(), $event->broadcastWith());
        }
    }
}
