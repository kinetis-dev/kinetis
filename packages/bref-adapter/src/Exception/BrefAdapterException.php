<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Exception;

use RuntimeException;

final class BrefAdapterException extends RuntimeException
{
    public static function streamingNotSupported(): self
    {
        return new self(
            'BrefLambdaAdapter cannot emit a streaming response — the Lambda Runtime API '
            . 'supports exactly one response payload per invocation, with no mechanism for '
            . 'incremental delivery.',
        );
    }

    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open a php://temp stream to parse a multipart body.');
    }
}
