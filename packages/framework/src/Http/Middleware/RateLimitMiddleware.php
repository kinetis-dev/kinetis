<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Middleware\Exception\InvalidRateLimitConfigException;
use Kinetis\Http\Middleware\Exception\RateLimitUnavailableException;
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
            self::assertUsableProxy($proxy);
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /**
     * A range decides who may set X-Forwarded-For, so a malformed one is
     * rejected here rather than reaching ipInCidr(), where a negative
     * prefix raises ArithmeticError on the bit shift and an oversized one
     * silently narrows the range to a single address.
     */
    private static function assertUsableProxy(string $proxy): void
    {
        if (!str_contains($proxy, '/')) {
            if (@inet_pton($proxy) === false) {
                throw InvalidRateLimitConfigException::malformedTrustedProxy($proxy, 'not an IP address');
            }

            return;
        }

        [$subnet, $prefix] = explode('/', $proxy, 2);
        $binary = @inet_pton($subnet);

        if ($binary === false) {
            throw InvalidRateLimitConfigException::malformedTrustedProxy($proxy, 'the part before "/" is not an IP address');
        }

        if (preg_match('/^\d+$/', $prefix) !== 1) {
            throw InvalidRateLimitConfigException::malformedTrustedProxy($proxy, 'the prefix length must be a whole number');
        }

        $maxBits = strlen($binary) * 8;

        if ((int) $prefix > $maxBits) {
            $family = $maxBits === 32 ? 'IPv4' : 'IPv6';

            throw InvalidRateLimitConfigException::malformedTrustedProxy($proxy, "an {$family} prefix length runs from 0 to {$maxBits}");
        }
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
