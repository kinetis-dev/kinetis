<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

final class RouteNotFoundException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self("No route matches path \"{$path}\".");
    }
}
