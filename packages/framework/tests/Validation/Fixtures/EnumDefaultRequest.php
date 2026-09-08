<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * The one object a plan may capture as a default: an enum case. Every
 * request that omits `direction` gets the same case because a case is a
 * process-wide singleton, and var_export() writes it as a literal the
 * compiled artifact evaluates back to that same case.
 */
final readonly class EnumDefaultRequest
{
    public function __construct(
        public string $term,
        public SortDirection $direction = SortDirection::Ascending,
    ) {}
}
