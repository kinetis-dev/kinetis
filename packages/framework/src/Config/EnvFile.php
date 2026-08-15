<?php

declare(strict_types=1);

namespace Kinetis\Config;

use Dotenv\Dotenv;

/**
 * Loads a `.env` file at the project root into the real process
 * environment — `putenv()`/`$_ENV`/`$_SERVER`, via `vlucas/phpdotenv`
 * rather than a hand-rolled parser — correctly handling quoting, comments,
 * multiline values, and variable interpolation is a solved problem.
 *
 * Unconditional and environment-agnostic on purpose — no `AppEnvironment`
 * check gates this. Real deployment environment variables (Docker,
 * systemd, a secrets manager) always win over `.env`: `Dotenv::createImmutable()`
 * never overwrites a variable that's already set, so a real production
 * deployment with its own env vars is unaffected either way. Traditional
 * hosting with only file-level access and no way to set real process
 * environment variables is the other real case this serves — there,
 * `.env` genuinely is the only way to configure the app, in production
 * included, not a dev-only convenience.
 *
 * Calls `Dotenv`'s own `safeLoad()`, not `load()` — a missing `.env` file
 * is the normal case for a deployment that sets real environment variables
 * directly, not an error condition. `load()` throws `InvalidPathException`
 * when the file doesn't exist; `safeLoad()` quietly does nothing. This
 * method is itself named `safeLoad()` too, for the same reason: the name
 * alone should make it obvious a missing `.env` is never an error, without
 * having to open this docblock to check.
 *
 * `createUnsafeImmutable()`, not `createImmutable()` — checked against the
 * actual library source rather than assumed from the name: `createImmutable()`
 * writes only to `$_ENV`/`$_SERVER`, never calling `putenv()`, so plain
 * `getenv()` — which both `Config::fromEnvironment()` and
 * `AppEnvironment::detect()` use — would never see a `.env`-loaded value
 * at all. `createUnsafeImmutable()` adds the `putenv()` adapter back;
 * "unsafe" here means not thread-safe under concurrent calls, not
 * insecure — irrelevant to this specific call site, which runs exactly
 * once at worker boot, before any request handling begins.
 *
 * Deliberately not part of the AOT cache (see Kinetis\Cache): that cache's
 * entire value proposition is being 100% reproducible from source code
 * alone, so deleting and rebuilding it always produces the same artifact.
 * Environment variables break that invariant by definition — baking them
 * into a compiled cache file would mean a changed .env silently does
 * nothing until someone remembers to rebuild the cache.
 */
final class EnvFile
{
    public static function safeLoad(string $projectRoot): void
    {
        Dotenv::createUnsafeImmutable($projectRoot)->safeLoad();
    }
}
