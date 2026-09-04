<?php

declare(strict_types=1);

/**
 * The strict boundary every manifest-driven tool crosses before it does
 * anything else. generate-composer.php, validate-manifest.php and
 * release-plan.php all run a manifest through here first, so a malformed
 * or hostile entry is rejected before a file is written or a remote is
 * contacted rather than reaching an array index and half-succeeding.
 *
 * What it establishes:
 *
 *   - A package key is one safe basename. It becomes a directory name
 *     under packages/ and a repository name under the release org, so
 *     anything with a slash, a dot segment, or a shell-significant byte
 *     is rejected outright.
 *   - Every package is named exactly kinetis/<key>. The two are used
 *     interchangeably across the tools and the workflows.
 *   - Required fields exist and hold the type the generator assumes.
 *   - Sibling references name a real package, never the package itself,
 *     never twice.
 *   - The manifest and packages/ agree on which packages exist.
 *   - Every path the generator derives from an entry stays inside that
 *     package's own directory.
 */

require_once __DIR__ . '/version-policy.php';

/**
 * The manifest could not be read or decoded at all — distinct from a
 * document that decoded and then failed the schema, which comes back as
 * a problem list. Thrown from the reusable helpers below and turned into
 * a message and an exit code by the three entry points, so nothing in
 * between exits or leaks a stack trace.
 */
final class ManifestUnreadable extends RuntimeException
{
}

const PROJECT_ROOT = __DIR__ . '/..';
const MANIFEST_PATH = PROJECT_ROOT . '/packages.manifest.json';

/**
 * Lowercase, digits, single dashes between segments — the intersection
 * of a safe path basename, a valid GitHub repository name, and the
 * second half of a Composer package name.
 */
const PACKAGE_KEY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

const MANIFEST_TOP_LEVEL_KEYS = ['defaults', 'packages'];

const MANIFEST_DEFAULTS_KEYS = [
    'type', 'license', 'authors', 'minimumStability', 'preferStable',
    'phpVersion', 'requireDev', 'phpstanRules',
];

const MANIFEST_PACKAGE_KEYS = [
    'name', 'description', 'namespace', 'testNamespace', 'version', 'type',
    'requires', 'requiresDev', 'require', 'requireDevExtra', 'requireDevOverride',
    'versionDriftExemptions', 'suggest', 'bin', 'autoloadFiles', 'kinetis',
];

const MANIFEST_REQUIRED_PACKAGE_KEYS = ['name', 'description', 'namespace', 'version'];

/**
 * Composer's own package-name shape, covering both a vendor/name pair
 * and a single-segment platform requirement (`php`, `ext-pdo_mysql`,
 * `lib-*`). These names become keys in the generated composer.json, so
 * anything outside it either fails Composer or changes what the JSON
 * says.
 */
const COMPOSER_NAME_PATTERN = '/^[a-z0-9](?:[_.-]?[a-z0-9]+)*(?:\/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*)?$/';

/** Author fields the generator copies through into composer.json. */
const COMPOSER_AUTHOR_KEYS = ['name', 'email', 'homepage', 'role'];

function packageDirectory(string $key, ?string $projectRoot = null): string
{
    return ($projectRoot ?? PROJECT_ROOT) . "/packages/{$key}";
}

function composerJsonPath(string $key, ?string $projectRoot = null): string
{
    return packageDirectory($key, $projectRoot) . '/composer.json';
}

/**
 * Decodes manifest JSON without asserting what shape it decoded to —
 * a scalar or a list is a schema problem manifestProblems() reports,
 * not a type error raised on the way in.
 *
 * @throws ManifestUnreadable
 */
function decodeManifest(string $json): mixed
{
    if (trim($json) === '') {
        throw new ManifestUnreadable('the manifest is empty');
    }

    try {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ManifestUnreadable('not valid JSON: ' . $e->getMessage());
    }
}

