<?php

declare(strict_types=1);

/**
 * The checks CI runs against packages.manifest.json — see CLAUDE.md and
 * the monorepo packaging plan for the full design:
 *
 *   1. Manifest schema — tools/manifest-schema.php's strict boundary.
 *      Runs first and alone: every check below indexes into the manifest,
 *      so an invalid one is rejected before anything reads it.
 *   2. Cycle detection over the requires graph.
 *   3. Cross-manifest version consistency for shared external deps,
 *      with per-dependency exemptions that each carry a reason.
 *   4. Generated-file drift (reuses tools/generate-composer.php).
 *   5. Version-bump completeness — the release trigger's own integrity
 *      check, comparing the current manifest against its state at the
 *      comparison base (see tools/git-history.php) and holding every
 *      change to tools/version-policy.php's one-step transition rule.
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
 *      leaves it untested while everything still passes, which is how
 *      session and telemetry ended up missing from the mutation matrix
 *      documented in docs/appendix-ci.md. Exemptions are named in
 *      INFECTION_EXEMPT and WORKFLOW_ONLY below, with reasons, so an
 *      absence is a decision rather than an oversight. The same check
 *      requires sonarqube.yml's coverage loop and the reportPaths list
 *      in sonar-project.properties to name the same packages: a package
 *      in one but not the other produces a coverage report nobody reads,
 *      or names a report nobody writes, and reads as 0% either way.
 *
 * Checks 5 and 6 compare against a base commit that has to be readable.
 * tools/git-history.php decides which states skip them and which fail
 * the run; a historical manifest that fails the schema fails here too.
 *
 * A separate check — does each package's committed composer.lock
 * still match its composer.json — is just `composer validate --strict`,
 * run directly, no new code needed for it.
 *
 * Usage: php tools/validate-manifest.php [--base=<ref>]
 *
 * --base pins the comparison explicitly, which is what a feature branch
 * needs: passing the merge base with the integration branch checks the
 * branch as one whole change, so an early commit's bump is what the
 * later commits are measured against rather than each commit re-deciding
 * from HEAD.
 */

