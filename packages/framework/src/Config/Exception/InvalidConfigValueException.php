<?php

declare(strict_types=1);

namespace Kinetis\Config\Exception;

use RuntimeException;

final class InvalidConfigValueException extends RuntimeException
{
    public static function notAnInteger(string $key, string $value): self
    {
        return new self("Config value \"{$key}\" is not a valid integer, got \"{$value}\".");
    }

    public static function notAFloat(string $key, string $value): self
    {
        return new self("Config value \"{$key}\" is not a valid number, got \"{$value}\".");
    }

    public static function notABoolean(string $key, string $value): self
    {
        return new self(
            "Config value \"{$key}\" is not a recognized boolean, got \"{$value}\". "
            . 'Use "true"/"false", "1"/"0", "on"/"off", or "yes"/"no".',
        );
    }
}
