<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Records every invocation via a plain mutable counter — registered as
 * a singleton instance on the container, so a test can assert its
 * authorization method was never called: the concrete proof that a
 * malformed socket_id/channel_name is rejected before any application
 * authorizer runs at all, not merely before its result is trusted.
 */
final class TrackedChannelAuthorizer
{
    public int $calls = 0;

    #[BroadcastChannel('tracked.{id}')]
    public function authorize(string $id): bool
    {
        $this->calls++;

        return true;
    }
}
