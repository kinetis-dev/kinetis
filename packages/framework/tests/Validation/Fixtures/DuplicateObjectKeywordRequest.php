<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

#[ClaimsObjectKeyword('minProperties', 'one')]
#[ClaimsObjectKeyword('minProperties', 'two')]
final readonly class DuplicateObjectKeywordRequest
{
    public function __construct(
        public string $name,
    ) {}
}
