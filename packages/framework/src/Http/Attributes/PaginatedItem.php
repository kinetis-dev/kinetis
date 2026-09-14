<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Names the item class held in the `data` list of a route's response
 * wrapper — reflection alone can't recover it from the return type, since
 * a wrapper declares `array $data` whatever it holds (PHP has no
 * generics). Any wrapper qualifies: a paginator envelope or an
 * application's own class. Read by OpenApiGenerator to describe the
 * wrapper inline with `data` as an array of this class's own schema;
 * purely descriptive, the same trust already placed in Response's status
 * code — nothing here enforces that the route actually returns this item
 * type at runtime, or that the wrapper declares `data` at all.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PaginatedItem
{
    /**
     * @param class-string $itemClass
     */
    public function __construct(
        private string $itemClass,
    ) {}

    /**
     * @return class-string
     */
    public function itemClass(): string
    {
        return $this->itemClass;
    }
}
