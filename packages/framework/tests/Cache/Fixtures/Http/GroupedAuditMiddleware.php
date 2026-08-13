<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Shares 'admin''s default priority with GroupedTracingMiddleware, so the
 * two of them are what exercises the alphabetical tiebreak within a
 * single group.
 */
#[AsMiddlewareGroup('audited')]
final class GroupedAuditMiddleware extends RecordingMiddleware {}
