<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use RuntimeException;

/** Its own method throws — for proving a controller's real exception survives a failing telemetry backend. */
final readonly class TelemetryThrowingController
{
    #[Get('/telemetry-throws')]
    public function boom(): never
    {
        throw new RuntimeException('the real controller failure');
    }
}
