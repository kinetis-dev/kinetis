<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

#[ClaimsObjectKeyword('minProperties', 'one')]
final readonly class ObjectRuleKeywordRequest
{
    public function __construct(
        public string $name,
    ) {}
}
