<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\RoutePrefix;

#[RoutePrefix('bad')]
final class UnrootedPrefixMiddleware extends RecordingMiddleware {}
