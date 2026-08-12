<?php

declare(strict_types=1);

namespace Kinetis\Config\Exception;

use RuntimeException;

final class MissingConfigException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Missing required config value \"{$key}\".");
    }
}
