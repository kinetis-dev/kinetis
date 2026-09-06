<?php

declare(strict_types=1);

/**
 * Publishes this round's release candidates, one split repository at a
 * time, in the order tools/release-plan.php gave.
 *
 * Usage: php tools/release-publish.php --plan=plan.json
 *
 * For each candidate, in order:
 *
 *   1. Write its release-mode composer.json — real ^X.Y.Z sibling
 *      constraints, no repositories key — and drop its composer.lock,
 *      then commit both in this runner's own disposable checkout. The
 *      splitter reads committed git objects, not the working tree, so
 *      release metadata that is not in a commit is not in the split.
 *      This commit is never pushed back to the monorepo.
 *   2. Split packages/<key> out with `git subtree split`, giving the
 *      exact commit that repository would publish.
 *   3. Read that repository's main branch and version tag.
 *   4. Update both refs in one atomic push, or skip, or fail.
 *
 * Step 4 is the whole idempotency contract. A repository whose tag and
 * main both already resolve to this round's split commit is finished and
 * is skipped. A repository missing either ref, or carrying a main that
 * points elsewhere, is written by a single `git push --atomic` that
 * moves main and creates the tag together — so a rerun after an
 * interrupted round completes it rather than leaving main behind its own
 * tag. A tag that already exists at a different commit is a hard
 * failure: two different contents cannot both be that version, and
 * choosing one of them is a person's decision, not this script's.
 *
 * Git cannot update two repositories atomically, and this does not claim
 * to. Each repository is written atomically; a round that stops between
 * two repositories is completed by the next one.
 *
 * Three things make a rerun safe, and each one is load-bearing:
 *
 * - The split commits are a function of the source commit and the plan.
 *   Every synthetic commit takes its author, committer and both dates
 *   from the monorepo commit being released, read before the first of
 *   them is made, and `git subtree split` carries a commit's own
 *   metadata into the commit it derives. Rerunning a failed round from
 *   the same source therefore produces the same split commits, which is
 *   what lets step 4 recognize an already-published repository rather
 *   than rejecting it as different content under the same version.
 * - A round only writes while the commit it splits is still the
 *   monorepo's main. A workflow rerun started by hand after main moved
 *   on is publishing content that is no longer current, and would move a
 *   split repository's main backwards; it fails instead, before touching
 *   any split repository.
 * - Each push carries an explicit lease on the split repository's main,
 *   naming the commit read in step 3. A main that changed between the
 *   read and the push fails the push rather than being overwritten.
 *
 * The deploy credential is read from RELEASE_DEPLOY_TOKEN and reaches
 * git only through an askpass helper this script writes with owner-only
 * permissions and removes before returning. It leaves this process's own
 * environment before any child starts, and is passed explicitly to the
 * push alone, so the generator, the commits, the splitter and the
 * unauthenticated remote reads never hold it. It is never put in a URL,
 * an argument, a config value, or a generated file — git copies all four
 * into its own output.
 */

require_once __DIR__ . '/release-plan.php';

/** The environment variable the publication's credential arrives in. */
const DEPLOY_CREDENTIAL_VARIABLE = 'RELEASE_DEPLOY_TOKEN';

/** The username GitHub expects alongside a token. */
const DEPLOY_CREDENTIAL_USERNAME = 'x-access-token';

/** Who the disposable release commits are authored by. */
const RELEASE_COMMIT_NAME = 'kinetis-release';
const RELEASE_COMMIT_EMAIL = 'noreply@kinetis.dev';

/**
 * The checkout's own remote, which is the monorepo this round releases
 * from. Read without a credential, exactly like the split repositories.
 */
const MONOREPO_REMOTE = 'origin';

final class PublicationFailure extends RuntimeException
{
}

/**
 * The monorepo commit this round publishes, captured before anything is
 * staged: the identity a rerun has to agree with, and the date every
 * synthetic commit below is dated by.
 */
final class SourceCommit
{
    public function __construct(
        public readonly string $sha,
        public readonly string $date,
    ) {
    }
}

/**
 * Reads the commit this checkout is on, before any release commit moves
 * HEAD.
 *
 * @throws PublicationFailure
 */
