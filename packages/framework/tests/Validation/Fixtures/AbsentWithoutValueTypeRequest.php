<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;

/**
 * The marker with nothing beside it. PHP folds `null|Absent` back into
 * the nullable named type `?Absent`, so this is the declaration a
 * "presence union with no value type" actually reaches reflection as.
 */
final readonly class AbsentWithoutValueTypeRequest
{
    public function __construct(
        public null|Absent $nothing = Absent::Value,
    ) {}
}
