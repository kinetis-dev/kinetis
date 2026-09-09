<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * Backed enums as DTO fields: one of each backing type, a nullable
 * one, and one carrying a rule that reads the resolved case.
 */
final readonly class EnumFieldsRequest
{
    public function __construct(
        public Priority $priority,
        public ?SortDirection $direction = null,
        #[MinimumPriority(2)]
        public ?Priority $escalation = null,
    ) {}
}
