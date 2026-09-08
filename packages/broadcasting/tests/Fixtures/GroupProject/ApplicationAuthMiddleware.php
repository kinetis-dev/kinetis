<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures\GroupProject;

use Kinetis\Broadcasting\Tests\Fixtures\FakeCurrentUser;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An application's own `broadcasting`-group authentication middleware,
 * joining at the attribute's default priority — the shape a thin
 * subclass of kinetis/auth's BearerAuthMiddleware has from the
 * pipeline's point of view. Registers an identity for a valid credential
 * and delegates without one rather than answering 401 itself, so an
 * uncredentialed request still reaches an anonymous channel authorizer.
 */
#[AsMiddlewareGroup('broadcasting')]
final readonly class ApplicationAuthMiddleware implements MiddlewareInterface
{
    public const string TOKEN_HEADER = 'X-Fixture-Token';

    public function __construct(
        private RequestScope $scope,
        private AuthAttemptLog $log,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->attempts++;

        if ($request->getHeaderLine(self::TOKEN_HEADER) === 'valid') {
            $this->scope->instance(CurrentUserInterface::class, new FakeCurrentUser('7'));
        }

        return $handler->handle($request);
    }
}
