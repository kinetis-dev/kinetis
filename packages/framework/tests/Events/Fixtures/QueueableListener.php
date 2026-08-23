<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Events\ShouldQueue;

final readonly class QueueableListener implements ShouldQueue
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->recorder->record("queued:{$event->orderId}");
    }
}
