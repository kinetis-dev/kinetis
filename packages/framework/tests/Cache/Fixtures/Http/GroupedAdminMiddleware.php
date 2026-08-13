<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

#[AsMiddlewareGroup('admin', priority: 50)]
final class GroupedAdminMiddleware extends RecordingMiddleware {}
