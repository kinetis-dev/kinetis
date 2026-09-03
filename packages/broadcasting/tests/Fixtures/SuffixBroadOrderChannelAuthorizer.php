<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `{id}-draft` — its own language is a strict superset of
 * SuffixNarrowOrderChannelAuthorizer's `{id}-final-draft`: every channel
 * name ending in "-final-draft" also ends in "-draft", but not every
 * channel name ending in "-draft" ends in "-final-draft". Must always
 * lose to the narrower pattern for a channel name both match.
 */
final class SuffixBroadOrderChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}-draft')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
