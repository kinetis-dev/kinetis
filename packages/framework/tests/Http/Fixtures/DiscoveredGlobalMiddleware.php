<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Stands in for a class GlobalMiddlewareDiscovery would have found — used
 * to test Kernel's own merge/dedupe logic directly via its
 * $discoveredGlobalMiddleware constructor parameter, with no real
 * namespace scan or #[AsGlobalMiddleware] attribute involved.
 */
final class DiscoveredGlobalMiddleware extends RecordingMiddleware {}
