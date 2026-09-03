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
 *
 * **`httpStatus()` must return an HTTP error status, 400 through 599
 * inclusive, and must not throw.** A 1xx/2xx/3xx value would misrepresent
 * a real error as a success or redirect; a value outside PSR-7's own
 * supported range can fail to even construct a response. Neither is
 * trusted at face value: ExceptionHandlerMiddleware validates the
 * returned status and contains any failure raised while calling this
 * method, treating a violation as a broken implementation of this
 * interface — logged and mapped to a generic 500 the same as any other
 * uncaught `Throwable`, never propagated and never turned into a
 * non-error response.
 *
 * Deliberately does not extend `Throwable` — nothing about this
 * interface's own contract requires implementing it, only reaching
 * `ExceptionHandlerMiddleware` by actually being thrown does, and this
 * interface has no say over that. `ExceptionHandlerMiddleware` only ever
 * reasons about an instance already known to be `Throwable` (it always
 * comes from a `catch (Throwable $e)` clause), so it expresses that
 * locally — a native `Throwable&HttpStatusExceptionInterface`
 * intersection type where it matters — rather than widening this public
 * interface to require something a downstream implementer was never
 * actually short of.
 */
interface HttpStatusExceptionInterface
{
    public function httpStatus(): int;
}
