<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Exception;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;

/**
 * Thrown by Gate::authorize() when a check denies. Declaring its own
 * status through core's HttpStatusExceptionInterface is what makes a
 * denial reach the client as a 403 from any route: the
 * ExceptionHandlerMiddleware Kernel includes unconditionally — second in
 * the global pipeline, inside SecurityHeadersMiddleware — reads the
 * status off this interface, so installing this package wires nothing
 * and there is no middleware for an application to register, order, or
 * accidentally leave out.
 *
 * getMessage() carries whichever message the denying AuthorizationResponse
 * resolved to and becomes the response body's error text — the
 * client-visible trust every HttpStatusExceptionInterface message gets.
 * A Policy method's denial message is written for the client for exactly
 * that reason.
 */
final class AuthorizationException extends RuntimeException implements HttpStatusExceptionInterface
{
    #[\Override]
    public function httpStatus(): int
    {
        return 403;
    }

    public static function denied(string $message): self
    {
        return new self($message);
    }
}
