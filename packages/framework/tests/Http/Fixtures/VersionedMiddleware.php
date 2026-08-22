<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\RoutePrefix;

#[RoutePrefix('/v1')]
final class VersionedMiddleware extends RecordingMiddleware {}
