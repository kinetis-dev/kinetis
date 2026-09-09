<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

/**
 * Two rules on one field claiming the same JSON Schema keyword with
 * different values. There is no honest schema for it, so generating one
 * fails rather than letting declaration order pick a bound.
 */
final readonly class DuplicateKeywordRequest
{
    public function __construct(
        #[MinLength(3)]
        #[MinLength(5)]
        public string $code,
    ) {}
}
