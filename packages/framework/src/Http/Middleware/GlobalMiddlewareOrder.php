<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

/**
 * Computes the real global-middleware order: ExceptionHandlerMiddleware
 * first, then MaxBodySizeMiddleware, then $explicit
 * (AppScope::middlewares()) as a group, then $discovered
 * (GlobalMiddlewareDiscovery) minus anything already in $explicit.
 *
 * Returns plain class-strings, not resolved instances — the caller maps
 * this through its own container; this class knows nothing about
 * dependency injection.
 */
final class GlobalMiddlewareOrder
{
    /**
     * @param list<class-string> $explicit
     * @param list<class-string> $discovered
     * @return list<class-string>
     */
    public static function resolve(array $explicit, array $discovered): array
    {
        return [
            ExceptionHandlerMiddleware::class,
            MaxBodySizeMiddleware::class,
            ...self::merge($explicit, $discovered),
        ];
    }

    /**
     * The plain explicit-then-discovered merge, with no fixed prepended
     * classes — factored out so Kernel's /mcp and /openapi.json/docs
     * scoped pipelines can reuse the identical precedence rule
     * (explicit always wins, discovered fills in the rest) without
     * inheriting ExceptionHandlerMiddleware/MaxBodySizeMiddleware, which
     * neither scoped pipeline needs or wants a copy of — both already run
     * inside the global pipeline that already includes those two.
     *
     * @param list<class-string> $explicit
     * @param list<class-string> $discovered
     * @return list<class-string>
     */
    public static function merge(array $explicit, array $discovered): array
    {
        // A class present in both lists runs once, at its explicit
        // position — discovery is a convenience for a class nobody
        // registered by hand, not a second copy of one that was.
        $discoveredOnly = array_values(array_diff($discovered, $explicit));

        return [...$explicit, ...$discoveredOnly];
    }
}
