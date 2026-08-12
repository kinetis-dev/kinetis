<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class ShouldNeverRunListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onStoppableTestEvent(StoppableTestEvent $event): void
    {
        $this->recorder->record('should-never-run');
    }
}
