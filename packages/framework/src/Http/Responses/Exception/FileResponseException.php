<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses\Exception;

use RuntimeException;

final class FileResponseException extends RuntimeException
{
    public static function invalidDownloadFilename(string $reason): self
    {
        return new self("Cannot build a FileResponse: the download filename {$reason}.");
    }
}
