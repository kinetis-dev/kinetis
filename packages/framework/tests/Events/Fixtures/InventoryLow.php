<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

final readonly class InventoryLow
{
    public function __construct(
        public string $sku,
    ) {}
}
