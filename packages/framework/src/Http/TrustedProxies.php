<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Config\Config;
use Kinetis\Http\Exception\InvalidTrustedProxyException;
use Kinetis\Http\Exception\UntrustedForwardedHeaderException;
use SensitiveParameter;

/**
 * Which peers may speak for a client — the one policy every runtime
 * consults before letting a forwarded header decide anything.
 *
 * `X-Forwarded-Proto` and `X-Forwarded-For` are ordinary request headers:
 * any client that can reach the listener can send them. Consuming one
 * without knowing the peer is an edge lets that client choose the scheme
 * its own request appears to have arrived over, which decides every
 * absolute URL the application generates, whether a `Secure` cookie is
 * set, and whether an OAuth redirect target validates. So the default is
 * to consume nothing: an empty policy trusts no peer, and a forwarded
 * header from an untrusted peer is data the request happens to carry and
 * nothing more.
 *
 * A deployment names its edge explicitly through `TRUSTED_PROXIES`, a
 * comma-separated list of addresses and CIDR ranges. Only when the peer
 * that actually connected matches one of them is a forwarded header read
 * — and then it has to be readable: a trusted proxy sending a scheme
 * that is neither `http` nor `https`, or sending several, is a
 * {@see UntrustedForwardedHeaderException} rather than a value to guess
 * at, because the peer that could have gotten it right is the one that
 * got it wrong.
 *
 * One object answers both questions a forwarded chain raises. The
 * runtime adapters build the application's own from `TRUSTED_PROXIES`
 * and ask {@see forwardedScheme()} before the Kernel exists;
 * {@see \Kinetis\Http\Middleware\RateLimitMiddleware} builds one from
 * the ranges that policy was configured with — a limiter may trust a
 * narrower set than the application does — and asks
 * {@see clientAddress()} while keying a bucket. Neither rewrites
 * `REMOTE_ADDR`: the transport peer stays what actually connected, and
 * the client behind an edge is derived from it on demand rather than
 * substituted for it.
 */
final readonly class TrustedProxies
{
    /**
     * @param list<string> $ranges each an address or CIDR range, already
     *     validated by {@see fromList()}
     */
    private function __construct(
        private array $ranges,
    ) {}

    /**
     * @param list<string> $ranges
     */
    public static function fromList(array $ranges): self
    {
        foreach ($ranges as $range) {
            $reason = self::unusableReason($range);

            if ($reason !== null) {
                throw InvalidTrustedProxyException::malformed($range, $reason);
            }
        }

        return new self(array_values($ranges));
    }

    /**
     * `TRUSTED_PROXIES`, comma-separated. Absent or empty means an empty
     * policy — no peer is an edge, and no forwarded header is read.
     */
    public static function fromConfig(#[SensitiveParameter] Config $config): self
    {
        $configured = trim($config->string('TRUSTED_PROXIES', ''));

        if ($configured === '') {
            return new self([]);
        }

        $ranges = array_values(array_filter(array_map(trim(...), explode(',', $configured)), static fn (string $entry): bool => $entry !== ''));

        return self::fromList($ranges);
    }

    public function trustsNobody(): bool
    {
        return $this->ranges === [];
    }

    public function trusts(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        foreach ($this->ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scheme a trusted edge says the client used, or null when there
     * is no edge to believe — no policy, an untrusted peer, or no header
     * at all. Null means "keep the scheme this environment serves"; it
     * never means "assume http".
     *
     * @param string $forwardedProto the raw `X-Forwarded-Proto` header
     *     line, comma-joined if it arrived more than once
     */
    public function forwardedScheme(?string $remoteAddr, string $forwardedProto): ?string
    {
        if ($forwardedProto === '' || !$this->trusts($remoteAddr)) {
            return null;
        }

        // A folded or repeated header names more than one scheme for one
        // request. There is no rule that picks the right one — the first
        // entry is the client's hop under one convention and the last
        // under another — so a trusted proxy that sends several has to be
        // fixed, not guessed at.
        $scheme = strtolower(trim($forwardedProto));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw UntrustedForwardedHeaderException::unreadableScheme();
        }

        return $scheme;
    }

    /**
     * The client behind a trusted edge, from `X-Forwarded-For`: the chain
     * is walked from its nearest hop backward, skipping every entry that
     * is itself a trusted proxy, and the first untrusted entry is the
     * client. A chain of nothing but trusted proxies falls back to its
     * oldest entry. An untrusted peer is its own client address.
     */
    public function clientAddress(?string $remoteAddr, string $forwardedFor): ?string
    {
        if ($remoteAddr === null || $forwardedFor === '' || !$this->trusts($remoteAddr)) {
            return $remoteAddr;
        }

        $chain = array_map(trim(...), explode(',', $forwardedFor));

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->trusts($chain[$i])) {
                return $chain[$i];
            }
        }

        return $chain[0];
    }

    /**
     * Why an entry cannot be used as a range, or null when it can. A
     * range decides who may rewrite a request's identity, so a malformed
     * one is caught at construction rather than reaching {@see matches()},
     * where a negative prefix raises `ArithmeticError` on the bit shift
     * and an oversized one silently narrows the range to a single
     * address.
     */
    public static function unusableReason(string $entry): ?string
    {
        if (!str_contains($entry, '/')) {
            return @inet_pton($entry) === false ? 'not an IP address' : null;
        }

        [$subnet, $prefix] = explode('/', $entry, 2);
        $binary = @inet_pton($subnet);

        if ($binary === false) {
            return 'the part before "/" is not an IP address';
        }

        if (preg_match('/^\d+$/', $prefix) !== 1) {
            return 'the prefix length must be a whole number';
        }

        $maxBits = strlen($binary) * 8;

        if ((int) $prefix > $maxBits) {
            $family = $maxBits === 32 ? 'IPv4' : 'IPv6';

            return "an {$family} prefix length runs from 0 to {$maxBits}";
        }

        return null;
    }

    /**
     * `inet_pton()`-based binary comparison, so an IPv4 and an IPv6 range
     * are handled the same way rather than needing separate integer and
     * string logic for each.
     *
     * An exact address is compared the same way, not as text. One IPv6
     * address has many spellings — `2001:db8::1`,
     * `2001:0db8:0000:0000:0000:0000:0000:0001`, `2001:DB8::1` — and a
     * peer arrives spelled however whatever is in front of it spells it,
     * which is not how an operator wrote `TRUSTED_PROXIES`. A textual
     * comparison reads two spellings of one edge as two different hosts,
     * so the edge is disbelieved and the client behind it is invisible:
     * the scheme falls back to the listener's own and every request from
     * behind that proxy keys one shared rate-limit bucket. `inet_pton()`
     * answers what the address *is*.
     */
    public static function matches(string $ip, string $range): bool
    {
        $ipBinary = @inet_pton($ip);

        if (!str_contains($range, '/')) {
            $rangeBinary = @inet_pton($range);

            return $ipBinary !== false && $rangeBinary !== false && $ipBinary === $rangeBinary;
        }

        [$subnet, $bitsString] = explode('/', $range, 2);
        $bits = (int) $bitsString;

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
}
