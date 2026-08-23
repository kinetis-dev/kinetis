<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
    ) {}
}
