<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Middleware\Exception\InvalidRateLimitConfigException;
use Kinetis\Http\Middleware\Exception\RateLimitUnavailableException;
use Kinetis\Http\TrustedProxies;
use Kinetis\SimpleCache\AtomicCounterInterface;
use Kinetis\SimpleCache\Counter;
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
 * any of them writes, so each one believes it is the first. Measured
 * against a real Redis, that fallback let a limit of 5 admit all 40
 * requests that arrived together — a rate limiter that stops applying
 * under the exact concurrent load it exists to resist is not a rounding
 * error, so this is rejected at construction rather than left to a flag
 * (`Counter::isAtomic()`) the application has to remember to check.
 * NullSimpleCache is checked first, for its own clearer message: a
 * counter that never stores anything enforces no limit at all while
 * still emitting healthy-looking X-RateLimit-* headers.
 *
 * Keyed by client IP by default, sha256-hashed (PSR-16 forbids `{}()/\@:`
 * in a key, and IPv6 addresses are full of colons). Holds no per-request
 * state as instance properties — the per-request bookkeeping this class
 * needs (see "Composing two policies" below) lives entirely on the PSR-7
 * request object, never on `$this` — so it's safe as either global
 * (AppScope-resolved singleton) or route middleware.
 *
 * `$trustedProxies` is empty by default, so `identifierFor()` always uses
 * the raw `REMOTE_ADDR` — never client-settable `X-Forwarded-For` — unless
 * the connecting address matches a listed CIDR range, opting in to reading
 * that header for requests that actually came through a trusted proxy.
 * Which address that resolves to is `Kinetis\Http\TrustedProxies`'
 * answer: the same object, and the same chain-walking rules, the runtime
 * adapters apply to the same headers before the Kernel runs.
 *
 * Per-route limits: since `#[Middleware(...)]` only ever carries a
 * class-string with no arguments, a different limit for a different route
 * means a thin subclass overriding the constructor defaults (this class is
 * deliberately not `final`, unlike almost everything else here) or a
 * distinct `AppScope::bind()` closure.
 *
 * **Every counter is scoped to the policy that owns it, not just the
 * subject being counted.** The policy identity folds in `static::class`,
 * `$maxAttempts`, `$windowSeconds`, `$trustedProxies`, and `$namespace` —
 * two policies that would otherwise collide on subject+window alone (a
 * 60/minute global limiter and a 5/minute route limiter checked in the
 * same minute, say) get genuinely independent counters instead of
 * silently sharing one. `$trustedProxies` is included because it changes
 * which identifier `identifierFor()` even resolves to, exactly the same
 * as `$maxAttempts`/`$windowSeconds` do — canonicalized to a sorted,
 * duplicate-free set first, since two CIDR lists with the same members in
 * a different order or with a repeated entry authorize identically and
 * must be treated as the identical policy. `$namespace` is the explicit
 * escape hatch for the one case configuration alone can't infer: two
 * policies with the *identical* class and limits guarding different
 * things (a login endpoint and a 2FA endpoint, both
 * `RateLimitMiddleware($cache, 5, 60)`) — pass a distinct string to each
 * so they don't share a bucket. See {doc}`middleware`'s "Composing more
 * than one policy" section for the deployment consequence of any of this
 * changing on an already-running system.
 *
 * **Composing two policies.** A global limiter (outermost) and a route
 * limiter (innermost) each increment their own counter and each decide
 * independently — but two things need explicit handling once both are in
 * the same request's pipeline:
 *
 * - *The same policy registered twice* (typically by mistake — once
 *   globally, once again on the matched route) must still count as one
 *   check, not two, against one incoming request. `process()` records its
 *   decision — the resulting attempt count and the window it was counted
 *   against — as a PSR-7 request attribute, keyed by the policy's own
 *   identity plus the request's own subject, deliberately *not* the
 *   window: a second instance of the identical policy checking the
 *   identical subject reads that recorded decision back and reuses it
 *   wholesale — including the original window, for a truthful
 *   `Retry-After` — instead of incrementing the counter again or
 *   resolving its own, possibly later, window. A slow intervening
 *   middleware crossing a real window boundary between the two
 *   occurrences must not be read as two independent checks just because
 *   each would otherwise resolve a different window on its own.
 * - *Two genuinely different policies* must never let one's headers
 *   clobber the other's. `X-RateLimit-Limit`/`X-RateLimit-Remaining` are
 *   only ever set on a response that doesn't already carry them — so the
 *   innermost policy to actually run (closest to the controller, whether
 *   it succeeded or rejected with 429) is the one whose real numbers
 *   reach the client, and an outer policy that is itself within budget
 *   never overwrites them with its own, unrelated ones. This is a
 *   deliberate, documented rule, not an accident of registration order.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private const string EXECUTED_ATTRIBUTE = 'kinetis.rate-limit.executed';

    private readonly Counter $counter;

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
     * @param list<string> $trustedProxies CIDR ranges (e.g. '10.0.0.0/8') — see identifierFor().
     * @param ?string $namespace Disambiguates an otherwise identical class+config policy from another one guarding something different — see this class's own docblock.
     */
    public function __construct(
        CacheInterface $cache,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly array $trustedProxies = [],
        private readonly ?string $namespace = null,
        private readonly ?\Closure $clock = null,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw RateLimitUnavailableException::nullCache();
        }

        if (!$cache instanceof AtomicCounterInterface) {
            throw RateLimitUnavailableException::notAtomic();
        }

        $this->counter = new Counter($cache);

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
            // The identical policy (same class, configuration, and
            // namespace) checking the identical subject already ran
            // earlier in this same request's pipeline — most commonly
            // registered both globally and, redundantly, on the matched
            // route. Reuse its whole recorded decision, original window
            // included, instead of incrementing the counter again or
            // resolving a fresh window of our own — see this class's own
            // "Composing two policies" docblock section.
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
     * The policy's own identity plus the request's own subject — folded
     * together, deliberately without a window component, so the same
     * policy checking the same subject dedupes correctly across a
     * request's pipeline regardless of which window each occurrence
     * would independently resolve. Used as the per-request dedup
     * attribute's own map key.
     */
    private function dedupeKey(ServerRequestInterface $request): string
    {
        return $this->policyIdentity() . '.' . hash('sha256', $this->identifierFor($request));
    }

    private function cacheKey(string $subject, int $window): string
    {
        return "ratelimit.{$subject}.{$window}";
    }

    /**
     * A stable, unambiguous identity for this exact policy — every field
     * that changes what actually gets checked, not just the subject
     * being counted: `static::class`, `$maxAttempts`, `$windowSeconds`,
     * `$trustedProxies` (canonicalized to a sorted, duplicate-free set —
     * trust is a set-membership check, so an equivalent list in a
     * different order or with a repeated entry authorizes identically
     * and must map to the identical identity), and `$namespace`.
     * `$trustedProxies` changes which identifier `identifierFor()` even
     * resolves to, so it's policy behavior exactly the same as
     * `$maxAttempts`/`$windowSeconds` are.
     *
     * Each field is hashed on its own before being joined, then the
     * joined, fixed-width result is hashed once more — not the fields
     * concatenated directly. A plain delimited join of caller-controlled
     * values (a namespace, a CIDR list) has no safe delimiter: an IPv6
     * CIDR range already contains colons, so two genuinely different
     * configurations could concatenate to the identical string and
     * collide. Hashing every field first fixes each one to the same
     * width regardless of its own content, so no field's content can
     * ever be mistaken for a delimiter or shift into a neighboring
     * field.
     */
    private function policyIdentity(): string
    {
        $canonicalProxies = array_unique($this->trustedProxies);
        sort($canonicalProxies);

        $fields = implode('|', [
            hash('sha256', static::class),
            hash('sha256', (string) $this->maxAttempts),
            hash('sha256', (string) $this->windowSeconds),
            hash('sha256', implode(',', $canonicalProxies)),
            hash('sha256', $this->namespace ?? ''),
        ]);

        return hash('sha256', $fields);
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
