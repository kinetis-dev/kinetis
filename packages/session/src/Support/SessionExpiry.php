<?php

declare(strict_types=1);

namespace Kinetis\Session\Support;

use Kinetis\Session\Exception\SessionException;

/**
 * @internal Used by SessionMiddleware and this package's own stores.
 *
 * The single place a session lifetime is checked and turned into an
 * absolute expiry timestamp. Every shipped store passes its
 * `$lifetimeSeconds` through this function, so an invalid lifetime
 * fails identically whichever SESSION_DRIVER is configured.
 */
final class SessionExpiry
{
    /**
     * The absolute Unix timestamp a session expires at.
     *
     * $label names the value in the exception message, so a
     * misconfigured environment variable names itself.
     *
     * The clock is read once and the sum is range-checked before it is
     * computed: an addition past PHP's integer range promotes to float,
     * which a store's JSON envelope or SQL timestamp formatter would
     * then carry through as a malformed record or a raw TypeError
     * instead of one package-owned exception.
     */
    public static function timestampFor(int $lifetimeSeconds, string $label = 'Session lifetime'): int
    {
        if ($lifetimeSeconds < 1) {
            throw new SessionException("{$label} must be a positive number of seconds, got {$lifetimeSeconds}.");
        }

        $now = \time();

        if ($lifetimeSeconds > \PHP_INT_MAX - $now) {
            throw new SessionException(
                "{$label} {$lifetimeSeconds} pushes the expiry past the largest timestamp PHP can represent.",
            );
        }

        return $now + $lifetimeSeconds;
    }
}
