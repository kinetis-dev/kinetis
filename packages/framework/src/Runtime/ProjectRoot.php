<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * Finds the consumer application's project root — where bootstrap.php
 * and .kinetis-cache/ live — from either public/index.php (never a Composer
 * bin-proxy target, so always the directory above the caller) or bin/kinetis
 * (which, once installed as a dependency, runs through Composer's generated
 * vendor/bin/kinetis proxy script; that proxy sets $GLOBALS['_composer_bin_dir']
 * to <app>/vendor/bin before including this package's real bin/kinetis, so
 * __DIR__ inside this package resolves to the *package's own* bin directory,
 * not the consumer's).
 *
 * An instance carries the root an entry point passed to
 * BootSequence::run(), which binds it on AppScope for package bootstraps
 * and commands.
 */
final readonly class ProjectRoot
{
    public function __construct(
        public string $path,
    ) {}

    /**
     * $composerBinDir is accepted as an optional parameter for the same
     * testability reason as RuntimeDetector::detect()/AppEnvironment::detect()
     * — exercising the bin-proxy branch without a real Composer install.
     */
    public static function detect(string $callerDir, ?string $composerBinDir = null): string
    {
        $composerBinDir ??= is_string($GLOBALS['_composer_bin_dir'] ?? null)
            ? $GLOBALS['_composer_bin_dir']
            : null;

        return $composerBinDir !== null ? dirname($composerBinDir, 2) : dirname($callerDir);
    }
}
