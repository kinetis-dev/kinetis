<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares a PSR-15 middleware class as scoped to Kernel's `/mcp`
 * endpoint specifically — found by the same project-wide scan
 * Kinetis\Http\Middleware\GlobalMiddlewareDiscovery already performs for
 * #[AsGlobalMiddleware], bucketed separately. Global middleware already
 * wraps `/mcp` (Kernel's global pipeline covers the request's entire
 * body, short-circuits included), so this exists for the narrower need a
 * security review surfaced: a way to run middleware for *only* `/mcp`
 * without it also running on every other route — a global-only consumer
 * had no way to protect `/mcp` specifically without also touching
 * unrelated traffic.
 *
 * Same shape as #[AsGlobalMiddleware] deliberately, not a coincidence:
 * $priority bounded 0-100, defaulting 50, alphabetical tiebreak. An
 * explicit `AppScope::mcpMiddleware()` registration always wins over a
 * discovered one, the identical precedence #[AsGlobalMiddleware] already
 * establishes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsMcpMiddleware
{
    public function __construct(
        public int $priority = 50,
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException("AsMcpMiddleware priority must be between 0 and 100, got {$priority}.");
        }
    }
}
