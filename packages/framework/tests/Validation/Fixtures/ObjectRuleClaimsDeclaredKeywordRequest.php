<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

#[ClaimsObjectKeyword('additionalProperties', true)]
final readonly class ObjectRuleClaimsDeclaredKeywordRequest
{
    public function __construct(
        public string $name,
    ) {}
}
