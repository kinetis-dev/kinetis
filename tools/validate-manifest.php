<?php

declare(strict_types=1);

/**
 * The checks CI runs against packages.manifest.json — see CLAUDE.md and
 * tools/README.md for the full flow:
 *
 *   1. Manifest schema — the shape every check below indexes into.
 *      Runs first and alone: an invalid manifest is rejected before
 *      anything reads it.
 *   2. Cycle detection over the requires graph.
 *   3. Cross-manifest version consistency: two packages sharing an
 *      external dependency declare the same constraint for it.
 *   4. Generated-file drift (reuses tools/generate-composer.php).
 *   5. Version-bump completeness — the release trigger's own integrity
 *      check, comparing the current manifest against its state at the
 *      comparison base and holding every change to
 *      tools/version-policy.php's one-step transition rule.
 *   6. Content-bump completeness — the counterpart check 5 can't see:
 *      check 5 only compares manifest *entries*, so a change to a
 *      package's own files with no manifest change (a compose file, a
 *      bootstrap, a README) is invisible to it — and an unbumped
 *      version means the release pipeline never tags the new content
 *      at all, so the split repo silently stays on the old tag. This
 *      check diffs each package's tracked files against the same base
 *      and requires a version change whenever anything besides
 *      composer.lock changed (the lock is deleted from release commits
 *      and refreshes constantly without semantic change). Uncommitted
 *      brand-new files are invisible to a local run (git diff only sees
 *      tracked paths); the post-push CI run sees everything.
 *   7. Workflow coverage — every package in the manifest has a job in
 *      ci.yml and in infection.yml, and every job in those two maps
 *      back to a package. Adding a package without wiring it into CI
 *      leaves it untested while everything still passes. Exemptions are
 *      named in INFECTION_EXEMPT and WORKFLOW_ONLY below, with reasons,
 *      so an absence is a decision rather than an oversight. The same
 *      check requires sonarqube.yml's coverage loop and the reportPaths
 *      list in sonar-project.properties to name the same packages: a
 *      package in one but not the other produces a coverage report
 *      nobody reads, or names a report nobody writes, and reads as 0%
 *      either way.
 *
 * A separate check — does each package's committed composer.lock still
 * match its composer.json — is just `composer validate --strict`, run
 * directly, no new code needed for it.
 *
 * Usage: php tools/validate-manifest.php [--base=<ref>]
 *
 * --base pins the comparison for checks 5 and 6, which is what a feature
 * branch needs: passing the merge base with the integration branch
 * measures the branch as one whole change, so an early commit's bump is
 * what the later commits are judged against rather than each commit
 * re-deciding from HEAD. Without it the base is GITHUB_EVENT_BEFORE when
 * set, HEAD^ otherwise. A base that was named but cannot be read fails
 * the run; only the absence of any base at all (the repository's first
 * commit) skips the two checks.
 */

require_once __DIR__ . '/generate-composer.php';

/**
 * A package's own directory name, which is also its manifest key and
 * the prefix its split repository is built from.
 */
const PACKAGE_KEY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

/** Packagist's own name grammar, for the `name` field. */
const COMPOSER_NAME_PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

const MANIFEST_DEFAULTS_KEYS = [
    'type', 'license', 'authors', 'minimumStability', 'preferStable',
    'phpVersion', 'requireDev', 'phpstanRules',
];

const MANIFEST_PACKAGE_KEYS = [
    'name', 'description', 'namespace', 'version', 'type', 'requires', 'requiresDev',
    'require', 'requireDevExtra', 'requireDevOverride', 'suggest', 'autoloadFiles',
    'testNamespace', 'bin', 'kinetis',
];

const MANIFEST_REQUIRED_PACKAGE_KEYS = ['name', 'description', 'namespace', 'version'];

/**
 * Check 1. Everything below assumes a manifest that got through here,
 * so this reports every problem it finds rather than stopping at the
 * first — a manifest is edited by hand and one run should name all of
 * the mistakes in it.
 *
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function checkManifestSchema(array $manifest, ?string $projectRoot = null): array
{
    $problems = unknownKeyProblems('manifest', $manifest, ['defaults', 'packages']);
    $problems = [...$problems, ...unknownKeyProblems('defaults', $manifest['defaults'], MANIFEST_DEFAULTS_KEYS)];

    foreach (MANIFEST_DEFAULTS_KEYS as $field) {
        if (!array_key_exists($field, $manifest['defaults'])) {
            $problems[] = "defaults is missing '{$field}'";
        }
    }

    $packages = $manifest['packages'];

    foreach ($packages as $key => $pkg) {
        $problems = [...$problems, ...packageSchemaProblems((string) $key, $pkg, $packages, $projectRoot)];
    }

    return $problems;
}

/**
 * @param array<string, mixed> $packages
 * @return list<string>
 */
