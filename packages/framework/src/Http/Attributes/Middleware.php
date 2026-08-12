<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Declares a PSR-15 middleware class that wraps this route (method-level)
 * or every route on the controller (class-level). Repeatable and
 * stackable across both levels — class-level middleware runs first
 * (outermost), method-level middleware runs closer to the controller, in
 * declaration order within each level. Router::register() discovers these
 * the same way it discovers #[Get]/#[Post]/..., via one more
 * getAttributes() call inside its existing reflection loop.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /**
     * @param class-string<\Psr\Http\Server\MiddlewareInterface> $middlewareClass
     */
    public function __construct(
        public string $middlewareClass,
    ) {}
}
