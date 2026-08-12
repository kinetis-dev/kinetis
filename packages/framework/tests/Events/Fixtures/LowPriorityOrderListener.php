<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class LowPriorityOrderListener
{
    #[Listener(priority: 10)]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }
}
