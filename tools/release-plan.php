<?php

declare(strict_types=1);

/**
 * Computes this round's release plan — see CLAUDE.md and the monorepo
 * packaging plan (Phase 5) for the full design. Read-only: never writes
 * anything, never tags, never pushes; .github/workflows/release.yml
 * (splitsh-lite) is what acts on this plan's output.
 *
 * Usage: php tools/release-plan.php [--json] [--base=<ref>]
 *
 * A package is a release candidate for either of two reasons: its
 * version field differs from packages.manifest.json's state at the
 * comparison base (see tools/git-history.php — GITHUB_EVENT_BEFORE when
 * set, HEAD^ otherwise), or its current version has no matching tag on
 * its own split repo yet, regardless of whether anything changed this
 * push — see findUntaggedCandidates(), which covers a version that
 * predates this pipeline and so has nothing for a manifest diff alone
 * to catch. Reports the union in publish-order (topological over the
 * requires graph, restricted to candidates), each with whether its
 * sibling requirements resolve against a real tag on the sibling's own
 * split repo. No-ops cleanly (reports zero candidates, exits 0) only
 * when neither source finds anything; exits 1 if any candidate has an
 * unresolved sibling.
 *
 * The manifest goes through tools/manifest-schema.php before any of
 * that, so a malformed entry stops the plan here rather than reaching a
 * remote lookup or the split loop — this run is the last thing between a
 * manifest and a force-pushed tag, and it does not lean on another
 * workflow having caught the problem concurrently. Every other way this
 * run can fail to establish a fact — an unreadable comparison base, an
 * indeterminate tag lookup, a graph with no total order — ends the run
 * rather than producing a plan that omits work.
 *
 * --json emits {candidates: [{key, version, problems}], ok} instead of
 * the human-readable report — what release.yml consumes to drive
 * publishing, one candidate at a time, in the given order.
 */

require_once __DIR__ . '/generate-composer.php';
require_once __DIR__ . '/validate-manifest.php';

const GITHUB_ORG = 'kinetis-dev';

/**
 * A tag lookup reaches the network, and this script runs inside the job
 * that publishes. A stalled read has to end as a failure rather than
 * holding the release open.
 */
const TAG_LOOKUP_TIMEOUT_SECONDS = 30;

final class ReleasePlanFailure extends RuntimeException
{
}

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
 * failed run alongside a diff-based candidate elsewhere in the same
 * round, and this catches that with no extra bookkeeping. A tagged
 * version stops matching here on its own; nothing has to track that a
 * package was already released.
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
 * Unions the two candidate sources, then filters out anything whose tag
 * already exists — the one check neither source applies on its own to
 * the *other's* results. findUntaggedCandidates() only ever sees the
 * untagged path; a diff-based candidate goes straight into the union
 * with no equivalent check. That gap is invisible on a normal run (a
 * version bump has no tag yet by definition) but real on a re-run of a
 * partially-successful release round: re-running release.yml after a
 * mid-round failure (e.g. a missing split repo) replays the same
 * GITHUB_EVENT_BEFORE, so a package the diff already found — and whose
 * tag a *previous* attempt this same round already pushed successfully
 * — gets re-added by the diff alone and its tag push then fails, since
 * tags (unlike the branch ref) are never force-pushed. Filtering the
 * whole union by tag existence, not just the untagged source's own
 * candidates, is what makes a retry idempotent.
 *
 * @param list<string> $diffCandidates
 * @param list<string> $untaggedCandidates
 * @param array<string, mixed> $manifest
 * @param callable(string, string): bool $tagExists
 * @return list<string>
 */
function resolveCandidates(array $diffCandidates, array $untaggedCandidates, array $manifest, callable $tagExists): array
{
    $union = array_keys(array_fill_keys($diffCandidates, true) + array_fill_keys($untaggedCandidates, true));

    return array_values(array_filter(
        $union,
        static fn (string $key): bool => !$tagExists($key, "v{$manifest['packages'][$key]['version']}"),
    ));
}

/**
 * Kahn's algorithm over the full requires graph, foundational packages
 * first — e.g. framework and revolt-http-client before pingpong.
 *
 * A graph it can't fully order comes back short rather than raising, so
 * every caller has to compare what it got against what it asked for —
 * see publishOrder(), which is the one place that does.
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
 * The publish order for this round's candidates, proven to contain every
 * one of them.
 *
 * Kahn's algorithm stops early on a graph it can't finish — a cycle, or
 * an edge pointing at a node that isn't in it — and returns whatever it
 * did manage to order. Filtering that partial list down to the
 * candidates turns a real graph fault into a shorter plan, or an empty
 * one, that reads as a clean "nothing to release" all the way through
 * release.yml. So the order is checked against the graph it came from
 * and then against the candidate set, and any shortfall is raised here
 * rather than published around.
 *
 * @param array<string, mixed> $manifest
 * @param list<string> $candidates
 * @return list<string> publish order, restricted to $candidates, dependency-respecting
 * @throws ReleasePlanFailure
 */
