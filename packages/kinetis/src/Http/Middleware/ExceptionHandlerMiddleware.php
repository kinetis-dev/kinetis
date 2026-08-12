<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registered unconditionally as the outermost global middleware by
 * Kernel — the same unconditional-dispose-hook reasoning
 * TransactionGuard::rollbackDangling() uses. An uncaught exception from
 * anywhere in the pipeline — a controller, a route-level middleware,
 * application code in general — is caught here and converted into a 500
 * instead of propagating out of Kernel::handle() entirely.
 *
 * Resolved through the container (see Kernel), so $logger is whatever
 * LoggerInterface AppScope::boot() ended up binding — the consumer's own
 * registration if they made one, a NullLogger otherwise.
 */
final readonly class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception while handling {method} {path}: {message}', [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri()->getPath(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ErrorResponse::create(500, 'Internal server error.');
        }
    }
}
