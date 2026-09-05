<?php

declare(strict_types=1);

/**
 * Computes this round's release plan — see CLAUDE.md and the monorepo
 * packaging plan (Phase 5) for the full design. Read-only and
 * unauthenticated: never writes anything, never tags, never pushes, and
 * never holds the deploy credential. tools/release-transaction.php is
 * what acts on this plan's output.
 *
 * Usage: php tools/release-plan.php [--json] [--base=<ref>]
 *
 * A package is a release candidate for either of two reasons: its
 * version field differs from packages.manifest.json's state at the
 * comparison base (see tools/git-history.php — GITHUB_EVENT_BEFORE when
 * set, HEAD^ otherwise), or its split repository does not yet carry the
 * current version as a finished release — see
 * findUnpublishedCandidates(), which covers a version that predates this
 * pipeline as well as a round that tagged a package without pushing its
 * main branch. Reports the union in publish-order (topological over the
 * requires graph, restricted to candidates), each with whether its
 * sibling requirements resolve against a real tag on the sibling's own
 * split repo. No-ops cleanly (reports zero candidates, exits 0) only
 * when neither source finds anything; exits 1 if any candidate has an
 * unresolved sibling.
 *
 * A candidate is work to inspect, not work to publish. Which of a
 * candidate's refs are actually written is decided by the publication
 * transaction, against the exact split commit and the remote state it
 * reads for itself.
 *
 * The manifest goes through tools/manifest-schema.php before any of
 * that, so a malformed entry stops the plan here rather than reaching a
 * remote lookup or the split — this run is the last thing between a
 * manifest and a published tag, and it does not lean on another workflow
 * having caught the problem concurrently. Every other way this run can
 * fail to establish a fact — an unreadable comparison base, an
 * indeterminate ref lookup, a graph with no total order — ends the run
 * rather than producing a plan that omits work.
 *
 * --json emits {candidates: [{key, version, problems}], ok} instead of
 * the human-readable report — what the release workflow hands to the
 * publication transaction, in the given order.
 */

require_once __DIR__ . '/generate-composer.php';
require_once __DIR__ . '/validate-manifest.php';

const GITHUB_ORG = 'kinetis-dev';

/** Where one package's own split repository lives. */
function splitRepositoryUrl(string $key): string
{
    return 'https://github.com/' . GITHUB_ORG . "/{$key}.git";
}

/**
 * A ref lookup reaches the network, and this script runs inside the job
 * that publishes. A stalled read has to end as a failure rather than
 * holding the release open.
 */
const REF_LOOKUP_TIMEOUT_SECONDS = 30;

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
 * its own split repository does not yet carry the current version as a
 * finished release: no tag, no main branch, or a main branch pointing
 * somewhere other than the tagged commit.
 *
 * Two states reach this that a manifest diff never can. A version that
 * predates this pipeline never changes again, so a diff has nothing left
 * to compare against. And a round that pushed a tag without pushing the
 * branch leaves a repository whose tag is right and whose main is stale;
 * reconciling that is what makes a partial publication recoverable, so a
 * tagged package is inspected here rather than filtered out.
 *
 * Runs unconditionally, not gated behind "only if the diff found
 * nothing" — a package can independently be unpublished from an earlier
 * failed run alongside a diff-based candidate elsewhere in the same
 * round, and this catches that with no extra bookkeeping.
 *
 * Being a candidate is not a decision to write anything.
 * tools/release-transaction.php reads each candidate's exact split
 * commit and remote state and decides, per package, whether to publish,
 * repair main, or leave the repository untouched.
 *
 * @param array<string, mixed> $manifest
 * @param callable(string, string): PublicationRefs $refsFor Same
 *     injectable-callable shape as checkResolution() — testable without
 *     a real network call; publicationRefs() itself is exercised
 *     separately, directly.
 * @return list<string> package keys whose current version is not fully published
 */
function findUnpublishedCandidates(array $manifest, callable $refsFor): array
{
    $candidates = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        $refs = $refsFor($key, "v{$pkg['version']}");

        if ($refs->tag === null || $refs->main === null || $refs->main !== $refs->taggedCommit()) {
            $candidates[] = $key;
        }
    }

    return $candidates;
}

