<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use RuntimeException;

final class StreamException extends RuntimeException
{
    public static function couldNotOpenTempStream(): self
    {
        return new self('Could not open a php://temp stream.');
    }

    public static function couldNotDetermineStreamPosition(): self
    {
        return new self('Could not determine the stream position.');
    }

    public static function couldNotSeekStream(): self
    {
        return new self('Could not seek the stream.');
    }

    public static function couldNotWriteToStream(): self
    {
        return new self('Could not write to the stream.');
    }

    public static function couldNotReadFromStream(): self
    {
        return new self('Could not read from the stream.');
    }

    public static function couldNotReadRemainingStreamContents(): self
    {
        return new self('Could not read the remaining stream contents.');
    }
}
