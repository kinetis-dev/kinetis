<?php

declare(strict_types=1);

/**
 * Computes this round's release plan. Read-only and unauthenticated:
 * never writes anything, never tags, never pushes, and never holds the
 * deploy credential. tools/release-publish.php is what acts on this
 * plan's output.
 *
 * Usage: php tools/release-plan.php [--json] [--base=<ref>]
 *
 * A package is a release candidate for either of two reasons: its
 * version field differs from packages.manifest.json's state at the
 * comparison base (see validate-manifest.php's comparisonBase() —
 * GITHUB_EVENT_BEFORE when set, HEAD^ otherwise), or its split
 * repository does not yet carry the current version as a finished
 * release: no tag, no main branch, or a main branch pointing somewhere
 * other than the tagged commit. The second source covers a version that
 * predates this pipeline, which a manifest diff can never detect — the
 * value never changes again once committed — as well as a round that
 * wrote nothing because it stopped partway.
 *
 * The union is reported in publish order (topological over the requires
 * graph, restricted to candidates), each with two questions answered:
 * whether its sibling requirements resolve against a real tag on the
 * sibling's own split repo, and whether its version is the next one that
 * repository may publish. Zero candidates exits 0; a candidate carrying
 * either problem exits 1.
 *
 * Being a candidate is not a decision to publish. release-publish.php
 * reads each candidate's exact split commit alongside the remote refs
 * and decides, per package, whether to push or to leave the repository
 * alone.
 *
 * Every fact this run needs it either establishes or fails on. A ref
 * lookup answers "absent" only when ls-remote reached the remote and
 * matched nothing; an unreachable remote, a repository that does not
 * exist yet, or an unreadable comparison base ends the run rather than
 * producing a plan that leaves work out.
 *
 * --json emits {candidates: [{key, version, problems}], ok} instead of
 * the human-readable report — what release.yml hands to the publisher,
 * in the given order.
 */

require_once __DIR__ . '/validate-manifest.php';

const GITHUB_ORG = 'kinetis-dev';

/** Where one package's own split repository lives. */
function splitRepositoryUrl(string $key): string
{
    return 'https://github.com/' . GITHUB_ORG . "/{$key}.git";
}

final class ReleasePlanFailure extends RuntimeException
{
}

/**
 * What one split repository publishes for a given version: the commit
 * its main branch points at, and the commit its version tag names.
 * Either is null when the ref is absent.
 */
final class PublicationRefs
{
    public function __construct(
        public readonly ?string $main,
        public readonly ?string $tag,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->tag !== null && $this->main === $this->tag;
    }
}

/**
 * Reads a split repository's main branch and one version tag in a
 * single ls-remote.
 *
 * A tag is read through its peeled value (`^{}`) when there is one, so
 * an annotated tag reports the commit it names rather than the tag
 * object. This tooling only ever writes lightweight tags; reading both
 * means a tag written by hand still compares against a commit.
 *
 * @throws ReleasePlanFailure when the remote could not be read at all
 */
function publicationRefs(string $key, string $tag): PublicationRefs
{
    $output = git(PROJECT_ROOT, 'ls-remote', splitRepositoryUrl($key), 'refs/heads/main', "refs/tags/{$tag}", "refs/tags/{$tag}^{}");

    if ($output === null) {
        throw new ReleasePlanFailure(
            "Could not read kinetis-dev/{$key} — the repository has to exist and be readable before it can be released.",
        );
    }

    $refs = [];

    foreach (explode("\n", $output) as $line) {
        $parts = preg_split('/\s+/', trim($line));

        if ($parts !== false && count($parts) === 2) {
            $refs[$parts[1]] = $parts[0];
        }
    }

    return new PublicationRefs(
        main: $refs['refs/heads/main'] ?? null,
        tag: $refs["refs/tags/{$tag}^{}"] ?? $refs["refs/tags/{$tag}"] ?? null,
    );
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
        if (($oldManifest['packages'][$key]['version'] ?? null) !== $newPkg['version']) {
            $candidates[] = $key;
        }
    }

    return $candidates;
}

/**
 * A package is also a candidate, independent of any manifest diff, when
 * its split repository does not yet carry the current version as a
 * finished release. Runs unconditionally rather than only when the diff
 * found nothing: a package can be unpublished from an earlier stopped
 * round alongside a diff-based candidate elsewhere in the same one.
 *
 * @param array<string, mixed> $manifest
 * @param callable(string, string): PublicationRefs $refsFor injectable
 *        so the surrounding logic is testable without a network call;
 *        publicationRefs() is exercised separately, directly
 * @return list<string>
 */
