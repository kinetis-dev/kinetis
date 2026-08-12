<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class SamePriorityOrderListenerA
{
    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }
}
