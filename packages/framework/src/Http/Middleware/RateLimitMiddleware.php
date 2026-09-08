<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Middleware\Exception\InvalidRateLimitConfigException;
use Kinetis\Http\Middleware\Exception\RateLimitUnavailableException;
use Kinetis\Http\TrustedProxies;
use Kinetis\SimpleCache\AtomicCounterInterface;
use Kinetis\SimpleCache\NullSimpleCache;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A fixed-window request counter backed by Psr\SimpleCache\CacheInterface
 * (see Kinetis\SimpleCache) — requires the cache to also implement
 * Kinetis\SimpleCache\AtomicCounterInterface. A cache lacking it can only
 * count by reading the value and writing it back, which is not safe
 * across processes: every request in flight reads the same number before
 * any of them writes, so each one believes it is the first. That is
 * rejected at construction rather than left to a flag the application has
 * to remember to check. NullSimpleCache is checked first, for its own
 * clearer message: a counter that never stores anything enforces no limit
 * at all while still emitting healthy-looking X-RateLimit-* headers.
 *
 * **`$policyId` is the policy's identity.** It names which policy owns
 * the counter, and nothing else does: two instances constructed with the
 * same ID count one subject against one shared budget and dedupe as one
 * check, and two policies that must not share a budget are given
 * different IDs. Configuration is not identity, so raising a limit or
 * adding a trusted proxy leaves every counter a running deployment
 * already holds where it is. The ID is trusted application configuration
 * — non-empty is the only requirement — and is sha256-hashed once at
 * construction into this policy's cache-key prefix, since PSR-16 forbids
 * `{}()/\@:` in a key.
 *
 * Keyed by client IP by default, hashed the same way (an IPv6 address is
 * full of colons). Holds no per-request state as instance properties —
 * the per-request bookkeeping this class needs (see "Composing two
 * policies" below) lives entirely on the PSR-7 request object, never on
 * `$this` — so it is safe as either global (AppScope-resolved singleton)
 * or route middleware.
 *
 * `$trustedProxies` is empty by default, so `identifierFor()` always uses
 * the raw `REMOTE_ADDR` — never client-settable `X-Forwarded-For` — unless
 * the connecting address matches a listed CIDR range, opting in to reading
 * that header for requests that actually came through a trusted proxy.
 * Which address that resolves to is `Kinetis\Http\TrustedProxies`'
 * answer: the same object, and the same chain-walking rules, the runtime
 * adapters apply to the same headers before the Kernel runs.
 *
 * Since `#[Middleware(...)]` only ever carries a class-string with no
 * arguments, a policy reached that way is a thin subclass passing its own
 * ID and limits to this constructor (this class is deliberately not
 * `final`, unlike almost everything else here). A global policy is that
 * same subclass, or an `AppScope::bind()` closure for this class.
 *
 * **Composing two policies.** A global limiter (outermost) and a route
 * limiter (innermost) each increment their own counter and each decide
 * independently — but two things need explicit handling once both are in
 * the same request's pipeline:
 *
 * - *The same policy twice in one pipeline* — typically registered once
 *   globally and again, redundantly, on the matched route — must still
 *   count as one check against one incoming request. `process()` records
 *   its decision — the resulting attempt count and the window it was
 *   counted against — as a PSR-7 request attribute, keyed by policy ID
 *   plus the request's own subject and deliberately *not* the window: the
 *   second occurrence reads that decision back and reuses it wholesale,
 *   including the original window for a truthful `Retry-After`, instead
 *   of incrementing the counter again or resolving its own, possibly
 *   later, window. A slow intervening middleware crossing a real window
 *   boundary between the two occurrences must not be read as two
 *   independent checks.
 * - *Two different policies* must never let one's headers
 *   clobber the other's. `X-RateLimit-Limit`/`X-RateLimit-Remaining` are
 *   only ever set on a response that doesn't already carry them — so the
 *   innermost policy to actually run (closest to the controller, whether
 *   it succeeded or rejected with 429) is the one whose real numbers
 *   reach the client, and an outer policy that is itself within budget
 *   never overwrites them with its own, unrelated ones.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private const string EXECUTED_ATTRIBUTE = 'kinetis.rate-limit.executed';

    private readonly AtomicCounterInterface $counter;

    /**
     * This policy's identity, reduced once to a fixed-width, PSR-16-safe
     * prefix for every cache key and dedup key it owns.
     */
    private readonly string $policyPrefix;

    /**
     * This policy's own edge, as the one object that answers "who is
     * this request's client" for the whole framework. The runtime
     * adapters build theirs from `TRUSTED_PROXIES` before the Kernel
     * exists; this one is built from the ranges this policy was
     * configured with, since a limiter may trust a narrower set than the
     * application itself does. Same rules either way — which is
     * what stops a request from having one client address for its scheme
     * and another for its rate-limit bucket.
     */
    private readonly TrustedProxies $proxies;

    /**
     * $clock exists purely for deterministic testing — a real window
     * boundary can be crossed in a test without a real sleep() by
     * substituting a closure that advances an in-memory counter instead
     * of reading the real system clock. `null` (the default, and the
     * only thing any real caller ever passes) uses `time(...)` itself.
     *
     * @param string $policyId Names the policy that owns the counter — see this class's own docblock.
     * @param list<string> $trustedProxies CIDR ranges (e.g. '10.0.0.0/8') — see identifierFor().
     */
    public function __construct(
        CacheInterface $cache,
        string $policyId,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        array $trustedProxies = [],
        private readonly ?\Closure $clock = null,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw RateLimitUnavailableException::nullCache();
        }

        if (!$cache instanceof AtomicCounterInterface) {
            throw RateLimitUnavailableException::notAtomic();
        }

        $this->counter = $cache;

        if (trim($policyId) === '') {
            throw InvalidRateLimitConfigException::blankPolicyId();
        }

        $this->policyPrefix = hash('sha256', $policyId);

        if ($maxAttempts < 1) {
            throw InvalidRateLimitConfigException::nonPositiveMaxAttempts($maxAttempts);
        }

        if ($windowSeconds < 1) {
            throw InvalidRateLimitConfigException::nonPositiveWindow($windowSeconds);
        }

        foreach ($trustedProxies as $proxy) {
            // Checked here, and reported under this middleware's own
            // configuration exception, so a bad range names the setting
            // the operator actually wrote. A range decides who may speak
            // for a client, so a malformed one is refused at construction
            // rather than reaching a match, where a negative prefix
            // raises ArithmeticError on the bit shift and an oversized
            // one silently narrows the range to a single address.
            $reason = TrustedProxies::unusableReason($proxy);

            if ($reason !== null) {
                throw InvalidRateLimitConfigException::malformedTrustedProxy($proxy, $reason);
            }
        }

        $this->proxies = TrustedProxies::fromList($trustedProxies);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $subject = $this->dedupeKey($request);

        /** @var array<string, array{attempts: int, window: int}> $executed */
        $executed = $request->getAttribute(self::EXECUTED_ATTRIBUTE, []);

        if (array_key_exists($subject, $executed)) {
            // This policy already checked this subject earlier in the
            // same request's pipeline. Reuse its whole recorded decision,
            // original window included, instead of incrementing the
            // counter again or resolving a fresh window of our own — see
            // this class's own "Composing two policies" docblock section.
            ['attempts' => $attempts, 'window' => $window] = $executed[$subject];
        } else {
            $window = intdiv($this->now(), $this->windowSeconds);
            // Counted before the decision, not after. A request over the
            // limit still counts, which costs nothing: the key belongs to
            // this window alone and the next window uses a different one.
            $attempts = $this->counter->increment($this->cacheKey($subject, $window), $this->windowSeconds);
            $request = $request->withAttribute(
                self::EXECUTED_ATTRIBUTE,
                [...$executed, $subject => ['attempts' => $attempts, 'window' => $window]],
            );
        }

        if ($attempts > $this->maxAttempts) {
            return $this->tooManyRequestsResponse($window);
        }

        $remaining = $this->maxAttempts - $attempts;
        $response = $handler->handle($request);

        // An outer (typically global) policy that is itself within
        // budget must never overwrite the X-RateLimit-* headers a more
        // specific, already-decided inner (typically route) policy
        // already stamped on this response — success or 429 alike —
        // with its own, unrelated numbers. Whichever policy actually ran
        // closest to the controller wins; see this class's own
        // "Composing two policies" docblock section.
        if ($response->hasHeader('X-RateLimit-Limit')) {
            return $response;
        }

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining));
    }

    /**
     * This policy plus the request's own subject, without a window
     * component, so the same policy checking the same subject
     * dedupes correctly across a request's pipeline regardless of which
     * window each occurrence would independently resolve. Used as the
     * per-request dedup attribute's own map key.
     */
    private function dedupeKey(ServerRequestInterface $request): string
    {
        return $this->policyPrefix . '.' . hash('sha256', $this->identifierFor($request));
    }

    private function cacheKey(string $subject, int $window): string
    {
        return "ratelimit.{$subject}.{$window}";
    }

    /**
     * IP-based by default — the common case, and the only signal available
     * before any authentication middleware has necessarily run. Protected,
     * not private, specifically so a subclass can override it — see
     * Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware for the
     * built-in "key by the authenticated user when one is resolved, IP
     * otherwise" variant.
     *
     * The address itself is Kinetis\Http\TrustedProxies' answer, not
     * this class's: X-Forwarded-For is only ever consulted when the peer
     * that connected matches one of $trustedProxies — never
     * unconditionally, since a client can set that header to any value
     * it likes — and the chain is then walked from its nearest hop
     * backward past every entry that is itself a trusted proxy. The
     * transport peer is untouched by any of it: REMOTE_ADDR stays the
     * socket's own, so a component reading it still reads what actually
     * connected.
     *
     * A request carrying no REMOTE_ADDR at all has no client to key on
     * and shares one bucket named `unknown` — a limit is still applied,
     * which is the safe direction; a per-request identifier would be no
     * limit at all.
     */
    protected function identifierFor(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : null;

        return $this->proxies->clientAddress($remoteAddr, $request->getHeaderLine('X-Forwarded-For')) ?? 'unknown';
    }

    private function tooManyRequestsResponse(int $window): ResponseInterface
    {
        $windowEnd = ($window + 1) * $this->windowSeconds;
        $retryAfter = max(0, $windowEnd - $this->now());

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
