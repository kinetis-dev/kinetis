<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class OrderPlacedEvent
{
    public function __construct(
        public int $orderId,
    ) {}
}