function packageSchemaProblems(string $key, mixed $pkg, array $packages, ?string $projectRoot): array
{
    if (!is_array($pkg)) {
        return ["{$key}: entry is not an object"];
    }

    $problems = unknownKeyProblems($key, $pkg, MANIFEST_PACKAGE_KEYS);

    if (preg_match(PACKAGE_KEY_PATTERN, $key) !== 1) {
        $problems[] = "{$key}: package key must be lowercase words joined by single dashes";
    } elseif (!is_dir(packageDirectory($key, $projectRoot))) {
        $problems[] = "{$key}: no packages/{$key} directory";
    }

    foreach (MANIFEST_REQUIRED_PACKAGE_KEYS as $field) {
        if (!isset($pkg[$field]) || !is_string($pkg[$field]) || trim($pkg[$field]) === '') {
            $problems[] = "{$key}: '{$field}' must be a non-empty string";
        }
    }

    if (isset($pkg['name']) && is_string($pkg['name']) && preg_match(COMPOSER_NAME_PATTERN, $pkg['name']) !== 1) {
        $problems[] = "{$key}: 'name' is not a vendor/package Composer name";
    }

    if (isset($pkg['version']) && is_string($pkg['version'])) {
        $problem = versionTransitionProblem(null, $pkg['version']);

        // A package already past 1.0.0 is the normal case, so only the
        // two facts that hold for every entry are checked here: the
        // version parses, and it is on the incubation line. Which move
        // is legal is checks 5 and 6.
        if (parseVersion($pkg['version']) === null || ($problem !== null && str_contains($problem, '.x line'))) {
            $problems[] = "{$key}: 'version' must be a canonical " . INCUBATION_MAJOR . '.x.y version';
        }
    }

    foreach (['requires', 'requiresDev'] as $field) {
        foreach ($pkg[$field] ?? [] as $sibling) {
            if (!is_string($sibling) || !isset($packages[$sibling])) {
                $problems[] = "{$key}: '{$field}' names " . describeValue($sibling) . ', which is not a manifest package';
            } elseif ($sibling === $key) {
                $problems[] = "{$key}: '{$field}' names the package itself";
            }
        }
    }

    foreach (['require', 'requireDevExtra', 'requireDevOverride', 'suggest'] as $field) {
        if (array_key_exists($field, $pkg) && !isConstraintMap($pkg[$field])) {
            $problems[] = "{$key}: '{$field}' must be an object of package name => string";
        }
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
            $problems[] = "{$label}: unknown key '{$field}'";
        }
    }

    return $problems;
}

function isConstraintMap(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $name => $constraint) {
        if (!is_string($name) || !is_string($constraint)) {
            return false;
        }
    }

    return true;
}

function describeValue(mixed $value): string
{
    return is_string($value) ? "'{$value}'" : get_debug_type($value);
}

/**
 * @param array<string, array<string, mixed>> $packages
 * @return array<string, list<string>> package key => its requires list
 */
function buildGraph(array $packages): array
{
    $graph = [];

    foreach ($packages as $key => $pkg) {
        $graph[$key] = $pkg['requires'] ?? [];
    }

    return $graph;
}

