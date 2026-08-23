<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Exception;

use RuntimeException;

final class BodyTooLargeException extends RuntimeException
{
    public static function exceeds(int $maxBytes): self
    {
        return new self("Request body exceeds the maximum allowed size of {$maxBytes} bytes.");
    }
}
