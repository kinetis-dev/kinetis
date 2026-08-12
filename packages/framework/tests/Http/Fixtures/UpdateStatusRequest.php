<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

/**
 * Deliberately uses asymmetric visibility (public private(set)) rather
 * than a plain readonly promoted property, to confirm Hydrator/Dispatcher
 * — which only ever reason about constructor parameters via reflection —
 * don't care how the backing property's visibility is declared.
 */
final class UpdateStatusRequest
{
    public function __construct(
        #[MinLength(2)]
        public private(set) string $status,
    ) {}
}
