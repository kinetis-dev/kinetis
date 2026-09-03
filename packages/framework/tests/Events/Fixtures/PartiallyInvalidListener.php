<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

/**
 * One valid #[Listener] method, one invalid one — register() must reject
 * the whole class, leaving no trace of the valid method either.
 */
final class PartiallyInvalidListener
{
    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }

    #[Listener]
    public function bad(OrderPlaced $first, InventoryLow $second): void
    {
    }
}
