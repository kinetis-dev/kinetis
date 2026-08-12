<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Names the class a route's Paginator/CursorPaginator response actually
 * wraps — reflection alone can't recover this from the return type, since
 * every paginated route declares the same concrete Paginator/CursorPaginator
 * class regardless of what it holds (PHP has no generics). Read by
 * OpenApiGenerator to describe `data` as an array of this class's own
 * schema instead of a bare {type: object}; purely descriptive, the same
 * trust already placed in Response's status code — nothing here enforces
 * that the route actually returns this item type at runtime.
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
