<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A DTO guarded only by application rules — no framework constraint
 * anywhere on it.
 */
final readonly class ApplicationRulesRequest
{
    public function __construct(
        #[Uppercase]
        #[NotReserved]
        public string $code,
    ) {}
}
