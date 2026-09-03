<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class FalseTypedFieldRequest
{
    public function __construct(
        public false $declined,
    ) {}
}
