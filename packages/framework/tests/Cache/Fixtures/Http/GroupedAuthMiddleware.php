<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Belongs to two groups at once, with a different priority in each —
 * priority 50 (the default) in 'auth' where it's the only member, and 90
 * in 'admin' where it must run ahead of GroupedAdminMiddleware.
 */
#[AsMiddlewareGroup('auth')]
#[AsMiddlewareGroup('admin', priority: 90)]
final class GroupedAuthMiddleware extends RecordingMiddleware {}
