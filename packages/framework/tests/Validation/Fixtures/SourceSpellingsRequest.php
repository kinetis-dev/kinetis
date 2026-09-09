<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

/**
 * One field per scalar type whose accepted spellings differ by
 * InputSource, plus a nullable constrained one for what an accepted null
 * skips.
 */
final readonly class SourceSpellingsRequest
{
    public function __construct(
        public int $count,
        public float $ratio,
        public bool $flag,
        public string $label,
        #[MinLength(3)]
        public ?string $nickname = null,
    ) {}
}
