<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class OrderChannelAuthorizer
{
    #[BroadcastChannel('orders.{orderId}')]
    public function authorizeOrder(CurrentUserInterface $user, string $orderId): bool
    {
        // Fixture rule: user id "7" owns every order.
        return $user->id() === '7';
    }

    #[BroadcastChannel('lobby')]
    public function authorizeLobby(): bool
    {
        return true;
    }
}
