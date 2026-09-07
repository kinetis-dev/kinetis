<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Exception;

use RuntimeException;

/**
 * Configuration this package refuses to build a client from. Every one of
 * these is thrown while OpenSearchClientFactory constructs, and the
 * package bootstrap constructs during registration, so a deployment that
 * would send search traffic somewhere unintended, or in the clear, fails
 * at boot rather than on the first search.
 *
 * A message names the scoped configuration key to look at and nothing
 * else. The value under that key is a URL that may carry credentials in
 * its userinfo, and these messages reach logs and error trackers.
 */
final class OpenSearchConfigurationException extends RuntimeException
{
    public static function malformedHost(string $key, string $reason): self
    {
        return new self("{$key} is not a usable OpenSearch origin: {$reason}.");
    }

    public static function plaintextHost(string $key, string $plaintextKey): self
    {
        return new self(
            "{$key} is a plain-HTTP origin, which would carry credentials and documents in the clear. Use https, or set {$plaintextKey}=true to accept that on a trusted network.",
        );
    }

    public static function nonPositiveTimeout(string $key): self
    {
        return new self("{$key} must be a positive number of seconds.");
    }

    public static function nonPositiveResponseLimit(string $key): self
    {
        return new self("{$key} must be a positive number of bytes.");
    }
}
