<?php

declare(strict_types=1);

/**
 * Computes this round's release plan — see CLAUDE.md and the monorepo
 * packaging plan (Phase 5) for the full design. Read-only: never writes
 * anything, never tags, never pushes. Phase 6 (splitsh-lite itself) is
 * what would actually act on this plan's output, and is deliberately
 * gated behind real prerequisites that don't exist yet (all 19 split
 * repos under kinetis-dev — kinetis-dev/kinetis is the monorepo itself,
 * not a split target, so every manifest package including "framework"
 * needs its own new repo — a deploy key, Packagist submissions).
 *
 * Usage: php tools/release-plan.php
 *
 * Diffs packages.manifest.json at HEAD against HEAD^; any package whose
 * version field differs is a release candidate. Prints them in
 * publish-order (topological, restricted to candidates — the same
 * ordering rule as tools/validate-manifest.php's cycle check, just
 * filtered down), then checks whether each candidate's sibling
 * requirements actually resolve against a real tag on the sibling's own
 * split repo. No-ops cleanly (prints "no candidates", exits 0) if
 * nothing's changed version-wise.
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
 * either require or require-dev) already has a matching tag on its own
 * split repo. Doesn't require the sibling to be a candidate this round
 * — an unchanged sibling's already-published version is exactly what's
 * expected to resolve.
 *
 * @param array<string, mixed> $manifest
 * @param callable(string, string): bool $tagExists Injectable so the
 *     surrounding logic (which siblings get checked, how a miss is
 *     worded) is unit-testable without a real network call — the real
 *     tagExistsOnGitHub() itself is exercised separately, directly,
 *     the same "real backend, not mocked in the committed suite"
 *     precedent this project already applies to RedisQueue/SqlQueue.
 * @return list<string> problems, empty if everything resolves
 */
function checkResolution(array $manifest, string $key, callable $tagExists): array
{
    $pkg = $manifest['packages'][$key];
    $siblings = [...($pkg['requires'] ?? []), ...($pkg['requiresDev'] ?? [])];
    $problems = [];

    foreach ($siblings as $sibling) {
        $version = $manifest['packages'][$sibling]['version'];
        $tag = "v{$version}";

        if (!$tagExists($sibling, $tag)) {
            $problems[] = "{$key} requires {$sibling} ({$tag}), but that tag doesn't exist on kinetis-dev/{$sibling} yet";
        }
    }

    return $problems;
}

function main(): int
{
    $newManifest = loadManifest();
    $oldManifest = loadManifestAtRef('HEAD^');

    if ($oldManifest === null) {
        echo "No previous commit's manifest available — nothing to compare, no candidates.\n";

        return 0;
    }

    $candidates = findReleaseCandidates($oldManifest, $newManifest);

    if ($candidates === []) {
        echo "No version changes since the last commit — nothing to release.\n";

        return 0;
    }

    $order = publishOrder($newManifest, $candidates);

    echo "Release candidates, in publish order:\n";

    $ok = true;

    foreach ($order as $key) {
        $version = $newManifest['packages'][$key]['version'];
        echo "  {$key} -> v{$version}\n";

        $problems = checkResolution($newManifest, $key, tagExists: tagExistsOnGitHub(...));

        foreach ($problems as $p) {
            echo "    [resolution] {$p}\n";
            $ok = false;
        }
    }

    return $ok ? 0 : 1;
}

// See generate-composer.php for why this checks get_included_files()
// rather than $argv[0], and for the confirmed-false-positive reasoning
// behind the Psalm suppression below.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(main());
}
