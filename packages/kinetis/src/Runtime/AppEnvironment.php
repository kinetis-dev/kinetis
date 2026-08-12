<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * Gates production-only behavior (AOT caching — see Kinetis\Cache) behind an
 * explicit signal rather than guessing from RuntimeAdapterInterface, since
 * dev/prod is an orthogonal axis to persistent/boot-and-die: FPM can run in
 * prod, FrankenPHP could run in dev.
 *
 * Unset or unrecognized APP_ENV defaults to Production.
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
            'development', 'dev' => self::Development,
            default => self::Production,
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
