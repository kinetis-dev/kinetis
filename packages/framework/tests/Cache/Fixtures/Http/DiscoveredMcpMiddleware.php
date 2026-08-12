<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\AsMcpMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

#[AsMcpMiddleware]
final class DiscoveredMcpMiddleware extends RecordingMiddleware {}
