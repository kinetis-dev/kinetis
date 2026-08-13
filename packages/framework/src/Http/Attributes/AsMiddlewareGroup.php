<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares this PSR-15 middleware class a member of a named group, found
 * by Kinetis\Http\Middleware\GlobalMiddlewareDiscovery. A route or
 * controller then references the whole group by name —
 * #[Middleware('@admin')] — instead of listing every class in it.
 *
 * Repeatable: one class can belong to several groups, each with its own
 * priority, since the same middleware can reasonably need a different
 * position in two different stacks.
 *
 * $priority orders members *within this group* — higher runs more outer.
 * Bounded to 0-100, defaulting to 50, the same scheme
 * #[AsGlobalMiddleware] uses. Two members of one group sharing a
 * priority are ordered alphabetically by fully-qualified class name, so
 * the result never depends on filesystem/scan order.
 *
 * Group membership is what this attribute declares — not that the
 * middleware runs anywhere on its own. A group only ever runs where a
 * route or controller references it; a group nothing references is
 * discovered and simply never used.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AsMiddlewareGroup
{
    public function __construct(
        public string $name,
        public int $priority = 50,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('AsMiddlewareGroup name must not be empty.');
        }

        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException("AsMiddlewareGroup priority must be between 0 and 100, got {$priority}.");
        }
    }
}
