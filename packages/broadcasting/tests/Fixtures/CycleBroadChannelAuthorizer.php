<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `x.{id}a` — strictly broader than CycleNarrowChannelAuthorizer's
 * `x.{id}za`; see that class's own docblock.
 */
final class CycleBroadChannelAuthorizer
{
    #[BroadcastChannel('x.{id}a')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
