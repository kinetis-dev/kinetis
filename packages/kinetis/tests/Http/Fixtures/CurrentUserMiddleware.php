<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for a real auth middleware: resolves a user (a fixed value
 * here) and registers it on the current RequestScope, via constructor
 * injection of the scope itself — proving AppScope::createRequestScope()'s
 * self-registration actually lets a request-scoped class reach the real,
 * current scope rather than a disconnected new one.
 */
final readonly class CurrentUserMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->scope->instance(CurrentUserInterface::class, new FakeCurrentUser('user-42'));

        return $handler->handle($request);
    }
}
