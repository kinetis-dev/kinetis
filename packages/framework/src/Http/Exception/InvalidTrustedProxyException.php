<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use InvalidArgumentException;

/**
 * A `TRUSTED_PROXIES` entry that cannot be used as one. Configuration,
 * not client input: it decides who is allowed to rewrite a request's
 * scheme and client address, so an entry this framework cannot read is
 * refused at construction rather than quietly matching nothing — an
 * unusable range that silently never matches reads, from the outside,
 * exactly like a correctly configured one that is never reached.
 */
final class InvalidTrustedProxyException extends InvalidArgumentException
{
    public static function malformed(string $entry, string $reason): self
    {
        return new self("TRUSTED_PROXIES entry \"{$entry}\" is not an address or CIDR range: {$reason}.");
    }
}
