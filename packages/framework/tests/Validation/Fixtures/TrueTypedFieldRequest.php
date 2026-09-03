<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class TrueTypedFieldRequest
{
    public function __construct(
        public true $confirmed,
    ) {}
}
