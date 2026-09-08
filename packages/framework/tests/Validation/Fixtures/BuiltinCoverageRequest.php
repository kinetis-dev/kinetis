<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * The builtin categories a #[Body] DTO field may declare beyond the
 * numeric and string scalars — array, iterable, mixed, and a bool whose
 * wire spelling differs between a JSON and a form-encoded body — in one
 * DTO, used end to end through both OpenApiGeneratorTest (the generated
 * schema) and DispatcherTest (real request dispatch).
 */
final readonly class BuiltinCoverageRequest
{
    public function __construct(
        public array $tags,
        public iterable $items,
        public mixed $note = null,
        public bool $flag = false,
    ) {}
}
