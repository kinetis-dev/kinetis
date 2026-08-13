<?php

declare(strict_types=1);

/**
 * Generates each packages/<name>/composer.json from the one canonical
 * packages.manifest.json — see CLAUDE.md and the monorepo packaging plan
 * for the full design. Usages:
 *
 *   php tools/generate-composer.php            Write every package's
 *                                               composer.json (dev-mode:
 *                                               path repos, dev-main).
 *   php tools/generate-composer.php --check     Regenerate in memory,
 *                                               diff against what's
 *                                               committed, never write.
 *                                               Exit 1 if anything's
 *                                               stale.
 *   php tools/generate-composer.php --bump=<key>[,<key>,...]|all
 *       (--major|--minor|--patch|--set-version=<key>=<version>)
 *                                               Force-bump one or more
 *                                               packages' version field
 *                                               only — nothing else in
 *                                               the manifest changes.
 *   php tools/generate-composer.php --release=<key>[,<key>,...]
 *                                               Print each package's
 *                                               release-mode composer.json
 *                                               (real sibling ^X.Y
 *                                               constraints, no
 *                                               repositories key) to
 *                                               stdout — never writes.
 *   php tools/generate-composer.php --release-write=<key>[,<key>,...]
 *                                               Same content as
 *                                               --release, written to
 *                                               packages/<key>/composer.json
 *                                               instead of printed.
 *
 * Never runs `composer` itself — see tools/README.md for the full
 * edit-manifest -> regenerate -> composer update -> commit flow.
 */

const PROJECT_ROOT = __DIR__ . '/..';
const MANIFEST_PATH = PROJECT_ROOT . '/packages.manifest.json';

