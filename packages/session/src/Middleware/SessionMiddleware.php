<?php

declare(strict_types=1);

namespace Kinetis\Session\Middleware;

use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Session\Exception\SessionException;
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
 *
 * `SESSION_COOKIE` may carry a cookie name prefix, which is what makes
 * the attributes above enforceable by the browser rather than merely
 * requested: a browser refuses to store a `__Secure-` cookie that is not
 * marked Secure, and a `__Host-` cookie that is not Secure, not
 * `Path=/`, or scoped to a Domain. Every cookie written here is
 * `Path=/` with no Domain, so both prefixes work as soon as
 * `SESSION_SECURE` is on:
 *
 *     SESSION_COOKIE=__Host-kinetis_session
 *
 * The prefix has to reach the browser to mean anything, so an
 * unsatisfiable combination is refused at construction. The alternative
 * is a cookie the browser silently drops on every response, which
 * presents as sessions that never persist and says nothing about why.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    private const string ID_PATTERN = '/^[a-f0-9]{32}$/';

    /**
     * RFC 6265's own cookie-name grammar. Enforced because the name goes
     * into a Set-Cookie header verbatim, where a separator or control
     * character is a malformed cookie at best and a second header at
     * worst.
     */
    private const string NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /** The only values a browser recognises for the SameSite attribute. */
    private const array SAME_SITE_VALUES = ['Strict', 'Lax', 'None'];

    private string $cookieName;

    private bool $secure;
    private string $sameSite;

    public function __construct(
        private RequestScope $scope,
        private SessionStoreInterface $store,
        private Config $config,
    ) {
        $this->cookieName = $config->string('SESSION_COOKIE', 'kinetis_session');
        $this->secure = $config->bool('SESSION_SECURE', true);
        $this->sameSite = ucfirst(strtolower($config->string('SESSION_SAMESITE', 'Lax')));

        if (!\in_array($this->sameSite, self::SAME_SITE_VALUES, true)) {
            $accepted = implode(', ', self::SAME_SITE_VALUES);

            throw new SessionException(
                "SESSION_SAMESITE \"{$config->string('SESSION_SAMESITE', 'Lax')}\" is not a SameSite value. "
                . "Use one of {$accepted} — anything else is written into the cookie header verbatim, where a "
                . 'browser ignores the attribute and may drop the cookie.',
            );
        }

        if ($this->sameSite === 'None' && !$this->secure) {
            throw new SessionException(
                'SESSION_SAMESITE is None, which a browser only accepts on a Secure cookie, but SESSION_SECURE '
                . 'is off — the cookie would be dropped and every session lost. Turn SESSION_SECURE on, or use '
                . 'Lax for non-TLS local development.',
            );
        }

        if (\preg_match(self::NAME_PATTERN, $this->cookieName) !== 1) {
            throw new SessionException(
                "SESSION_COOKIE \"{$this->cookieName}\" is not a valid cookie name. "
                . 'Use only letters, digits, and !#$%&\'*+-.^_`|~ — no spaces, commas, semicolons, or quotes.',
            );
        }

        $prefix = $this->cookiePrefix();

        if ($prefix !== null && !$this->secure) {
            throw new SessionException(
                "SESSION_COOKIE \"{$this->cookieName}\" carries the {$prefix} prefix, which a browser only "
                . 'accepts on a Secure cookie, but SESSION_SECURE is off. Turn SESSION_SECURE on, or drop the '
                . 'prefix for non-TLS local development.',
            );
        }
    }

    /**
     * Prefix matching is case-sensitive, as the specification defines it
     * — `__host-` is an ordinary name carrying no guarantee at all, and
     * treating it as one would promise something no browser enforces.
     */
    private function cookiePrefix(): ?string
    {
        foreach (['__Host-', '__Secure-'] as $prefix) {
            if (\str_starts_with($this->cookieName, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $raw = $this->cookieValue($request, $this->cookieName);
        $cookieId = $raw !== null && \preg_match(self::ID_PATTERN, $raw) === 1 ? $raw : null;

        $session = new Session($this->store, $cookieId);
        $this->scope->instance(Session::class, $session);

        $response = $handler->handle($request);

        $lifetime = $this->config->int('SESSION_LIFETIME', 7200);

        if ($session->isDestroyed()) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie('', -1));
        }

        $needsCookie = $session->commit($lifetime);

        if ($needsCookie) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie($session->id(), $lifetime));
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

    private function cookie(string $value, int $lifetimeSeconds): string
    {
        $attributes = [
            $this->cookieName . '=' . $value,
            'Path=/',
            'HttpOnly',
            'SameSite=' . $this->sameSite,
        ];

        if ($lifetimeSeconds >= 0) {
            $attributes[] = 'Max-Age=' . $lifetimeSeconds;
        } else {
            // Expiring the cookie: a past date plus Max-Age=0.
            $attributes[] = 'Max-Age=0';
            $attributes[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }

        if ($this->secure) {
            $attributes[] = 'Secure';
        }

        return \implode('; ', $attributes);
    }
}