function findUnpublishedCandidates(array $manifest, callable $refsFor): array
{
    $candidates = [];

    foreach ($manifest['packages'] as $key => $pkg) {
        if (!$refsFor($key, "v{$pkg['version']}")->isPublished()) {
            $candidates[] = $key;
        }
    }

    return $candidates;
}

/**
 * Every version kinetis-dev/<key> already publishes as a tag, in
 * canonical form and on the incubation major line.
 *
 * @return list<string>
 * @throws ReleasePlanFailure when the remote could not be read at all
 */
function publishedVersions(string $key): array
{
    $output = git(PROJECT_ROOT, 'ls-remote', '--tags', '--refs', splitRepositoryUrl($key), 'refs/tags/v*');

    if ($output === null) {
        throw new ReleasePlanFailure(
            "Could not read the tags on kinetis-dev/{$key} — what a package may publish next is decided against "
            . 'what it publishes now.',
        );
    }

    return versionTags($output);
}

/**
 * The versions named by one ls-remote listing. --refs already drops the
 * peeled `^{}` line an annotated tag adds, so each tag appears once; a
 * tag that is not a canonical X.Y.Z on the incubation major line is not
 * a version this tooling published and is not one it reasons about.
 *
 * @return list<string>
 */
function versionTags(string $listing): array
{
    $versions = [];

    foreach (explode("\n", $listing) as $line) {
        $parts = preg_split('/\s+/', trim($line));

        if ($parts === false || count($parts) !== 2 || !str_starts_with($parts[1], 'refs/tags/v')) {
            continue;
        }

        $version = substr($parts[1], strlen('refs/tags/v'));
        $parsed = parseVersion($version);

        if ($parsed !== null && $parsed['major'] === INCUBATION_MAJOR) {
            $versions[] = $version;
        }
    }

    return $versions;
}

/**
 * The highest of a set of versions, or null when the set is empty.
 *
 * @param list<string> $versions
 */
function highestVersion(array $versions): ?string
{
    $highest = null;
    $highestParts = null;

    foreach ($versions as $version) {
        $parts = parseVersion($version);

        if ($parts !== null && ($highestParts === null || array_values($parts) > $highestParts)) {
            $highest = $version;
            $highestParts = array_values($parts);
        }
    }

    return $highest;
}

/**
 * The version a candidate publishes has to be one step above the highest
 * version its split repository already publishes, or 1.0.0 when it
 * publishes none.
 *
 * release.yml keeps at most one pending run per group and discards the
 * rest, so a version can be pushed onto main while an earlier version's
 * run is still waiting and then never get a run of its own. The manifest
 * only ever states the current version, so no manifest diff can see that
 * the version underneath was never tagged. This is where it is seen: a
 * package whose published line would gain a hole stops the round and
 * names the version that is missing, rather than tagging over it.
 *
 * The version-policy rule is the same one the manifest is held to, read
 * here against tags instead of against the previous commit.
 *
 * A version already tagged is left alone — a repository whose tag landed
 * without its branch is repaired by this same round, and nothing under
 * that tag can be missing.
 *
 * @param list<string> $published every version tagged on the split repository
 * @return list<string> problems, empty when the target follows the published line
 */
