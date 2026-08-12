<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

#[AsGlobalMiddleware]
final class DiscoveredGlobalMiddleware extends RecordingMiddleware {}
