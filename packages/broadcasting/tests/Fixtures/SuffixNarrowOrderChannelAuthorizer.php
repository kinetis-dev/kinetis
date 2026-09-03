<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `{id}-final-draft` — strictly narrower than
 * SuffixBroadOrderChannelAuthorizer's `{id}-draft`; see that class's own
 * docblock.
 */
final class SuffixNarrowOrderChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}-final-draft')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
