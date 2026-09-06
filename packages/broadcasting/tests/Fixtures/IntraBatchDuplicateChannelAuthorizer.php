<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Two methods on one class claiming the identical pattern — a conflict
 * the class carries on its own, with nothing else registered.
 */
final class IntraBatchDuplicateChannelAuthorizer
{
    #[BroadcastChannel('batch-owner.{id}')]
    public function authorizeFirst(string $id): bool
    {
        return true;
    }

    #[BroadcastChannel('batch-owner.{id}')]
    public function authorizeSecond(string $id): bool
    {
        return true;
    }
}
