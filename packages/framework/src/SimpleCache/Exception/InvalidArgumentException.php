<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Exception;

use InvalidArgumentException as SplInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

final class InvalidArgumentException extends SplInvalidArgumentException implements PsrInvalidArgumentException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid cache key \"{$key}\": {$reason}");
    }

    /**
     * A Redis Cluster seed address that isn't "host:port" or
     * "[ipv6-address]:port" — including an empty string, a missing or
     * non-numeric port, or an unbracketed value with more than one
     * colon (ambiguous: which colon is the port separator?).
     */
    public static function forMalformedClusterEndpoint(string $raw): self
    {
        return new self(
            "Invalid Redis Cluster endpoint \"{$raw}\": expected \"host:port\" for a hostname or IPv4 address, "
            . 'or "[ipv6-address]:port" for an IPv6 address.',
        );
    }

    public static function forInvalidClusterPort(string $raw, int $port): self
    {
        return new self("Invalid Redis Cluster endpoint \"{$raw}\": port {$port} is outside the valid 1-65535 range.");
    }
}
