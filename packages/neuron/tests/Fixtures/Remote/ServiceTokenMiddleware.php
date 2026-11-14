<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Remote;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Identifies a caller presenting the fixture's service token, as an application's authentication middleware would. */
final readonly class ServiceTokenMiddleware implements MiddlewareInterface
{
    public const string TOKEN = 'service-token';

    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('Authorization') === 'Bearer ' . self::TOKEN) {
            $this->scope->instance(CurrentUserInterface::class, new ServiceUser());
        }

        return $handler->handle($request);
    }
}
