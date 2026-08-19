<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares a PSR-15 middleware class as scoped to Kernel's `/openapi.json`
 * and `/openapi` endpoints specifically — one attribute shared by both,
 * since they're the same "expose the API's own shape" concern, not two
 * independently protectable surfaces. Found by the same project-wide scan
 * Kinetis\Http\Middleware\GlobalMiddlewareDiscovery already performs for
 * #[AsGlobalMiddleware], bucketed separately — global middleware already
 * wraps these endpoints as part of every request; this exists for
 * protecting them specifically without touching unrelated traffic.
 *
 * Same shape as #[AsGlobalMiddleware] deliberately:
 * $priority bounded 0-100, defaulting 50, alphabetical tiebreak. An
 * explicit `AppScope::openApiMiddleware()` registration always wins over
 * a discovered one.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsOpenApiMiddleware
{
    public function __construct(
        public int $priority = 50,
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException("AsOpenApiMiddleware priority must be between 0 and 100, got {$priority}.");
        }
    }
}
