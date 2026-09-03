<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * `authorizeCurrent()` is the class's one real, currently attributed
 * channel; `legacyAuthorize()` is a real, callable public method that
 * carries no `#[BroadcastChannel]` attribute at all — standing in for a
 * method whose attribute was removed, or renamed away from, since
 * whatever produced a stale cache artifact last saw this class.
 */
final class ReconciledChannelAuthorizer
{
    #[BroadcastChannel('reconciled.{id}')]
    public function authorizeCurrent(string $id): bool
    {
        return true;
    }

    public function legacyAuthorize(string $id): bool
    {
        return true;
    }
}
