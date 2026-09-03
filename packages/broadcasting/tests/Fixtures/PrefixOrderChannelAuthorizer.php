<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `archived-{id}` genuinely overlaps with
 * IncomparableSuffixOrderChannelAuthorizer's `{id}-2024` (both match
 * `orders.archived-2024`, with different `id` captures) without either
 * one containing the other — "orders.archived-foo" matches this pattern
 * but not the other; "orders.bar-2024" matches the other but not this
 * one. Registering both must be rejected as ambiguous.
 */
final class PrefixOrderChannelAuthorizer
{
    #[BroadcastChannel('orders.archived-{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
