<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * The first `#[BroadcastChannel]` method's own signature is valid; the
 * second's is not (its pattern needs one `id` parameter, but the method
 * declares none). Registering this class must reject the whole class
 * atomically — proves the valid first method never ends up permanently
 * registered just because it was reflected before the invalid second
 * one threw.
 */
final class ValidThenInvalidChannelAuthorizer
{
    #[BroadcastChannel('valid-owner.{id}')]
    public function authorizeValid(string $id): bool
    {
        return true;
    }

    #[BroadcastChannel('invalid-owner.{id}')]
    public function authorizeInvalid(): bool
    {
        return true;
    }
}
