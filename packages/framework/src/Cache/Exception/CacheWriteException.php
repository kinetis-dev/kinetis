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

    public static function couldNotRemove(string $path): self
    {
        return new self("Could not remove \"{$path}\" while destroying the cache directory.");
    }

    public static function unexportableObject(string $file, string $keyPath, string $class): self
    {
        return new self(
            "Cannot compile \"{$file}\": an instance of {$class} at \"{$keyPath}\" has no var_export() "
            . 'representation that can be required back. Most commonly this is a constructor default '
            . 'value that constructs an object — replace it with a plain scalar/array default, or make '
            . 'the parameter nullable and construct the object in the constructor body.',
        );
    }
}
