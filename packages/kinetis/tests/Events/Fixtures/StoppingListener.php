<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class StoppingListener
{
    // Must run before ShouldNeverRunListener — with both otherwise
    // defaulting to the same priority, alphabetical tiebreak
    // ("ShouldNeverRunListener" < "StoppingListener") would put the other
    // one first instead, defeating the entire point of this fixture pair.
    #[Listener(priority: 90)]
    public function onStoppableTestEvent(StoppableTestEvent $event): void
    {
        $event->stop();
    }
}
