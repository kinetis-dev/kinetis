<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * The first `#[BroadcastChannel]` method's own pattern is unclaimed; the
 * second reuses `orders.{orderId}`, already claimed by
 * OrderChannelAuthorizer::authorizeOrder() — a conflict against the
 * registry's own already-committed state, not against anything this
 * class itself is staging. Registering this class after
 * OrderChannelAuthorizer must reject it atomically: the first method's
 * own pattern must never survive the throw either.
 */
final class ValidThenConflictingChannelAuthorizer
{
    #[BroadcastChannel('unique-owner.{id}')]
    public function authorizeUnique(string $id): bool
    {
        return true;
    }

    #[BroadcastChannel('orders.{orderId}')]
    public function authorizeConflicting(string $orderId): bool
    {
        return true;
    }
}
