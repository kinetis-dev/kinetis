<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Exception;

use RuntimeException;

final class UnknownMiddlewareGroupException extends RuntimeException
{
    /**
     * @param list<string> $knownGroups
     */
    public static function forRoute(string $group, string $controllerClass, string $controllerMethod, array $knownGroups): self
    {
        $known = $knownGroups === [] ? 'none are declared' : 'declared groups: ' . implode(', ', $knownGroups);

        return new self(
            "{$controllerClass}::{$controllerMethod}() references middleware group \"{$group}\", which no class declares "
            . "with #[AsMiddlewareGroup]. Add that attribute to the middleware class that belongs to it ({$known}).",
        );
    }
}
