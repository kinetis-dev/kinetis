<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class PlainArrayFieldRequest
{
    public function __construct(
        public array $tags,
        // A genuinely nullable *plain* array — no #[ListOf] at all,
        // distinct from a #[ListOf] array (which additionally hydrates
        // each element as a nested DTO): only the shared list-shape
        // check (see Hydrator::listShapeMismatchMessage()) applies here.
        public ?array $optionalTags = null,
    ) {}
}
