<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a plain closure to PSR-15's RequestHandlerInterface — the
 * terminal handler at the innermost end of a MiddlewarePipeline is
 * usually "just call this one method," not a class worth naming on its
 * own. Typed as Closure rather than callable: PHP doesn't allow callable
 * as a property type at all, and every real call site already passes a
 * Closure (a first-class callable reference or an arrow function).
 */
final readonly class CallableRequestHandler implements RequestHandlerInterface
{
    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(
        private Closure $handler,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}
