<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Domain\Orders;

use Kinetis\Events\Listener;
use Kinetis\Tests\Cache\Fixtures\Events\DiscoveredEvent;

final class UnconventionalListener
{
    #[Listener]
    public function onDiscoveredEvent(DiscoveredEvent $event): void
    {
    }
}
