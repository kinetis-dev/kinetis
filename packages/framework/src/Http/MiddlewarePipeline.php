<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Instrumentation\Telemetry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The standard PSR-15 "onion": each middleware wraps the next handler in
 * the list, terminating at $core once the list is exhausted. Kernel uses
 * this for two independent pipelines — a global one wrapping the entire
 * request, and a per-route one wrapping just Dispatcher::dispatch() —
 * rather than building two separate composition mechanisms.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $core,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $remaining = $this->middleware;
        $next = array_shift($remaining);

        if ($next === null) {
            return $this->core->handle($request);
        }

        $telemetry = Telemetry::global();
        $token = $telemetry->middlewareEntered($next::class);

        try {
            $response = $next->process($request, new self($remaining, $this->core));
            $telemetry->middlewareExited($token, null);

            return $response;
        } catch (Throwable $e) {
            $telemetry->middlewareExited($token, $e);

            throw $e;
        }
    }
}
