<?php

declare(strict_types=1);

namespace Kinetis\Runtime\Exception;

use RuntimeException;

final class RuntimeUnavailableException extends RuntimeException
{
    public static function missingFunction(string $adapter, string $function): self
    {
        return new self("Cannot run {$adapter}: \"{$function}()\" does not exist in this environment.");
    }

    public static function missingEnvironmentVariable(string $adapter, string $variable): self
    {
        return new self("Cannot run {$adapter}: environment variable \"{$variable}\" is not set.");
    }

    public static function missingAdapterPackage(string $adapter, string $package): self
    {
        return new self("Cannot run {$adapter}: install \"{$package}\" to enable it.");
    }
}
