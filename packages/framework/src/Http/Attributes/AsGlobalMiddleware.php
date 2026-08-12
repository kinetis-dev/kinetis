<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares a PSR-15 middleware class as global — found by
 * Kinetis\Http\Middleware\GlobalMiddlewareDiscovery and appended to
 * Kernel's pipeline automatically, with no AppScope::middleware() call
 * needed. Deliberately a distinct attribute from #[Middleware]: that one
 * lives on a *controller*, referencing another class by name; this one
 * lives on the middleware class itself, the opposite direction.
 *
 * $priority breaks ties among multiple discovered classes — higher runs
 * more outer (closer to the framework's own unconditional
 * ExceptionHandlerMiddleware). Bounded to 0-100, defaulting to 50. Two
 * classes sharing a priority are ordered alphabetically by their own
 * fully-qualified class name instead, so the result never depends on
 * filesystem/scan order.
 *
 * A class explicitly registered via AppScope::middleware() always wins
 * over this attribute: Kernel skips a discovered class already present in
 * that list rather than adding it to the pipeline twice, and every
 * explicit registration runs more outer than every discovered one, as a
 * group.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsGlobalMiddleware
{
    public function __construct(
        public int $priority = 50,
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException("AsGlobalMiddleware priority must be between 0 and 100, got {$priority}.");
        }
    }
}