function publishOrder(array $manifest, array $candidates): array
{
    $graph = buildGraph($manifest['packages']);

    foreach ($candidates as $candidate) {
        if (!isset($graph[$candidate])) {
            throw new ReleasePlanFailure("Release candidate {$candidate} is not a package in this manifest");
        }
    }

    foreach ($graph as $node => $dependencies) {
        foreach ($dependencies as $dependency) {
            if (!isset($graph[$dependency])) {
                throw new ReleasePlanFailure(
                    "{$node} requires {$dependency}, which is not a package in this manifest",
                );
            }
        }
    }

    $cycle = checkCycles($manifest);

    if ($cycle !== null) {
        throw new ReleasePlanFailure("The requires graph has no publish order — {$cycle}");
    }

    $order = topologicalOrder($graph);
    $unordered = array_diff(array_keys($graph), $order);

    if ($unordered !== [] || count($order) !== count($graph)) {
        throw new ReleasePlanFailure(
            'The requires graph did not order every package — unresolved: '
            . implode(', ', $unordered === [] ? ['(order and graph disagree)'] : $unordered),
        );
    }

    $candidateSet = array_fill_keys($candidates, true);
    $ordered = array_values(array_filter($order, static fn (string $key): bool => isset($candidateSet[$key])));
    $missing = array_diff(array_keys($candidateSet), $ordered);

    if ($missing !== []) {
        throw new ReleasePlanFailure(
            'The publish order left out release candidates: ' . implode(', ', $missing),
        );
    }

    return $ordered;
}

/**
 * Asks one split repository whether a tag is there.
 *
 * "Absent" means one thing only: ls-remote ran, spoke to the remote, and
 * matched no ref. A remote that refuses the connection, an
 * unauthenticated fetch, a repository that doesn't exist, git failing to
 * start, a read that stalls until the deadline — none of those are
 * evidence about the tag, and reporting them as absence makes every
 * package a release candidate and republishes tags that already exist.
 * They raise instead.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleasePlanFailure
 */
function tagLookup(string $repo, string $tag, callable $run): bool
{
    $url = 'https://github.com/' . GITHUB_ORG . "/{$repo}.git";
    $result = $run(['ls-remote', '--tags', '--end-of-options', $url, "refs/tags/{$tag}"]);

    if ($result['timedOut']) {
        throw new ReleasePlanFailure(
            "Looking up {$tag} on " . GITHUB_ORG . "/{$repo} did not finish in time — the release plan cannot say "
            . 'whether that tag exists.',
        );
    }

    if ($result['exitCode'] !== 0) {
        throw new ReleasePlanFailure(
            'Could not reach ' . GITHUB_ORG . "/{$repo} to look up {$tag}: " . redactCredentials($result['stderr']),
        );
    }

    if (trim($result['stdout']) === '') {
        return false;
    }

    if (matchesTagRecord($result['stdout'], $tag)) {
        return true;
    }

    throw new ReleasePlanFailure(
        'Looking up ' . GITHUB_ORG . "/{$repo} for {$tag} produced output that names no such ref, "
        . 'so whether the tag exists is unknown.',
    );
}

/**
 * Whether ls-remote's output names the ref that was asked for.
 * Its records are `<object id>\t<ref>`, and an annotated tag also
 * reports its peeled `^{}` line. Anything else — a warning, a redirect
 * notice, a ref that merely resembles the one requested — leaves the
 * question unanswered rather than answering it.
 */
function matchesTagRecord(string $output, string $tag): bool
{
    $wanted = "refs/tags/{$tag}";

    foreach (explode("\n", $output) as $line) {
        $line = rtrim($line, "\r");

        if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\t(\S+)$/', $line, $m) !== 1) {
            continue;
        }

        if ($m[2] === $wanted || $m[2] === "{$wanted}^{}") {
            return true;
        }
    }

    return false;
}

/** Real git tag check via ls-remote — never touches Packagist. */
function tagExistsOnGitHub(string $repo, string $tag): bool
{
    return tagLookup(
        $repo,
        $tag,
        static fn (array $args): array => runGit($args, PROJECT_ROOT, TAG_LOOKUP_TIMEOUT_SECONDS),
    );
}

/**
 * Wraps a tag check so each repo/tag pair costs one lookup per run.
 * Candidate discovery and sibling resolution both ask about the same
 * pairs, and a network round trip per question is the whole cost of this
 * script.
 *
 * @param callable(string, string): bool $tagExists
 * @return callable(string, string): bool
 */
function memoizeTagLookups(callable $tagExists): callable
{
    $answers = [];

    return static function (string $repo, string $tag) use ($tagExists, &$answers): bool {
        $key = "{$repo}\0{$tag}";

        return $answers[$key] ??= $tagExists($repo, $tag);
    };
}

