<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\PaginatedItem;
use Kinetis\Http\Pagination\CursorPaginator;
use Kinetis\Http\Pagination\Paginator;

final readonly class PaginatedOrderController
{
    #[Get('/orders/paginated')]
    #[PaginatedItem(OrderResponse::class)]
    public function paginated(): Paginator
    {
        return new Paginator(data: [], currentPage: 1, perPage: 20, total: 0, lastPage: 0);
    }

    #[Get('/orders/cursor')]
    #[PaginatedItem(OrderResponse::class)]
    public function cursor(): CursorPaginator
    {
        return new CursorPaginator(data: [], nextCursor: null, hasMore: false);
    }

    #[Get('/orders/paginated-bare')]
    public function paginatedBare(): Paginator
    {
        return new Paginator(data: [], currentPage: 1, perPage: 20, total: 0, lastPage: 0);
    }
}
