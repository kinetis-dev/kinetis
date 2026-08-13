<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Same group and same (default) priority as GroupedAuditMiddleware —
 * "Audit" sorting before "Tracing" alphabetically is the whole point of
 * this pair.
 */
#[AsMiddlewareGroup('audited')]
final class GroupedTracingMiddleware extends RecordingMiddleware {}
