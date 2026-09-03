<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class NullTypedFieldRequest
{
    public function __construct(
        public null $marker,
    ) {}
}