/** @throws ManifestUnreadable */
function readManifestDocument(?string $path = null): mixed
{
    $path ??= MANIFEST_PATH;
    $json = @file_get_contents($path);

    if ($json === false) {
        throw new ManifestUnreadable("could not read {$path}");
    }

    return decodeManifest($json);
}

/**
 * Reads and validates in one step, reporting both failure kinds the same
 * way. A null manifest means the problems are fatal and nothing else
 * should run.
 *
 * @return array{manifest: array<string, mixed>|null, problems: list<string>}
 */
function loadValidatedManifest(?string $projectRoot = null, ?string $path = null): array
{
    try {
        $document = readManifestDocument($path);
    } catch (ManifestUnreadable $e) {
        return ['manifest' => null, 'problems' => ['manifest: ' . $e->getMessage()]];
    }

    $problems = manifestProblems($document, $projectRoot);

    if ($problems !== []) {
        return ['manifest' => null, 'problems' => $problems];
    }

    /** @var array<string, mixed> $document */
    return ['manifest' => $document, 'problems' => []];
}

/**
 * Every problem with $manifest, empty when it's valid. $projectRoot
 * enables the on-disk checks — packages/ agreeing with the manifest, and
 * derived paths staying inside their package — and is left null by unit
 * tests working from a manifest array alone.
 *
 * @return list<string>
 */
function manifestProblems(mixed $manifest, ?string $projectRoot = null): array
{
    if (!is_array($manifest) || ($manifest !== [] && array_is_list($manifest))) {
        return ['manifest: expected a JSON object, got ' . get_debug_type($manifest)];
    }

    $problems = unknownKeyProblems('manifest', $manifest, MANIFEST_TOP_LEVEL_KEYS);

    foreach (MANIFEST_TOP_LEVEL_KEYS as $required) {
        if (!isset($manifest[$required]) || !is_array($manifest[$required])) {
            $problems[] = "manifest: '{$required}' is missing or is not an object";
        }
    }

    if ($problems !== []) {
        return $problems;
    }

    /** @var array<string, mixed> $defaults */
    $defaults = $manifest['defaults'];
    /** @var array<string, mixed> $packages */
    $packages = $manifest['packages'];

    $problems = [...$problems, ...defaultsProblems($defaults)];

    if ($packages === []) {
        return [...$problems, 'manifest: packages is empty'];
    }

    foreach ($packages as $key => $package) {
        $problems = [...$problems, ...packageProblems((string) $key, $package, $packages, $projectRoot)];
    }

    if ($projectRoot !== null) {
        $problems = [...$problems, ...packageDirectoryProblems(array_map(strval(...), array_keys($packages)), $projectRoot)];
    }

    return $problems;
}

/**
 * @param array<string, mixed> $defaults
 * @return list<string>
 */
function defaultsProblems(array $defaults): array
{
    $problems = unknownKeyProblems('defaults', $defaults, MANIFEST_DEFAULTS_KEYS);

    foreach (['type', 'license', 'minimumStability', 'phpVersion'] as $field) {
        if (!isNonBlankString($defaults[$field] ?? null)) {
            $problems[] = "defaults: '{$field}' must be a non-empty string";
        }
    }

    if (!is_bool($defaults['preferStable'] ?? null)) {
        $problems[] = "defaults: 'preferStable' must be a boolean";
    }

    if (!isConstraintMap($defaults['requireDev'] ?? null)) {
        $problems[] = "defaults: 'requireDev' must be a map of package name to constraint";
    }

    $problems = [...$problems, ...authorProblems($defaults['authors'] ?? null)];

    if (array_key_exists('phpstanRules', $defaults) && !isStringList($defaults['phpstanRules'])) {
        $problems[] = "defaults: 'phpstanRules' must be a list of strings";
    }

    return $problems;
}

/**
 * The authors block lands verbatim in every generated composer.json, so
 * it has to be the shape Composer reads: a list of objects, each with a
 * name and optionally the three other fields Composer knows. A bare
 * string or a nested array here would ship into 27 files unchallenged.
 *
 * @return list<string>
 */
