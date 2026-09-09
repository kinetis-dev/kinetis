<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;

final readonly class EachOnADtoListRequest
{
    public function __construct(
        #[ListOf(OrderItem::class)]
        #[Each(Uppercase::class)]
        public array $items,
    ) {}
}
