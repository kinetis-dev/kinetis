<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

final readonly class Address
{
    public function __construct(
        #[MinLength(3)]
        public string $street,
        public string $city,
    ) {}
}
