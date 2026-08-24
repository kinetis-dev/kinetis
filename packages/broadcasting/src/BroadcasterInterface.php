<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * The one method a broadcast driver implements: push $event, carrying
 * $payload, to every subscriber of $channel. Nothing about channel
 * naming, authorization, or delivery guarantees is part of this contract
 * — a driver that cannot guarantee delivery (every driver here, since the
 * transport is a WebSocket server this process does not control) simply
 * does its best and returns.
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
