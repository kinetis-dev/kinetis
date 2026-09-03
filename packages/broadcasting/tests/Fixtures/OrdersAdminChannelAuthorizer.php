<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Fully literal — overlaps with OrderChannelAuthorizer's
 * `orders.{orderId}` for the channel name `orders.admin` specifically,
 * and must always win regardless of registration/artifact order.
 */
final class OrdersAdminChannelAuthorizer
{
    #[BroadcastChannel('orders.admin')]
    public function authorizeAdmin(): bool
    {
        return true;
    }
}
