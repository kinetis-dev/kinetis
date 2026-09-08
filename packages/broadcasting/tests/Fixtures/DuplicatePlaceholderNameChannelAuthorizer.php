<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * The placeholder name "id" is reused across two segments — rejected
 * when the pattern is parsed, before the signature is looked at, since
 * a real PHP method cannot declare two parameters both named $id.
 */
final class DuplicatePlaceholderNameChannelAuthorizer
{
    #[BroadcastChannel('orders.{id}.{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
