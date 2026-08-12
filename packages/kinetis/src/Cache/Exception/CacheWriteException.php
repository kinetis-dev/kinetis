<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use RuntimeException;

final class CacheWriteException extends RuntimeException
{
    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self("Could not create cache directory \"{$directory}\".");
    }

    public static function couldNotWriteTemporaryFile(string $path): self
    {
        return new self("Could not write temporary cache file \"{$path}\".");
    }

    public static function couldNotPublish(string $path): self
    {
        return new self("Could not publish compiled cache to \"{$path}\".");
    }
}
