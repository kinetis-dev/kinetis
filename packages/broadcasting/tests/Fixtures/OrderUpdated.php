<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\ShouldBroadcast;

final readonly class OrderUpdated implements ShouldBroadcast
{
    public function __construct(
        private string $orderId,
        private string $status,
    ) {}

    #[\Override]
    public function broadcastOn(): array
    {
        return ["private-orders.{$this->orderId}", 'public-orders'];
    }

    #[\Override]
    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    #[\Override]
    public function broadcastWith(): array
    {
        return ['orderId' => $this->orderId, 'status' => $this->status];
    }
}
