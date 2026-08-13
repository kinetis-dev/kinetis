<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use RuntimeException;

final class MalformedRequestBodyException extends RuntimeException
{
    public static function invalidJson(): self
    {
        return new self('Request body is not valid JSON.');
    }

    public static function notAnObject(): self
    {
        return new self('Request body must be a JSON object.');
    }
}
