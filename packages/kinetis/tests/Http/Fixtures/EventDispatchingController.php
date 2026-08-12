<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Events\EventDispatcher;
use Kinetis\Http\Attributes\Post;

final readonly class EventDispatchingController
{
    public function __construct(
        private EventDispatcher $events,
    ) {}

    #[Post('/orders', status: 201)]
    public function store(): array
    {
        $this->events->dispatch(new OrderPlacedEvent(42));

        return ['status' => 'created'];
    }
}