/** @return array<string, mixed> */
function loadManifest(): array
{
    $json = file_get_contents(MANIFEST_PATH);

    if ($json === false) {
        fwrite(STDERR, "Could not read " . MANIFEST_PATH . "\n");
        exit(1);
    }

    /** @var array<string, mixed> */
    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

function majorMinorConstraint(string $version): string
{
    $v = parseSemver($version);

    return "^{$v['major']}.{$v['minor']}";
}

/**
 * @param array<string, mixed> $pkg
 * @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function assembleComposerJson(array $pkg, array $manifest, bool $release = false): array
{
    $defaults = $manifest['defaults'];
    $requiresSiblings = $pkg['requires'] ?? [];
    $requiresDevSiblings = $pkg['requiresDev'] ?? [];

    // Dev mode: every sibling is "dev-main", resolved via a path repo.
    // Release mode: every sibling resolves to a real ^X.Y constraint,
    // read from that sibling's own *current* version in the manifest —
    // not whether it's also releasing this round. No repositories key
    // at all, since there's nothing local to point at once this ships
    // to its own standalone repo.
    $siblingConstraint = $release
        ? static fn (string $sibling): string => majorMinorConstraint($manifest['packages'][$sibling]['version'])
        : static fn (string $sibling): string => 'dev-main';

    $require = ['php' => $defaults['phpVersion']];

    foreach ($requiresSiblings as $sibling) {
        $require["kinetis/{$sibling}"] = $siblingConstraint($sibling);
    }

    foreach ($pkg['require'] ?? [] as $name => $version) {
        $require[$name] = $version;
    }

    $requireDev = [];

    foreach ($requiresDevSiblings as $sibling) {
        $requireDev["kinetis/{$sibling}"] = $siblingConstraint($sibling);
    }

    if (array_key_exists('requireDevOverride', $pkg)) {
        $requireDev = [...$requireDev, ...$pkg['requireDevOverride']];
    } else {
        $requireDev = [...$requireDev, ...$defaults['requireDev'], ...($pkg['requireDevExtra'] ?? [])];
    }

    ksort($requireDev, SORT_STRING);

    $out = [
        'name' => $pkg['name'],
        'description' => $pkg['description'],
        'type' => $pkg['type'] ?? $defaults['type'],
        'license' => $defaults['license'],
        'authors' => $defaults['authors'],
        'require' => $require,
        'require-dev' => $requireDev,
    ];

    if (!empty($pkg['suggest'])) {
        $out['suggest'] = $pkg['suggest'];
    }

    $autoload = ['psr-4' => [$pkg['namespace'] => 'src/']];

    if (!empty($pkg['autoloadFiles'])) {
        $autoload['files'] = $pkg['autoloadFiles'];
    }

    $out['autoload'] = $autoload;

    if (!empty($pkg['testNamespace'])) {
        $out['autoload-dev'] = ['psr-4' => [$pkg['testNamespace'] => 'tests/']];
    }

    if (!empty($pkg['bin'])) {
        $out['bin'] = $pkg['bin'];
    }

    if (!$release) {
        // repositories: requiresDev siblings first, then requires
        // siblings — confirmed against every real composer.json,
        // including the one package (aws-sigv4) with both kinds
        // present at once.
        $repoSiblings = [...$requiresDevSiblings, ...$requiresSiblings];

        if ($repoSiblings !== []) {
            $out['repositories'] = array_map(
                static fn (string $sibling): array => ['type' => 'path', 'url' => "../{$sibling}"],
                $repoSiblings,
            );
        }
    }

    $config = ['sort-packages' => true];

    if (array_key_exists('infection/infection', $requireDev)) {
        $config['allow-plugins'] = ['infection/extension-installer' => true];
    }

    $out['config'] = $config;
    $out['minimum-stability'] = $defaults['minimumStability'];
    $out['prefer-stable'] = $defaults['preferStable'];

    return $out;
}

/** @param array<string, mixed> $data */
function encodeComposerJson(array $data): string
{
    return json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
}

/**
 * @param array<string, mixed> $manifest
 * @return array<string, string> package key => generated dev-mode composer.json content
 */
function generateAll(array $manifest): array
{
    $generated = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        $generated[$key] = encodeComposerJson(assembleComposerJson($pkg, $manifest, release: false));
    }

    return $generated;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $keys
 * @return array<string, string> package key => generated release-mode composer.json content
 */
function generateRelease(array $manifest, array $keys): array
{
    $generated = [];

    foreach ($keys as $key) {
        $generated[$key] = encodeComposerJson(assembleComposerJson($manifest['packages'][$key], $manifest, release: true));
    }

    return $generated;
}

function composerJsonPath(string $key, ?string $projectRoot = null): string
{
    return ($projectRoot ?? PROJECT_ROOT) . "/packages/{$key}/composer.json";
}

/** @param array<string, mixed> $manifest */
function runWrite(array $manifest): int
{
    foreach (generateAll($manifest) as $key => $content) {
        file_put_contents(composerJsonPath($key), $content);
        echo "wrote packages/{$key}/composer.json\n";
    }

    return 0;
}

/**
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function parseKeys(array $manifest, string $keysArg): array
{
    $keys = explode(',', $keysArg);

    foreach ($keys as $key) {
        if (!isset($manifest['packages'][$key])) {
            fwrite(STDERR, "Unknown package: {$key}\n");

            return [];
        }
    }

    return $keys;
}

/**
 * Writes each given package's release-mode composer.json to disk —
 * real ^X.Y sibling constraints, no repositories key — the counterpart
 * to runRelease()'s stdout preview. $projectRoot is injectable for
 * testing against a temp directory rather than this repo's own tree.
 *
 * @param array<string, mixed> $manifest
 */
function runReleaseWrite(array $manifest, string $keysArg, ?string $projectRoot = null): int
{
    $keys = parseKeys($manifest, $keysArg);

    if ($keys === []) {
        return 1;
    }

    foreach (generateRelease($manifest, $keys) as $key => $content) {
        file_put_contents(composerJsonPath($key, $projectRoot), $content);
        echo "wrote release-mode packages/{$key}/composer.json\n";
    }

    return 0;
}

/**
 * @param array<string, mixed> $manifest
 * @return list<string> package keys whose committed composer.json doesn't match the manifest
 */
function findStalePackages(array $manifest, ?string $projectRoot = null): array
{
    $stale = [];

    foreach (generateAll($manifest) as $key => $content) {
        $path = composerJsonPath($key, $projectRoot);
        $current = is_file($path) ? file_get_contents($path) : null;

        if ($current !== $content) {
            $stale[] = $key;
        }
    }

    return $stale;
}

/** @param array<string, mixed> $manifest */
function runCheck(array $manifest): int
{
    $stale = findStalePackages($manifest);

    if ($stale === []) {
        echo "All " . count($manifest['packages']) . " packages match the manifest.\n";

        return 0;
    }

    fwrite(STDERR, "Stale composer.json for: " . implode(', ', $stale) . "\n");
    fwrite(STDERR, "Run: php tools/generate-composer.php\n");

    return 1;
}

/** @return array{major: int, minor: int, patch: int} */
function parseSemver(string $version): array
{
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m) !== 1) {
        fwrite(STDERR, "Not a plain X.Y.Z version: {$version}\n");
        exit(1);
    }

    return ['major' => (int) $m[1], 'minor' => (int) $m[2], 'patch' => (int) $m[3]];
}

