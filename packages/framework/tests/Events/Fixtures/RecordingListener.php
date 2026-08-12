<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final readonly class RecordingListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->recorder->record("order:{$event->orderId}");
    }

    #[Listener]
    public function onInventoryLow(InventoryLow $event): void
    {
        $this->recorder->record("inventory:{$event->sku}");
    }

    public function notAListener(OrderPlaced $event): void
    {
        $this->recorder->record('should-never-be-called');
    }
}
