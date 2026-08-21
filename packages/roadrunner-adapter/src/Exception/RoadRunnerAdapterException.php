<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Exception;

use RuntimeException;

final class RoadRunnerAdapterException extends RuntimeException
{
    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open a php://temp stream to parse a multipart body.');
    }
}
