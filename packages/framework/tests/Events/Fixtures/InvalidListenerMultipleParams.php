<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final class InvalidListenerMultipleParams
{
    #[Listener]
    public function bad(OrderPlaced $first, InventoryLow $second): void
    {
    }
}
