<?php

declare(strict_types=1);

namespace Kinetis\Session\Support;

use Kinetis\Session\Exception\SessionException;

/**
 * @internal Used by SessionMiddleware and this package's own stores —
 *     not a supported consumer API. Kept in its own class purely for
 *     internal reuse, not because it's meant to be called from outside
 *     this package.
 *
 * The one place every store validates $lifetimeSeconds and, where a
 * store needs an absolute expiry timestamp rather than handing a
 * relative TTL straight to a backend that computes its own
 * (CacheSessionStore), computes and checks it — so an invalid or
 * unrepresentable lifetime fails the same way, with the same
 * package-owned exception, regardless of which backend is configured,
 * instead of surfacing as backend-specific corruption or a raw error
 * partway through a request.
 */
final class SessionExpiry
{
    /**
     * 9999-12-31 23:59:59 UTC — the real upper bound of MySQL's own
     * DATETIME column type, confirmed directly against a real MySQL 8.4
     * server: an INSERT one second past this value is rejected outright
     * (a real, driver-level "Incorrect datetime value" error), not
     * silently clamped or truncated. DATETIME, not TIMESTAMP, is what
     * this package's own MySQL migration stub uses for `expires_at` —
     * TIMESTAMP reinterprets a bound literal wall-clock string through
     * the connection's own session `time_zone`, confirmed directly
     * against a real server to store a materially different absolute
     * instant depending on that session setting, and to outright reject
     * a value comfortably within this same ceiling under a
     * negative-offset session. DATETIME stores exactly the literal
     * value given, confirmed the same way, with no such reinterpretation
     * regardless of session timezone. Postgres's own TIMESTAMP (without
     * time zone — the default, and what this package's Postgres stub
     * uses) already has this same timezone-blind behavior and, confirmed
     * directly, supports a far wider range than this — so MySQL
     * DATETIME's own year-9999 cap is the narrower, binding constraint
     * across both backends this package's own migration stubs ship for
     * `sql`.
     *
     * Every store enforces this same ceiling, even CacheSessionStore,
     * which never computes an absolute timestamp of its own —
     * $lifetimeSeconds must mean the same thing regardless of which
     * SESSION_DRIVER is configured, so switching drivers never silently
     * starts accepting a value it didn't before.
     */
    public const int MAX_EXPIRES_AT = 253_402_300_799;

    /**
     * $label names the value being validated in the exception message —
     * SessionMiddleware passes 'SESSION_LIFETIME' so a misconfigured
     * environment variable names itself; every other call site (a store
     * validating whatever $lifetimeSeconds it was actually handed, not
     * necessarily config-derived) uses the generic default. $now is the
     * same fixed-clock testing seam timestampFor() takes — see its own
     * docblock for why it exists.
     */
    public static function assertValidLifetime(int $lifetimeSeconds, string $label = 'Session lifetime', ?int $now = null): void
    {
        $now ??= \time();

        self::validate($lifetimeSeconds, $now + $lifetimeSeconds, $label);
    }

    /**
     * The absolute Unix timestamp a session expires at. time() +
     * $lifetimeSeconds can overflow PHP's native int range for an
     * extreme $lifetimeSeconds — checked directly rather than assumed
     * impossible, since PHP silently promotes an overflowing addition to
     * a float, which a store's own JSON/SQL-timestamp encoding would
     * otherwise carry through as backend-specific corruption (a file
     * store's own reader rejecting its freshly-written expiresAt as
     * malformed) or a raw TypeError (a SQL store's timestamp formatter
     * refusing a non-int argument under strict_types) instead of one
     * clear, package-owned exception at the point the bad value was
     * actually given.
     *
     * $now defaults to time(), used exactly once inside this call — but
     * is also an explicit, injectable parameter, purely so a test can
     * pin it to a fixed value and derive $lifetimeSeconds from that same
     * value, rather than computing $lifetimeSeconds from one time() call
     * in the test and having this method make an entirely separate one
     * of its own. Two calls to the real clock, even a line apart, are
     * two different instants a slow or preempted process can let tick
     * over between — this parameter is what removes that gap for an
     * exact-boundary test, not just narrows it. Production call sites
     * never pass it.
     */
    public static function timestampFor(int $lifetimeSeconds, ?int $now = null): int
    {
        $now ??= \time();
        $expiresAt = $now + $lifetimeSeconds;
        self::validate($lifetimeSeconds, $expiresAt, 'Session lifetime');

        /** @var int already confirmed representable and within MAX_EXPIRES_AT by validate() above */
        return $expiresAt;
    }

    /**
     * The one boundary predicate every store's own "is this session
     * still live" check goes through — FileSessionStore's read()/gc()
     * call this directly; SqlSessionStore's `expires_at > ?` /
     * `expires_at <= ?` WHERE clauses are evaluated by the database
     * itself and can't call a PHP function, but use the identical `<=`
     * semantics deliberately, so every store agrees on the exact same
     * second a session actually expires. Takes $now explicitly rather
     * than calling time() itself, so a test can pin both sides of the
     * comparison to one fixed value with no real clock involved at all.
     */
    public static function isExpired(int $expiresAt, int $now): bool
    {
        return $expiresAt <= $now;
    }

    /**
     * Whether $expiresAt is a genuine int within MAX_EXPIRES_AT — pure
     * and real-clock-free, so a test can pin the exact boundary
     * (MAX_EXPIRES_AT itself, and MAX_EXPIRES_AT + 1) with no risk of a
     * clock tick between two separate time() calls landing the value on
     * the wrong side of it, the same hazard isExpired() is deliberately
     * shaped to avoid too.
     */
    public static function isRepresentable(int|float $expiresAt): bool
    {
        return \is_int($expiresAt) && $expiresAt <= self::MAX_EXPIRES_AT;
    }

    private static function validate(int $lifetimeSeconds, int|float $expiresAt, string $label): void
    {
        if ($lifetimeSeconds < 1) {
            throw new SessionException(
                "{$label} must be a positive number of seconds, got {$lifetimeSeconds}.",
            );
        }

        if (!self::isRepresentable($expiresAt)) {
            throw new SessionException(\sprintf(
                '%s %d produces an expiry beyond %d (9999-12-31 23:59:59 UTC) — the latest timestamp every '
                . 'backend this package ships can store.',
                $label,
                $lifetimeSeconds,
                self::MAX_EXPIRES_AT,
            ));
        }
    }
}
