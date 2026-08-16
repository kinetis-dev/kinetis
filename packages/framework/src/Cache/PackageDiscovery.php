<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Finds installed Composer packages that participate in Kinetis's
 * attribute discovery — the discoverer whose subject is packages, named
 * consistently with RouteDiscovery/CommandDiscovery/McpDiscovery.
 *
 * Participation is purely convention: a package declares, in its own
 * composer.json,
 *
 *     "extra": {
 *         "kinetis": {
 *             "scan": "Vendor\\Package\\Console\\,Vendor\\Package\\Http\\",
 *             "bootstrap": "Vendor\\Package\\PackageBootstrap"
 *         }
 *     }
 *
 * and installing it is the entire integration step — no registration
 * file, no veto list. `scan` is a comma-separated list of PSR-4
 * namespace prefixes offered for discovery (the same comma-separated
 * list shape as ROUTE_DISCOVERY_PATHS and `--queue=high,default`);
 * every attribute Kinetis discovers for application code works on these
 * roots identically. `bootstrap` names a
 * {@see \Kinetis\Container\PackageBootstrapInterface} implementation run
 * ahead of the application's own bootstrap.php.
 *
 * Reads vendor/composer/installed.json — Composer's own generated record
 * of what is installed, the one place `extra` and each package's
 * autoload map and install path all live together. Declared prefixes
 * resolve against the *declaring package's own* PSR-4 map, so this stays
 * a set of targeted lookups: packages without `extra.kinetis` are never
 * touched, and nothing here ever walks vendor/ blindly. In production
 * the resolved result ships inside the AOT cache (see Compiler), so no
 * composer file is read per request at all.
 *
 * A declared prefix that doesn't match the package's own PSR-4 roots is
 * a package-author mistake — surfaced via error_log() and skipped, never
 * fatal for the consuming application (the same error_log() constraint
 * NamespaceScanner::warnIfNoPsr4Root() documents: static context, no
 * container to resolve a logger from).
 */
final class PackageDiscovery
{
    /**
     * Directory roots offered for discovery by installed packages, as
     * prefix/directory pairs NamespaceScanner can walk.
     *
     * @return list<array{prefix: string, directory: string}>
     */
    public static function scanRoots(string $projectRoot): array
    {
        $roots = [];

        foreach (self::participants($projectRoot) as $package) {
            $scan = $package['kinetis']['scan'] ?? null;

            if (!is_string($scan) || $scan === '') {
                continue;
            }

            foreach (array_map('trim', explode(',', $scan)) as $prefix) {
                if ($prefix === '') {
                    continue;
                }

                $resolved = self::resolvePrefix($package, $prefix);

                if ($resolved === null) {
                    error_log(
                        "Kinetis\\Cache\\PackageDiscovery: package \"{$package['name']}\" declares scan prefix "
                        . "\"{$prefix}\" in extra.kinetis, but its own autoload.psr-4 map has no matching root — skipped.",
                    );

                    continue;
                }

                $roots[] = $resolved;
            }
        }

        return $roots;
    }

    /**
     * Bootstrap classes declared by installed packages, in Composer's
     * recorded order (dependencies first) — each run before the
     * application's own bootstrap.php by RoutesFile::loadBootstrap().
     *
     * @return list<class-string>
     */
    public static function bootstrapClasses(string $projectRoot): array
    {
        $classes = [];

        foreach (self::participants($projectRoot) as $package) {
            $bootstrap = $package['kinetis']['bootstrap'] ?? null;

            if (!is_string($bootstrap) || $bootstrap === '') {
                continue;
            }

            if (!class_exists($bootstrap)) {
                error_log(
                    "Kinetis\\Cache\\PackageDiscovery: package \"{$package['name']}\" declares bootstrap class "
                    . "\"{$bootstrap}\" in extra.kinetis, but no such class is autoloadable — skipped.",
                );

                continue;
            }

            /** @var class-string $bootstrap */
            $classes[] = $bootstrap;
        }

        return $classes;
    }

    /**
     * @return iterable<array{name: string, path: string, autoload: array<mixed, mixed>, kinetis: array<mixed, mixed>}>
     */
    private static function participants(string $projectRoot): iterable
    {
        $installedJsonPath = $projectRoot . '/vendor/composer/installed.json';

        if (!is_file($installedJsonPath)) {
            return;
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($installedJsonPath), associative: true);
        $packages = is_array($decoded) ? ($decoded['packages'] ?? null) : null;

        if (!is_array($packages)) {
            return;
        }

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $kinetis = $package['extra']['kinetis'] ?? null;
            $name = $package['name'] ?? null;
            $installPath = $package['install-path'] ?? null;

            if (!is_array($kinetis) || !is_string($name) || !is_string($installPath)) {
                continue;
            }

            $psr4 = $package['autoload']['psr-4'] ?? null;

            yield [
                'name' => $name,
                // install-path is relative to vendor/composer/.
                'path' => $projectRoot . '/vendor/composer/' . $installPath,
                'autoload' => is_array($psr4) ? $psr4 : [],
                'kinetis' => $kinetis,
            ];
        }
    }

    /**
     * A declared prefix may equal a registered PSR-4 prefix or extend one
     * (a package registering "Vendor\Pkg\" => "src/" can offer
     * "Vendor\Pkg\Console\" for scanning) — the remainder maps onto a
     * subdirectory the same way PSR-4 itself does.
     *
     * @param array{name: string, path: string, autoload: array<mixed, mixed>, kinetis: array<mixed, mixed>} $package
     * @return array{prefix: string, directory: string}|null
     */
    private static function resolvePrefix(array $package, string $prefix): ?array
    {
        $normalized = rtrim($prefix, '\\') . '\\';

        foreach ($package['autoload'] as $registeredPrefix => $directories) {
            if (!is_string($registeredPrefix) || !str_starts_with($normalized, $registeredPrefix)) {
                continue;
            }

            $remainder = substr($normalized, strlen($registeredPrefix));
            $resolved = self::resolveAgainstDirectories($package['path'], $directories, $remainder);

            if ($resolved !== null) {
                return ['prefix' => $normalized, 'directory' => $resolved];
            }
        }

        return null;
    }

    /**
     * The remainder of a declared prefix beyond the registered one maps
     * onto a subdirectory of each registered base directory; the first
     * one that exists on disk wins.
     */
    private static function resolveAgainstDirectories(string $packagePath, mixed $directories, string $remainder): ?string
    {
        $subPath = str_replace('\\', '/', rtrim($remainder, '\\'));

        foreach ((is_array($directories) ? $directories : [$directories]) as $directory) {
            if (!is_string($directory)) {
                continue;
            }

            $base = rtrim($packagePath . '/' . trim($directory, '/'), '/');
            $resolved = $subPath === '' ? $base : $base . '/' . $subPath;

            if (is_dir($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
