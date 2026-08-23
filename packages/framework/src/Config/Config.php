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
     * else set to a string is_numeric() doesn't accept throws
     * InvalidConfigValueException rather than silently taking whatever
     * PHP's own (int) cast would produce from it — "5abc" becoming 5 is
     * exactly the kind of plausible-but-wrong value this exists to catch
     * before it reaches whatever the number configures.
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
     */
    public function intOrNull(string $key): ?int
    {
        $raw = $this->values[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw InvalidConfigValueException::notAnInteger($key, $raw);
        }

        return (int) $raw;
    }

    public function float(string $key, float $default): float
    {
        $raw = $this->values[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!is_numeric($raw)) {
            throw InvalidConfigValueException::notAFloat($key, $raw);
        }

        return (float) $raw;
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
