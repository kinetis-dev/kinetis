<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class OrderController
{
    #[Post('/orders', status: 201)]
    public function store(#[Body] CreateOrderRequest $data): OrderResponse
    {
        return new OrderResponse(
            id: 1,
            customerName: $data->customerName,
            shippingAddress: $data->shippingAddress,
        );
    }
}
