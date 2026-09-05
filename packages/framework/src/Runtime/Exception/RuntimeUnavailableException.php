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

    /**
     * The SAPI is running, but not configured the way a Kinetis adapter
     * requires. A deployment problem rather than a client error, so it
     * propagates as a server failure instead of becoming a response —
     * and it is detected rather than assumed, because a setting whose
     * absence silently changes what a handler receives is worse than one
     * that fails loudly.
     */
    public static function misconfiguredSapi(string $requirement): self
    {
        return new self($requirement);
    }

    public static function missingAdapterPackage(string $adapter, string $package): self
    {
        return new self("Cannot run {$adapter}: install \"{$package}\" to enable it.");
    }
}