function sourceCommit(string $root): SourceCommit
{
    $output = git($root, 'show', '--no-patch', '--format=%H%n%cI', 'HEAD');
    $lines = array_values(array_filter(
        array_map(trim(...), explode("\n", (string) $output)),
        static fn (string $line): bool => $line !== '',
    ));

    if ($output === null || count($lines) !== 2 || preg_match('/^[0-9a-f]{40}$/', $lines[0]) !== 1) {
        throw new PublicationFailure('Could not read the monorepo commit this round would publish.');
    }

    return new SourceCommit(sha: $lines[0], date: $lines[1]);
}

/**
 * The commit the monorepo's own main branch points at now.
 *
 * @throws PublicationFailure when the remote could not be read, or
 *         carries no main branch: neither is evidence that this round is
 *         still current
 */
function monorepoMainSha(string $root): string
{
    $output = git($root, 'ls-remote', MONOREPO_REMOTE, 'refs/heads/main');
    $sha = $output === null ? '' : trim(explode("\t", trim($output))[0]);

    if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        throw new PublicationFailure(
            "Could not read refs/heads/main from the monorepo's own remote, so this round cannot show it is "
            . 'still the current one.',
        );
    }

    return $sha;
}

/**
 * Ends the run when the commit being released is no longer the
 * monorepo's main.
 *
 * A workflow run started by hand replays whatever commit it was queued
 * for. Publishing it after main moved on would push older package
 * content over a split repository's main, so the round stops here —
 * before the first split repository is written, and with nothing to
 * repair afterwards.
 *
 * @param callable(): string $readMain injectable so the decision is
 *        testable without a network call
 * @throws PublicationFailure
 */
function requireCurrentSource(SourceCommit $source, callable $readMain): void
{
    $current = $readMain();

    if ($current !== $source->sha) {
        throw new PublicationFailure(
            "The monorepo's main is {$current}, and this run releases {$source->sha}. That content is no longer "
            . 'current, so nothing is published — the run for main as it stands now is the one that publishes it.',
        );
    }
}

/**
 * What to do with one split repository, given the commit this round
 * would publish and the refs the repository carries now.
 *
 * @return 'skip'|'push'
 * @throws PublicationFailure when the remote state and this round
 *         disagree about what the version is
 */
function publicationAction(string $key, string $version, string $splitCommit, PublicationRefs $refs): string
{
    if ($refs->tag !== null && $refs->tag !== $splitCommit) {
        throw new PublicationFailure(
            "kinetis-dev/{$key} already publishes v{$version} as {$refs->tag}, and this round splits it as "
            . "{$splitCommit}. A published version is never rewritten — release the change as a new version, or "
            . "remove that tag by hand if it was written in error.",
        );
    }

    return $refs->tag === $splitCommit && $refs->main === $splitCommit ? 'skip' : 'push';
}

/**
 * Stages one candidate's release metadata and commits it, so the split
 * carries what the package publishes rather than what the monorepo
 * develops against.
 *
 * The commit's author, committer and both dates come from the source
 * commit, so the same source and the same plan describe the same commit
 * every time this runs. A wall-clock date would give a rerun of a failed
 * round a new split commit for content already published under that
 * version, which step 4 above reads as two different contents claiming
 * one version and refuses.
 *
 * composer.lock is removed rather than regenerated: Composer never reads
 * a dependency's own lock once it is required transitively, and the
 * dev-mode lock's dev-main entries fail `composer validate --strict`
 * against the now-^X.Y.Z composer.json for anyone cloning the split
 * repository directly.
 *
 * @throws PublicationFailure
 */
function stageReleaseCommit(string $root, string $key, string $version, SourceCommit $source): void
{
    $generated = run($root, ['php', __DIR__ . '/generate-composer.php', "--release-write={$key}"]);

    if ($generated['code'] !== 0) {
        throw new PublicationFailure("Could not write the release composer.json for {$key}: {$generated['err']}");
    }

    $steps = [
        'staging the release composer.json' => ['git', 'add', '--', "packages/{$key}/composer.json"],
        'dropping the dev lock file' => [
            'git', 'rm', '--quiet', '--ignore-unmatch', '--', "packages/{$key}/composer.lock",
        ],
        'committing the release metadata' => [
            'git', 'commit', '--quiet', '--allow-empty', '-m', "release: {$key} v{$version}",
        ],
    ];

    foreach ($steps as $what => $step) {
        $result = run($root, $step, releaseCommitIdentity($source));

        if ($result['code'] !== 0) {
            throw new PublicationFailure("{$key} v{$version}: {$what} failed: " . trim($result['err']));
        }
    }
}

