<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\PaginatedItem;

final readonly class PaginatedOrderController
{
    #[Get('/orders/paginated')]
    #[PaginatedItem(OrderResponse::class)]
    public function paginated(): OffsetPage
    {
        return new OffsetPage(data: [], currentPage: 1, perPage: 20, total: 0, lastPage: 0);
    }

    #[Get('/orders/cursor')]
    #[PaginatedItem(OrderResponse::class)]
    public function cursor(): CursorPage
    {
        return new CursorPage(data: [], nextCursor: null, hasMore: false);
    }

    #[Get('/orders/constructorless')]
    #[PaginatedItem(OrderResponse::class)]
    public function constructorless(): ConstructorlessPage
    {
        return new ConstructorlessPage();
    }

    #[Get('/orders/paginated-bare')]
    public function paginatedBare(): OffsetPage
    {
        return new OffsetPage(data: [], currentPage: 1, perPage: 20, total: 0, lastPage: 0);
    }
}
