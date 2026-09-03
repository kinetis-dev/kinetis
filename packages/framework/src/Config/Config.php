<?php

declare(strict_types=1);

namespace Kinetis\Config;

use Kinetis\Config\Exception\InvalidConfigValueException;
use Kinetis\Config\Exception\MissingConfigException;

/**
 * Typed access to environment configuration — not scattered `getenv()`
 * calls sprinkled through business logic. A plain snapshot taken once at
 * construction, not live `getenv()` reads on every access: environment
 * variables are worker-lifetime configuration, not per-request state, so
 * there's nothing to keep re-reading for.
 *
 * `AppScope::boot()` registers one of these automatically (see that
 * class) unless the consumer already registered their own — resolvable
 * anywhere via constructor injection like any other AppScope service,
 * including through `RequestScope`'s existing delegation rule (it's an
 * explicit AppScope registration, so no new resolution logic was needed
 * for that to work).
 */
final readonly class Config
{
    /**
     * A plain decimal integer: an optional sign, then one or more decimal
     * digits — nothing else. Leading zeroes are accepted (`"007"` is
     * unambiguously seven in this grammar; there is no octal-literal
     * concept here to make them surprising), but fractions, exponents,
     * surrounding whitespace, alternate bases (`0x1A`), and grouping
     * separators (`1_000`) are not — see {@see intOrNull()}.
     */
    private const string INT_PATTERN = '/^([+-]?)([0-9]+)$/D';

    /**
     * Ordinary decimal notation (`5`, `5.`, `.5`, `5.5`) with an optional
     * scientific-notation exponent (`5e3`, `5.5e-3`). A leading `+`,
     * leading zeroes, a trailing dot with nothing after it (`5.`), and a
     * leading dot with nothing before it (`.5`) are all accepted — the
     * same "unambiguous, so no reason to reject it" reasoning as the
     * integer grammar's leading zeroes. Whitespace, a locale-specific
     * decimal separator (`1,5`), and a malformed/partial form (`1.2.3`,
     * `1e`, `e5`) are not — see {@see float()}.
     */
    private const string FLOAT_PATTERN = '/^[+-]?([0-9]+\.?[0-9]*|\.[0-9]+)([eE][+-]?[0-9]+)?$/D';

    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * Snapshots the real process environment — after Kinetis\Config\EnvFile::safeLoad()
     * has had a chance to populate it from `.env`, if this is called after
     * that, which is how both public/index.php and bin/kinetis sequence it.
     */
    public static function fromEnvironment(): self
    {
        /** @var array<string, string> $env */
        $env = getenv();

        return new self($env);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default): string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * An empty value is treated as unset, not as "configured but blank" —
     * the same "REDIS_HOST= is how a value gets turned off" convention
     * already established for named-connection config elsewhere. Anything
     * not matching {@see INT_PATTERN}, or outside the platform's
     * representable integer range, throws InvalidConfigValueException
     * rather than silently taking whatever a lossy cast would produce
     * from it — "5abc" becoming 5, "1.9" truncating to 1, or a huge digit
     * string saturating to PHP_INT_MAX are all exactly the kind of
     * plausible-but-wrong value this exists to catch before it reaches
     * whatever the number configures.
     */
    public function int(string $key, int $default): int
    {
        return $this->intOrNull($key) ?? $default;
    }

    /**
     * Unlike int(), no literal default: null (unset, or explicitly
     * cleared) is itself a real, distinct value some callers need —
     * SqlConnectionFactory's DB_CONNECT_TIMEOUT and kinetis/queue-sql's
     * QUEUE_VISIBILITY_TIMEOUT_SECONDS both mean "no timeout" only when
     * genuinely absent, not some literal integer standing in for it.
     *
     * Never casts before range validity is proven: a plain `(int)` cast
     * of a decimal string that overflows the platform's integer range
     * silently *saturates* toward PHP_INT_MAX/PHP_INT_MIN rather than
     * erroring — a wrong, plausible-looking number is worse than no
     * number at all for something that ends up sizing a pool or bounding
     * a request body. One edge case a saturating cast still gets wrong
     * even *after* range-checking is handled explicitly: a string whose
     * magnitude is exactly `-PHP_INT_MIN` (one greater than
     * `PHP_INT_MAX`, since two's-complement integers aren't symmetric)
     * would still saturate under a plain `-(int) $digits` cast, so that
     * one value is returned as the `PHP_INT_MIN` constant directly
     * instead of being cast at all.
     */
    public function intOrNull(string $key): ?int
    {
        $raw = $this->values[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match(self::INT_PATTERN, $raw, $matches) !== 1) {
            throw InvalidConfigValueException::notAnInteger($key, $raw);
        }

        $negative = $matches[1] === '-';
        $digits = ltrim($matches[2], '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw InvalidConfigValueException::integerOutOfRange($key, $raw);
        }

        if ($negative && $digits === $limit) {
            return PHP_INT_MIN;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /**
     * Ordinary decimal or scientific notation only — see
     * {@see FLOAT_PATTERN} for the exact grammar, including which
     * boundary forms it accepts. Beyond the syntax check, two more
     * things a syntactically valid string can still get wrong:
     *
     * - **Overflow to infinity**: a huge exponent (`1e9999`) is
     *   syntactically a real number, but casting it produces `INF`, not
     *   a number — `is_finite()` catches this (and the symmetric `NAN`
     *   case, though the grammar itself can never actually produce one).
     * - **Underflow to exact zero**: a genuinely nonzero value whose
     *   magnitude is smaller than a float can represent (`1e-400`) casts
     *   to exactly `0.0` — indistinguishable, once cast, from a
     *   deliberately-configured zero. {@see isZeroMantissa()} tells the
     *   two apart from the *string* (every digit character is literally
     *   `0`, independent of any exponent) before the cast ever throws
     *   away the difference, so an underflowed nonzero value is rejected
     *   rather than silently treated as "off"/"unlimited"/"immediate,"
     *   whatever a real `0.0` happens to mean to the caller.
     */
    public function float(string $key, float $default): float
    {
        $raw = $this->values[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (preg_match(self::FLOAT_PATTERN, $raw) !== 1) {
            throw InvalidConfigValueException::notAFloat($key, $raw);
        }

        $value = (float) $raw;

        if (!is_finite($value) || ($value === 0.0 && !self::isZeroMantissa($raw))) {
            throw InvalidConfigValueException::floatOutOfRange($key, $raw);
        }

        return $value;
    }

    /**
     * Whether $raw — already confirmed to match {@see FLOAT_PATTERN} —
     * is mathematically zero regardless of any exponent, checked from
     * the string itself rather than the (potentially already-lossy) cast
     * result: strip the exponent (a zero mantissa times any power of ten
     * is still zero), then the sign and decimal point, and confirm every
     * remaining character is the digit `0`.
     */
    private static function isZeroMantissa(string $raw): bool
    {
        $mantissa = preg_replace('/[eE].*$/', '', $raw) ?? $raw;
        $digitsOnly = str_replace(['+', '-', '.'], '', $mantissa);

        return $digitsOnly !== '' && trim($digitsOnly, '0') === '';
    }

    /**
     * FILTER_NULL_ON_FAILURE is what makes this distinguishable from a
     * value FILTER_VALIDATE_BOOLEAN genuinely recognizes as false
     * ("0"/"false"/"off"/"no") — without it, an unrecognized value like
     * "purple" would silently produce the identical `false` a real "no"
     * does, with nothing telling the two apart.
     */
    public function bool(string $key, bool $default): bool
    {
        $raw = $this->values[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw InvalidConfigValueException::notABoolean($key, $raw);
        }

        return $parsed;
    }

    /**
     * For config with no sane default — a missing DB password should fail
     * fast and clearly, not silently proceed as null/empty and fail later
     * somewhere far less obvious.
     *
     * @throws MissingConfigException
     */
    public function required(string $key): string
    {
        return $this->values[$key] ?? throw MissingConfigException::forKey($key);
    }

    /**
     * The named-connection convention shared by every technology-specific
     * connection builder (RedisSimpleCache, Kinetis\Persistence\SqlConnectionFactory,
     * and any future one): 'default' reads the env var under its own plain
     * name, unchanged; any other name inserts itself, uppercased, as a new
     * segment right after the key's own prefix — REDIS_HOST + "cache2"
     * becomes REDIS_CACHE2_HOST, DB_HOST + "db2" becomes DB_DB2_HOST.
     */
    public static function scopedKey(string $key, string $connection = 'default'): string
    {
        if ($connection === 'default') {
            return $key;
        }

        $separator = strpos($key, '_');

        if ($separator === false) {
            return $key . '_' . strtoupper($connection);
        }

        return substr($key, 0, $separator) . '_' . strtoupper($connection) . substr($key, $separator);
    }
}
