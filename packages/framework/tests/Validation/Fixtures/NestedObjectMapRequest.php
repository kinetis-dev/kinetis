<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class NestedObjectMapRequest
{
    public function __construct(
        public ObjectMapFieldRequest $payload,
    ) {}
}
