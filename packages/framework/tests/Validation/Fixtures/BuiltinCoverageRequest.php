<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * KINETIS-76 follow-up: every remaining builtin type category
 * JsonSchema::forType()/Hydrator::typeMismatchMessage() give an explicit,
 * deliberate policy — array, iterable, mixed, null, true, false — in one
 * #[Body] DTO, used end-to-end through both OpenApiGeneratorTest (the
 * generated schema) and DispatcherTest (real request dispatch).
 */
final readonly class BuiltinCoverageRequest
{
    public function __construct(
        public array $tags,
        public iterable $items,
        public mixed $note = null,
        public null $marker = null,
        public true $confirmed = true,
        public false $declined = false,
    ) {}
}