/**
 * Unions the two candidate sources.
 *
 * Nothing is filtered back out. A candidate whose tag already exists
 * still has to reach the publication transaction, the one place that
 * reads the exact split commit alongside both remote refs and can tell
 * a finished package from one whose main branch never caught up.
 * Dropping it here would make a partial publication unrecoverable: the
 * round that could repair it would report nothing to do.
 *
 * @param list<string> $diffCandidates
 * @param list<string> $unpublishedCandidates
 * @return list<string>
 */
function resolveCandidates(array $diffCandidates, array $unpublishedCandidates): array
{
    return array_keys(
        array_fill_keys($diffCandidates, true) + array_fill_keys($unpublishedCandidates, true),
    );
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
 * Asks one split repository what it carries at some exact refs.
 *
 * "Absent" means one thing only: ls-remote ran, spoke to the remote, and
 * reported no record for a ref that was asked about. A remote that
 * refuses the connection, an unauthenticated fetch, a repository that
 * doesn't exist, git failing to start, a read that stalls until the
 * deadline — none of those are evidence about a ref, and reporting them
 * as absence makes every package a release candidate and republishes
 * work that already exists. They raise instead.
 *
 * The answer is object ids rather than a yes or no. A publication
 * compares a remote ref against the split commit it computed and leases
 * the exact value it read, so "the tag is there" is not something it can
 * act on.
 *
 * Read-only and unauthenticated: this runs before anything is published,
 * over a public URL with no credential of any kind.
 *
 * @param list<string> $refs exact ref names, e.g. refs/tags/v1.2.3
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @param (callable(string): string)|null $urlFor where the split repository lives, for a run pointed somewhere else
 * @return array<string, string> ref name => object id, holding only the
 *         asked-for refs the remote reported; an annotated tag also
 *         reports its peeled `^{}` record
 * @throws ReleasePlanFailure
 */
function refLookup(string $repo, array $refs, callable $run, ?callable $urlFor = null): array
{
    $url = ($urlFor ?? splitRepositoryUrl(...))($repo);
    $result = $run(['ls-remote', '--end-of-options', $url, ...$refs]);
    $asked = implode(', ', $refs);

    if ($result['timedOut']) {
        throw new ReleasePlanFailure(
            "Looking up {$asked} on " . GITHUB_ORG . "/{$repo} did not finish in time — the release plan cannot say "
            . 'what those refs point at.',
        );
    }

    if ($result['exitCode'] !== 0) {
        throw new ReleasePlanFailure(
            'Could not reach ' . GITHUB_ORG . "/{$repo} to look up {$asked}: " . redactCredentials($result['stderr']),
        );
    }

    if (trim($result['stdout']) === '') {
        return [];
    }

    $records = matchingRefRecords($result['stdout'], $refs);

    if ($records === []) {
        throw new ReleasePlanFailure(
            'Looking up ' . GITHUB_ORG . "/{$repo} for {$asked} produced output that names no such ref, "
            . 'so what those refs point at is unknown.',
        );
    }

    return $records;
}

/**
 * The records ls-remote wrote for the refs that were asked about.
 *
 * Its records are `<object id>\t<ref>`, and an annotated tag also
 * reports its peeled `^{}` line. Anything else — a warning, a redirect
 * notice, a ref that merely resembles one of those asked for — is not a
 * record of an answer and is left out, so output carrying none of the
 * asked-for refs comes back empty and settles nothing.
 *
 * @param list<string> $refs
 * @return array<string, string>
 */
function matchingRefRecords(string $output, array $refs): array
{
    $wanted = [];

    foreach ($refs as $ref) {
        $wanted[$ref] = true;

        if (str_starts_with($ref, 'refs/tags/')) {
            $wanted["{$ref}^{}"] = true;
        }
    }

    $records = [];

    foreach (explode("\n", $output) as $line) {
        $line = rtrim($line, "\r");

        if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\t(\S+)$/', $line, $m) !== 1) {
            continue;
        }

        if (isset($wanted[$m[2]])) {
            $records[$m[2]] = $m[1];
        }
    }

    return $records;
}

/**
 * What one split repository holds for a version: the tag object, the
 * commit an annotated tag peels to, and where main points. Every field
 * is an exact object id, or null for a ref the remote does not have —
 * the states a publication has to tell apart before it writes anything.
 */
