<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class HighPriorityOrderListener
{
    #[Listener(priority: 90)]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }
}
