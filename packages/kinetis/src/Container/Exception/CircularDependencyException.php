<?php

declare(strict_types=1);

namespace Kinetis\Container\Exception;

final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $path
     */
    public static function forPath(array $path): self
    {
        return new self('Circular dependency detected: ' . implode(' -> ', $path));
    }
}
