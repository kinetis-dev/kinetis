<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A DTO guarded by a rule that yields something other than a Violation —
 * the other half of a broken rule's contract, and equally a definition
 * failure rather than a client response.
 */
#[YieldsNonViolation]
final readonly class YieldsNonViolationRequest
{
    public function __construct(
        public string $name,
    ) {}
}
