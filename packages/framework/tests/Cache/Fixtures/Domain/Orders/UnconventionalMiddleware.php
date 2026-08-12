<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Domain\Orders;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

#[AsGlobalMiddleware]
final class UnconventionalMiddleware extends RecordingMiddleware {}
