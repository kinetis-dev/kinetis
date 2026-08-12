<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Events\Listener;

final readonly class SendOrderConfirmationListener
{
    public function __construct(
        private EventLog $log,
    ) {}

    #[Listener]
    public function onOrderPlaced(OrderPlacedEvent $event): void
    {
        $this->log->orderIds[] = $event->orderId;
    }
}
