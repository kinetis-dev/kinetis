<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Http;

use Kinetis\Config\Config;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Origin validation for `POST /broadcasting/auth`, which a browser posts
 * to on the subscriber's behalf with whatever session cookie or
 * `Authorization` header the page already carries — so an origin that
 * was never allowed to ask is refused before any authentication
 * middleware or authorizer in the `broadcasting` group runs.
 *
 * A request passes carrying no `Origin` header (any non-browser client),
 * carrying the request URI's own `scheme://authority`, or carrying an
 * exact entry from `BROADCAST_ALLOWED_ORIGINS`, a comma-separated list
 * empty by default. Anything else is `403`. Comparison is exact string
 * equality: an `Origin` is `scheme://host[:port]` with no path, trailing
 * slash, userinfo or default port, the same normalized form
 * `UriInterface::getScheme()`/`getAuthority()` produce, so a value
 * differing in any of those — including the `null` a sandboxed frame
 * sends — matches only by being configured verbatim. This is the route's
 * own guard, not a CORS policy: a browser's cross-origin request still
 * has to be admitted by the application's global `CorsMiddleware` too.
 *
 * Priority 100 puts it ahead of whatever an application adds at the
 * default 50, and its membership is what guarantees the group
 * {@see BroadcastAuthController} references exists.
 */
#[AsMiddlewareGroup('broadcasting', priority: 100)]
final readonly class BroadcastOriginMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if (
            $origin === ''
            || $origin === self::requestOrigin($request->getUri())
            || \in_array($origin, $this->allowedOrigins(), true)
        ) {
            return $handler->handle($request);
        }

        return ErrorResponse::create(403, "Origin \"{$origin}\" is not allowed to authorize broadcast channels.");
    }

    /**
     * Null when the URI carries no authority — a request built by hand
     * from a path alone — so the same-origin allowance is skipped rather
     * than reduced to a bare `scheme://` nothing should match.
     */
    private static function requestOrigin(UriInterface $uri): ?string
    {
        $authority = $uri->getAuthority();

        return $authority === '' ? null : $uri->getScheme() . '://' . $authority;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $configured = $this->config->string('BROADCAST_ALLOWED_ORIGINS', '');

        if ($configured === '') {
            return [];
        }

        // Trimmed, so a space after a comma in .env is not read as part
        // of the next origin.
        return \array_map(\trim(...), \explode(',', $configured));
    }
}
