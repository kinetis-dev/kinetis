<?php

declare(strict_types=1);

/**
 * Computes this round's release plan — see CLAUDE.md and the monorepo
 * packaging plan (Phase 5) for the full design. Read-only: never writes
 * anything, never tags, never pushes. Phase 6 (splitsh-lite itself,
 * .github/workflows/release.yml) is what actually acts on this plan's
 * output. All 19 kinetis-dev/<name> split repos exist now (public,
 * empty, Code Security on) — kinetis-dev/kinetis is the monorepo
 * itself, not a split target, so "framework" still needed its own new
 * repo alongside every other package. Still gated on a real prerequisite
 * that doesn't exist yet: the RELEASE_DEPLOY_TOKEN secret release.yml
 * needs to actually push to any of them.
 *
 * Usage: php tools/release-plan.php [--json]
 *
 * A package is a release candidate for either of two independent
 * reasons — see findReleaseCandidates()/findUntaggedCandidates() for
 * the full reasoning behind each: its version field differs from
 * packages.manifest.json's state at oldManifestRef() (see
 * validate-manifest.php — GITHUB_EVENT_BEFORE when set, HEAD^
 * otherwise) — the ordinary case, something actually changed this push
 * — or its current version has no matching tag on its own split repo
 * yet, regardless of whether anything changed this push — the "first
 * release" case, where the target version was already committed before
 * this pipeline existed to act on it, so a pure diff can never trigger
 * on its own. Reports the union in publish-order (topological,
 * restricted to candidates — the same ordering rule as
 * tools/validate-manifest.php's cycle check, just filtered down), each
 * with whether its sibling requirements actually resolve against a real
 * tag on the sibling's own split repo. No-ops cleanly (reports zero
 * candidates, exits 0) only when neither source finds anything; exits 1
 * if any candidate has an unresolved sibling.
 *
 * --json emits {candidates: [{key, version, problems}], ok} instead of
 * the human-readable report — what release.yml actually consumes to
 * drive publishing, one candidate at a time, in the given order.
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
 * A package is also a candidate — even with zero manifest diff at all —
 * when its current version has never actually been tagged on its own
 * split repo. This is a real gap a pure manifest diff can't see on its
 * own: a version committed to the manifest before this pipeline existed
 * to act on it (this project's own first release, all 19 packages
 * already sitting at their target 1.0.0 the moment release.yml was
 * built) has nothing to diff against, ever — findReleaseCandidates()
 * alone would report zero candidates on every future push forever, no
 * matter how many times main pushes, since the manifest value itself
 * never changes again.
 *
 * Runs unconditionally on every check, not gated behind "only if the
 * diff found nothing" — a package sitting alongside a real diff-based
 * candidate can independently still be untagged from an earlier failed
 * run, and this catches that for free with no extra bookkeeping. Once a
 * version genuinely gets tagged, it stops matching here on its own —
 * nothing has to "remember" a package was already released.
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
 * first — the same ordering direction already proven against this
 * manifest earlier in this project's own history (kinetis and
 * revolt-http-client first, pingpong last).
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
 * The second case matters structurally, not just as an optimization: for
 * an ordinary release round (one or two packages, everything else
 * already tagged from a prior round) a sibling never being a same-round
 * candidate is the common case. But for a release round where several
 * interdependent packages publish together — this project's own first
 * release, all 19 at once, being the concrete case this was built
 * for — every non-root candidate requires a sibling with no tag yet,
 * because that sibling is *also* going to be published, earlier, in this
 * exact same run. Checking only tagExists() would report every one of
 * those as unresolved forever, regardless of how correctly the
 * prerequisites get provisioned, permanently blocking the one release
 * this pipeline exists to enable. A same-round sibling is safe to treat
 * as resolved without a live tag check: publishOrder()'s topological
 * sort already guarantees it appears earlier in the sequential publish
 * loop, and that loop's fail-fast design (any push failure aborts the
 * whole run immediately, see release.yml) means a later candidate can
 * never actually be attempted against a sibling whose push silently
 * failed — by the time $key's own turn comes, $sibling's tag either
 * genuinely exists or the run already stopped.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, true> $candidateSet Every package key that's a
 *     candidate this round, keyed for O(1) lookup — not just $key's own
 *     siblings, the full round.
 * @param callable(string, string): bool $tagExists Injectable so the
 *     surrounding logic (which siblings get checked, how a miss is
 *     worded) is unit-testable without a real network call — the real
 *     tagExistsOnGitHub() itself is exercised separately, directly,
 *     the same "real backend, not mocked in the committed suite"
 *     precedent this project already applies to RedisQueue/SqlQueue.
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
