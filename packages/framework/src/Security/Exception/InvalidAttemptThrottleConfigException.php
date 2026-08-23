<?php

declare(strict_types=1);

namespace Kinetis\Security\Exception;

use InvalidArgumentException;

/**
 * Rejects throttle configuration that cannot enforce a lockout, at the
 * point it is constructed rather than on the attempt that trips over
 * it. Extends InvalidArgumentException so a caller can treat it as the
 * argument error it is.
 */
final class InvalidAttemptThrottleConfigException extends InvalidArgumentException
{
    public static function nonPositiveMaxAttempts(int $maxAttempts): self
    {
        return new self(
            "AttemptThrottle needs a maxAttempts of at least 1, got {$maxAttempts}. "
            . 'Zero or fewer locks out every attempt, including the first, which is a blocked action '
            . 'rather than a throttle.',
        );
    }

    public static function nonPositiveDecay(int $decaySeconds): self
    {
        return new self(
            "AttemptThrottle needs a decaySeconds of at least 1, got {$decaySeconds}. "
            . 'Zero or a negative value stores the counter with a TTL already in the past, so nothing is '
            . 'ever counted and no lockout is enforced while recordFailure() calls appear to succeed.',
        );
    }
}
