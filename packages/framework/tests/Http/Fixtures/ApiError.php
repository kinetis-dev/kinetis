<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * The payload shape an error status returns — the DTO a #[Response]
 * names as its body, which the route's own return type does not
 * describe.
 */
final readonly class ApiError
{
    public function __construct(
        public string $title,
        public int $status,
    ) {}
}