function bumpVersion(string $current, string $component): string
{
    $v = parseSemver($current);

    return match ($component) {
        'major' => ($v['major'] + 1) . '.0.0',
        'minor' => "{$v['major']}." . ($v['minor'] + 1) . '.0',
        'patch' => "{$v['major']}.{$v['minor']}." . ($v['patch'] + 1),
        default => throw new InvalidArgumentException("Unknown bump component: {$component}"),
    };
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $argv
 */
function runBump(array $manifest, array $argv): int
{
    $bumpArg = null;
    $component = null;
    $setVersions = [];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--bump=')) {
            $bumpArg = substr($arg, strlen('--bump='));
        } elseif (in_array($arg, ['--major', '--minor', '--patch'], true)) {
            $component = substr($arg, 2);
        } elseif (str_starts_with($arg, '--set-version=')) {
            [$key, $version] = explode('=', substr($arg, strlen('--set-version=')), 2);
            $setVersions[$key] = $version;
        }
    }

    if ($bumpArg === null && $setVersions === []) {
        fwrite(STDERR, "Usage: --bump=<key>[,<key>,...]|all --major|--minor|--patch\n");
        fwrite(STDERR, "   or: --set-version=<key>=<version>\n");

        return 1;
    }

    if ($bumpArg !== null) {
        if ($component === null) {
            fwrite(STDERR, "--bump requires --major, --minor, or --patch.\n");

            return 1;
        }

        $keys = $bumpArg === 'all' ? array_keys($manifest['packages']) : explode(',', $bumpArg);

        foreach ($keys as $key) {
            if (!isset($manifest['packages'][$key])) {
                fwrite(STDERR, "Unknown package: {$key}\n");

                return 1;
            }

            $current = $manifest['packages'][$key]['version'];
            $next = bumpVersion($current, $component);
            $manifest['packages'][$key]['version'] = $next;
            echo "{$key}: {$current} -> {$next}\n";
        }
    }

    foreach ($setVersions as $key => $version) {
        if (!isset($manifest['packages'][$key])) {
            fwrite(STDERR, "Unknown package: {$key}\n");

            return 1;
        }

        parseSemver($version); // validates shape, exits on failure
        $current = $manifest['packages'][$key]['version'];
        $manifest['packages'][$key]['version'] = $version;
        echo "{$key}: {$current} -> {$version}\n";
    }

    file_put_contents(MANIFEST_PATH, encodeComposerJson($manifest));
    echo "Wrote " . MANIFEST_PATH . "\n";

    return 0;
}

/** @param array<string, mixed> $manifest */
function runRelease(array $manifest, string $keysArg): int
{
    $keys = parseKeys($manifest, $keysArg);

    if ($keys === []) {
        return 1;
    }

    foreach (generateRelease($manifest, $keys) as $key => $content) {
        echo "===== {$key} =====\n";
        echo $content;
    }

    return 0;
}

/** @param list<string> $argv */
function generatorMain(array $argv): int
{
    $manifest = loadManifest();
    $args = array_slice($argv, 1);

    if (in_array('--check', $args, true)) {
        return runCheck($manifest);
    }

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--release-write=')) {
            return runReleaseWrite($manifest, substr($arg, strlen('--release-write=')));
        }

        if (str_starts_with($arg, '--release=')) {
            return runRelease($manifest, substr($arg, strlen('--release=')));
        }
    }

    if (array_filter($args, static fn (string $a): bool => str_starts_with($a, '--bump=') || str_starts_with($a, '--set-version=')) !== []) {
        return runBump($manifest, $args);
    }

    return runWrite($manifest);
}

// get_included_files()[0] is always the true entry-point script,
// regardless of whether $argv is populated — unlike realpath($argv[0]),
// this works reliably under PHPUnit's own CLI invocation too, where
// $argv isn't set the way a plain `php generate-composer.php` gives it.
// Psalm flags this as a ParadoxicalCondition — a real, confirmed false
// positive, not a bug: its own static analysis of get_included_files()
// doesn't match real runtime behavior, verified directly with an
// isolated repro (a standalone script correctly reporting itself as
// the sole included file) and by this exact guard already behaving
// correctly both ways in real CLI runs and under PHPUnit.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(generatorMain($argv ?? []));
}
