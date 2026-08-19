<?php

declare(strict_types=1);

/**
 * Six checks against packages.manifest.json — see CLAUDE.md and the
 * monorepo packaging plan for the full design:
 *
 *   1. Cycle detection over the requires graph.
 *   2. Cross-manifest version consistency for shared external deps.
 *   3. Generated-file drift (reuses tools/generate-composer.php).
 *   4. Version-bump completeness — the release trigger's own integrity
 *      check, comparing the current manifest against its state at
 *      GITHUB_EVENT_BEFORE (what main pointed to before this push,
 *      when running as the push trigger) or HEAD^ otherwise — see
 *      oldManifestRef(). Requires the checkout to have that ref's
 *      history available (fetch-depth: 2 or 0, or deeper if
 *      GITHUB_EVENT_BEFORE is more than one commit back) — if it isn't,
 *      this check is skipped, not failed, since there's nothing to diff
 *      against.
 *   5. Content-bump completeness — the counterpart check 4 can't see:
 *      check 4 only compares manifest *entries*, so a change to a
 *      package's own files with no manifest change (a compose file, a
 *      bootstrap, a README) is invisible to it — and an unbumped
 *      version means the release pipeline never tags the new content
 *      at all, so the split repo silently stays on the old tag. This
 *      check diffs each package's tracked files against the same old
 *      ref and requires a version change whenever anything besides
 *      composer.lock changed (the lock is deleted from release commits
 *      and refreshes constantly without semantic change). Same
 *      skip-when-no-history behavior as check 4. Uncommitted brand-new
 *      files are invisible to a local run (git diff only sees tracked
 *      paths); the post-push CI run sees everything.
 *   6. Workflow coverage — every package in the manifest has a job in
 *      ci.yml and in infection.yml, and every job in those two maps
 *      back to a package. Adding a package without wiring it into CI
 *      leaves it untested while everything still passes, which is how
 *      session and telemetry ended up missing from the mutation matrix
 *      documented in docs/appendix-ci.md. Exemptions are named in
 *      INFECTION_EXEMPT and WORKFLOW_ONLY below, with reasons, so an
 *      absence is a decision rather than an oversight.
 *
 * A separate check — does each package's committed composer.lock
 * still match its composer.json — is just `composer validate --strict`,
 * run directly, no new code needed for it.
 *
 * Usage: php tools/validate-manifest.php
 */

require_once __DIR__ . '/generate-composer.php';

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
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function checkVersionConsistency(array $manifest): array
{
    $seen = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        if (!empty($pkg['allowVersionDrift'])) {
            continue;
        }

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
 * The ref check 4 diffs the current manifest against. HEAD^ only ever
 * looks one commit back — correct for a single-commit push, but wrong
 * for a direct multi-commit push to main (this project's own normal
 * workflow, not an edge case): HEAD^ then lands on some commit *within*
 * that same push, not on whatever main pointed to before it, silently
 * hiding a downgrade introduced earlier in the same push. GITHUB_EVENT_BEFORE
 * — set by the workflow only for the push trigger, from github.event.before
 * — is the actual answer to "what did main point to before this push,"
 * used when it's present and not the all-zero SHA GitHub sends for a
 * branch's very first push. Absent (a local run, or the pull_request
 * trigger, where actions/checkout's default merge-commit checkout
 * already makes HEAD^ resolve to the PR's base branch tip regardless of
 * commit count) falls back to HEAD^ unchanged.
 */
function oldManifestRef(): string
{
    $before = getenv('GITHUB_EVENT_BEFORE');

    if ($before === false || $before === '' || $before === str_repeat('0', 40)) {
        return 'HEAD^';
    }

    return $before;
}

/** @return array<string, mixed>|null */
function loadManifestAtRef(string $ref): ?array
{
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', 'show', "{$ref}:packages.manifest.json"],
        $descriptorSpec,
        $pipes,
        PROJECT_ROOT,
    );

    if (!is_resource($process)) {
        return null;
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === false || trim($output) === '') {
        return null;
    }

    try {
        /** @var array{defaults: array<string, mixed>, packages: array<string, array<string, mixed>>} */
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
}

/** @return list<string> */
function validateVersionBump(string $key, ?string $old, string $new): array
{
    if (preg_match('/^\d+\.\d+\.\d+$/', $new) !== 1) {
        return ["{$key}: version '{$new}' is not valid SemVer (X.Y.Z)"];
    }

    if ($old === null) {
        return [];
    }

    if (version_compare($new, $old, '<=')) {
        return ["{$key}: version must strictly increase — was {$old}, now {$new}"];
    }

    return [];
}

/**
 * @param array<string, mixed>|null $oldManifest
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

        if ($oldPkg === null || $oldPkg === $newPkg) {
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
            $problems = [...$problems, ...validateVersionBump($key, $oldPkg['version'] ?? null, $newPkg['version'])];
        }
    }

    return $problems;
}

/**
 * Tracked files under packages/ that differ between $ref and the
 * working tree. Null when git can't produce the diff (missing history,
 * no git available) — the caller skips the check rather than failing.
 *
 * @return list<string>|null
 */
function changedPackageFiles(string $ref): ?array
{
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', 'diff', '--name-only', $ref, '--', 'packages'],
        $descriptorSpec,
        $pipes,
        PROJECT_ROOT,
    );

    if (!is_resource($process)) {
        return null;
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === false) {
        return null;
    }

    return array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => $line !== ''));
}

/**
 * Check 5: a package whose own files changed needs a version bump,
 * whether or not its manifest entry moved. composer.lock is the one
 * exclusion — deleted from release commits, refreshed constantly
 * without semantic change. A package with no old manifest entry is
 * brand new and exempt, matching check 4's own rule.
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

function validatorMain(): int
{
    $manifest = loadManifest();
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

    $oldManifest = loadManifestAtRef(oldManifestRef());

    if ($oldManifest === null) {
        echo "[version-bump] Skipped — no previous commit's manifest available (shallow checkout or first commit).\n";
    } else {
        $bumpProblems = checkVersionBumpCompleteness($oldManifest, $manifest);

        if ($bumpProblems !== []) {
            foreach ($bumpProblems as $p) {
                fwrite(STDERR, "[version-bump] {$p}\n");
            }

            $ok = false;
        } else {
            echo "[version-bump] OK.\n";
        }

        $changedFiles = changedPackageFiles(oldManifestRef());

        if ($changedFiles === null) {
            echo "[content-bump] Skipped — git couldn't diff against the previous ref.\n";
        } else {
            $contentProblems = checkContentBumpCompleteness($oldManifest, $manifest, $changedFiles);

            if ($contentProblems !== []) {
                foreach ($contentProblems as $p) {
                    fwrite(STDERR, "[content-bump] {$p}\n");
                }

                $ok = false;
            } else {
                echo "[content-bump] OK.\n";
            }
        }
    }

    $coverageProblems = checkWorkflowCoverage(
        $manifest,
        workflowPackages(__DIR__ . '/../.github/workflows/ci.yml'),
        workflowPackages(__DIR__ . '/../.github/workflows/infection.yml'),
    );

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

// See generate-composer.php for why this checks get_included_files()
// rather than $argv[0], and for the confirmed-false-positive reasoning
// behind the Psalm suppression below.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(validatorMain());
}
