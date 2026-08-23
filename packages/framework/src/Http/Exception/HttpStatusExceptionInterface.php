<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

/**
 * Lets an exception raised from inside a controller — not just Kernel's
 * or Dispatcher's own control flow — declare which HTTP status it maps
 * to. ExceptionHandlerMiddleware checks for this before falling back to
 * a generic 500: MalformedRequestBodyException/ValidationException are
 * caught by name because Dispatcher raises them itself, but an exception
 * a satellite package throws from application code reaches
 * ExceptionHandlerMiddleware directly, and core can never catch a
 * satellite package's own exception class by name. This interface is
 * the seam: core defines it, any package's exception can implement it,
 * with no dependency running the wrong direction.
 *
 * getMessage() is used as the response body's error text — the same
 * "this message is already meant to be client-visible" trust
 * MalformedRequestBodyException's own message already gets.
 */
interface HttpStatusExceptionInterface
{
    public function httpStatus(): int;
}
