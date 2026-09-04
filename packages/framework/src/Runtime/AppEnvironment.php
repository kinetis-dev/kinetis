<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * Gates production-only behavior (AOT caching — see Kinetis\Cache) behind an
 * explicit signal rather than guessing from RuntimeAdapterInterface, since
 * the development/production axis is orthogonal to persistent/boot-and-die:
 * FPM can serve production, FrankenPHP can serve development.
 *
 * Only the exact name `development`, ignoring case, selects Development.
 * An unset APP_ENV and every other name — a deployment's own `staging`
 * included — is Production, so the cheaper, safer-to-be-wrong side of the
 * gate is what an unfamiliar name lands on. A deployment that needs its
 * own environment names distinguished by name matches the raw APP_ENV
 * string itself, the way {@see \Kinetis\OpenApi\OpenApiAccess} does.
 */
enum AppEnvironment: string
{
    case Development = 'development';
    case Production = 'production';

    /**
     * $appEnv is accepted as an optional parameter (rather than reading
     * getenv() directly) for the same reason RuntimeDetector::detect() does
     * — tests can exercise every branch without mutating real process
     * environment state.
     */
    public static function detect(?string $appEnv = null): self
    {
        $appEnv ??= getenv('APP_ENV') ?: null;

        return match (strtolower((string) $appEnv)) {
            'development' => self::Development,
            default => self::Production,
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
