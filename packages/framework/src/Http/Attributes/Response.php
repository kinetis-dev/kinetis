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
 *
 * Documents the statuses *besides* the route's own, which the route
 * attribute already declares (200 unless it says otherwise) and which
 * OpenApiGenerator describes from the method's return type, schema
 * included. An attribute repeating that status is ignored, so it can
 * never replace that richer entry with a bare description.
 *
 * `$body` names the DTO this status's own payload is shaped like — an
 * error envelope, a problem document — published as that class's
 * component schema under `$mediaType`. Without it the entry stays
 * description-only and `$mediaType` describes nothing, so it is ignored.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    public function __construct(
        private int $status,
        private string $description,
        private ?string $body = null,
        private string $mediaType = 'application/json',
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }
}
