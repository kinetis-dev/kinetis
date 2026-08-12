<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\OrderWithItems;

final readonly class OrderItemsController
{
    #[Post('/orders-with-items', status: 201)]
    public function store(#[Body] OrderWithItems $data): OrderWithItems
    {
        return $data;
    }
}
