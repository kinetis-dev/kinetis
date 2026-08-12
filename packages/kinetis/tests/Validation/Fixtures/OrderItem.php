<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\GreaterThan;

final readonly class OrderItem
{
    public function __construct(
        public string $product,
        #[GreaterThan(0)]
        public int $quantity,
    ) {}
}
