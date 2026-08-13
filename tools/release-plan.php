<?php

declare(strict_types=1);

/**
 * Computes this round's release plan — see CLAUDE.md and the monorepo
 * packaging plan (Phase 5) for the full design. Read-only: never writes
 * anything, never tags, never pushes; .github/workflows/release.yml
 * (splitsh-lite) is what acts on this plan's output.
 *
 * Usage: php tools/release-plan.php [--json]
 *
 * A package is a release candidate for either of two reasons: its
 * version field differs from packages.manifest.json's state at
 * oldManifestRef() (see validate-manifest.php — GITHUB_EVENT_BEFORE
 * when set, HEAD^ otherwise), or its current version has no matching
 * tag on its own split repo yet, regardless of whether anything changed
 * this push — see findUntaggedCandidates(), which covers a version that
 * predates this pipeline and so has nothing for a manifest diff alone
 * to catch. Reports the union in publish-order (topological, restricted
 * to candidates — the same ordering rule as
 * tools/validate-manifest.php's cycle check, just filtered down), each
 * with whether its sibling requirements resolve against a real tag on
 * the sibling's own split repo. No-ops cleanly (reports zero
 * candidates, exits 0) only when neither source finds anything; exits 1
 * if any candidate has an unresolved sibling.
 *
 * --json emits {candidates: [{key, version, problems}], ok} instead of
 * the human-readable report — what release.yml consumes to drive
 * publishing, one candidate at a time, in the given order.
 */

require_once __DIR__ . '/generate-composer.php';
require_once __DIR__ . '/validate-manifest.php';

const GITHUB_ORG = 'kinetis-dev';

/**
 * @param array<string, mixed> $oldManifest
 * @param array<string, mixed> $newManifest
 * @return list<string> package keys whose version differs between the two manifests
 */
function findReleaseCandidates(array $oldManifest, array $newManifest): array
{
    $candidates = [];

    foreach ($newManifest['packages'] as $key => $newPkg) {
        $oldPkg = $oldManifest['packages'][$key] ?? null;
        $oldVersion = $oldPkg['version'] ?? null;

        if ($oldVersion !== $newPkg['version']) {
            $candidates[] = $key;
        }
    }

    return $candidates;
}

/**
 * A package is also a candidate, independent of any manifest diff, when
 * its current version has no matching tag on its own split repo yet —
 * covers a version that predates this pipeline, which
 * findReleaseCandidates() alone can never detect: the manifest value
 * itself never changes again once committed, so a pure diff has nothing
 * left to compare against.
 *
 * Runs unconditionally, not gated behind "only if the diff found
 * nothing" — a package can independently be untagged from an earlier
 * failed run alongside a real diff-based candidate elsewhere in the
 * same round, and this catches that with no extra bookkeeping. A
 * version that's genuinely tagged simply stops matching here on its
 * own; nothing has to track that a package was already released.
 *
 * @param array<string, mixed> $manifest
 * @param callable(string, string): bool $tagExists Same injectable-callable
 *     shape as checkResolution() — testable without a real network call;
 *     tagExistsOnGitHub() itself is exercised separately, directly.
 * @return list<string> package keys whose current version has no tag yet
 */
function findUntaggedCandidates(array $manifest, callable $tagExists): array
{
    $candidates = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        if (!$tagExists($key, "v{$pkg['version']}")) {
            $candidates[] = $key;
        }
    }

    return $candidates;
}

/**
 * Kahn's algorithm over the full requires graph, foundational packages
 * first — e.g. framework and revolt-http-client before pingpong.
 *
 * @param array<string, list<string>> $graph
 * @return list<string>
 */
