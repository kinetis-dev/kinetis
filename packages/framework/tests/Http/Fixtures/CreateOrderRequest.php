<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

final readonly class CreateOrderRequest
{
    public function __construct(
        #[MinLength(2)]
        public string $customerName,
        public Address $shippingAddress,
    ) {}
}
