<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `x.{id}m.more` — three segments, disjoint by segment count from both
 * CycleNarrowChannelAuthorizer's and CycleBroadChannelAuthorizer's
 * two-segment patterns; see CycleNarrowChannelAuthorizer's own
 * docblock for why this specific trio matters.
 */
final class CycleDisjointChannelAuthorizer
{
    #[BroadcastChannel('x.{id}m.more')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