function checkPredecessor(string $key, string $version, array $published): array
{
    if (in_array($version, $published, true)) {
        return [];
    }

    $problem = versionTransitionProblem(highestVersion($published), $version);

    return $problem === null
        ? []
        : ["{$key} v{$version} does not follow what kinetis-dev/{$key} publishes — {$problem}"];
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
    $dependents = array_fill_keys(array_keys($graph), []);
    $remainingDeps = [];

    foreach ($graph as $node => $deps) {
        $remainingDeps[$node] = count($deps);

        foreach ($deps as $dep) {
            $dependents[$dep][] = $node;
        }
    }

    $queue = array_keys(array_filter($remainingDeps, static fn (int $n): bool => $n === 0));
    sort($queue);
    $order = [];

    while ($queue !== []) {
        $node = array_shift($queue);
        $order[] = $node;
        $next = [];

        foreach ($dependents[$node] as $dependent) {
            if (--$remainingDeps[$dependent] === 0) {
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
 * @return list<string> publish order, restricted to $candidates
 * @throws ReleasePlanFailure when the graph has no total order, so a
 *         candidate would otherwise silently drop out of the plan
 */
function publishOrder(array $manifest, array $candidates): array
{
    $order = topologicalOrder(buildGraph($manifest['packages']));

    if (count($order) !== count($manifest['packages'])) {
        throw new ReleasePlanFailure('The requires graph has no publish order — validate-manifest.php names the cycle.');
    }

    $candidateSet = array_flip($candidates);

    return array_values(array_filter($order, static fn (string $key): bool => isset($candidateSet[$key])));
}

/**
 * For a release candidate, checks that every sibling it requires (in
 * either require or require-dev) either already has a matching tag on
 * its own split repo, or is itself a candidate this same round.
 *
 * A same-round sibling is safe to treat as resolved without a live tag
 * check: publishOrder() puts it earlier in the sequential publish loop,
 * and any push failure ends the run, so a later candidate is never
 * attempted against a sibling whose push did not happen. Requiring a
 * live tag instead would block every round where interdependent
 * packages release together.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, true> $candidateSet every candidate this round
 * @param callable(string, string): PublicationRefs $refsFor
 * @return list<string> problems, empty when everything resolves
 */
function checkResolution(array $manifest, string $key, array $candidateSet, callable $refsFor): array
{
    $pkg = $manifest['packages'][$key];
    $problems = [];

    foreach ([...($pkg['requires'] ?? []), ...($pkg['requiresDev'] ?? [])] as $sibling) {
        if (isset($candidateSet[$sibling])) {
            continue;
        }

        $tag = "v{$manifest['packages'][$sibling]['version']}";

        if ($refsFor($sibling, $tag)->tag === null) {
            $problems[] = "{$key} requires {$sibling} ({$tag}), but that tag doesn't exist on kinetis-dev/{$sibling} yet";
        }
    }

    return $problems;
}

/**
 * @param list<array{key: string, version: string, problems: list<string>}> $plan
 */
function printHumanReadable(array $plan): void
{
    if ($plan === []) {
        echo "Nothing to release — every current version is already published.\n";

        return;
    }

    echo "Release candidates, in publish order:\n";

    foreach ($plan as $entry) {
        echo "  {$entry['key']} -> v{$entry['version']}\n";

        foreach ($entry['problems'] as $problem) {
            echo "    [resolution] {$problem}\n";
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
 * @param array<string, mixed> $manifest
 * @param callable(string, string): PublicationRefs $refsFor
 * @param callable(string): list<string> $versionsFor
 * @return list<array{key: string, version: string, problems: list<string>}>
 * @throws ReleasePlanFailure
 */
function buildPlan(array $manifest, ?array $oldManifest, callable $refsFor, callable $versionsFor): array
{
    // Two independent sources, unioned. $oldManifest is null only when
    // there is no earlier commit to diff against; the second source
    // still runs, which is what makes a first release possible.
    $candidates = array_keys(
        array_fill_keys($oldManifest !== null ? findReleaseCandidates($oldManifest, $manifest) : [], true)
        + array_fill_keys(findUnpublishedCandidates($manifest, $refsFor), true),
    );

    if ($candidates === []) {
        return [];
    }

    $candidateSet = array_fill_keys($candidates, true);
    $plan = [];

    foreach (publishOrder($manifest, $candidates) as $key) {
        $version = $manifest['packages'][$key]['version'];
        $plan[] = [
            'key' => $key,
            'version' => $version,
            'problems' => [
                ...checkResolution($manifest, $key, $candidateSet, $refsFor),
                ...checkPredecessor($key, $version, $versionsFor($key)),
            ],
        ];
    }

    return $plan;
}

/**
 * @param list<string> $argv
 */
function planMain(array $argv = []): int
{
    $json = in_array('--json', $argv, true);
    $arguments = parseValidatorArguments(array_values(array_filter(
        array_slice($argv, 1),
        static fn (string $arg): bool => $arg !== '--json',
    )));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    $manifest = loadManifest();

    // This run is the last thing between a manifest and a published
    // tag, so it validates the manifest itself rather than leaning on
    // another workflow having caught the problem concurrently.
    $schemaProblems = checkManifestSchema($manifest);

    if ($schemaProblems !== []) {
        foreach ($schemaProblems as $problem) {
            fwrite(STDERR, "[manifest-schema] {$problem}\n");
        }

        return 1;
    }

    try {
        $base = comparisonBase($arguments['base'], PROJECT_ROOT);
        $oldManifest = $base === null ? null : manifestAtCommit($base, PROJECT_ROOT);
        $plan = buildPlan($manifest, $oldManifest, publicationRefs(...), publishedVersions(...));
    } catch (GitUnavailable | ReleasePlanFailure $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }

    $json ? printJson($plan) : printHumanReadable($plan);

    return array_all($plan, static fn (array $entry): bool => $entry['problems'] === []) ? 0 : 1;
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(planMain($argv ?? []));
}
