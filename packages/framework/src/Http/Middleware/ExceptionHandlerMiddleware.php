<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Runtime\AppEnvironment;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
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
 */
final readonly class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private AppEnvironment $environment = AppEnvironment::Production,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpStatusExceptionInterface $e) {
            // A well-formed client error a satellite package's own
            // exception raised from inside a controller — not a bug, so
            // not logged as one, and not gated behind $environment: the
            // exception's own message is already meant to be shown.
            return ErrorResponse::create($e->httpStatus(), $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception while handling {method} {path}: {message}', [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri()->getPath(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            if ($this->environment->isProduction()) {
                return ErrorResponse::create(500, 'Internal server error.');
            }

            return new Response(
                status: 500,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode([
                    'error' => 'Internal server error.',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'location' => $e->getFile() . ':' . $e->getLine(),
                ], JSON_THROW_ON_ERROR),
            );
        }
    }
}
