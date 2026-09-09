<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;
use Kinetis\Validation\ObjectMap;

final readonly class ObjectMapListOfRequest
{
    public function __construct(
        #[ObjectMap]
        #[ListOf(OrderItem::class)]
        public array $items,
    ) {}
}
