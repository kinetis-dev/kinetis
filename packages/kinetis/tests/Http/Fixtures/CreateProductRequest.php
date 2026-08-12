<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\Regex;

final readonly class CreateProductRequest
{
    public function __construct(
        #[Regex('/^[A-Z]{3}\d{3}$/')]
        public string $sku,
        #[GreaterThan(0)]
        public float $price,
    ) {}
}
