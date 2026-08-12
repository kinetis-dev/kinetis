<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

#[AsGlobalMiddleware(priority: 10)]
final class LowPriorityMiddleware extends RecordingMiddleware {}
