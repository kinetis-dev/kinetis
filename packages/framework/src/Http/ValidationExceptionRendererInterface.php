<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Validation\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a validation failure into the response this application wants
 * for it. {@see Middleware\ExceptionHandlerMiddleware} resolves one
 * implementation and calls it for every `ValidationException` that
 * reaches the terminal boundary, whatever raised it — a #[Body] DTO
 * field, a #[Query]/path value, a route middleware, or an application
 * service that validated something hydration cannot see.
 *
 * {@see ProblemDetailsValidationExceptionRenderer} is the default. An
 * application binds its own implementation to this interface before
 * `AppScope::boot()` to redirect, render HTML, emit a domain-specific
 * body, translate messages, spell paths its own way, or answer with a
 * different status; nothing else has to change, since the middleware
 * autowires whatever is bound.
 *
 * Every status is allowed — a 422 document, a 303 back to the form, and
 * a deliberately re-rendered 200 page are all application policy — so
 * this contract states no range beyond the `ResponseInterface` the
 * return type already guarantees.
 *
 * An implementation is resolved from `AppScope` and shared by every
 * request, so it must be worker-safe: constructor dependencies only,
 * and neither the request nor the exception retained past `render()`.
 * By the time it runs, `Kernel::dispatchCore()` has already disposed
 * the request scope, so a request-scoped service (a session, say) is
 * gone; a workflow needing one catches `ValidationException` in route
 * or application middleware instead, closer to the controller.
 *
 * A `render()` call that throws cannot defeat the terminal boundary:
 * the middleware contains it, logs the original failure with the
 * rendering failure as context, and answers with its generic 500.
 */
interface ValidationExceptionRendererInterface
{
    public function render(ValidationException $exception, ServerRequestInterface $request): ResponseInterface;
}
