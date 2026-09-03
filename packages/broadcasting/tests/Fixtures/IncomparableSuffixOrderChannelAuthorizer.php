<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `{id}-2024` — genuinely, incomparably overlapping with
 * PrefixOrderChannelAuthorizer's `archived-{id}`; see that class's own
 * docblock.
 */
final class IncomparableSuffixOrderChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}-2024')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
