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
 *       --minor|--patch                         Move one or more
 *                                               packages' version field
 *                                               one step — nothing else
 *                                               in the manifest changes.
 *   php tools/generate-composer.php --set-version=<key>=<version>
 *                                               The same move, written
 *                                               as an explicit target
 *                                               rather than a size.
 *   php tools/generate-composer.php --release=<key>[,<key>,...]
 *                                               Print each package's
 *                                               release-mode composer.json
 *                                               (real sibling ^X.Y.Z
 *                                               constraints, no
 *                                               repositories key) to
 *                                               stdout — never writes.
 *   php tools/generate-composer.php --release-write=<key>[,<key>,...]
 *                                               Same content as
 *                                               --release, written to
 *                                               packages/<key>/composer.json
 *                                               instead of printed.
 *
 * Both version modes obey tools/version-policy.php, the same policy
 * validate-manifest.php checks a push against — there is no mode here
 * that writes a version the validator would then reject.
 *
 * Every manifest read runs through tools/manifest-schema.php first, so
 * nothing below writes a file from an entry that hasn't been validated.
 *
 * Never runs `composer` itself — see tools/README.md for the full
 * edit-manifest -> regenerate -> composer update -> commit flow.
 */

require_once __DIR__ . '/manifest-schema.php';
require_once __DIR__ . '/checked-write.php';

/**
 * Reads and validates the manifest, or returns the reason it can't be
 * used. Reading, decoding and schema failures all arrive the same way,
 * and only the three entry-point functions turn one into a message and
 * an exit code — nothing deeper exits, and nothing has written a file or
 * contacted a remote by the time this returns.
 *
 * @return array<string, mixed>|null
 */
function loadManifestOrReport(?string $projectRoot = null): ?array
{
    $loaded = loadValidatedManifest($projectRoot ?? PROJECT_ROOT);

    foreach ($loaded['problems'] as $problem) {
        fwrite(STDERR, "[manifest] {$problem}\n");
    }

    return $loaded['manifest'];
}

/**
 * The sibling constraint a release ships with: a caret on the exact
 * version being released alongside it, patch included. `^1.19` would
 * admit 1.19.0, which is not a version this package was ever built
 * against — and a patch release can add public API, so the difference is
 * real rather than pedantic. The upper bound is unchanged, so nobody's
 * upgrade path narrows; only the floor becomes true.
 */
