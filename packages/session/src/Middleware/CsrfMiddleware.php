<?php

declare(strict_types=1);

namespace Kinetis\Session\Middleware;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Synchronizer-token CSRF protection: state-changing requests must carry
 * the token bound to the session, via an `X-CSRF-Token` header or a
 * `_token` body field. Safe methods (GET/HEAD/OPTIONS) pass untouched —
 * they are what fetches the token in the first place, through
 * `Session::csrfToken()` rendered into a page or handed to a client.
 *
 * Route middleware, stacked *after* SessionMiddleware in declaration
 * order — it reads the Session that middleware registered:
 *
 *     #[Middleware(SessionMiddleware::class)]
 *     #[Middleware(CsrfMiddleware::class)]
 *
 * A missing or mismatched token is a 403 with a stable JSON body;
 * comparison is hash_equals(), so a byte-wise timing probe learns
 * nothing about the real token.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (\in_array(\strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if (!$this->scope->has(Session::class)) {
            // Declaration-order mistake, not an attack: without
            // SessionMiddleware ahead of this one there is no session to
            // hold a token, so every request would 403 mysteriously.
            return ErrorResponse::create(500, 'CsrfMiddleware needs SessionMiddleware declared before it on this route.');
        }

        /** @var Session $session */
        $session = $this->scope->get(Session::class);
        $submitted = $this->submittedToken($request);

        if ($submitted === null || !\hash_equals($session->csrfToken(), $submitted)) {
            return ErrorResponse::create(403, 'CSRF token mismatch.');
        }

        return $handler->handle($request);
    }

    private function submittedToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-CSRF-Token');

        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();

        if (\is_array($body) && \is_string($body['_token'] ?? null)) {
            return $body['_token'];
        }

        return null;
    }
}