function authorProblems(mixed $authors): array
{
    if (!is_array($authors) || $authors === [] || !array_is_list($authors)) {
        $problems = ["defaults: 'authors' must be a non-empty list"];

        return $problems;
    }

    $problems = [];

    foreach ($authors as $index => $author) {
        if (!is_array($author) || array_is_list($author)) {
            $problems[] = "defaults: author {$index} must be an object";

            continue;
        }

        if (!isPlainString($author['name'] ?? null)) {
            $problems[] = "defaults: author {$index} needs a 'name'";
        }

        foreach ($author as $field => $value) {
            if (!in_array((string) $field, COMPOSER_AUTHOR_KEYS, true)) {
                $problems[] = "defaults: author {$index} has an unknown field '" . (string) $field . "'";

                continue;
            }

            if (!isPlainString($value)) {
                $problems[] = "defaults: author {$index} field '" . (string) $field . "' must be a string";
            }
        }
    }

    return $problems;
}

/**
 * @param array<string, mixed> $allPackages
 * @return list<string>
 */
function packageProblems(string $key, mixed $package, array $allPackages, ?string $projectRoot): array
{
    if (preg_match(PACKAGE_KEY_PATTERN, $key) !== 1) {
        return ["packages: '{$key}' is not a safe package key (lowercase letters, digits and single dashes only)"];
    }

    if (!is_array($package)) {
        return ["{$key}: expected an object"];
    }

    $problems = unknownKeyProblems($key, $package, MANIFEST_PACKAGE_KEYS);

    foreach (MANIFEST_REQUIRED_PACKAGE_KEYS as $required) {
        if (!array_key_exists($required, $package)) {
            $problems[] = "{$key}: '{$required}' is missing";
        }
    }

    if ($problems !== []) {
        return $problems;
    }

    if ($package['name'] !== "kinetis/{$key}") {
        $problems[] = "{$key}: 'name' must be kinetis/{$key}, not " . describeValue($package['name']);
    }

    if (!isPlainString($package['description'])) {
        $problems[] = "{$key}: 'description' must be a non-empty single-line string";
    }

    $problems = [
        ...$problems,
        ...namespaceProblems($key, 'namespace', $package['namespace'], required: true),
        ...namespaceProblems($key, 'testNamespace', $package['testNamespace'] ?? null, required: false),
        ...versionProblems($key, $package['version']),
        ...siblingProblems($key, $package, $allPackages),
        ...constraintMapProblems($key, $package),
        ...driftExemptionProblems($key, $package),
        ...relativePathProblems($key, $package, $projectRoot),
    ];

    if (array_key_exists('type', $package) && !isNonBlankString($package['type'])) {
        $problems[] = "{$key}: 'type' must be a non-empty string";
    }

    if (array_key_exists('kinetis', $package) && !is_array($package['kinetis'])) {
        $problems[] = "{$key}: 'kinetis' must be an object (or an empty list for no entries)";
    }

    return $problems;
}

/** @return list<string> */
function namespaceProblems(string $key, string $field, mixed $value, bool $required): array
{
    if ($value === null && !$required) {
        return [];
    }

    if (!isPlainString($value) || !str_ends_with($value, '\\')) {
        return ["{$key}: '{$field}' must be a PSR-4 namespace prefix ending in a backslash"];
    }

    return [];
}

/** @return list<string> */
function versionProblems(string $key, mixed $version): array
{
    if (!is_string($version)) {
        return ["{$key}: 'version' must be a string"];
    }

    $parsed = parseVersion($version);

    if ($parsed === null) {
        return ["{$key}: version '{$version}' is not a canonical X.Y.Z version"];
    }

    if ($parsed['major'] !== INCUBATION_MAJOR) {
        return ["{$key}: version '{$version}' is not on the " . INCUBATION_MAJOR . '.x line'];
    }

    return [];
}

/**
 * @param array<string, mixed> $package
 * @param array<string, mixed> $allPackages
 * @return list<string>
 */
