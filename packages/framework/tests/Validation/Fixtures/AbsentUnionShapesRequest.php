<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\ListOf;
use Kinetis\Validation\ObjectMap;

/**
 * One presence union around each value shape a DTO field can otherwise
 * declare — a scalar, a nested DTO, a #[ListOf] list and an #[ObjectMap]
 * — so `T|Absent` is shown to be exactly as expressive as `T`.
 */
final readonly class AbsentUnionShapesRequest
{
    /**
     * @param list<OrderItem>|Absent $items
     * @param array<string, mixed>|Absent $meta
     */
    public function __construct(
        public int|Absent $quantity = Absent::Value,
        public OrderItem|Absent $item = Absent::Value,
        #[ListOf(OrderItem::class)]
        public array|Absent $items = Absent::Value,
        #[ObjectMap]
        public array|Absent $meta = Absent::Value,
    ) {}
}
