<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * Implemented by an application's own event/DTO class to describe how it
 * broadcasts, without dictating when — {@see Broadcaster::event()} reads
 * these three methods and calls {@see BroadcasterInterface::broadcast()}
 * once per channel. Deliberately not wired into `Kinetis\Events\EventDispatcher`
 * automatically: unlike `Kinetis\Events\ShouldQueue` (checked per listener,
 * inside a dispatch loop that already exists), broadcasting an event is a
 * per-event concern with no natural hook in that loop, and forcing one in
 * would mean every event dispatch pays for a check nothing asked for.
 * Call `Broadcaster::event($event)` explicitly — typically from inside a
 * `#[Listener]` method, right where a queued job would otherwise be
 * dispatched.
 */
interface ShouldBroadcast
{
    /**
     * @return list<string> channel names, each carrying whatever prefix
     *     its own authorization model needs (`private-orders.42`)
     */
    public function broadcastOn(): array;

    /**
     * The event name delivered to subscribers — distinct from the PHP
     * class name, since a client should never depend on Kinetis's own
     * naming.
     */
    public function broadcastAs(): string;

    /**
     * @return array<string, mixed> the payload delivered to subscribers
     */
    public function broadcastWith(): array;
}
