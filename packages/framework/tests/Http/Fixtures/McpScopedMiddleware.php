<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Stands in for a class discovered via #[AsMcpMiddleware] — used to test
 * Kernel's $discoveredMcpMiddleware constructor parameter directly, with
 * no real namespace scan involved.
 */
final class McpScopedMiddleware extends RecordingMiddleware {}