function siblingProblems(string $key, array $package, array $allPackages): array
{
    $problems = [];
    $seen = [];

    foreach (['requires', 'requiresDev'] as $field) {
        if (!array_key_exists($field, $package)) {
            continue;
        }

        if (!isStringList($package[$field])) {
            $problems[] = "{$key}: '{$field}' must be a list of sibling package keys";

            continue;
        }

        /** @var list<string> $siblings */
        $siblings = $package[$field];

        foreach ($siblings as $sibling) {
            if ($sibling === $key) {
                $problems[] = "{$key}: '{$field}' lists the package itself";

                continue;
            }

            if (!array_key_exists($sibling, $allPackages)) {
                $problems[] = "{$key}: '{$field}' names {$sibling}, which is not a package in this manifest";

                continue;
            }

            if (isset($seen[$sibling])) {
                $problems[] = "{$key}: {$sibling} is listed more than once across 'requires'/'requiresDev'";

                continue;
            }

            $seen[$sibling] = true;
        }
    }

    return $problems;
}

/**
 * @param array<string, mixed> $package
 * @return list<string>
 */
function constraintMapProblems(string $key, array $package): array
{
    $problems = [];

    foreach (['require', 'requireDevExtra', 'requireDevOverride', 'suggest'] as $field) {
        if (array_key_exists($field, $package) && !isConstraintMap($package[$field])) {
            $problems[] = "{$key}: '{$field}' must be a map of package name to string";
        }
    }

    return $problems;
}

/**
 * A drift exemption is per dependency and carries its reason. It has to
 * name a dependency the package declares, or it is a stale
 * entry silently exempting nothing.
 *
 * @param array<string, mixed> $package
 * @return list<string>
 */
function driftExemptionProblems(string $key, array $package): array
{
    if (!array_key_exists('versionDriftExemptions', $package)) {
        return [];
    }

    $exemptions = $package['versionDriftExemptions'];

    if (!is_array($exemptions) || $exemptions === [] || array_is_list($exemptions)) {
        return ["{$key}: 'versionDriftExemptions' must be a non-empty map of dependency name to reason"];
    }

    $declared = is_array($package['require'] ?? null) ? $package['require'] : [];
    $problems = [];

    foreach ($exemptions as $dependency => $reason) {
        $dependency = (string) $dependency;

        if (!isPlainString($reason)) {
            $problems[] = "{$key}: the drift exemption for {$dependency} needs a reason";
        }

        if (!array_key_exists($dependency, $declared)) {
            $problems[] = "{$key}: drift exemption for {$dependency}, which this package does not require";
        }
    }

    return $problems;
}

/**
 * bin and autoloadFiles land verbatim in the generated composer.json and
 * are resolved relative to the package root by Composer, so each must
 * stay inside it.
 *
 * Two checks, because they answer different questions. The lexical one
 * runs always and rejects a path whose spelling leaves the directory —
 * the manifest may name a file that doesn't exist yet, so spelling is
 * all there is to go on. The filesystem one runs when a project root is
 * given and rejects a path that exists but reaches outside through a
 * link; a lexically-clean name is no guarantee once a symlink is in the
 * way.
 *
 * @param array<string, mixed> $package
 * @return list<string>
 */
function relativePathProblems(string $key, array $package, ?string $projectRoot): array
{
    $problems = [];
    $directory = packageDirectory($key, $projectRoot);

    foreach (['bin', 'autoloadFiles'] as $field) {
        if (!array_key_exists($field, $package)) {
            continue;
        }

        if (!isStringList($package[$field])) {
            $problems[] = "{$key}: '{$field}' must be a list of package-relative paths";

            continue;
        }

        /** @var list<string> $paths */
        $paths = $package[$field];

        foreach ($paths as $path) {
            $shape = packageRelativePathProblem($path);

            if ($shape !== null) {
                $problems[] = "{$key}: '{$field}' entry " . describeValue($path) . " {$shape}";

                continue;
            }

            if ($projectRoot === null) {
                continue;
            }

            $escape = confinementProblem("{$directory}/{$path}", $directory);

            if ($escape !== null) {
                $problems[] = "{$key}: '{$field}' entry " . describeValue($path) . " {$escape}";
            }
        }
    }

    if (!pathIsWithin(composerJsonPath($key, $projectRoot), $directory)) {
        $problems[] = "{$key}: the generated composer.json path escapes the package directory";
    }

    return $problems;
}

