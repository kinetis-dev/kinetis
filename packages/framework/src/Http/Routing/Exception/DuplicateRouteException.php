<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use Kinetis\Http\Routing\Route;
use RuntimeException;

final class DuplicateRouteException extends RuntimeException
{
    public static function forConflict(Route $existing, Route $new): self
    {
        return new self(sprintf(
            '%s %s is already registered by %s::%s() — %s::%s() claims exactly the same requests. '
            . 'Matching is first-match-wins, so the second registration would silently never run.',
            $new->httpMethod,
            $new->pathTemplate,
            $existing->controllerClass,
            $existing->controllerMethod,
            $new->controllerClass,
            $new->controllerMethod,
        ));
    }
}
