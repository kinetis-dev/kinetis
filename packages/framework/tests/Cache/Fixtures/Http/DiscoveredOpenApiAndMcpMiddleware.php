<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMcpMiddleware;
use Kinetis\Http\Attributes\AsOpenApiMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Carries both attributes deliberately — proves discoverAll() lets a
 * class appear in more than one bucket rather than assuming they're
 * mutually exclusive.
 */
#[AsMcpMiddleware]
#[AsOpenApiMiddleware]
final class DiscoveredOpenApiAndMcpMiddleware extends RecordingMiddleware {}
