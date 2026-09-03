<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Two distinct methods on the same class claim the identical pattern —
 * a conflict that only exists against the batch this class's own
 * registration is still staging (`$this->definitions` is empty when
 * this class registers alone), not against anything already committed
 * to the registry. Registering this class must reject it atomically:
 * the first method's own definition, staged only in memory while the
 * second is still being validated, must never survive the throw.
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
