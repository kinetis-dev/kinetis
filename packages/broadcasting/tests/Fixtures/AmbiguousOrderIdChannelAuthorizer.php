<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `orders.{id}` is OrderChannelAuthorizer's `orders.{orderId}` under a
 * renamed placeholder — the same channel names, so the two conflict.
 */
final class AmbiguousOrderIdChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
