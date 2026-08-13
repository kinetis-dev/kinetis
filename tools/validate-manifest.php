<?php

declare(strict_types=1);

/**
 * Four checks against packages.manifest.json — see CLAUDE.md and the
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
 *
 * A separate, fifth check — does each package's committed composer.lock
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
