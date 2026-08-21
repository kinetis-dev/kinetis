<?php

declare(strict_types=1);

namespace Kinetis\Container\Exception;

final class DisconnectedRequestScopeException extends ContainerException
{
    /**
     * @param list<string> $path
     */
    public static function forPath(array $path): self
    {
        return new self('Cannot resolve RequestScope from AppScope: ' . implode(' -> ', $path));
    }
}
