<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `x.{id}za` — a strict subset of CycleBroadChannelAuthorizer's
 * `x.{id}a` (every channel ending in "za" also ends in "a"). Paired
 * with CycleDisjointChannelAuthorizer's `x.{id}m.more` (disjoint from
 * both by segment count), this specific trio is what exposed the
 * previous comparator's non-transitivity: "subset first" for the
 * A/B pair combined with an unrelated lexical tie-break for the
 * disjoint pairs could form a genuine A < B < C < A cycle.
 */
final class CycleNarrowChannelAuthorizer
{
    #[BroadcastChannel('x.{id}za')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
