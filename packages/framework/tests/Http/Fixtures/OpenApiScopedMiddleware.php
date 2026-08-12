<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Stands in for a class discovered via #[AsOpenApiMiddleware] — used to
 * test Kernel's $discoveredOpenApiMiddleware constructor parameter
 * directly, with no real namespace scan involved.
 */
final class OpenApiScopedMiddleware extends RecordingMiddleware {}
