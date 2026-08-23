<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Events\Listener;

abstract class AbstractListenerBase
{
    #[Listener]
    public function onEvent(ScopeFixtureEvent $event): void
    {
        $event->seen = true;
    }
}
