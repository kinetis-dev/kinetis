<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A fixed-window request counter backed by Psr\SimpleCache\CacheInterface
 * (see Kinetis\SimpleCache) — works with any PSR-16 implementation,
 * including a no-op NullSimpleCache, since it only ever calls get()/set().
 *
 * Keyed by client IP by default, sha256-hashed (PSR-16 forbids `{}()/\@:`
 * in a key, and IPv6 addresses are full of colons). Holds no per-request
 * state, so it's safe as either global (AppScope-resolved singleton) or
 * route middleware.
 *
 * `$trustedProxies` is empty by default, so `identifierFor()` always uses
 * the raw `REMOTE_ADDR` — never client-settable `X-Forwarded-For` — unless
 * the connecting address matches a listed CIDR range, opting in to reading
 * that header for requests that actually came through a trusted proxy.
 *
 * Per-route limits: since `#[Middleware(...)]` only ever carries a
 * class-string with no arguments, a different limit for a different route
 * means a thin subclass overriding the constructor defaults (this class is
 * deliberately not `final`, unlike almost everything else here) or a
 * distinct `AppScope::bind()` closure.
 *
 * The get()-then-set() pair below is not atomic — two concurrent requests
 * against the same bucket can both read the same count and both write the
 * same incremented value, silently losing an increment. Accepted as a
 * rounding error rather than switching to a backend-specific atomic INCR,
 * which would make this Redis-only.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $trustedProxies CIDR ranges (e.g. '10.0.0.0/8') — see identifierFor().
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly array $trustedProxies = [],
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $window = intdiv(time(), $this->windowSeconds);
        $key = $this->cacheKey($request, $window);
        $attempts = (int) $this->cache->get($key, 0);

        if ($attempts >= $this->maxAttempts) {
            return $this->tooManyRequestsResponse($window);
        }

        $this->cache->set($key, $attempts + 1, $this->windowSeconds);

        $remaining = $this->maxAttempts - $attempts - 1;

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining));
    }

    private function cacheKey(ServerRequestInterface $request, int $window): string
    {
        $identifier = hash('sha256', $this->identifierFor($request));

        return "ratelimit.{$identifier}.{$window}";
    }

    /**
     * IP-based by default — the common case, and the only signal available
     * before any authentication middleware has necessarily run. Protected,
     * not private, specifically so a subclass can override it — see
     * Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware for the
     * built-in "key by the authenticated user when one is resolved, IP
     * otherwise" variant.
     *
     * X-Forwarded-For is only ever consulted when REMOTE_ADDR itself
     * matches one of $trustedProxies — never unconditionally, since a
     * client can set that header to any value it likes. Once trusted, the
     * chain (nearest hop last) is walked from the end backward, skipping
     * every entry that's itself a trusted proxy; the first untrusted entry
     * found is the real client. A chain of only trusted proxies (no client
     * entry present) falls back to its leftmost, oldest entry.
     */
    protected function identifierFor(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : null;

        if ($remoteAddr === null) {
            return 'unknown';
        }

        if ($this->trustedProxies === [] || !$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

        if ($forwardedFor === '') {
            return $remoteAddr;
        }

        $chain = array_map('trim', explode(',', $forwardedFor));

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->isTrustedProxy($chain[$i])) {
                return $chain[$i];
            }
        }

        return $chain[0];
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * inet_pton()-based binary comparison, so IPv4 and IPv6 ranges are
     * handled uniformly rather than needing separate integer/string logic
     * for each.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bitsString] = explode('/', $cidr, 2);
        $bits = (int) $bitsString;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainderBits) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    private function tooManyRequestsResponse(int $window): ResponseInterface
    {
        $windowEnd = ($window + 1) * $this->windowSeconds;
        $retryAfter = max(0, $windowEnd - time());

        return new Response(
            status: 429,
            headers: [
                'Content-Type' => 'application/json',
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $this->maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ],
            body: json_encode(['error' => 'Too many requests.'], JSON_THROW_ON_ERROR),
        );
    }
}
