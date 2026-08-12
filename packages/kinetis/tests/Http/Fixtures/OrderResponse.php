<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class OrderResponse
{
    public function __construct(
        public int $id,
        public string $customerName,
        public Address $shippingAddress,
    ) {}
}