require_once __DIR__ . '/generate-composer.php';
require_once __DIR__ . '/git-history.php';

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
 * constraint for it. An exemption names the one dependency it covers and
 * why, so it can never widen to the rest of the package's dependencies
 * the way a package-wide flag does — every other shared dependency of an
 * exempted package is still checked.
 *
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function checkVersionConsistency(array $manifest): array
{
    $seen = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        $exemptions = $pkg['versionDriftExemptions'] ?? [];

        foreach ($pkg['require'] ?? [] as $extName => $constraint) {
            if (array_key_exists($extName, $exemptions)) {
                continue;
            }

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
 * @param array<string, mixed>|null $oldManifest null when there is
 *        nothing to compare against
 * @param array<string, mixed> $newManifest
 * @return list<string>
 */
function checkVersionBumpCompleteness(?array $oldManifest, array $newManifest): array
{
    if ($oldManifest === null) {
        return [];
    }

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

        if ($oldPkg === $newPkg) {
            continue;
        }

        $oldWithoutVersion = $oldPkg;
        $newWithoutVersion = $newPkg;
        unset($oldWithoutVersion['version'], $newWithoutVersion['version']);

        $versionChanged = ($oldPkg['version'] ?? null) !== ($newPkg['version'] ?? null);

        if ($oldWithoutVersion != $newWithoutVersion && !$versionChanged) {
            $problems[] = "{$key}: manifest entry changed but 'version' was not bumped";

            continue;
        }

        if ($versionChanged) {
            $problem = versionTransitionProblem($oldPkg['version'] ?? null, $newPkg['version']);

            if ($problem !== null) {
                $problems[] = "{$key}: {$problem}";
            }
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
 * gained it — because changedPackagePaths() turns git's rename detection
 * off. Both packages are attributed, and both need their own bump: the
 * source package's next release drops that file, which is a change its
 * consumers see.
 *
 * @param array<string, mixed>|null $oldManifest
 * @param array<string, mixed> $newManifest
 * @param list<string> $changedFiles repo-relative paths
 * @return list<string>
 */
function checkContentBumpCompleteness(?array $oldManifest, array $newManifest, array $changedFiles): array
{
    if ($oldManifest === null) {
        return [];
    }

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

        if (($oldPkg['version'] ?? null) === ($newPkg['version'] ?? null)) {
            $shown = implode(', ', array_slice($changed, 0, 3));
            $more = count($changed) > 3 ? ', …' : '';
            $problems[] = "{$key}: package files changed but 'version' was not bumped ({$shown}{$more})";
        }
    }

    return $problems;
}

/**
 * Packages with no infection.yml job, and why.
 */
const INFECTION_EXEMPT = [
    'pingpong' => 'a demo application, read and run rather than mutated; its suite runs in ci.yml and is measured for coverage',
];

/**
 * Workflow job directories that are deliberately not manifest packages.
 */
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
 * @param array<string, array<string, mixed>> $manifest
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

/**
 * @param list<string> $argv
 * @return array{base: ?string, problems: list<string>}
 */
function parseValidatorArguments(array $argv): array
{
    $base = null;
    $problems = [];
    $seen = 0;

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--base=')) {
            $problems[] = "Unknown option: {$arg}";

            continue;
        }

        $seen++;
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

    if ($seen > 1) {
        $problems[] = '--base is given more than once.';
    }

    return ['base' => $base, 'problems' => $problems];
}

/**
 * Checks 5 and 6 together, so the base is resolved and read once. Each
 * keeps its own problem list: they answer different questions and the
 * report names them separately.
 *
 * @param array<string, mixed> $manifest
 * @return array{versionBump: list<string>, contentBump: list<string>, skipped: ?string}
 * @throws HistoryUnavailable
 */
function checkAgainstHistory(array $manifest, ?string $baseOverride, string $projectRoot): array
{
    $base = resolveComparisonBase(
        static fn (string $ref): string => gitResolveCommit($ref, $projectRoot),
        static fn (string $ref): bool => gitCommitExists($ref, $projectRoot),
        static fn (): bool => gitIsShallow($projectRoot),
        $baseOverride,
    );

    if ($base->commit === null) {
        return ['versionBump' => [], 'contentBump' => [], 'skipped' => $base->reason];
    }

    $oldManifest = readManifestAtCommit($base->commit, $projectRoot);
    $changedFiles = changedPackagePaths($base->commit, $projectRoot);

    return [
        'versionBump' => checkVersionBumpCompleteness($oldManifest, $manifest),
        'contentBump' => checkContentBumpCompleteness($oldManifest, $manifest, $changedFiles),
        'skipped' => null,
    ];
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

    // Nothing below can index safely into a manifest that hasn't been
    // through the schema, so this check both runs first and stops the
    // run on its own.
    $manifest = loadManifestOrReport();

    if ($manifest === null) {
        return 1;
    }

    echo "[manifest-schema] OK.\n";
    $ok = true;

    $cycle = checkCycles($manifest);

    if ($cycle !== null) {
        fwrite(STDERR, "[cycle] {$cycle}\n");
        $ok = false;
    } else {
        echo "[cycle] OK — acyclic.\n";
    }

    $driftProblems = checkVersionConsistency($manifest);

    if ($driftProblems !== []) {
        foreach ($driftProblems as $p) {
            fwrite(STDERR, "[version-consistency] {$p}\n");
        }

        $ok = false;
    } else {
        echo "[version-consistency] OK.\n";
    }

    $stale = findStalePackages($manifest);

    if ($stale !== []) {
        fwrite(STDERR, '[generated-drift] Stale: ' . implode(', ', $stale) . " — run: php tools/generate-composer.php\n");
        $ok = false;
    } else {
        echo "[generated-drift] OK.\n";
    }

    try {
        $history = checkAgainstHistory($manifest, $arguments['base'], PROJECT_ROOT);
    } catch (HistoryUnavailable $e) {
        fwrite(STDERR, '[version-bump] ' . $e->getMessage() . "\n");

        return 1;
    }

    if ($history['skipped'] !== null) {
        echo "[version-bump] Skipped — {$history['skipped']}\n";
        echo "[content-bump] Skipped — {$history['skipped']}\n";
    } else {
        foreach (['version-bump' => $history['versionBump'], 'content-bump' => $history['contentBump']] as $label => $problems) {
            if ($problems === []) {
                echo "[{$label}] OK.\n";

                continue;
            }

            foreach ($problems as $p) {
                fwrite(STDERR, "[{$label}] {$p}\n");
            }

            $ok = false;
        }
    }

    $coverageProblems = checkWorkflowCoverage(
        $manifest,
        workflowPackages(__DIR__ . '/../.github/workflows/ci.yml'),
        workflowPackages(__DIR__ . '/../.github/workflows/infection.yml'),
    );

    $coverageProblems = [...$coverageProblems, ...checkCoverageWiring(
        coverageLoopPackages(__DIR__ . '/../.github/workflows/sonarqube.yml'),
        coverageReportPackages(__DIR__ . '/../sonar-project.properties'),
    )];

    if ($coverageProblems !== []) {
        foreach ($coverageProblems as $p) {
            fwrite(STDERR, "[workflow-coverage] {$p}\n");
        }

        $ok = false;
    } else {
        echo "[workflow-coverage] OK.\n";
    }

    return $ok ? 0 : 1;
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(validatorMain($argv ?? []));
}