/**
 * Names Windows refuses whatever extension follows them, matched without
 * regard to case. `CON.php` is as unusable there as `CON`.
 */
const WINDOWS_DEVICE_NAMES = [
    'con', 'prn', 'aux', 'nul',
    'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
    'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
];

/** Characters a Windows filesystem rejects outright. */
const WINDOWS_INVALID_PATH_CHARACTERS = '<>":|?*';

/**
 * The one grammar bin and autoloadFiles are held to.
 *
 * These values ship in the published composer.json and are resolved by
 * whichever machine installs the package, so the check cannot assume the
 * rules of the machine that generated them. The grammar is the
 * intersection: a path every supported platform reads the same way, or
 * no path at all.
 *
 * Two kinds of rule, for two kinds of reason. Traversal and ambiguity —
 * a leading separator, a `.` or `..` segment, a backslash (a separator
 * on Windows, an ordinary character here), a drive letter, an empty
 * segment — decide whether the path stays inside the package. The rest
 * decide whether it resolves at all: a colon introduces an alternate
 * data stream on NTFS, `< > " | ? *` are rejected by that filesystem,
 * a reserved device name is unusable as a file, and a trailing dot or
 * space is silently stripped, so one spelling becomes another.
 *
 * @return string|null what is wrong, or null when the path is usable
 */
function packageRelativePathProblem(string $path): ?string
{
    if ($path === '') {
        return 'is empty';
    }

    if (hasControlBytes($path)) {
        return 'holds a control byte';
    }

    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        return 'starts with a drive letter';
    }

    if (str_contains($path, '\\')) {
        return 'holds a backslash, which is a directory separator on some platforms';
    }

    if (str_starts_with($path, '/')) {
        return 'is absolute';
    }

    $invalid = str_split(WINDOWS_INVALID_PATH_CHARACTERS);

    foreach ($invalid as $character) {
        if (str_contains($path, $character)) {
            return "holds {$character}, which some platforms reject in a filename";
        }
    }

    foreach (explode('/', $path) as $segment) {
        if ($segment === '') {
            return 'has an empty path segment';
        }

        if ($segment === '.' || $segment === '..') {
            return 'has a relative path segment';
        }

        if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            return 'has a path segment ending in a dot or a space, which some platforms strip';
        }

        if (in_array(strtolower(explode('.', $segment, 2)[0]), WINDOWS_DEVICE_NAMES, true)) {
            return 'has a path segment reserved as a device name on some platforms';
        }
    }

    return null;
}

/**
 * Whether $path stays inside $directory once the filesystem has its say.
 *
 * Walks the path a component at a time rather than calling realpath()
 * on the whole thing, because the target may legitimately not exist yet
 * and realpath() answers false for the whole path in that case. Any
 * component that exists and is a link is refused outright: this tooling
 * writes into package directories and the release split reads them, and
 * neither follows a link to somewhere else usefully.
 *
 * @return string|null what is wrong, or null when the path is confined
 */
function confinementProblem(string $path, string $directory): ?string
{
    $base = realpath($directory);

    if ($base === false) {
        return 'sits under a package directory that does not exist';
    }

    $relative = substr(normalizePath($path), strlen(normalizePath($directory)) + 1);
    $walked = $base;

    foreach (explode('/', $relative) as $component) {
        $walked .= "/{$component}";

        if (is_link($walked)) {
            return 'reaches through a symlink';
        }

        if (!file_exists($walked)) {
            return null;
        }
    }

    $resolved = realpath($walked);

    if ($resolved === false || !pathIsWithin($resolved, $base)) {
        return 'resolves outside the package directory';
    }

    return null;
}