/** @param array<string, mixed> $manifest */
function checkCycles(array $manifest): ?string
{
    $graph = buildGraph($manifest['packages']);
    $visited = [];
    $inProgress = [];

    $visit = function (string $node, array $path) use (&$visit, &$visited, &$inProgress, $graph): ?string {
        if (isset($visited[$node])) {
            return null;
        }

        if (isset($inProgress[$node])) {
            return 'Cycle detected: ' . implode(' -> ', [...$path, $node]);
        }

        $inProgress[$node] = true;

        foreach ($graph[$node] ?? [] as $dep) {
            $result = $visit($dep, [...$path, $node]);

            if ($result !== null) {
                return $result;
            }
        }

        unset($inProgress[$node]);
        $visited[$node] = true;

        return null;
    };

    foreach (array_keys($graph) as $node) {
        $result = $visit($node, []);

        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

/**
 * Two packages sharing an external dependency declare the same
 * constraint for it, so the generated composer.json files a consumer
 * installs side by side cannot disagree about a floor.
 *
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function checkVersionConsistency(array $manifest): array
{
    $seen = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        foreach ($pkg['require'] ?? [] as $extName => $constraint) {
            $seen[$extName][$constraint][] = $key;
        }
    }

    $problems = [];

    foreach ($seen as $extName => $byConstraint) {
        if (count($byConstraint) <= 1) {
            continue;
        }

        $parts = [];

        foreach ($byConstraint as $constraint => $keys) {
            $parts[] = "{$constraint} (" . implode(', ', $keys) . ')';
        }

        $problems[] = "{$extName}: " . implode(' vs. ', $parts);
    }

    return $problems;
}

/**
 * @param array<string, mixed> $oldManifest
 * @param array<string, mixed> $newManifest
 * @return list<string>
 */
function checkVersionBumpCompleteness(array $oldManifest, array $newManifest): array
{
    $problems = [];

    foreach ($newManifest['packages'] as $key => $newPkg) {
        $oldPkg = $oldManifest['packages'][$key] ?? null;

        if ($oldPkg === null) {
            $problem = versionTransitionProblem(null, $newPkg['version']);

            if ($problem !== null) {
                $problems[] = "{$key}: {$problem}";
            }

            continue;
        }

        if ($oldPkg == $newPkg) {
            continue;
        }

        $versionChanged = ($oldPkg['version'] ?? null) !== $newPkg['version'];

        if (!$versionChanged) {
            $problems[] = "{$key}: manifest entry changed but 'version' was not bumped";

            continue;
        }

        $problem = versionTransitionProblem($oldPkg['version'] ?? null, $newPkg['version']);

        if ($problem !== null) {
            $problems[] = "{$key}: {$problem}";
        }
    }

    return $problems;
}

/**
 * Check 6: a package whose own files changed needs a version bump,
 * whether or not its manifest entry moved. composer.lock is the one
 * exclusion — deleted from release commits, refreshed constantly
 * without semantic change. A package with no old manifest entry is
 * brand new and exempt, matching check 5's own rule.
 *
 * A file moved between packages arrives here as two paths — a deletion
 * under the package that lost it and an addition under the one that
 * gained it — because changedPackagePaths() turns git's rename
 * detection off. Both packages are attributed, and both need their own
 * bump: the source package's next release drops that file, which is a
 * change its consumers see.
 *
 * @param array<string, mixed> $oldManifest
 * @param array<string, mixed> $newManifest
 * @param list<string> $changedFiles repo-relative paths
 * @return list<string>
 */
function checkContentBumpCompleteness(array $oldManifest, array $newManifest, array $changedFiles): array
{
    $changedByPackage = [];

    foreach ($changedFiles as $file) {
        if (preg_match('#^packages/([^/]+)/(.+)$#', $file, $m) !== 1) {
            continue;
        }

        // Only the package-root lock is release-deleted; a nested file
        // that shares the name (a test fixture's lock) is real content.
        if ($m[2] === 'composer.lock') {
            continue;
        }

        $changedByPackage[$m[1]][] = $m[2];
    }

    $problems = [];

    foreach ($newManifest['packages'] as $key => $newPkg) {
        $oldPkg = $oldManifest['packages'][$key] ?? null;
        $changed = $changedByPackage[$key] ?? [];

        if ($oldPkg === null || $changed === []) {
            continue;
        }

        if (($oldPkg['version'] ?? null) === $newPkg['version']) {
            $shown = implode(', ', array_slice($changed, 0, 3));
            $more = count($changed) > 3 ? ', …' : '';
            $problems[] = "{$key}: package files changed but 'version' was not bumped ({$shown}{$more})";
        }
    }

    return $problems;
}

/** Packages with no infection.yml job, and why. */
const INFECTION_EXEMPT = [
    'pingpong' => 'a demo application, read and run rather than mutated; its suite runs in ci.yml and is measured for coverage',
];

/** Workflow job directories that are deliberately not manifest packages. */
const WORKFLOW_ONLY = [
    'tools' => "the monorepo's own tooling rather than a published package",
];

/**
 * Reads the package directories a workflow's matrix declares.
 *
 * Matched on `dir:` rather than `name:` because the two differ — the
 * framework package is called `core` in both workflows — and the
 * directory is what maps back to a manifest key.
 *
 * @return list<string> manifest keys, e.g. 'framework', plus any
 *         non-package directory such as 'tools'
 */
function workflowPackages(string $workflowPath): array
{
    $contents = @file_get_contents($workflowPath);

    if ($contents === false) {
        return [];
    }

    preg_match_all('/^\s*- \{[^}]*\bdir: ([^,}\s]+)/m', $contents, $matches);

    $keys = [];

    foreach ($matches[1] as $dir) {
        $keys[] = str_starts_with($dir, 'packages/') ? substr($dir, strlen('packages/')) : $dir;
    }

    return array_values(array_unique($keys));
}

/**
 * Reads the packages sonarqube.yml generates a coverage report for.
 *
 * @return list<string>
 */
function coverageLoopPackages(string $workflowPath): array
{
    $contents = @file_get_contents($workflowPath);

    if ($contents === false || preg_match('/for pkg in ([a-z0-9 -]+); do/', $contents, $m) !== 1) {
        return [];
    }

    return array_values(array_filter(explode(' ', trim($m[1]))));
}

/**
 * Reads the coverage reports sonar-project.properties tells SonarQube to
 * read.
 *
 * @return list<string>
 */
function coverageReportPackages(string $propertiesPath): array
{
    $contents = @file_get_contents($propertiesPath);

    if ($contents === false) {
        return [];
    }

    preg_match_all('#packages/([a-z0-9-]+)/coverage\\.xml#', $contents, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * @param list<string> $generated
 * @param list<string> $read
 * @return list<string>
 */
function checkCoverageWiring(array $generated, array $read): array
{
    $problems = [];

    foreach (array_diff($generated, $read) as $key) {
        $problems[] = "{$key} has a coverage report generated by sonarqube.yml that sonar-project.properties never reads — "
            . 'add packages/' . $key . '/coverage.xml to sonar.php.coverage.reportPaths, or the package reads as 0% covered.';
    }

    foreach (array_diff($read, $generated) as $key) {
        $problems[] = "sonar-project.properties reads packages/{$key}/coverage.xml, which sonarqube.yml never generates — "
            . "add {$key} to its coverage loop, or drop the path.";
    }

    return $problems;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $ciPackages
 * @param list<string> $infectionPackages
 * @return list<string>
 */
function checkWorkflowCoverage(array $manifest, array $ciPackages, array $infectionPackages): array
{
    $packages = array_keys($manifest['packages'] ?? []);
    $problems = [];

    foreach ($packages as $key) {
        if (!in_array($key, $ciPackages, true)) {
            $problems[] = "{$key} has no job in ci.yml — add one to its matrix.";
        }

        if (in_array($key, $infectionPackages, true) || isset(INFECTION_EXEMPT[$key])) {
            continue;
        }

        $problems[] = "{$key} has no job in infection.yml — add one with a threshold below its measured score, "
            . 'or add it to INFECTION_EXEMPT here with the reason.';
    }

    foreach (['ci.yml' => $ciPackages, 'infection.yml' => $infectionPackages] as $workflow => $declared) {
        foreach ($declared as $key) {
            if (in_array($key, $packages, true) || isset(WORKFLOW_ONLY[$key])) {
                continue;
            }

            $problems[] = "{$workflow} has a job for \"{$key}\", which is not a manifest package — "
                . 'remove it, or add it to WORKFLOW_ONLY here with the reason.';
        }
    }

    return $problems;
}

/** A git read that could not answer the question it was asked. */
final class GitUnavailable extends RuntimeException
{
}

/**
 * Runs one command in $workingDirectory and reports what happened. The
 * one place in this directory that starts a process; release-plan.php
 * and release-publish.php read git through it too.
 *
 * $environment is added to this process's own environment for the child
 * only, which is how the publisher hands a credential to git without it
 * reaching an argument, a URL, or a config value.
 *
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{code: int, out: string, err: string}
 */
function run(string $workingDirectory, array $command, array $environment = []): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        $environment === [] ? null : [...getenv(), ...$environment],
    );

    if (!is_resource($process)) {
        throw new GitUnavailable('Could not start ' . $command[0] . '.');
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
}

/**
 * Runs git and returns stdout, or null when git exited non-zero.
 * Nothing here interprets a failure — the caller knows whether "no such
 * ref" is an answer or a fault.
 */
function git(string $workingDirectory, string ...$arguments): ?string
{
    $result = run($workingDirectory, ['git', ...$arguments]);

    return $result['code'] === 0 ? $result['out'] : null;
}

/**
 * HEAD's first parent, or null when HEAD is the repository's root
 * commit.
 *
 * `git rev-list --parents -n 1 HEAD` lists the parents recorded for
 * HEAD. A grafted commit at the bottom of a shallow checkout records
 * none, exactly as a root commit does, so a repository that says it is
 * shallow is a history this cannot read rather than one with nothing
 * behind it. A parent that is named but cannot be resolved is the same
 * answer for the same reason.
 *
 * @throws GitUnavailable when HEAD's history cannot be read
 */
function headParent(string $workingDirectory): ?string
{
    $line = git($workingDirectory, 'rev-list', '--parents', '-n', '1', 'HEAD');
    $fields = $line === null ? [] : preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY);

    if ($line === null || $fields === false || $fields === []) {
        throw new GitUnavailable('Could not read HEAD, so there is nothing to compare this change against.');
    }

    if (count($fields) === 1) {
        if (trim((string) git($workingDirectory, 'rev-parse', '--is-shallow-repository')) === 'true') {
            throw new GitUnavailable(
                'HEAD records no parent and the repository is shallow, so this checkout cannot tell a first '
                . 'commit from a truncated history — fetch full history.',
            );
        }

        return null;
    }

    $parent = git($workingDirectory, 'rev-parse', '--verify', '--quiet', "{$fields[1]}^{commit}");

    if ($parent === null || trim($parent) === '') {
        throw new GitUnavailable(
            "HEAD names {$fields[1]} as its parent, and this checkout cannot read it — fetch full history.",
        );
    }

    return trim($parent);
}

/**
 * The commit checks 5 and 6 compare against: the explicit --base when
 * given, GITHUB_EVENT_BEFORE when the push trigger set it, HEAD's first
 * parent otherwise.
 *
 * Returns null only for a commit proven to have no parent — the
 * repository's first, which has nothing behind it to compare against.
 * Every other history this cannot read throws, because a base that was
 * asked for and could not be read is a question nobody answered rather
 * than a question with no answer. That distinction is the whole point of
 * the parent lookup below: a shallow checkout's oldest commit reports no
 * parent for the same reason a root commit does, and reading it as a
 * root commit would skip both checks on a history that is not present.
 *
 * @throws GitUnavailable
 */
function comparisonBase(?string $override, string $workingDirectory): ?string
{
    $environment = getenv('GITHUB_EVENT_BEFORE');
    $named = $override ?? (is_string($environment) && trim($environment) !== '' ? trim($environment) : null);

    // The all-zero SHA is what GitHub sends for a branch's first push.
    if ($named !== null && trim($named, '0') === '') {
        return null;
    }

    if ($named === null) {
        return headParent($workingDirectory);
    }

    $resolved = git($workingDirectory, 'rev-parse', '--verify', '--quiet', "{$named}^{commit}");

    if ($resolved === null || trim($resolved) === '') {
        $shallow = trim((string) git($workingDirectory, 'rev-parse', '--is-shallow-repository'));

        throw new GitUnavailable(
            "Comparison base '{$named}' is not a commit this checkout can read"
            . ($shallow === 'true' ? ' — the repository is shallow; fetch full history.' : '.'),
        );
    }

    return trim($resolved);
}

/**
 * @return array<string, mixed>
 * @throws GitUnavailable
 */
function manifestAtCommit(string $commit, string $workingDirectory): array
{
    $json = git($workingDirectory, 'show', "{$commit}:packages.manifest.json");

    if ($json === null) {
        throw new GitUnavailable("Commit {$commit} carries no packages.manifest.json.");
    }

    try {
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new GitUnavailable("The manifest at {$commit} is not valid JSON: {$e->getMessage()}");
    }

    if (!is_array($manifest) || !isset($manifest['packages']) || !is_array($manifest['packages'])) {
        throw new GitUnavailable("The manifest at {$commit} has no 'packages' object.");
    }

    /** @var array<string, mixed> */
    return $manifest;
}

/**
 * Every tracked path under packages/ that differs between $commit and
 * the working tree. --no-renames is what makes a file moved between two
 * packages appear under both.
 *
 * @return list<string>
 * @throws GitUnavailable
 */
function changedPackagePaths(string $commit, string $workingDirectory): array
{
    $output = git($workingDirectory, 'diff', '--no-renames', '--name-only', '-z', $commit, '--', 'packages');

    if ($output === null) {
        throw new GitUnavailable("Could not diff packages/ against {$commit}.");
    }

    return array_values(array_filter(explode("\0", $output), static fn (string $p): bool => $p !== ''));
}

/**
 * @param list<string> $argv
 * @return array{base: ?string, problems: list<string>}
 */
function parseValidatorArguments(array $argv): array
{
    $base = null;
    $problems = [];

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--base=')) {
            $problems[] = "Unknown option: {$arg}";

            continue;
        }

        if ($base !== null) {
            $problems[] = '--base is given more than once.';
        }

        $value = trim(substr($arg, strlen('--base=')));

        // An empty --base is a base that was meant to be there. Reading
        // it as "no override" would quietly compare against HEAD^
        // instead, which is a different question than the one asked.
        if ($value === '') {
            $problems[] = '--base needs a commit id or a ref name.';

            continue;
        }

        $base = $value;
    }

    return ['base' => $base, 'problems' => $problems];
}

/**
 * Checks 5 and 6 together, so the base is resolved and read once. Each
 * keeps its own problem list: they answer different questions and the
 * report names them separately.
 *
 * @param array<string, mixed> $manifest
 * @return array{versionBump: list<string>, contentBump: list<string>, skipped: bool}
 * @throws GitUnavailable
 */
function checkAgainstHistory(array $manifest, ?string $baseOverride, string $projectRoot): array
{
    $base = comparisonBase($baseOverride, $projectRoot);

    if ($base === null) {
        return ['versionBump' => [], 'contentBump' => [], 'skipped' => true];
    }

    $oldManifest = manifestAtCommit($base, $projectRoot);
    $changedFiles = changedPackagePaths($base, $projectRoot);

    return [
        'versionBump' => checkVersionBumpCompleteness($oldManifest, $manifest),
        'contentBump' => checkContentBumpCompleteness($oldManifest, $manifest, $changedFiles),
        'skipped' => false,
    ];
}

/**
 * @param list<string> $problems
 */
function reportCheck(string $label, array $problems, bool &$ok): void
{
    if ($problems === []) {
        echo "[{$label}] OK.\n";

        return;
    }

    foreach ($problems as $problem) {
        fwrite(STDERR, "[{$label}] {$problem}\n");
    }

    $ok = false;
}

/** @param list<string> $argv */
function validatorMain(array $argv = []): int
{
    $arguments = parseValidatorArguments(array_slice($argv, 1));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    $manifest = loadManifest();
    $ok = true;

    // Nothing below can index safely into a manifest that hasn't been
    // through the schema, so this check both runs first and stops the
    // run on its own.
    $schemaProblems = checkManifestSchema($manifest);

    if ($schemaProblems !== []) {
        reportCheck('manifest-schema', $schemaProblems, $ok);

        return 1;
    }

    echo "[manifest-schema] OK.\n";

    $cycle = checkCycles($manifest);
    reportCheck('cycle', $cycle === null ? [] : [$cycle], $ok);
    reportCheck('version-consistency', checkVersionConsistency($manifest), $ok);

    $stale = findStalePackages($manifest);
    reportCheck('generated-drift', $stale === [] ? [] : [
        'Stale: ' . implode(', ', $stale) . ' — run: php tools/generate-composer.php',
    ], $ok);

    try {
        $history = checkAgainstHistory($manifest, $arguments['base'], PROJECT_ROOT);
    } catch (GitUnavailable $e) {
        fwrite(STDERR, '[version-bump] ' . $e->getMessage() . "\n");

        return 1;
    }

    if ($history['skipped']) {
        echo "[version-bump] Skipped — no earlier commit to compare against.\n";
        echo "[content-bump] Skipped — no earlier commit to compare against.\n";
    } else {
        reportCheck('version-bump', $history['versionBump'], $ok);
        reportCheck('content-bump', $history['contentBump'], $ok);
    }

    $coverageProblems = [
        ...checkWorkflowCoverage(
            $manifest,
            workflowPackages(__DIR__ . '/../.github/workflows/ci.yml'),
            workflowPackages(__DIR__ . '/../.github/workflows/infection.yml'),
        ),
        ...checkCoverageWiring(
            coverageLoopPackages(__DIR__ . '/../.github/workflows/sonarqube.yml'),
            coverageReportPackages(__DIR__ . '/../sonar-project.properties'),
        ),
    ];

    reportCheck('workflow-coverage', $coverageProblems, $ok);

    return $ok ? 0 : 1;
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(validatorMain($argv ?? []));
}
