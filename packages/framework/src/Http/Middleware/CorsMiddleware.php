<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-Origin Resource Sharing. Global middleware only, deliberately —
 * unlike RateLimitMiddleware, this isn't usable as route middleware at
 * all: a preflight OPTIONS request to a path with no registered OPTIONS
 * route would never reach a route's own middleware in the first place.
 * Route middleware only wraps Dispatcher::dispatch(), which only runs
 * after a successful route match — a preflight to an unmatched method
 * fails with MethodNotAllowedException before that. Registering this on
 * AppScope intercepts the preflight before routing runs at all, the same
 * reasoning ExceptionHandlerMiddleware and the OpenAPI/MCP short-circuits
 * already rely on.
 *
 * A request with no Origin header, or an Origin not on the allow list, is
 * passed through completely untouched — no CORS headers added, and no
 * error status returned either. This is deliberate, not a missing branch:
 * it's the browser's own same-origin policy that actually blocks a
 * disallowed cross-origin response, once it doesn't see an
 * Access-Control-Allow-Origin header naming it. Nothing server-side needs
 * to reject the request itself.
 *
 * `$allowedOrigins` defaults to `[]` (deny-by-default): a consumer must
 * explicitly opt in to `['*']` (or a real allow-list) before any
 * cross-origin request succeeds at all — zero-configuration use of this
 * middleware never grants any origin credentialed access to a response.
 * Separately, and regardless of the default,
 * `['*']` combined with `allowCredentials: true` is rejected outright at
 * construction — that combination has no correct interpretation to fall
 * back to (the spec forbids a literal wildcard alongside credentials,
 * which is why `withCorsHeaders()` below already echoes the specific
 * origin instead — but doing that for every origin unconditionally is the
 * exact "allow everyone, with credentials" misconfiguration this guard
 * exists to catch before it ships, not paper over silently).
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins '*' or a list of exact origins (scheme + host + port).
     * @param list<string> $allowedMethods sent back on a preflight response's
     *        Access-Control-Allow-Methods.
     * @param list<string> $allowedHeaders `['*']` reflects whatever the preflight actually
     *        requested (Access-Control-Request-Headers) instead of a fixed list — maintaining
     *        an exhaustive static allow-list is brittle against a client sending one custom
     *        header more than expected; a fixed list is still supported for when that
     *        brittleness is exactly the point.
     * @param list<string> $exposedHeaders response headers a browser script may read beyond
     *        the small always-visible set (Content-Type, ...) — empty (none) by default.
     * @param list<string> $allowedOriginPatterns full, delimited PCRE patterns (e.g.
     *        `#^https://[a-z0-9-]+\.example\.com$#`) checked against the Origin header when it
     *        matches none of $allowedOrigins exactly — for "any subdomain of example.com", not
     *        expressible as a fixed list. Added as the last constructor parameter, not next to
     *        $allowedOrigins where it reads most naturally, specifically so no existing
     *        positional constructor call shifts which argument lands in which parameter.
     *        A pattern must compile, which is checked here, and must match the Origin in
     *        full — a partial match is not enough, so an unanchored `example\.com` does not
     *        allow `https://evil-example.com.attacker.net`, a real and recurring class of
     *        CORS misconfiguration. Escaping literal dots is still yours to get right:
     *        `.+example\.com` matches `https://evilexample.com` in full, and nothing
     *        generic can tell that from an intended pattern.
     */
    public function __construct(
        private array $allowedOrigins = [],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization'],
        private array $exposedHeaders = [],
        private bool $allowCredentials = false,
        private int $maxAge = 86400,
        private array $allowedOriginPatterns = [],
    ) {
        if (in_array('*', $allowedOrigins, true) && $allowCredentials) {
            throw new InvalidArgumentException(
                "CorsMiddleware cannot allow every origin ('*') while also allowing credentials — "
                . 'this would grant any origin credentialed access to every response. '
                . 'List specific allowed origins instead, or set allowCredentials to false.',
            );
        }

        foreach ($allowedOriginPatterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(
                    "CorsMiddleware origin pattern \"{$pattern}\" is not a valid PCRE pattern. "
                    . 'It runs against the Origin header on every request, where one that cannot '
                    . 'compile matches nothing and silently denies every origin it was meant to '
                    . 'allow. Include the delimiters, as in #^https://[a-z0-9-]+\.example\.com$#.',
                );
            }
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || !$this->originAllowed($origin)) {
            $response = $handler->handle($request);

            // A disallowed (or absent) origin gets no CORS headers — but
            // the *presence* of an Origin header, and its value, still
            // decide which branch of this method runs at all, so the
            // response genuinely depends on Origin whenever CORS is
            // configured to allow anything — see originVaries()'s own
            // docblock for why this holds even for a literal "*"
            // allow-list. Only a completely unconfigured middleware,
            // where every request takes this exact branch regardless of
            // Origin, is genuinely origin-independent.
            if ($this->originVaries()) {
                $response = self::withVary($response, 'Origin');
            }

            return $response;
        }

        if ($this->isPreflight($request)) {
            return $this->preflightResponse($request, $origin);
        }

        $response = $this->withCorsHeaders($handler->handle($request), $origin);

        // For an OPTIONS request specifically (and only then — isPreflight()
        // is unconditionally false for every other method, so this
        // response can never differ from one that lacked the header),
        // reaching this branch instead of preflightResponse() already
        // depended on Access-Control-Request-Method's absence — a cache
        // keyed only on method/URI/Origin cannot tell this response
        // apart from a preflight one without this token.
        if ($request->getMethod() === 'OPTIONS') {
            $response = self::withVary($response, 'Access-Control-Request-Method');
        }

        return $response;
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method');
    }

    /**
     * Whether Access-Control-Allow-Origin should echo the literal "*"
     * instead of the specific requesting origin — a literal "*"
     * allow-list with no credentials. This governs the *value* CORS
     * headers carry, not whether the response varies by Origin — see
     * originVaries() for that, a genuinely different question: even a
     * static-wildcard configuration answers a no-Origin request (no
     * Access-Control-Allow-Origin at all) differently from an
     * Origin-bearing one (Access-Control-Allow-Origin: *), so "the value
     * is always the literal string '*'" does not mean "the response is
     * always identical."
     */
    private function staticWildcard(): bool
    {
        return in_array('*', $this->allowedOrigins, true) && !$this->allowCredentials;
    }

    /**
     * Whether any response this middleware produces could differ based
     * on the request's Origin header — true whenever this middleware is
     * configured to allow anything at all ($allowedOrigins or
     * $allowedOriginPatterns non-empty). A literal "*" allow-list is
     * origin-dependent in exactly this sense despite always producing
     * the same header *value*: a request with no Origin header takes the
     * disallowed/absent branch (no CORS headers at all), while one
     * carrying any Origin header takes the allowed branch
     * (Access-Control-Allow-Origin: *) — two different response shapes a
     * shared cache must be able to tell apart. Only a completely
     * unconfigured middleware ($allowedOrigins === [] and
     * $allowedOriginPatterns === []), where every request takes the same
     * pass-through branch regardless of Origin, is genuinely
     * origin-independent.
     */
    private function originVaries(): bool
    {
        return $this->allowedOrigins !== [] || $this->allowedOriginPatterns !== [];
    }

    /**
     * Merges $tokens into $response's own Vary header as one canonical,
     * deduplicated, case-insensitively-compared token set — never a
     * blind withAddedHeader('Vary', ...) append, which would duplicate a
     * token already present under different casing, split across
     * multiple header lines, or already comma-joined with an
     * application's own unrelated Vary dimensions (Accept-Encoding, for
     * one). Deduplication applies to the existing tokens themselves too,
     * not just between them and $tokens — an application response that
     * already repeats a token (across lines or within one comma-separated
     * value) comes out collapsed to one occurrence, keeping whichever
     * casing appeared first. Never appends anything at all once an
     * existing "*" token is present, since that already means "varies on
     * everything" and a narrower-looking addition next to it would only
     * invite a downstream cache to misread the field as a literal, finite
     * list.
     */
    private static function withVary(ResponseInterface $response, string ...$tokens): ResponseInterface
    {
        $existing = [];

        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $token) {
                $token = trim($token);

                if ($token !== '') {
                    $existing[] = $token;
                }
            }
        }

        if (in_array('*', $existing, true)) {
            return $response;
        }

        $merged = [];
        $normalized = [];

        foreach ([...$existing, ...$tokens] as $token) {
            $lower = strtolower($token);

            if (!in_array($lower, $normalized, true)) {
                $merged[] = $token;
                $normalized[] = $lower;
            }
        }

        return $response->withHeader('Vary', implode(', ', $merged));
    }

    private function originAllowed(string $origin): bool
    {
        if (in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        foreach ($this->allowedOriginPatterns as $pattern) {
            // The match has to span the whole Origin. Without that, a
            // pattern carrying no anchors matches a substring, and
            // example\.com would allow https://evil-example.com.attacker.net.
            // Checking the pattern for ^...$ instead would not do: an
            // alternation such as ^https://good\.com$|evil\.com$ carries
            // both anchors and is still unanchored on its second branch.
            if (preg_match($pattern, $origin, $matches) === 1 && $matches[0] === $origin) {
                return true;
            }
        }

        return false;
    }

    private function preflightResponse(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $reflectsRequestedHeaders = $this->allowedHeaders === ['*'];
        $allowedHeaders = $reflectsRequestedHeaders
            ? $request->getHeaderLine('Access-Control-Request-Headers')
            : implode(', ', $this->allowedHeaders);

        $response = $this->withCorsHeaders(new Response(204), $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', $allowedHeaders)
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);

        // Every preflight response depends on Access-Control-Request-Method's
        // presence — it's what routed the request here instead of the
        // ordinary-OPTIONS branch, so a cache must be able to tell the two
        // apart even for the identical method/URI/Origin. Access-Control-
        // Request-Headers only actually matters when $allowedHeaders is
        // ['*'] and this response is reflecting it back — a fixed
        // allow-list produces the exact same Access-Control-Allow-Headers
        // value no matter what was requested, so marking that dimension
        // there would be variance a cache can never actually observe.
        $varyTokens = ['Access-Control-Request-Method'];

        if ($reflectsRequestedHeaders) {
            $varyTokens[] = 'Access-Control-Request-Headers';
        }

        return self::withVary($response, ...$varyTokens);
    }

    /**
     * Per spec, Access-Control-Allow-Origin must never be the literal "*"
     * when credentials are allowed — browsers reject that combination
     * outright — so a credentialed response always echoes the specific
     * requesting origin instead of "*", whatever `$allowedOrigins` itself
     * is configured to. The one case that would need this to fall back to
     * echoing while `$allowedOrigins` is still `['*']` is rejected outright
     * at construction (see the constructor's own guard) rather than
     * silently overridden here — so by the time this method runs,
     * `$allowCredentials` and a wildcard `$allowedOrigins` never coexist.
     * Vary: Origin is added whenever originVaries() says the response
     * genuinely depends on the request's Origin — see that method's own
     * docblock for why this holds even for a literal "*" allow-list,
     * where the header *value* never changes but reaching this method at
     * all (as opposed to the disallowed/absent branch in process()) still
     * depends on Origin having been present and allowed.
     */
    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $wildcard = $this->staticWildcard();

        $response = $response->withHeader('Access-Control-Allow-Origin', $wildcard ? '*' : $origin);

        if ($this->originVaries()) {
            $response = self::withVary($response, 'Origin');
        }

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }
}