/**
 * The identity and dates every synthetic commit is made with. Both dates
 * are the source commit's own, and neither name nor email is read from
 * the runner's git configuration, so nothing about the machine reaches
 * the commit.
 *
 * @return array<string, string>
 */
function releaseCommitIdentity(SourceCommit $source): array
{
    return [
        'GIT_AUTHOR_NAME' => RELEASE_COMMIT_NAME,
        'GIT_AUTHOR_EMAIL' => RELEASE_COMMIT_EMAIL,
        'GIT_AUTHOR_DATE' => $source->date,
        'GIT_COMMITTER_NAME' => RELEASE_COMMIT_NAME,
        'GIT_COMMITTER_EMAIL' => RELEASE_COMMIT_EMAIL,
        'GIT_COMMITTER_DATE' => $source->date,
    ];
}

/**
 * The commit kinetis-dev/<key> would publish for this round: the
 * package's own prefix, split out of the monorepo history at HEAD.
 *
 * `git subtree split` is the git distribution's own splitter, so the
 * publication has no downloaded executable to trust and the deploy
 * credential has one less child process to be kept out of. It derives
 * each split commit from the monorepo commit it came from, metadata
 * included, which is what makes the result a function of the history
 * rather than of the run.
 *
 * @throws PublicationFailure
 */
function splitCommit(string $root, string $key): string
{
    $result = run($root, ['git', 'subtree', 'split', '--quiet', "--prefix=packages/{$key}", 'HEAD']);
    $sha = trim($result['out']);

    if ($result['code'] !== 0 || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        throw new PublicationFailure(
            "git subtree split produced no split commit for packages/{$key}: " . trim($result['err']),
        );
    }

    return $sha;
}

/**
 * Writes the askpass helper git reads the deploy credential through,
 * returns the environment that points git at it, and takes the
 * credential out of this process's environment on the way.
 *
 * Everything else this script runs — the generator, git add, git commit,
 * the splitter, the remote reads — inherits this process's environment.
 * The credential leaves it here, before the first of them starts, and
 * exists from then on only in the returned environment, which is passed
 * to the push and to nothing else. The helper file names the two
 * variables and holds neither value.
 *
 * @return array{path: string, environment: array<string, string>}
 * @throws PublicationFailure
 */
function credentialHelper(string $directory): array
{
    $token = getenv(DEPLOY_CREDENTIAL_VARIABLE);

    if (!is_string($token) || $token === '') {
        throw new PublicationFailure(
            DEPLOY_CREDENTIAL_VARIABLE . ' is not set — the publishing step is the only place it exists.',
        );
    }

    putenv(DEPLOY_CREDENTIAL_VARIABLE);
    unset($_ENV[DEPLOY_CREDENTIAL_VARIABLE], $_SERVER[DEPLOY_CREDENTIAL_VARIABLE]);

    $script = <<<'SH'
        #!/bin/sh
        case "$1" in
            Username*) printf '%s\n' "$RELEASE_DEPLOY_USERNAME" ;;
            Password*) printf '%s\n' "$RELEASE_DEPLOY_TOKEN" ;;
        esac
        SH;

    // A unique name, created here or not at all: the helper is an
    // executable git runs, so a path something else could have made
    // first, or could still write to, is a path something else chooses
    // that code for. The permissions are set before there is anything to
    // run.
    $path = $directory . '/kinetis-release-askpass-' . bin2hex(random_bytes(16)) . '.sh';
    $handle = @fopen($path, 'xb');

    if ($handle === false) {
        throw new PublicationFailure("Could not create the credential helper at {$path}.");
    }

    if (!chmod($path, 0o700) || fwrite($handle, $script . "\n") === false) {
        fclose($handle);
        @unlink($path);

        throw new PublicationFailure("Could not write the credential helper at {$path}.");
    }

    fclose($handle);

    return [
        'path' => $path,
        'environment' => [
            'GIT_ASKPASS' => $path,
            'GIT_TERMINAL_PROMPT' => '0',
            'RELEASE_DEPLOY_USERNAME' => DEPLOY_CREDENTIAL_USERNAME,
            DEPLOY_CREDENTIAL_VARIABLE => $token,
        ],
    ];
}

