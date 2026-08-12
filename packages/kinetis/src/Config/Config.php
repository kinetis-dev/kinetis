<?php

declare(strict_types=1);

namespace Kinetis\Config;

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

    public function int(string $key, int $default): int
    {
        return isset($this->values[$key]) ? (int) $this->values[$key] : $default;
    }

    public function float(string $key, float $default): float
    {
        return isset($this->values[$key]) ? (float) $this->values[$key] : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        return isset($this->values[$key]) ? filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN) : $default;
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
