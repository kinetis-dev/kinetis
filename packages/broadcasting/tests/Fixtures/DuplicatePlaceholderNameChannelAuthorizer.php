<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * The placeholder name "id" is reused across two different segments —
 * compilePattern() must reject this before assertSignature() ever runs,
 * since a real PHP method cannot declare two parameters both named
 * $id in the first place.
 */
final class DuplicatePlaceholderNameChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}.{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