/**
 * The command that moves main and creates the version tag in one push.
 *
 * main is written under a lease naming the commit this round read for
 * it, rather than forced outright: a split repository's history is
 * derived, so this round is entitled to replace the main it looked at,
 * and entitled to nothing else. A main that moved between the read and
 * the push fails the push, and --atomic means the tag does not land
 * without it. The tag itself is never forced — an existing tag at
 * another commit is a decision for a person, and publicationAction()
 * has already refused the round by then.
 *
 * @return list<string>
 */
function publicationPush(string $key, string $version, string $splitCommit, PublicationRefs $refs): array
{
    return [
        'git', 'push', '--atomic',
        // An empty expectation is git's spelling of "this ref must not
        // exist yet", which is what an absent main was when it was read.
        '--force-with-lease=refs/heads/main:' . ($refs->main ?? ''),
        splitRepositoryUrl($key),
        "{$splitCommit}:refs/heads/main",
        "{$splitCommit}:refs/tags/v{$version}",
    ];
}

/**
 * @param array<string, string> $environment
 * @throws PublicationFailure
 */
function pushPublication(
    string $root,
    string $key,
    string $version,
    string $splitCommit,
    PublicationRefs $refs,
    array $environment,
): void {
    $result = run($root, publicationPush($key, $version, $splitCommit, $refs), $environment);

    if ($result['code'] !== 0) {
        throw new PublicationFailure("Publishing {$key} v{$version} failed: " . trim($result['err']));
    }
}

/**
 * @param array<string, mixed> $plan
 * @return list<array{key: string, version: string}>
 * @throws PublicationFailure
 */
function planCandidates(array $plan): array
{
    if (!isset($plan['candidates']) || !is_array($plan['candidates'])) {
        throw new PublicationFailure('The plan file has no candidates list.');
    }

    $candidates = [];

    foreach ($plan['candidates'] as $candidate) {
        if (!is_array($candidate) || !isset($candidate['key'], $candidate['version'])
            || !is_string($candidate['key']) || !is_string($candidate['version'])) {
            throw new PublicationFailure('A plan candidate is missing its key or version.');
        }

        $candidates[] = ['key' => $candidate['key'], 'version' => $candidate['version']];
    }

    return $candidates;
}

/** @param list<string> $argv */
function publishMain(array $argv = []): int
{
    $planPath = null;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--plan=')) {
            $planPath = substr($arg, strlen('--plan='));

            continue;
        }

        fwrite(STDERR, "Unknown option: {$arg}\n");

        return 1;
    }

    if ($planPath === null || $planPath === '') {
        fwrite(STDERR, "Usage: php tools/release-publish.php --plan=<file>\n");

        return 1;
    }

    $root = realpath(PROJECT_ROOT);
    $helper = null;

    try {
        $json = @file_get_contents($planPath);

        if ($json === false || $root === false) {
            throw new PublicationFailure("Could not read the plan at {$planPath}.");
        }

        $candidates = planCandidates((array) json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        if ($candidates === []) {
            echo "Nothing to publish.\n";

            return 0;
        }

        // Before the first child process, so the credential is out of
        // the environment every one of them inherits.
        $helper = credentialHelper(sys_get_temp_dir());
        $source = sourceCommit($root);

        foreach ($candidates as $candidate) {
            ['key' => $key, 'version' => $version] = $candidate;
            echo "=== {$key} -> v{$version} ===\n";

            stageReleaseCommit($root, $key, $version, $source);
            $commit = splitCommit($root, $key);
            $refs = publicationRefs($key, "v{$version}");

            if (publicationAction($key, $version, $commit, $refs) === 'skip') {
                echo "  already published as {$commit}\n";

                continue;
            }

            requireCurrentSource($source, static fn (): string => monorepoMainSha($root));
            pushPublication($root, $key, $version, $commit, $refs, $helper['environment']);
            echo "  published {$commit}\n";
        }
    } catch (PublicationFailure | ReleasePlanFailure | GitUnavailable | JsonException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    } finally {
        if ($helper !== null) {
            @unlink($helper['path']);
        }
    }

    return 0;
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(publishMain($argv ?? []));
}
