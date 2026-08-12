<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\ListOf;

final readonly class OrderWithItems
{
    public function __construct(
        #[MinLength(2)]
        public string $customerName,
        #[ListOf(OrderItem::class)]
        public array $items,
    ) {}
}
