<?php

declare(strict_types=1);

namespace Kinetis\Session\Middleware;

use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Session\Session;
use Kinetis\Session\SessionStoreInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware only, never global — the same constraint, for the
 * same structural reason, as Kinetis\Auth\BearerAuthMiddleware: it
 * registers the request's {@see Session} on the request's own
 * RequestScope so controllers can constructor-inject it, and only route
 * middleware is resolved through that scope. Attach it per route, per
 * controller, or as a middleware group:
 *
 *     #[Middleware(SessionMiddleware::class)]
 *
 * Reads the session cookie (rejecting anything that isn't a wellformed
 * id), hands the handler a lazily-loading Session, and afterwards
 * persists and sets the cookie — but only when the session was actually
 * written to. A route that never touches its session performs no
 * storage round trip and sends no Set-Cookie, so attaching this
 * middleware broadly costs nothing on session-free requests.
 *
 * Cookie attributes: HttpOnly always (script access to a session id has
 * no legitimate use), SameSite and Secure from configuration —
 * `SESSION_SECURE=false` is for non-TLS local development only.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    private const string ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private RequestScope $scope,
        private SessionStoreInterface $store,
        private Config $config,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookieName = $this->config->string('SESSION_COOKIE', 'kinetis_session');
        $raw = $this->cookieValue($request, $cookieName);
        $cookieId = $raw !== null && \preg_match(self::ID_PATTERN, $raw) === 1 ? $raw : null;

        $session = new Session($this->store, $cookieId);
        $this->scope->instance(Session::class, $session);

        $response = $handler->handle($request);

        $lifetime = $this->config->int('SESSION_LIFETIME', 7200);

        if ($session->isDestroyed()) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie($cookieName, '', -1));
        }

        $needsCookie = $session->commit($lifetime);

        if ($needsCookie) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie($cookieName, $session->id(), $lifetime));
        }

        return $response;
    }

    /**
     * cookieParams first — what every real runtime adapter populates —
     * with the raw Cookie header as fallback, so a hand-built PSR-7
     * request (Kinetis\Testing\TestClient's included) works by just
     * setting the header.
     */
    private function cookieValue(ServerRequestInterface $request, string $name): ?string
    {
        $params = $request->getCookieParams();

        if (\is_string($params[$name] ?? null)) {
            return $params[$name];
        }

        foreach (\explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            [$key, $value] = \array_pad(\explode('=', \trim($pair), 2), 2, null);

            if ($key === $name && $value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function cookie(string $name, string $value, int $lifetimeSeconds): string
    {
        $attributes = [
            $name . '=' . $value,
            'Path=/',
            'HttpOnly',
            'SameSite=' . $this->config->string('SESSION_SAMESITE', 'Lax'),
        ];

        if ($lifetimeSeconds >= 0) {
            $attributes[] = 'Max-Age=' . $lifetimeSeconds;
        } else {
            // Expiring the cookie: a past date plus Max-Age=0.
            $attributes[] = 'Max-Age=0';
            $attributes[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }

        if ($this->config->bool('SESSION_SECURE', true)) {
            $attributes[] = 'Secure';
        }

        return \implode('; ', $attributes);
    }
}