/**
 * The manifest and packages/ have to name the same set. A directory with
 * no entry is generated for by nothing and released by nothing; an entry
 * with no directory writes a composer.json into a directory that does not
 * exist and splits an empty repository.
 *
 * A symlinked packages/ root, or a symlinked package directory, is
 * refused rather than followed. The package directory is the write
 * boundary for generation and the read boundary for the release split,
 * and a link makes both point somewhere the manifest never named.
 *
 * @param list<string> $keys
 * @return list<string>
 */
function packageDirectoryProblems(array $keys, string $projectRoot): array
{
    $packagesRoot = "{$projectRoot}/packages";

    if (is_link($packagesRoot)) {
        return ['manifest: packages/ is a symlink, which is not a directory this tooling writes into'];
    }

    $entries = @scandir($packagesRoot);

    if ($entries === false) {
        return ["manifest: {$packagesRoot} could not be read"];
    }

    $problems = [];
    $onDisk = [];

    foreach ($entries as $entry) {
        if (str_starts_with($entry, '.')) {
            continue;
        }

        $path = "{$packagesRoot}/{$entry}";

        if (is_link($path)) {
            $problems[] = "manifest: packages/{$entry} is a symlink, which is not a package directory";

            continue;
        }

        if (is_dir($path)) {
            $onDisk[] = $entry;
        }
    }

    foreach (array_diff($onDisk, $keys) as $orphan) {
        $problems[] = "manifest: packages/{$orphan} has no manifest entry";
    }

    foreach (array_diff($keys, $onDisk) as $missing) {
        $problems[] = "{$missing}: the manifest names it but packages/{$missing} does not exist";
    }

    return $problems;
}

/**
 * @param array<string, mixed> $subject
 * @param list<string> $known
 * @return list<string>
 */
function unknownKeyProblems(string $label, array $subject, array $known): array
{
    $problems = [];

    foreach (array_keys($subject) as $field) {
        if (!in_array((string) $field, $known, true)) {
            $problems[] = "{$label}: unknown field '" . (string) $field . "'";
        }
    }

    return $problems;
}

function isNonBlankString(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

function isStringList(mixed $value): bool
{
    return is_array($value)
        && array_is_list($value)
        && array_all($value, static fn (mixed $entry): bool => isNonBlankString($entry));
}

/**
 * A map of Composer package name to a string value — a version
 * constraint, or the sentence behind a `suggest`. Both halves reach the
 * generated composer.json unchanged, so both are held to what Composer
 * can read.
 */
function isConstraintMap(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }

    if ($value === []) {
        return true;
    }

    if (array_is_list($value)) {
        return false;
    }

    foreach ($value as $name => $constraint) {
        if (!isComposerPackageName((string) $name) || !isPlainString($constraint)) {
            return false;
        }
    }

    return true;
}

function isComposerPackageName(string $name): bool
{
    return $name !== ''
        && strlen($name) <= 200
        && preg_match('/[a-z]/', $name) === 1
        && preg_match(COMPOSER_NAME_PATTERN, $name) === 1;
}

/** A non-blank string with no control bytes to carry into a JSON file. */
function isPlainString(mixed $value): bool
{
    return isNonBlankString($value) && !hasControlBytes($value);
}

function hasControlBytes(string $value): bool
{
    return preg_match('/[\x00-\x1f\x7f]/', $value) === 1;
}

function describeValue(mixed $value): string
{
    return is_string($value) ? "'{$value}'" : get_debug_type($value);
}

/** Resolves . and .. textually — the target need not exist yet. */
function normalizePath(string $path): string
{
    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
}

function pathIsWithin(string $path, string $directory): bool
{
    return str_starts_with(normalizePath($path), rtrim(normalizePath($directory), '/') . '/');
}