final class PublicationRefs
{
    public function __construct(
        public readonly ?string $tag,
        public readonly ?string $peeledTag,
        public readonly ?string $main,
    ) {
    }

    /** The commit the tag names, or null when the tag is absent. */
    public function taggedCommit(): ?string
    {
        return $this->peeledTag ?? $this->tag;
    }
}

/**
 * One split repository's tag and main state for a version, in a single
 * round trip. Both refs answer one question — whether this version is
 * published, and whether main carries it — and asking separately doubles
 * the network cost of the whole script.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleasePlanFailure
 */
function publicationRefs(string $repo, string $tag, callable $run, ?callable $urlFor = null): PublicationRefs
{
    $tagRef = "refs/tags/{$tag}";
    $records = refLookup($repo, [$tagRef, 'refs/heads/main'], $run, $urlFor);

    return new PublicationRefs(
        $records[$tagRef] ?? null,
        $records["{$tagRef}^{}"] ?? null,
        $records['refs/heads/main'] ?? null,
    );
}

/** Real ref lookup via ls-remote — never touches Packagist. */
function publicationRefsOnGitHub(string $repo, string $tag): PublicationRefs
{
    return publicationRefs(
        $repo,
        $tag,
        static fn (array $args): array => runGit($args, PROJECT_ROOT, REF_LOOKUP_TIMEOUT_SECONDS),
    );
}

/**
 * Wraps a ref lookup so each repo/tag pair costs one lookup per run.
 * Candidate discovery and sibling resolution both ask about the same
 * pairs, and a network round trip per question is the whole cost of this
 * script.
 *
 * @param callable(string, string): PublicationRefs $refsFor
 * @return callable(string, string): PublicationRefs
 */
function memoizeRefLookups(callable $refsFor): callable
{
    $answers = [];

    return static function (string $repo, string $tag) use ($refsFor, &$answers): PublicationRefs {
        $key = "{$repo}\0{$tag}";

        return $answers[$key] ??= $refsFor($repo, $tag);
    };
}

/**
 * For a release candidate, checks that the siblings it names are
 * resolvable by the time it publishes.
 *
 * A same-round sibling is skipped, for a different reason per kind.
 *
 * A `requires` sibling is ordered earlier: publishOrder() sorts on the
 * requires graph and proves the result contains every candidate, and the
 * publication transaction applies candidates in that order, stopping at
 * the first one it cannot complete.
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
 * @param callable(string, string): PublicationRefs $refsFor Injectable so
 *     the surrounding logic (which siblings get checked, how a miss is
 *     worded) is unit-testable without a real network call — refLookup()
 *     itself is exercised separately, directly.
 * @return list<string> problems, empty if everything resolves
 */
function checkResolution(array $manifest, string $key, array $candidateSet, callable $refsFor): array
{
    $pkg = $manifest['packages'][$key];
    $problems = [];

    foreach ([...($pkg['requires'] ?? []), ...($pkg['requiresDev'] ?? [])] as $sibling) {
        if (isset($candidateSet[$sibling])) {
            continue;
        }

        $version = $manifest['packages'][$sibling]['version'];
        $tag = "v{$version}";

        if ($refsFor($sibling, $tag)->tag === null) {
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
        echo "Nothing to release — no version changed since the comparison base, and every current version is already published.\n";

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

    $refsFor = memoizeRefLookups(publicationRefsOnGitHub(...));

    try {
        $oldManifest = manifestAtComparisonBase($arguments['base'], PROJECT_ROOT);

        // Two independent sources, unioned — a diff-based candidate (the
        // ordinary case: a version field changed this push) and an
        // unpublished one (see findUnpublishedCandidates() — the "first
        // release" and "half-published round" cases a pure diff can never
        // detect on its own). $oldManifest being unavailable (no prior
        // commit to diff against at all) only removes the first source;
        // the second still runs.
        $diffCandidates = $oldManifest !== null ? findReleaseCandidates($oldManifest, $newManifest) : [];
        $unpublishedCandidates = findUnpublishedCandidates($newManifest, $refsFor);
        $candidates = resolveCandidates($diffCandidates, $unpublishedCandidates);

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
                'problems' => checkResolution($newManifest, $key, $candidateSet, $refsFor),
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
