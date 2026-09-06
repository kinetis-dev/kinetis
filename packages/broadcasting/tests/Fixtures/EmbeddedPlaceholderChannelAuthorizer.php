<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * A placeholder sharing its segment with literal text — outside the
 * grammar, where a placeholder always spans a whole segment.
 */
final class EmbeddedPlaceholderChannelAuthorizer
{
    #[BroadcastChannel('orders.order-{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
