<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * The one method a broadcast driver implements: push $event, carrying
 * $payload, to every subscriber of $channel. Nothing about channel
 * naming, authorization, or delivery guarantees is part of this contract
 * — the transport is a WebSocket server this process does not control,
 * so returning proves only whatever the driver's own protocol confirmed,
 * never that a subscriber received anything. A driver may also throw;
 * each one documents the failures it raises and what they leave unknown.
 *
 * $channel carries whatever prefix its own authorization model needs
 * (`private-orders.42`, `presence-team.7`, or no prefix at all for a
 * public channel) — this interface does not interpret it, only forwards
 * it to the driver.
 */
interface BroadcasterInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, string $event, array $payload): void;
}