function siblingConstraint(string $version): string
{
    $v = parseVersion($version);

    if ($v === null) {
        throw new InvalidArgumentException("Not a canonical X.Y.Z version: {$version}");
    }

    return "^{$v['major']}.{$v['minor']}.{$v['patch']}";
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
    // Release mode: every sibling resolves to a real ^X.Y.Z constraint,
    // read from that sibling's own *current* version in the manifest —
    // not whether it's also releasing this round. No repositories key
    // at all, since there's nothing local to point at once this ships
    // to its own standalone repo.
    $siblingConstraint = $release
        ? static fn (string $sibling): string => siblingConstraint($manifest['packages'][$sibling]['version'])
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

    // Every library package carries extra.kinetis — the marker (and,
    // where declared, scan roots / bootstrap class) the framework's
    // PackageDiscovery reads from installed.json. An empty object means
    // "member of the ecosystem, nothing to register yet".
    if (\array_key_exists('kinetis', $pkg)) {
        $out['extra'] = ['kinetis' => (object) $pkg['kinetis']];
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

/** @param array<string, mixed> $manifest */
function runWrite(array $manifest): int
{
    foreach (generateAll($manifest) as $key => $content) {
        try {
            writeFileChecked(composerJsonPath($key), $content);
        } catch (CheckedWriteFailure $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

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
 * real ^X.Y.Z sibling constraints, no repositories key — the counterpart
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
        try {
            writeFileChecked(composerJsonPath($key, $projectRoot), $content);
        } catch (CheckedWriteFailure $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

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
        echo 'All ' . count($manifest['packages']) . " packages match the manifest.\n";

        return 0;
    }

    fwrite(STDERR, 'Stale composer.json for: ' . implode(', ', $stale) . "\n");
    fwrite(STDERR, "Run: php tools/generate-composer.php\n");

    return 1;
}

/**
 * Resolves every requested version move against the shared policy before
 * any of them is applied, so a rejected key leaves the manifest whole
 * rather than half-bumped.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, string> $targets package key => requested version
 * @return array{versions: array<string, string>, problems: list<string>}
 */
function planVersionMoves(array $manifest, array $targets): array
{
    $versions = [];
    $problems = [];

    foreach ($targets as $key => $target) {
        if (!isset($manifest['packages'][$key])) {
            $problems[] = "Unknown package: {$key}";

            continue;
        }

        $current = $manifest['packages'][$key]['version'];
        $problem = versionTransitionProblem($current, $target);

        if ($problem !== null) {
            $problems[] = "{$key}: {$problem}";

            continue;
        }

        $versions[$key] = $target;
    }

    return ['versions' => $versions, 'problems' => $problems];
}

/**
 * The whole invocation, checked before any of it runs.
 *
 * Every mode below writes a file, so an argument list that could mean
 * two things has to be rejected rather than resolved by whichever branch
 * happens to be tested first. A repeated --bump, two size flags, a size
 * flag with nothing to size, --check alongside a mode that writes: each
 * is a different intent than any single reading of it.
 *
 * @param list<string> $args
 * @return array{
 *     mode: 'write'|'check'|'version'|'release'|'release-write',
 *     bump: ?string,
 *     size: ?string,
 *     setVersions: array<string, string>,
 *     keys: ?string,
 *     problems: list<string>,
 * }
 */
function parseGeneratorArguments(array $args): array
{
    $bump = null;
    $size = null;
    $setVersions = [];
    $keys = null;
    $modes = [];
    $problems = [];
    $seen = ['--bump' => 0, 'size' => 0, '--release' => 0, '--release-write' => 0, '--check' => 0];

    foreach ($args as $arg) {
        if ($arg === '--check') {
            $seen['--check']++;
            $modes['check'] = true;
        } elseif (str_starts_with($arg, '--bump=')) {
            $seen['--bump']++;
            $bump = substr($arg, strlen('--bump='));
            $modes['version'] = true;
        } elseif ($arg === '--minor' || $arg === '--patch') {
            $seen['size']++;

            if ($size !== null && $size !== substr($arg, 2)) {
                $problems[] = 'Pick one of --minor or --patch, not both.';
            }

            $size = substr($arg, 2);
        } elseif (str_starts_with($arg, '--set-version=')) {
            $modes['version'] = true;
            $assignment = substr($arg, strlen('--set-version='));

            if (!str_contains($assignment, '=')) {
                $problems[] = "--set-version needs <key>=<version>, got '{$assignment}'";

                continue;
            }

            [$key, $version] = explode('=', $assignment, 2);

            if (array_key_exists($key, $setVersions)) {
                $problems[] = "--set-version names {$key} more than once.";

                continue;
            }

            $setVersions[$key] = $version;
        } elseif (str_starts_with($arg, '--release=')) {
            $seen['--release']++;
            $keys = substr($arg, strlen('--release='));
            $modes['release'] = true;
        } elseif (str_starts_with($arg, '--release-write=')) {
            $seen['--release-write']++;
            $keys = substr($arg, strlen('--release-write='));
            $modes['release-write'] = true;
        } else {
            $problems[] = "Unknown option: {$arg}";
        }
    }

    foreach (['--bump', '--release', '--release-write', '--check'] as $option) {
        if ($seen[$option] > 1) {
            $problems[] = "{$option} is given more than once.";
        }
    }

    if ($seen['size'] > 1 && $size !== null) {
        $problems[] = 'A bump size is given more than once.';
    }

    if ($size !== null && $bump === null) {
        $problems[] = "--{$size} sizes a --bump, which this invocation doesn't have.";
    }

    if ($bump !== null && $size === null) {
        $problems[] = '--bump requires --minor or --patch.';
    }

    if (count($modes) > 1) {
        $named = array_keys($modes);
        sort($named);
        $problems[] = 'These modes cannot run together: ' . implode(', ', $named) . '.';
    }

    return [
        'mode' => array_key_first($modes) ?? 'write',
        'bump' => $bump,
        'size' => $size,
        'setVersions' => $setVersions,
        'keys' => $keys,
        'problems' => array_values(array_unique($problems)),
    ];
}

/**
 * The version each named package is asked to move to. Runs after
 * parseGeneratorArguments() has accepted the invocation, so the only
 * failures left are about the packages themselves.
 *
 * @param array<string, mixed> $manifest
 * @param array{bump: ?string, size: ?string, setVersions: array<string, string>} $parsed
 * @return array{targets: array<string, string>, problems: list<string>}
 */
function versionTargets(array $manifest, array $parsed): array
{
    $targets = $parsed['setVersions'];
    $problems = [];

    foreach (array_keys($targets) as $key) {
        if (!isset($manifest['packages'][$key])) {
            $problems[] = "Unknown package: {$key}";
            unset($targets[$key]);
        }
    }

    if ($parsed['bump'] === null || $parsed['size'] === null) {
        return ['targets' => $targets, 'problems' => $problems];
    }

    $keys = $parsed['bump'] === 'all'
        ? array_map(strval(...), array_keys($manifest['packages']))
        : explode(',', $parsed['bump']);

    foreach ($keys as $key) {
        if (!isset($manifest['packages'][$key])) {
            $problems[] = "Unknown package: {$key}";

            continue;
        }

        if (isset($targets[$key])) {
            $problems[] = "{$key}: --bump and --set-version both name it; pick one";

            continue;
        }

        if (!canStep($manifest['packages'][$key]['version'], $parsed['size'])) {
            $problems[] = "{$key}: a {$parsed['size']} step from {$manifest['packages'][$key]['version']} "
                . 'exceeds the largest version component this tool represents';

            continue;
        }

        $targets[$key] = nextVersion($manifest['packages'][$key]['version'], $parsed['size']);
    }

    return ['targets' => $targets, 'problems' => $problems];
}

/**
 * @param array<string, mixed> $manifest
 * @param array{bump: ?string, size: ?string, setVersions: array<string, string>} $parsed
 */
function runBump(array $manifest, array $parsed): int
{
    $requested = versionTargets($manifest, $parsed);
    $plan = planVersionMoves($manifest, $requested['targets']);
    $problems = [...$requested['problems'], ...$plan['problems']];

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    if ($plan['versions'] === []) {
        fwrite(STDERR, "Usage: --bump=<key>[,<key>,...]|all --minor|--patch\n");
        fwrite(STDERR, "   or: --set-version=<key>=<version>\n");

        return 1;
    }

    $moves = [];

    foreach ($plan['versions'] as $key => $version) {
        $moves[] = "{$key}: {$manifest['packages'][$key]['version']} -> {$version}";
        $manifest['packages'][$key]['version'] = $version;
    }

    try {
        writeFileChecked(MANIFEST_PATH, encodeComposerJson($manifest));
    } catch (CheckedWriteFailure $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }

    foreach ($moves as $move) {
        echo "{$move}\n";
    }

    echo 'Wrote ' . MANIFEST_PATH . "\n";

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
    $parsed = parseGeneratorArguments(array_slice($argv, 1));

    if ($parsed['problems'] !== []) {
        foreach ($parsed['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    $manifest = loadManifestOrReport();

    if ($manifest === null) {
        return 1;
    }

    return match ($parsed['mode']) {
        'check' => runCheck($manifest),
        'version' => runBump($manifest, $parsed),
        'release' => runRelease($manifest, (string) $parsed['keys']),
        'release-write' => runReleaseWrite($manifest, (string) $parsed['keys']),
        'write' => runWrite($manifest),
    };
}

// get_included_files()[0] is the entry-point script whether or not $argv
// is populated, which realpath($argv[0]) is not: PHPUnit's own CLI
// invocation leaves $argv unset. Psalm reads get_included_files() as
// never returning __FILE__ here and reports a ParadoxicalCondition; its
// model of that function does not match runtime, so the suppression
// stays.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(generatorMain($argv ?? []));
}
