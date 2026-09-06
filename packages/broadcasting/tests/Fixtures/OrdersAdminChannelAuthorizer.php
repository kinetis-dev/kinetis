<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Fully literal, and overlapping with OrderChannelAuthorizer's
 * `orders.{orderId}` for the channel name `orders.admin` — a conflict,
 * since no precedence decides which of the two authorizes it.
 */
final class OrdersAdminChannelAuthorizer
{
    #[BroadcastChannel('orders.admin')]
    public function authorizeAdmin(): bool
    {
        return true;
    }
}
