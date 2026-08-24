<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

final class WrongParameterCountAuthorizer
{
    #[BroadcastChannel('orders.{orderId}')]
    public function authorize(): bool
    {
        return true;
    }
}
