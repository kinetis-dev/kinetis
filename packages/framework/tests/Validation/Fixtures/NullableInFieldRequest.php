<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\In;

/**
 * A nullable #[In] field beside its non-nullable equivalent, so the
 * published schema's `enum` can be checked to admit `null` only where
 * the declared type itself does.
 */
final readonly class NullableInFieldRequest
{
    public function __construct(
        #[In(['draft', 'published'])]
        public ?string $nullableStatus,
        #[In(['draft', 'published'])]
        public string $status,
    ) {}
}
