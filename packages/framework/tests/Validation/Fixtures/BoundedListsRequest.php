<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\MaxItems;
use Kinetis\Validation\Constraints\MinItems;
use Kinetis\Validation\ListOf;

final readonly class BoundedListsRequest
{
    public function __construct(
        #[MinItems(1)]
        #[MaxItems(3)]
        public array $tags,
        #[MinItems(1)]
        #[MaxItems(2)]
        #[ListOf(OrderItem::class)]
        public array $items,
    ) {}
}
