<?php

declare(strict_types=1);

namespace Kinetis\Search\Exception;

use RuntimeException;

/**
 * Configuration this package refuses to build a transport from. Every
 * one of these is thrown while {@see \Kinetis\Search\SearchTransport}
 * constructs, and an engine's package bootstrap constructs during
 * registration, so a deployment that would send search traffic somewhere
 * unintended, or in the clear, fails at boot rather than on the first
 * search.
 *
 * A message names the scoped configuration key to look at and nothing
 * else. The value under that key is a URL that may carry credentials in
 * its userinfo, and these messages reach logs and error trackers.
 */
final class SearchConfigurationException extends RuntimeException
{
    public static function malformedHost(string $key, string $reason): self
    {
        return new self("{$key} is not a usable search origin: {$reason}.");
    }

    public static function plaintextHost(string $key, string $plaintextKey): self
    {
        return new self(
            "{$key} is a plain-HTTP origin, which would carry credentials and documents in the clear. Use https, or set {$plaintextKey}=true to accept that on a trusted network.",
        );
    }

    /**
     * Two credentials configured for one request, where which one
     * reaches the cluster would depend on header precedence rather than
     * on the deployment's own decision.
     */
    public static function conflictingCredentials(string $key, string $otherKey): self
    {
        return new self("{$key} and {$otherKey} are two credentials for one request. Configure one of them.");
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
