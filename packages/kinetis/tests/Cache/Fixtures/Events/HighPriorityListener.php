<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Events;

use Kinetis\Events\Listener;

final class HighPriorityListener
{
    #[Listener(priority: 90)]
    public function onDiscoveredEvent(DiscoveredEvent $event): void
    {
    }
}
