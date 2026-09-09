<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ObjectMap;

final readonly class NullableObjectMapRequest
{
    public function __construct(
        #[ObjectMap]
        public ?array $meta,
    ) {}
}
