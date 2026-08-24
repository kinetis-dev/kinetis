<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

final class WrongParameterNameAuthorizer
{
    #[BroadcastChannel('orders.{orderId}')]
    public function authorize(string $wrongName): bool
    {
        return true;
    }
}
