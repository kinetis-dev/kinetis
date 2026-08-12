<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class SelfReferencingRequest
{
    public function __construct(
        public string $label,
        public ?SelfReferencingRequest $child = null,
    ) {}
}
