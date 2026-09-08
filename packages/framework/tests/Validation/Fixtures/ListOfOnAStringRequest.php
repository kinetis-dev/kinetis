<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

final readonly class ListOfOnAStringRequest
{
    public function __construct(
        #[ListOf(OrderItem::class)]
        public string $items,
    ) {}
}
