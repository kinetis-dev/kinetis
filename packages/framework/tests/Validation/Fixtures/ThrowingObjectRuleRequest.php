<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A DTO guarded by a rule that throws. The failure is the rule's, so it
 * propagates as the server error it is rather than reaching a client as
 * a validation message.
 */
#[ThrowsFromRule]
final readonly class ThrowingObjectRuleRequest
{
    public function __construct(
        public string $name,
    ) {}
}