function topologicalOrder(array $graph): array
{
    $inDegree = array_fill_keys(array_keys($graph), 0);

    foreach ($graph as $deps) {
        foreach ($deps as $dep) {
            $inDegree[$dep] ??= 0;
        }
    }

    // A node's "in-degree" here is how many other nodes require it —
    // i.e. how many edges point *at* it, since those must publish
    // after it. Building the reverse-adjacency count directly.
    $dependents = array_fill_keys(array_keys($inDegree), []);

    foreach ($graph as $node => $deps) {
        foreach ($deps as $dep) {
            $dependents[$dep][] = $node;
        }
    }

    $remainingDeps = [];

    foreach ($graph as $node => $deps) {
        $remainingDeps[$node] = count($deps);
    }

    foreach (array_keys($inDegree) as $node) {
        $remainingDeps[$node] ??= 0;
    }

    $queue = array_keys(array_filter($remainingDeps, static fn (int $n): bool => $n === 0));
    sort($queue);
    $order = [];

    while ($queue !== []) {
        $node = array_shift($queue);
        $order[] = $node;

        $next = [];

        foreach ($dependents[$node] ?? [] as $dependent) {
            $remainingDeps[$dependent]--;

            if ($remainingDeps[$dependent] === 0) {
                $next[] = $dependent;
            }
        }

        sort($next);
        $queue = [...$queue, ...$next];
    }

    return $order;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $candidates
 * @return list<string> publish order, restricted to $candidates, dependency-respecting
 */
function publishOrder(array $manifest, array $candidates): array
{
    $graph = buildGraph($manifest['packages']);
    $fullOrder = topologicalOrder($graph);
    $candidateSet = array_flip($candidates);

    return array_values(array_filter($fullOrder, static fn (string $key): bool => isset($candidateSet[$key])));
}

/** Real git tag check via ls-remote — never touches Packagist. */
function tagExistsOnGitHub(string $repo, string $tag): bool
{
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $url = 'https://github.com/' . GITHUB_ORG . "/{$repo}.git";
    $process = proc_open(['git', 'ls-remote', '--tags', $url, "refs/tags/{$tag}"], $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        return false;
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return trim((string) $output) !== '';
}

/**
 * For a release candidate, checks that every sibling it requires (in
 * either require or require-dev) either already has a matching tag on
 * its own split repo, or is itself a candidate this same round.
 *
 * A same-round sibling is safe to treat as resolved without a live tag
 * check: publishOrder()'s topological sort already guarantees it
 * appears earlier in the sequential publish loop, and that loop's
 * fail-fast design (any push failure aborts the whole run immediately,
 * see release.yml) means a later candidate can never actually be
 * attempted against a sibling whose push silently failed — by the time
 * $key's own turn comes, $sibling's tag either genuinely exists or the
 * run already stopped. Checking only tagExists() without this exception
 * would block any round where several interdependent packages release
 * together, since every non-root candidate's sibling has no tag yet
 * until its own, earlier turn in the same run.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, true> $candidateSet Every package key that's a
 *     candidate this round, keyed for O(1) lookup — not just $key's own
 *     siblings, the full round.
 * @param callable(string, string): bool $tagExists Injectable so the
 *     surrounding logic (which siblings get checked, how a miss is
 *     worded) is unit-testable without a real network call —
 *     tagExistsOnGitHub() itself is exercised separately, directly.
 * @return list<string> problems, empty if everything resolves
 */
function checkResolution(array $manifest, string $key, array $candidateSet, callable $tagExists): array
{
    $pkg = $manifest['packages'][$key];
    $siblings = [...($pkg['requires'] ?? []), ...($pkg['requiresDev'] ?? [])];
    $problems = [];

    foreach ($siblings as $sibling) {
        if (isset($candidateSet[$sibling])) {
            continue;
        }

        $version = $manifest['packages'][$sibling]['version'];
        $tag = "v{$version}";

        if (!$tagExists($sibling, $tag)) {
            $problems[] = "{$key} requires {$sibling} ({$tag}), but that tag doesn't exist on kinetis-dev/{$sibling} yet";
        }
    }

    return $problems;
}

/**
 * @param list<array{key: string, version: string, problems: list<string>}> $plan
 */
function printHumanReadable(array $plan, ?string $note): void
{
    if ($note !== null) {
        echo "{$note}\n";

        return;
    }

    if ($plan === []) {
        echo "Nothing to release — no version changes since the last commit, and every current version is already tagged.\n";

        return;
    }

    echo "Release candidates, in publish order:\n";

    foreach ($plan as $entry) {
        echo "  {$entry['key']} -> v{$entry['version']}\n";

        foreach ($entry['problems'] as $p) {
            echo "    [resolution] {$p}\n";
        }
    }
}

/**
 * @param list<array{key: string, version: string, problems: list<string>}> $plan
 */
function printJson(array $plan): void
{
    $ok = array_all($plan, static fn (array $entry): bool => $entry['problems'] === []);

    echo json_encode(['candidates' => $plan, 'ok' => $ok], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * @param list<string> $argv
 */
function main(array $argv = []): int
{
    $json = in_array('--json', $argv, true);
    $newManifest = loadManifest();
    $oldManifest = loadManifestAtRef(oldManifestRef());

    // Two independent sources, unioned — a diff-based candidate (the
    // ordinary case: something's version field actually changed this
    // push) and an untagged one (see findUntaggedCandidates() — the
    // "first release" case a pure diff can never detect on its own).
    // $oldManifest being unavailable (no prior commit to diff against
    // at all) only removes the first source; the second still runs.
    $diffCandidates = $oldManifest !== null ? findReleaseCandidates($oldManifest, $newManifest) : [];
    $untaggedCandidates = findUntaggedCandidates($newManifest, tagExists: tagExistsOnGitHub(...));
    $candidates = array_keys(array_fill_keys($diffCandidates, true) + array_fill_keys($untaggedCandidates, true));

    if ($candidates === []) {
        $json ? printJson([]) : printHumanReadable([], note: null);

        return 0;
    }

    $order = publishOrder($newManifest, $candidates);
    $candidateSet = array_fill_keys($candidates, true);
    $plan = [];

    foreach ($order as $key) {
        $plan[] = [
            'key' => $key,
            'version' => $newManifest['packages'][$key]['version'],
            'problems' => checkResolution($newManifest, $key, $candidateSet, tagExists: tagExistsOnGitHub(...)),
        ];
    }

    $json ? printJson($plan) : printHumanReadable($plan, note: null);

    return array_all($plan, static fn (array $entry): bool => $entry['problems'] === []) ? 0 : 1;
}

// See generate-composer.php for why this checks get_included_files()
// rather than $argv[0], and for the confirmed-false-positive reasoning
// behind the Psalm suppression below.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(main($argv ?? []));
}
