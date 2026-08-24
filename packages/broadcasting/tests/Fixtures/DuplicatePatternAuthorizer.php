<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

final class DuplicatePatternAuthorizer
{
    #[BroadcastChannel('orders.{orderId}')]
    public function authorizeAgain(string $orderId): bool
    {
        return true;
    }
}