/**
 * For a release candidate, checks that the siblings it names are
 * resolvable by the time it publishes.
 *
 * A same-round sibling is skipped, for a different reason per kind.
 *
 * A `requires` sibling is ordered earlier: publishOrder() sorts on the
 * requires graph and proves the result contains every candidate, and
 * release.yml's publish loop stops on the first failed push, so by the
 * time $key is attempted its required sibling is either tagged or the
 * run has already ended.
 *
 * A `requiresDev` sibling carries no such ordering — the dev graph has
 * cycles, so no total order over it exists to impose. It is skipped
 * because it is absent from the released package's own require block: a
 * dev tag landing later in the round, or not at all, changes nothing
 * about installing $key. It affects running that package's suite from
 * its split repo, which this plan does not gate.
 *
 * A sibling of either kind that is not a candidate this round is checked
 * against a real tag, since nothing in this run will create one.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, true> $candidateSet Every package key that's a
 *     candidate this round, keyed for O(1) lookup — not just $key's own
 *     siblings, the full round.
 * @param callable(string, string): bool $tagExists Injectable so the
 *     surrounding logic (which siblings get checked, how a miss is
 *     worded) is unit-testable without a real network call —
 *     tagLookup() itself is exercised separately, directly.
 * @return list<string> problems, empty if everything resolves
 */
function checkResolution(array $manifest, string $key, array $candidateSet, callable $tagExists): array
{
    $pkg = $manifest['packages'][$key];
    $problems = [];

    foreach ([...($pkg['requires'] ?? []), ...($pkg['requiresDev'] ?? [])] as $sibling) {
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
        echo "Nothing to release — no version changed since the comparison base, and every current version is already tagged.\n";

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
 * @return array{json: bool, base: ?string, problems: list<string>}
 */
function parsePlanArguments(array $argv): array
{
    $json = false;
    $base = null;
    $problems = [];
    $seen = ['--base' => 0, '--json' => 0];

    foreach ($argv as $arg) {
        if ($arg === '--json') {
            $seen['--json']++;
            $json = true;
        } elseif (str_starts_with($arg, '--base=')) {
            $seen['--base']++;
            $value = trim(substr($arg, strlen('--base=')));

            // See parseValidatorArguments(): an empty --base is a base
            // that was meant to be there, not an absent one.
            if ($value === '') {
                $problems[] = '--base needs a commit id or a ref name.';

                continue;
            }

            $base = $value;
        } else {
            $problems[] = "Unknown option: {$arg}";
        }
    }

    foreach ($seen as $option => $count) {
        if ($count > 1) {
            $problems[] = "{$option} is given more than once.";
        }
    }

    return ['json' => $json, 'base' => $base, 'problems' => $problems];
}

/**
 * @param list<string> $argv
 */
function main(array $argv = []): int
{
    $arguments = parsePlanArguments(array_slice($argv, 1));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    $newManifest = loadManifestOrReport();

    if ($newManifest === null) {
        return 1;
    }

    $tagExists = memoizeTagLookups(tagExistsOnGitHub(...));

    try {
        $oldManifest = manifestAtComparisonBase($arguments['base'], PROJECT_ROOT);

        // Two independent sources, unioned — a diff-based candidate (the
        // ordinary case: a version field changed this
        // push) and an untagged one (see findUntaggedCandidates() — the
        // "first release" case a pure diff can never detect on its own).
        // $oldManifest being unavailable (no prior commit to diff against
        // at all) only removes the first source; the second still runs.
        // resolveCandidates() then filters the whole union by tag
        // existence — see its own docblock for why that has to happen
        // after the union, not just inside findUntaggedCandidates().
        $diffCandidates = $oldManifest !== null ? findReleaseCandidates($oldManifest, $newManifest) : [];
        $untaggedCandidates = findUntaggedCandidates($newManifest, $tagExists);
        $candidates = resolveCandidates($diffCandidates, $untaggedCandidates, $newManifest, $tagExists);

        if ($candidates === []) {
            $arguments['json'] ? printJson([]) : printHumanReadable([], note: null);

            return 0;
        }

        $order = publishOrder($newManifest, $candidates);
        $candidateSet = array_fill_keys($candidates, true);
        $plan = [];

        foreach ($order as $key) {
            $plan[] = [
                'key' => $key,
                'version' => $newManifest['packages'][$key]['version'],
                'problems' => checkResolution($newManifest, $key, $candidateSet, $tagExists),
            ];
        }
    } catch (HistoryUnavailable | ReleasePlanFailure $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }

    $arguments['json'] ? printJson($plan) : printHumanReadable($plan, note: null);

    return array_all($plan, static fn (array $entry): bool => $entry['problems'] === []) ? 0 : 1;
}

/**
 * The manifest as of the comparison base, or null when there is no base
 * at all — the repository's first commit, or a ref's first push. An
 * unreadable base that should have been there throws.
 *
 * @return array<string, mixed>|null
 * @throws HistoryUnavailable
 */
function manifestAtComparisonBase(?string $baseOverride, string $projectRoot): ?array
{
    $base = resolveComparisonBase(
        static fn (string $ref): string => gitResolveCommit($ref, $projectRoot),
        static fn (string $ref): bool => gitCommitExists($ref, $projectRoot),
        static fn (): bool => gitIsShallow($projectRoot),
        $baseOverride,
    );

    return $base->commit === null ? null : readManifestAtCommit($base->commit, $projectRoot);
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(main($argv ?? []));
}
