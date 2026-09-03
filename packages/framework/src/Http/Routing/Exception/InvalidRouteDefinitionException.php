<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A route's own non-path fields — the HTTP method, the response status,
 * the controller class/method identifiers, a middleware reference —
 * don't hold a valid value. Every real `RouteAttribute` implementation
 * (`Get`, `Post`, ...) already returns a normalized method token and a
 * real int status, so this is unreachable from a genuine attribute in
 * source code; it exists for the same reason `Route`'s own path
 * validation does — a value replayed from a compiled cache artifact has
 * no such guarantee, and `Route`'s constructor is the one place both the
 * live and cached path funnel through.
 */
final class InvalidRouteDefinitionException extends RuntimeException
{
    public static function invalidHttpMethod(string $httpMethod): self
    {
        return new self(
            "\"{$httpMethod}\" is not a valid HTTP method token — it must be one or more of an "
            . "uppercase ASCII letter, a digit, or one of !#\$%&'*+-.^_`|~, with no separators, "
            . 'whitespace, or control characters.',
        );
    }

    public static function statusOutOfRange(int $status): self
    {
        return new self("{$status} is not a valid HTTP response status — it must be between 100 and 599.");
    }

    public static function invalidControllerClass(string $controllerClass): self
    {
        return new self("\"{$controllerClass}\" is not a valid class-string for a route's controller.");
    }

    public static function invalidControllerMethod(string $controllerMethod): self
    {
        return new self("\"{$controllerMethod}\" is not a valid method name for a route's controller.");
    }

    public static function invalidMiddlewareReference(string $reference): self
    {
        return new self("\"{$reference}\" is not a valid middleware reference — it must be a class-string or an \"@name\" group reference.");
    }
}
