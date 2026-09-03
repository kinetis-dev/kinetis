<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use Kinetis\Http\Middleware\Exception\HttpStatusMappingException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Logging\SafeLogger;
use Kinetis\Runtime\AppEnvironment;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Registered unconditionally as global middleware by Kernel, second only
 * to SecurityHeadersMiddleware (see GlobalMiddlewareOrder::resolve() for
 * the exact order) — the same unconditional-dispose-hook reasoning
 * TransactionGuard::rollbackDangling() uses. An uncaught exception from
 * anywhere in the pipeline — a controller, a route-level middleware,
 * application code in general — is caught here instead of propagating
 * out of Kernel::handle() entirely: one implementing
 * Kinetis\Http\Exception\HttpStatusExceptionInterface becomes the status
 * it declares, everything else becomes a 500.
 *
 * Resolved through the container (see Kernel), so $logger is whatever
 * LoggerInterface AppScope::boot() ended up binding — the consumer's own
 * registration if they made one, the environment's default otherwise —
 * and $environment is the AppEnvironment boot() registered. In
 * development, the 500 body carries the exception's class, message, and
 * file:line so a mistake is diagnosable straight from the response;
 * production keeps the generic body with the detail going only to the
 * logger. The parameter defaults to Production so a directly-constructed
 * instance never leaks detail by accident.
 *
 * This is the boundary that guarantees a 500 for anything else in the
 * pipeline, so it must not have a boundary-shaped hole of its own: the
 * logger call goes through SafeLogger, and the development body's JSON
 * encoding tolerates an exception message that isn't valid UTF-8 — a
 * consumer's own logger throwing, or an exception constructed from
 * arbitrary bytes, must never be the reason this class fails to produce
 * the response it exists to guarantee.
 *
 * **Converting an `HttpStatusExceptionInterface` is itself inside the
 * terminal boundary, not a sibling catch of it.** A single `catch
 * (Throwable $e)` — not two sibling `catch` clauses — is what makes this
 * possible: PHP never lets a later sibling `catch` see an exception
 * thrown from inside an earlier one's own body, so a naive `catch
 * (HttpStatusExceptionInterface) {} catch (Throwable) {}` pair would let
 * a broken implementation (a throwing `httpStatus()`, or a status
 * `ErrorResponse::create()` can't even construct a response for) escape
 * this method entirely. Instead, the one catch block tries the
 * `HttpStatusExceptionInterface` conversion itself, inside its own nested
 * try — `tryHttpStatusResponse()` — and falls through to the exact same
 * generic-500 path any other uncaught `Throwable` takes on any failure:
 * `httpStatus()` throwing, or returning something outside the 400-599
 * range the interface requires (see its own docblock). The fallback is
 * logged with the *original* exception, plus the mapping failure itself
 * (a `HttpStatusMappingException`) as extra context when there was a
 * concrete secondary `Throwable` to report — never silently discarded.
 */
final readonly class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    private const int MIN_HTTP_ERROR_STATUS = 400;
    private const int MAX_HTTP_ERROR_STATUS = 599;

    public function __construct(
        private LoggerInterface $logger,
        private AppEnvironment $environment = AppEnvironment::Production,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $mappingFailure = null;

            if ($e instanceof HttpStatusExceptionInterface) {
                $response = $this->tryHttpStatusResponse($e, $mappingFailure);

                if ($response !== null) {
                    // A well-formed declared HTTP error — a valid
                    // 4xx or 5xx a satellite package's own exception
                    // raised from inside a controller — not logged as
                    // a framework bug, and not gated behind
                    // $environment: the exception's own message is
                    // already meant to be shown.
                    return $response;
                }
            }

            // Best-effort: logging here is diagnostic, not load-bearing —
            // a consumer-supplied logger that itself throws must never
            // escape this catch-all and defeat the 500 it exists to
            // guarantee. See SafeLogger's own docblock.
            $context = [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri()->getPath(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ];

            if ($mappingFailure !== null) {
                $context['mappingFailure'] = $mappingFailure;
            }

            SafeLogger::log($this->logger, LogLevel::ERROR, 'Unhandled exception while handling {method} {path}: {message}', $context);

            return $this->internalErrorResponse($e);
        }
    }

    /**
     * Returns the declared-status response, or null when $e's own
     * HttpStatusExceptionInterface implementation is broken —
     * httpStatus() itself threw, or returned something outside the
     * 400-599 range the interface requires. null is process()'s signal
     * to fall through to the same generic path any other uncaught
     * Throwable takes; $mappingFailure is set to a HttpStatusMappingException
     * describing what went wrong, for that same fallback to log.
     *
     * $e is typed as the native intersection Throwable&HttpStatusExceptionInterface,
     * not just HttpStatusExceptionInterface — the caller only ever has
     * one because it came from a caught Throwable already narrowed by
     * instanceof, and this expresses that locally rather than requiring
     * the public interface itself to extend Throwable, which no real
     * implementer was ever actually short of (only being thrown does
     * that, and this interface has no say over that).
     */
    private function tryHttpStatusResponse(Throwable&HttpStatusExceptionInterface $e, ?HttpStatusMappingException &$mappingFailure): ?ResponseInterface
    {
        try {
            $status = $e->httpStatus();
        } catch (Throwable $cause) {
            $mappingFailure = HttpStatusMappingException::threw($e, $cause);

            return null;
        }

        if ($status < self::MIN_HTTP_ERROR_STATUS || $status > self::MAX_HTTP_ERROR_STATUS) {
            $mappingFailure = HttpStatusMappingException::invalidStatus($e, $status);

            return null;
        }

        try {
            return ErrorResponse::create($status, $e->getMessage());
        } catch (Throwable $cause) {
            $mappingFailure = HttpStatusMappingException::threw($e, $cause);

            return null;
        }
    }

    private function internalErrorResponse(Throwable $e): ResponseInterface
    {
        if ($this->environment->isProduction()) {
            return ErrorResponse::create(500, 'Internal server error.');
        }

        return new Response(
            status: 500,
            headers: ['Content-Type' => 'application/json'],
            // JSON_INVALID_UTF8_SUBSTITUTE protects arbitrary text
            // anywhere in this payload, not just $e->getMessage(): a
            // Unix filename is a byte sequence with no UTF-8 guarantee
            // either, so $e->getFile() is not provably safe just because
            // it looks like a path. Applying the flag to the whole
            // json_encode() call, rather than trying to single out which
            // field might need it, is what makes that distinction moot —
            // nothing here has to be proven safe individually for this
            // diagnostic 500 to never fail to encode.
            body: json_encode([
                'error' => 'Internal server error.',
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
