<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

use Amp\Redis\RedisException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;

/**
 * A single Redis Cluster node's host and port, kept as two structured
 * fields rather than a colon-joined string. A bare "host:port" is
 * unambiguous for a hostname or an IPv4 literal, neither of which ever
 * contains a colon itself — but an IPv6 address does, so "host:port"
 * alone can't say where the address ends and the port begins. The
 * conventional fix is bracketing the host ("[addr]:port"), which only
 * makes sense once host and port are tracked as separate fields rather
 * than reassembled into one string later.
 *
 * The constructor is private specifically so an invalid instance can
 * never reach clientFor()/toUri() at all: parse() and fromParts() are
 * the only ways to get one, and both validate before ever calling it —
 * host/port grammar is a property of the type, not a caller convention.
 *
 * parse() is for a seed address — a config string, genuinely ambiguous
 * without the bracket grammar, and a caller mistake maps to
 * Kinetis\SimpleCache\Exception\InvalidArgumentException, the same
 * public configuration-error type ClusteredRedisSimpleCache::fromConfig()
 * already throws for a bad REDIS_TIMEOUT. fromParts() is for a
 * discovered master: CLUSTER SHARDS already reports ip and port as two
 * distinct reply fields, so a discovered address never goes through
 * parse()'s bracket grammar at all — but the values themselves are still
 * validated, since a garbled reply is a real (if rare) possibility, and
 * a failure there maps to Amp\Redis\RedisException instead — a server
 * protocol problem, not something the application configured wrong.
 */
final class ClusterEndpoint
{
    private const int MIN_PORT = 1;

    private const int MAX_PORT = 65535;

    /** Four purely-numeric, dot-separated groups — an *attempted* IPv4 literal, valid or not. */
    private const string DOTTED_QUAD_SHAPE = '/^\d+(\.\d+){3}$/';

    private function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {}

    /**
     * Accepts "host:port" (a hostname or IPv4 literal, split on the last
     * colon — there is only ever one) or "[ipv6-address]:port" (an IPv6
     * literal, unambiguous only once bracketed). An unbracketed value
     * containing more than one colon is rejected rather than guessed at:
     * which colon is the port separator is exactly the question
     * bracketing exists to answer, and there is no safe default reading.
     */
    public static function parse(string $raw): self
    {
        if ($raw !== '' && $raw[0] === '[') {
            return self::parseBracketed($raw);
        }

        $lastColon = strrpos($raw, ':');

        if ($lastColon === false || substr_count($raw, ':') > 1) {
            throw InvalidArgumentException::forMalformedClusterEndpoint($raw);
        }

        $host = substr($raw, 0, $lastColon);
        $portPart = substr($raw, $lastColon + 1);

        if (!self::isValidHostnameOrIpv4($host)) {
            throw InvalidArgumentException::forMalformedClusterEndpoint($raw);
        }

        return new self($host, self::assertValidPortString($portPart, $raw));
    }

    /**
     * @param string $host raw, already-separate — never containing a
     *     port to strip and never itself needing bracket parsing, since
     *     the caller (CLUSTER SHARDS's own reply shape) never joined it
     *     with the port to begin with. A colon-containing $host must be
     *     a genuine IPv6 literal (the only thing a colon can mean here);
     *     anything else is validated the same way parse()'s unbracketed
     *     host is.
     */
    public static function fromParts(string $host, int $port): self
    {
        $isValid = str_contains($host, ':') ? self::isValidIpv6($host) : self::isValidHostnameOrIpv4($host);

        if (!$isValid) {
            throw new RedisException("CLUSTER SHARDS reported an invalid node address \"{$host}\".");
        }

        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw new RedisException("CLUSTER SHARDS reported an invalid port {$port} for host \"{$host}\".");
        }

        return new self($host, $port);
    }

    /**
     * tcp://host:port for a hostname or IPv4 literal; tcp://[host]:port
     * once $host itself contains a colon, which only an IPv6 address
     * ever does — confirmed directly that Amp\Redis\RedisConfig::fromUri()
     * and PHP's own parse_url() both round-trip a bracketed IPv6 URI
     * correctly, so nothing downstream of this needs its own bracket
     * handling.
     */
    public function toUri(): string
    {
        return str_contains($this->host, ':')
            ? "tcp://[{$this->host}]:{$this->port}"
            : "tcp://{$this->host}:{$this->port}";
    }

    /** The same bracketing rule as toUri(), without the scheme — a stable, human-readable, unambiguous dedup/display key. */
    public function key(): string
    {
        return str_contains($this->host, ':')
            ? "[{$this->host}]:{$this->port}"
            : "{$this->host}:{$this->port}";
    }

    private static function parseBracketed(string $raw): self
    {
        $closingBracket = strpos($raw, ']');

        if ($closingBracket === false || $closingBracket === 1
            || !str_starts_with(substr($raw, $closingBracket + 1), ':')
        ) {
            throw InvalidArgumentException::forMalformedClusterEndpoint($raw);
        }

        $host = substr($raw, 1, $closingBracket - 1);
        $portPart = substr($raw, $closingBracket + 2);

        if (!self::isValidIpv6($host)) {
            throw InvalidArgumentException::forMalformedClusterEndpoint($raw);
        }

        return new self($host, self::assertValidPortString($portPart, $raw));
    }

    /**
     * A hostname or IPv4 literal — the unbracketed grammar, shared by
     * parse()'s unbracketed branch and fromParts()'s non-colon branch. A
     * string that merely *looks* like a dotted-quad IPv4 attempt (four
     * numeric, dot-separated groups) but fails strict IPv4 validation is
     * rejected outright rather than falling through to hostname
     * validation — confirmed directly, not assumed, that PHP's own
     * FILTER_VALIDATE_DOMAIN otherwise happily accepts "10.0.0.999" as a
     * syntactically valid hostname, silently reclassifying a broken IP
     * literal instead of rejecting it.
     */
    private static function isValidHostnameOrIpv4(string $host): bool
    {
        if (preg_match(self::DOTTED_QUAD_SHAPE, $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function isValidIpv6(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    private static function assertValidPortString(string $portPart, string $raw): int
    {
        if (!ctype_digit($portPart)) {
            throw InvalidArgumentException::forMalformedClusterEndpoint($raw);
        }

        $port = (int) $portPart;

        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw InvalidArgumentException::forInvalidClusterPort($raw, $port);
        }

        return $port;
    }
}
