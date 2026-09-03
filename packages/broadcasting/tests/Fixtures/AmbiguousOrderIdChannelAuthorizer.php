<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Same canonical shape as OrderChannelAuthorizer's `orders.{orderId}` —
 * `orders.{id}` matches exactly the same channel names with identical
 * specificity, differing only in the placeholder's own name — must be
 * rejected as ambiguous, never silently resolved by registration order.
 */
final class AmbiguousOrderIdChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
