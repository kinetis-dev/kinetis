<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Excludes this route (method-level) or every route on the controller
 * (class-level) from the generated OpenAPI document and Swagger UI. The
 * route itself still works normally — this only suppresses its
 * documentation, for a route that isn't really part of the API surface
 * (an HTML page, a health check, ...).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Hidden {}
