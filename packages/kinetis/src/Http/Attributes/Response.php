<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Documents an additional status code a route can produce beyond its
 * RouteAttribute's default — the OpenAPI-facing counterpart to returning a
 * ResponseInterface directly from the controller (see Dispatcher::dispatch()).
 * Repeatable, since a single method can short-circuit to more than one
 * non-default status (404, a 3xx redirect, ...). Purely descriptive: nothing
 * here enforces that the method actually produces this status at runtime,
 * the same trust OpenApiGenerator already places in the route's own
 * declared default.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    public function __construct(
        private int $status,
        private string $description,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function description(): string
    {
        return $this->description;
    }
}
