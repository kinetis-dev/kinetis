<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses\Exception;

use RuntimeException;

final class FileResponseException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self("Cannot build a FileResponse: \"{$path}\" does not exist or is not a file.");
    }
}
