<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

final class NonStringParameterAuthorizer
{
    #[BroadcastChannel('orders.{orderId}')]
    public function authorize(int $orderId): bool
    {
        return true;
    }
}
