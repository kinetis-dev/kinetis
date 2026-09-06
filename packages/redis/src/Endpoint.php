<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use InvalidArgumentException;

/**
 * One Redis node's host and port, kept as two fields. A bare
 * "host:port" is unambiguous for a hostname or an IPv4 literal, but an
 * IPv6 address contains colons of its own, so the bracketed form
 * "[address]:port" is the only way to say where the address ends.
 *
 * parse() reads one written address; fromParts() reads a host and port
 * that already arrived as separate values, as a CLUSTER SLOTS entry and
 * a REDIS_HOST/REDIS_PORT pair both do. Either rejects an unusable
 * address with InvalidArgumentException; {@see Cluster\SlotMap} is what
 * turns that into a topology failure for the values a server sent.
 */
final class Endpoint
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {}

    public static function parse(string $address): self
    {
        if (str_starts_with($address, '[')) {
            $close = strpos($address, ']');

            if ($close === false || $close === 1 || substr($address, $close + 1, 1) !== ':') {
                throw self::malformed($address);
            }

            return new self(substr($address, 1, $close - 1), self::port(substr($address, $close + 2), $address));
        }

        $colon = strpos($address, ':');

        if ($colon === false || $colon === 0 || strrpos($address, ':') !== $colon) {
            throw self::malformed($address);
        }

        return new self(substr($address, 0, $colon), self::port(substr($address, $colon + 1), $address));
    }

    public static function fromParts(string $host, int $port): self
    {
        if ($host === '' || strpbrk($host, " \t\r\n") !== false) {
            throw new InvalidArgumentException("Unusable Redis host \"{$host}\".");
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Redis endpoint \"{$host}\": port {$port} is outside 1-65535.");
        }

        return new self($host, $port);
    }

    /** "host:port", or "[address]:port" once the host itself contains a colon. */
    public function authority(): string
    {
        return str_contains($this->host, ':')
            ? "[{$this->host}]:{$this->port}"
            : "{$this->host}:{$this->port}";
    }

    public function toUri(): string
    {
        return 'tcp://' . $this->authority();
    }

    private static function port(string $digits, string $address): int
    {
        if (!ctype_digit($digits)) {
            throw self::malformed($address);
        }

        $port = (int) $digits;

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Redis endpoint \"{$address}\": port {$port} is outside 1-65535.");
        }

        return $port;
    }

    private static function malformed(string $address): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Malformed Redis endpoint \"{$address}\": expected \"host:port\", or \"[address]:port\" for IPv6.",
        );
    }
}
