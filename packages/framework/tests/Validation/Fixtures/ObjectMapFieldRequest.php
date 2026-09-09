<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ObjectMap;

final readonly class ObjectMapFieldRequest
{
    public function __construct(
        public string $name,
        #[ObjectMap]
        public array $meta,
    ) {}
}
