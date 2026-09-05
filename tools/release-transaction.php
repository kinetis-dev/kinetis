<?php

declare(strict_types=1);

/**
 * Publishes a release round to the split repositories — the one place
 * that builds a package's published commit, reads remote state, and
 * writes a ref.
 *
 * Usages:
 *
 *   php tools/release-transaction.php preflight --plan=<file> --out=<file>
 *                                     [--root=<dir>]
 *   php tools/release-transaction.php apply --transaction=<file> [--root=<dir>]
 *
 * Preflight reads tools/release-plan.php's JSON, stages the whole round
 * as one deterministic release commit, builds each package's publication
 * commit from that commit's own prefix tree on top of what the package's
 * repository publishes today, reads every target repository's tag and
 * main, and writes a transaction file naming the exact ref updates to
 * make and the exact remote value each one is allowed to replace. It
 * writes nothing to a remote. Any package it cannot decide about ends
 * the round with zero publication writes.
 *
 * Apply re-derives every object that file names against this checkout,
 * refuses anything it cannot account for, and only then takes the deploy
 * credential and performs the updates — one atomic tag-and-main push per
 * repository. Git cannot update two repositories together, so a round
 * that fails partway leaves some repositories published and the rest
 * untouched — never a repository in a half state. That is safe to
 * re-run: the next round recomputes the same publication commits,
 * re-reads remote state, and writes only what is still missing.
 *
 * The deploy credential exists in this process only once the whole
 * transaction has been validated, is handed to git through an askpass
 * helper reading it from that one child's environment, and appears in no
 * URL, argument, config value, transaction file, exception, or captured
 * output.
 */

require_once __DIR__ . '/release-plan.php';

/** The identity every staged release commit is authored and committed by. */
const RELEASE_COMMIT_NAME = 'kinetis-release';

/** @see RELEASE_COMMIT_NAME */
const RELEASE_COMMIT_EMAIL = 'noreply@kinetis.dev';

/** Where the staged release commit is anchored so nothing collects it mid-run. */
const STAGING_REF = 'refs/kinetis/release-staging';

/** Where a fetched remote ref is anchored, one namespace per package key. */
const REMOTE_REF_PREFIX = 'refs/kinetis/remote/';

/** A fetch or a push reaches the network, so both need a deadline of their own. */
const REMOTE_TIMEOUT_SECONDS = 180;

/**
 * How far back the manifest's own history is read to find the version a
 * package carried before the current one. Reaching this without finding
 * one is indeterminate, not "there is no predecessor".
 */
const MANIFEST_HISTORY_LIMIT = 500;

/** The environment variable apply reads the deploy credential from, once. */
const CREDENTIAL_VARIABLE = 'RELEASE_DEPLOY_TOKEN';

/** What git is told to send as the username alongside the credential. */
const CREDENTIAL_USERNAME = 'x-access-token';

final class ReleaseTransactionFailure extends RuntimeException
{
}

/**
 * One ref update, with the exact remote value it is allowed to replace.
 *
 * $expected is what preflight read from the remote, and null means the
 * ref was not there at all. Both are leases: git refuses the update when
 * the remote no longer matches, so a round racing another one loses
 * rather than overwriting work it never saw. A retry re-reads the remote
 * and builds new leases; a lease is never carried across runs.
 */
final class RefUpdate
{
    public function __construct(
        public readonly string $ref,
        public readonly ?string $expected,
        public readonly string $value,
    ) {
    }

    /** An empty expected value is git's spelling of "this ref must not exist". */
    public function lease(): string
    {
        return "--force-with-lease={$this->ref}:" . ($this->expected ?? '');
    }

    public function refspec(): string
    {
        return "{$this->value}:{$this->ref}";
    }

    /** @return array{ref: string, expected: string|null, value: string} */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'expected' => $this->expected, 'value' => $this->value];
    }

    /**
     * One update read back out of a transaction file.
     *
     * Everything it may say is fixed by the entry carrying it: the ref
     * is one of the two that entry is allowed to write, and the value is
     * that entry's own publication commit. An expected value is a lease,
     * so it may be absent, but it can never be anything but an object
     * id.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowed the only refs this entry may write
     * @throws ReleaseTransactionFailure
     */
    public static function fromArray(array $data, string $key, array $allowed, string $commit): self
    {
        requireExactKeys($data, ['ref', 'expected', 'value'], "an update of {$key}");

        $ref = $data['ref'];
        $value = $data['value'];
        $expected = $data['expected'];

        if (!is_string($ref) || !in_array($ref, $allowed, true)) {
            throw new ReleaseTransactionFailure(
                "An update of {$key} names " . describeUnusableValue($ref)
                . ', which is not a ref this publication writes.',
            );
        }

        if (!is_string($value) || !isObjectId($value)) {
            throw new ReleaseTransactionFailure("The update of {$key} for {$ref} carries no object id to publish.");
        }

        if ($value !== $commit) {
            throw new ReleaseTransactionFailure(
                "The update of {$key} for {$ref} publishes {$value}, which is not that entry's commit {$commit}.",
            );
        }

        if ($expected !== null && (!is_string($expected) || !isObjectId($expected))) {
            throw new ReleaseTransactionFailure("The update of {$key} for {$ref} carries an unusable expected value.");
        }

        return new self($ref, $expected, $value);
    }
}

/**
 * Every key a transaction object may carry, and no other.
 *
 * A transaction file decides what the one credential-bearing process
 * pushes, so a key this code does not write is not something to ignore:
 * it means the document came from somewhere else.
 *
 * @param array<string, mixed> $data
 * @param list<string> $keys
 * @throws ReleaseTransactionFailure
 */
function requireExactKeys(array $data, array $keys, string $what): void
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            throw new ReleaseTransactionFailure("The transaction file gives {$what} no {$key}.");
        }
    }

    $unknown = array_diff(array_keys($data), $keys);

    if ($unknown !== []) {
        throw new ReleaseTransactionFailure(
            "The transaction file gives {$what} keys this publication never writes: "
            . implode(', ', array_map(describeUnusableValue(...), $unknown)) . '.',
        );
    }
}

/** Names an unusable value without letting a file's own content run away. */
function describeUnusableValue(mixed $value): string
{
    if (!is_string($value)) {
        return get_debug_type($value);
    }

    $shown = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;

    return (string) json_encode($shown, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** What one package's split repository needs, and what may already be there. */
final class PackagePublication
{
    /** @param list<RefUpdate> $updates */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $url,
        public readonly string $commit,
        public readonly array $updates,
    ) {
    }

    public function repository(): string
    {
        return GITHUB_ORG . "/{$this->key}";
    }

    public function tagRef(): string
    {
        return "refs/tags/v{$this->version}";
    }

    /** Publishing this version, repairing a stale main, or nothing left to do. */
    public function action(): string
    {
        if ($this->updates === []) {
            return 'none';
        }

        foreach ($this->updates as $update) {
            if ($update->ref === $this->tagRef()) {
                return 'publish';
            }
        }

        return 'repair-main';
    }

    /** @return array{key: string, version: string, url: string, commit: string, action: string, updates: list<array{ref: string, expected: string|null, value: string}>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'url' => $this->url,
            'commit' => $this->commit,
            'action' => $this->action(),
            'updates' => array_map(static fn (RefUpdate $u): array => $u->toArray(), $this->updates),
        ];
    }

    /**
     * One package entry read back out of a transaction file.
     *
     * @param array<string, mixed> $data
     * @throws ReleaseTransactionFailure
     */
    public static function fromArray(array $data): self
    {
        requireExactKeys($data, ['key', 'version', 'url', 'commit', 'action', 'updates'], 'a package entry');

        $key = $data['key'];

        if (!is_string($key) || preg_match(PACKAGE_KEY_PATTERN, $key) !== 1) {
            throw new ReleaseTransactionFailure('A transaction entry names no package key.');
        }

        $version = $data['version'];

        if (!is_string($version) || parseVersion($version) === null) {
            throw new ReleaseTransactionFailure("The transaction entry for {$key} names no version.");
        }

        $commit = $data['commit'];

        if (!is_string($commit) || !isObjectId($commit)) {
            throw new ReleaseTransactionFailure("The transaction entry for {$key} names no publication commit.");
        }

        // A transaction file decides where a credential-carrying push
        // goes, so the only destination it may name is that package's
        // own split repository.
        $url = $data['url'];

        if (!is_string($url) || $url !== splitRepositoryUrl($key)) {
            throw new ReleaseTransactionFailure("The transaction entry for {$key} names another repository.");
        }

        $updates = $data['updates'];

        if (!is_array($updates) || !array_is_list($updates)) {
            throw new ReleaseTransactionFailure("The transaction entry for {$key} carries no update list.");
        }

        $publication = new self($key, $version, $url, $commit, self::parseUpdates($key, $version, $commit, $updates));

        // A tag with a lease is a request to replace a published
        // version. Nothing here writes one, so a file carrying one was
        // written by something else.
        foreach ($publication->updates as $update) {
            if ($update->ref === $publication->tagRef() && $update->expected !== null) {
                throw new ReleaseTransactionFailure(
                    "The transaction entry for {$key} would replace an existing {$update->ref}. "
                    . 'A published version is never re-pointed.',
                );
            }
        }

        if ($data['action'] !== $publication->action()) {
            throw new ReleaseTransactionFailure(
                "The transaction entry for {$key} calls itself " . describeUnusableValue($data['action'])
                . ", but its updates are a {$publication->action()}.",
            );
        }

        return $publication;
    }

    /**
     * @param list<mixed> $updates
     * @return list<RefUpdate>
     * @throws ReleaseTransactionFailure
     */
    private static function parseUpdates(string $key, string $version, string $commit, array $updates): array
    {
        $allowed = ["refs/tags/v{$version}", 'refs/heads/main'];
        $parsed = [];
        $seen = [];

        foreach ($updates as $update) {
            if (!is_array($update) || array_is_list($update)) {
                throw new ReleaseTransactionFailure("The transaction entry for {$key} carries a malformed update.");
            }

            /** @var array<string, mixed> $update */
            $refUpdate = RefUpdate::fromArray($update, $key, $allowed, $commit);

            if (isset($seen[$refUpdate->ref])) {
                throw new ReleaseTransactionFailure(
                    "The transaction entry for {$key} updates {$refUpdate->ref} more than once.",
                );
            }

            $seen[$refUpdate->ref] = true;
            $parsed[] = $refUpdate;
        }

        return $parsed;
    }
}

/** A whole preflighted round: what it was computed from, and what it will write. */
final class ReleaseTransaction
{
    /** @param list<PackagePublication> $packages */
    public function __construct(
        public readonly string $source,
        public readonly string $staged,
        public readonly array $packages,
    ) {
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'source' => $this->source,
                'staged' => $this->staged,
                'packages' => array_map(static fn (PackagePublication $p): array => $p->toArray(), $this->packages),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @throws ReleaseTransactionFailure */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ReleaseTransactionFailure('The transaction file is not readable JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ReleaseTransactionFailure('The transaction file does not describe a release round.');
        }

        /** @var array<string, mixed> $decoded */
        requireExactKeys($decoded, ['source', 'staged', 'packages'], 'the transaction file');

        $source = $decoded['source'];
        $staged = $decoded['staged'];
        $packages = $decoded['packages'];

        if (!is_string($source) || !isObjectId($source) || !is_string($staged) || !isObjectId($staged)) {
            throw new ReleaseTransactionFailure('The transaction file names no source and staged commit.');
        }

        if (!is_array($packages) || !array_is_list($packages)) {
            throw new ReleaseTransactionFailure('The transaction file carries no package list.');
        }

        $parsed = [];
        $seen = [];

        foreach ($packages as $package) {
            if (!is_array($package) || array_is_list($package)) {
                throw new ReleaseTransactionFailure('The transaction file carries a malformed package entry.');
            }

            /** @var array<string, mixed> $package */
            $publication = PackagePublication::fromArray($package);

            if (isset($seen[$publication->key])) {
                throw new ReleaseTransactionFailure(
                    "The transaction file names {$publication->key} more than once.",
                );
            }

            $seen[$publication->key] = true;
            $parsed[] = $publication;
        }

        return new self($source, $staged, $parsed);
    }
}

/**
 * Runs git and hands back its output, or ends the round.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @param list<string> $args
 * @throws ReleaseTransactionFailure
 */
function gitOutput(callable $run, array $args, string $what): string
{
    $result = $run($args);

    if ($result['exitCode'] !== 0) {
        throw new ReleaseTransactionFailure("Could not {$what}: " . redactCredentials($result['stderr']));
    }

    return $result['stdout'];
}

/**
 * The exact object id a rev names in this checkout.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function resolveObject(callable $run, string $rev, string $what): string
{
    $id = trim(gitOutput($run, ['rev-parse', '--verify', '--quiet', '--end-of-options', $rev], $what));

    if (!isObjectId($id)) {
        throw new ReleaseTransactionFailure("Could not {$what}: git named no object id for {$rev}.");
    }

    return $id;
}

/**
 * The tree one package's directory carries at the staged release commit.
 *
 * This is the content the round publishes, and every publication commit
 * is judged against it.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function stagedPrefixTree(callable $run, string $key, string $staged): string
{
    return resolveObject($run, "{$staged}:packages/{$key}", "read the staged tree of {$key}");
}

/**
 * Proves a commit publishes exactly this round's package content.
 *
 * A publication commit's tree is the prefix's own tree, so comparing the
 * two settles both questions at once: the id names a real commit in this
 * checkout, and that commit carries exactly the files being published.
 * Nothing downstream builds a refspec from an id that has not been
 * through here.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requirePublicationCommit(callable $run, string $key, string $commit, string $prefixTree): void
{
    $type = trim(gitOutput($run, ['cat-file', '-t', '--end-of-options', $commit], "read the publication of {$key}"));

    if ($type !== 'commit') {
        throw new ReleaseTransactionFailure(
            "The publication of {$key} names {$commit}, which is a {$type} in this checkout rather than a commit.",
        );
    }

    $tree = resolveObject($run, "{$commit}^{tree}", "read the publication tree of {$key}");

    if ($tree !== $prefixTree) {
        throw new ReleaseTransactionFailure(
            "The publication of {$key} carries tree {$tree}, but packages/{$key} at the staged release commit "
            . "is {$prefixTree} — it does not describe what this round is publishing.",
        );
    }
}

/**
 * Proves an already-published version carries what this round stages.
 *
 * A version whose tag is already on the remote has nothing left to
 * create: the round either agrees with what is published and completes
 * whatever is still missing around it, or it disagrees and stops.
 * Rebuilding the commit on a parent the remote has since moved past
 * would give it a different id and turn a finished release into a
 * re-pointed tag.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requirePublishedVersion(callable $run, string $key, string $version, string $tagged, string $prefixTree): void
{
    try {
        requirePublicationCommit($run, $key, $tagged, $prefixTree);
    } catch (ReleaseTransactionFailure $e) {
        throw new ReleaseTransactionFailure(
            "v{$version} is published on " . GITHUB_ORG . "/{$key} already, and it does not carry what this "
            . "round stages: {$e->getMessage()} A published version is never re-pointed; release a new version "
            . 'instead.',
        );
    }
}

/**
 * The commit one package's repository publishes for this version.
 *
 * A package's published history is a line of one commit per release.
 * Each carries the prefix's tree at that round's staged release commit
 * and names the commit the repository published last as its parent, so
 * a new release always descends from the one before it and a
 * fast-forward is the only way main ever moves. Every input is fixed by
 * the round — the tree, the parent read from the remote, the identity,
 * both dates and the message — so the same monorepo commit against the
 * same remote state always produces the same id, however often the round
 * is retried and whatever clean checkout it runs in.
 *
 * A version already carrying a tag is verified rather than rebuilt, so a
 * round whose push landed halfway repairs the branch around the tag it
 * already wrote.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function publicationCommitFor(
    callable $run,
    string $key,
    string $version,
    string $staged,
    PublicationRefs $refs,
    string $source,
    string $date,
): string {
    $prefixTree = stagedPrefixTree($run, $key, $staged);
    $tagged = $refs->taggedCommit();

    if ($tagged !== null) {
        requirePublishedVersion($run, $key, $version, $tagged, $prefixTree);

        return $tagged;
    }

    $commit = writeReleaseCommit($run, $prefixTree, $refs->main, publicationMessage($key, $version, $source), $date);
    requirePublicationCommit($run, $key, $commit, $prefixTree);

    return $commit;
}

/**
 * What a published commit says about itself.
 *
 * Fixed by the round like everything else about the commit: one package
 * at one version from one monorepo commit always writes the same
 * message, and therefore the same object.
 */
function publicationMessage(string $key, string $version, string $source): string
{
    return "release: {$key} v{$version}\n\npackages/{$key} at {$source} of the Kinetis monorepo.\n";
}

/**
 * Stages the whole round as one release commit.
 *
 * Every input to the commit is fixed — the identity, both dates, the
 * parent, the message, and the tree — so the same monorepo commit and
 * manifest produce the same commit id, and therefore the same package
 * trees, however often the round is retried.
 *
 * The group is staged together rather than one package at a time so
 * every package's release-mode composer.json names its siblings'
 * released versions in one coherent state. Ordering the packages is a
 * publication concern, not a staging one: the dev graph has cycles, and
 * nothing here needs a total order over it.
 *
 * @param array<string, mixed> $manifest
 * @param list<string> $keys in publish order
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function stageReleaseGroup(
    array $manifest,
    array $keys,
    string $source,
    string $date,
    string $projectRoot,
    callable $run,
): string {
    $contents = generateRelease($manifest, $keys);
    $expected = [];

    foreach ($keys as $key) {
        try {
            writeFileChecked(composerJsonPath($key, $projectRoot), $contents[$key]);
        } catch (CheckedWriteFailure $e) {
            throw new ReleaseTransactionFailure("Could not stage {$key}: " . $e->getMessage());
        }

        gitOutput($run, ['add', '--end-of-options', "packages/{$key}/composer.json"], "stage {$key}'s composer.json");
        $expected[] = "packages/{$key}/composer.json";

        // The dev-mode lock pins dev-main siblings, which stop resolving
        // once the released composer.json names ^X.Y instead. Composer
        // never reads a dependency's own lock file, so removing it costs
        // a consumer nothing and keeps `composer validate --strict` true
        // for anyone cloning the split repository directly.
        if (trim(gitOutput($run, ['ls-files', '--end-of-options', "packages/{$key}/composer.lock"], 'list tracked files')) !== '') {
            gitOutput($run, ['rm', '--quiet', '--cached', '--end-of-options', "packages/{$key}/composer.lock"], "unstage {$key}'s lock file");
            $expected[] = "packages/{$key}/composer.lock";
        }
    }

    $tree = trim(gitOutput($run, ['write-tree'], 'write the staged release tree'));

    if (!isObjectId($tree)) {
        throw new ReleaseTransactionFailure('git wrote no tree id for the staged release.');
    }

    $commit = writeReleaseCommit($run, $tree, $source, stagedCommitMessage($manifest, $keys, $source), $date);

    requireStagedTree($run, $source, $commit, $expected);
    gitOutput($run, ['update-ref', '--end-of-options', STAGING_REF, $commit], 'anchor the staged release commit');
    gitOutput($run, ['reset', '--hard', '--quiet', '--end-of-options', $source], 'restore the checkout after staging');

    return $commit;
}

/**
 * Writes one of this round's commits under a fixed author and committer.
 *
 * The identity arrives as configuration and the dates as environment,
 * because git reads an author or committer date from GIT_AUTHOR_DATE and
 * GIT_COMMITTER_DATE and has no configuration key for either. Both are
 * put back exactly as they were found, so no later call in this run
 * inherits them.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function writeReleaseCommit(callable $run, string $tree, ?string $parent, string $message, string $date): string
{
    $restore = [
        'GIT_AUTHOR_DATE' => getenv('GIT_AUTHOR_DATE'),
        'GIT_COMMITTER_DATE' => getenv('GIT_COMMITTER_DATE'),
    ];

    putenv("GIT_AUTHOR_DATE={$date}");
    putenv("GIT_COMMITTER_DATE={$date}");

    // A first release has no commit to descend from, and git spells that
    // as a commit-tree call with no -p at all.
    $parentage = $parent === null ? [] : ['-p', $parent];

    try {
        $commit = trim(gitOutput(
            $run,
            [
                '-c', 'user.name=' . RELEASE_COMMIT_NAME,
                '-c', 'user.email=' . RELEASE_COMMIT_EMAIL,
                'commit-tree', $tree, ...$parentage, '-m', $message,
            ],
            'write a release commit',
        ));
    } finally {
        foreach ($restore as $name => $value) {
            is_string($value) ? putenv("{$name}={$value}") : putenv($name);
        }
    }

    if (!isObjectId($commit)) {
        throw new ReleaseTransactionFailure('git wrote no commit id for this release.');
    }

    return $commit;
}

/**
 * The date every object this round writes is stamped with.
 *
 * It comes from the commit being released rather than from the clock: a
 * runtime date would make every retry a different object and turn an
 * idempotent republish into an endless force-push.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function sourceCommitDate(callable $run, string $source): string
{
    $date = trim(gitOutput(
        $run,
        ['show', '-s', '--format=%cI', '--end-of-options', $source],
        'read the source commit date',
    ));

    if ($date === '') {
        throw new ReleaseTransactionFailure('The source commit reports no date to stage the release at.');
    }

    return $date;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $keys
 */
function stagedCommitMessage(array $manifest, array $keys, string $source): string
{
    $lines = ["release: staged from {$source}", ''];

    foreach ($keys as $key) {
        $lines[] = "{$key} v{$manifest['packages'][$key]['version']}";
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Proves the staged commit changed exactly the files the round meant to
 * change. A stray file in the index would otherwise ride into every
 * split repository, and the commit id would stop being a function of the
 * source commit and the manifest.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @param list<string> $expected
 * @throws ReleaseTransactionFailure
 */
function requireStagedTree(callable $run, string $source, string $staged, array $expected): void
{
    $changed = splitNulSeparatedPaths(gitOutput(
        $run,
        ['diff', '--no-renames', '-z', '--name-only', '--end-of-options', $source, $staged],
        'compare the staged release against the source commit',
    ));

    sort($changed);
    sort($expected);

    if ($changed !== $expected) {
        throw new ReleaseTransactionFailure(
            'The staged release commit changes ' . implode(', ', $changed === [] ? ['nothing'] : $changed)
            . ', but this round stages ' . implode(', ', $expected === [] ? ['nothing'] : $expected) . '.',
        );
    }
}

/**
 * The version a package carried before its current one, read from the
 * manifest's own history.
 *
 * Null means the manifest has never held another version for this
 * package: it was introduced at the version it is on now, and there is
 * no earlier release to have missed. Running out of history to read
 * before deciding is neither answer and ends the round.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function previousManifestVersion(callable $run, string $key, string $current, string $source): ?string
{
    $log = gitOutput(
        $run,
        [
            'log', '--format=%H', '--max-count=' . (MANIFEST_HISTORY_LIMIT + 1),
            '--end-of-options', $source, '--', 'packages.manifest.json',
        ],
        "read the manifest history behind {$key}",
    );

    $commits = array_values(array_filter(explode("\n", trim($log)), static fn (string $line): bool => $line !== ''));

    if (count($commits) > MANIFEST_HISTORY_LIMIT) {
        throw new ReleaseTransactionFailure(
            'The manifest changed more than ' . MANIFEST_HISTORY_LIMIT . " times without {$key} moving off "
            . "v{$current}, so the version before it cannot be established.",
        );
    }

    foreach ($commits as $commit) {
        $version = manifestVersionAt($run, $commit, $key);

        if ($version === null) {
            // The manifest does not carry the package yet, so the
            // current version is its first.
            return null;
        }

        if ($version !== $current) {
            return $version;
        }
    }

    return null;
}

/**
 * One package's version in the manifest as of a commit, or null when the
 * manifest does not carry that package.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function manifestVersionAt(callable $run, string $commit, string $key): ?string
{
    $contents = gitOutput(
        $run,
        ['show', '--end-of-options', "{$commit}:packages.manifest.json"],
        "read packages.manifest.json at {$commit}",
    );

    try {
        $manifest = decodeManifest($contents);
    } catch (ManifestUnreadable $e) {
        throw new ReleaseTransactionFailure("packages.manifest.json at {$commit}: " . $e->getMessage());
    }

    $packages = is_array($manifest) ? ($manifest['packages'] ?? null) : null;

    if (!is_array($packages)) {
        throw new ReleaseTransactionFailure("packages.manifest.json at {$commit} lists no packages.");
    }

    $entry = $packages[$key] ?? null;

    if ($entry === null) {
        return null;
    }

    $version = is_array($entry) ? ($entry['version'] ?? null) : null;

    if (!is_string($version) || parseVersion($version) === null) {
        throw new ReleaseTransactionFailure(
            "packages.manifest.json at {$commit} gives {$key} no readable version.",
        );
    }

    return $version;
}

/**
 * Refuses to publish a version whose immediate predecessor was never
 * published.
 *
 * The publication group is serialized and never cancelled, but GitHub
 * keeps at most one pending run per group and drops the rest, so an
 * intermediate commit's validation can be discarded before it ever runs.
 * The newest round reconciles its own manifest version and only that
 * one: publishing the version before it would tag content no exact-SHA
 * gate ever passed. An operator releases that version by re-running the
 * release workflow on the commit that carried it.
 *
 * A package whose current version is the only one the manifest has ever
 * carried has no predecessor to have missed.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @param callable(string, string): PublicationRefs $refsFor
 * @throws ReleaseTransactionFailure
 */
function requirePublishedPredecessor(callable $run, callable $refsFor, string $key, string $version, string $source): void
{
    $previous = previousManifestVersion($run, $key, $version, $source);

    if ($previous === null) {
        return;
    }

    $problem = versionTransitionProblem($previous, $version);

    if ($problem !== null) {
        throw new ReleaseTransactionFailure(
            "The manifest moved {$key} from {$previous} to {$version}, which the version policy rejects: {$problem}",
        );
    }

    if ($refsFor($key, "v{$previous}")->tag === null) {
        throw new ReleaseTransactionFailure(
            "{$key} v{$version} follows v{$previous}, which is not published on " . GITHUB_ORG . "/{$key}. "
            . "Release the commit that carried v{$previous} first — this round publishes only the version its own "
            . 'commit was validated for.',
        );
    }
}

/**
 * Which ref updates one package's repository still needs.
 *
 * Every state a remote can be in is answered here, and only two of them
 * write anything. A tag naming a different commit is a published version
 * being re-pointed and ends the round. A main branch that already
 * contains this release is left alone, so a repository carrying a newer
 * release than the one being reconciled is never rolled back. A main
 * branch that is absent or behind is brought to the publication commit,
 * which was built on it and therefore always fast-forwards it.
 *
 * @param callable(string, string): bool $isAncestor
 * @return list<RefUpdate>
 * @throws ReleaseTransactionFailure
 */
function refUpdatesFor(string $key, string $tagRef, PublicationRefs $refs, string $commit, callable $isAncestor): array
{
    $updates = [];
    $tagged = $refs->taggedCommit();

    if ($refs->tag === null) {
        $updates[] = new RefUpdate($tagRef, null, $commit);
    } elseif ($tagged !== $commit) {
        throw new ReleaseTransactionFailure(
            "{$tagRef} on " . GITHUB_ORG . "/{$key} names {$tagged}, but this round publishes {$commit}. "
            . 'A published version is never re-pointed; release a new version instead.',
        );
    }

    if ($refs->main === null) {
        $updates[] = new RefUpdate('refs/heads/main', null, $commit);

        return $updates;
    }

    if ($refs->main === $commit || $isAncestor($commit, $refs->main)) {
        return $updates;
    }

    if ($isAncestor($refs->main, $commit)) {
        $updates[] = new RefUpdate('refs/heads/main', $refs->main, $commit);

        return $updates;
    }

    throw new ReleaseTransactionFailure(
        'main on ' . GITHUB_ORG . "/{$key} is at {$refs->main}, which shares no history with this round's "
        . "{$commit}. Publishing would discard whatever that branch carries.",
    );
}

/**
 * Whether one commit is reachable from another, both of which have to be
 * in this checkout. git answers 0 for yes and 1 for no; anything else is
 * git failing, and reading that as "no" would move a branch backward.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function isAncestorOf(callable $run, string $ancestor, string $descendant): bool
{
    $result = $run(['merge-base', '--is-ancestor', '--end-of-options', $ancestor, $descendant]);

    if ($result['exitCode'] === 0) {
        return true;
    }

    if ($result['exitCode'] === 1 && !$result['timedOut']) {
        return false;
    }

    throw new ReleaseTransactionFailure(
        "Could not tell whether {$ancestor} is an ancestor of {$descendant}: " . redactCredentials($result['stderr']),
    );
}

/**
 * Brings a split repository's published refs into this checkout, and
 * proves they are the ones that were just read.
 *
 * Both are needed as objects rather than as ids: a new release is built
 * on the branch this brings in, an already-published version is judged
 * by the tag it brings in, and ancestry is something git answers about
 * commits it holds. A ref that moved between the two reads makes every
 * classification built on it stale, so the round ends and the next one
 * re-reads. The fetch is unauthenticated, like every other read here.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function fetchPublishedRefs(callable $run, string $key, string $url, string $tagRef, PublicationRefs $refs): void
{
    $localMain = REMOTE_REF_PREFIX . "{$key}/main";
    $localTag = REMOTE_REF_PREFIX . "{$key}/tag";
    $specs = [];

    if ($refs->main !== null) {
        $specs[] = "+refs/heads/main:{$localMain}";
    }

    if ($refs->tag !== null) {
        $specs[] = "+{$tagRef}:{$localTag}";
    }

    if ($specs === []) {
        return;
    }

    $result = $run(['fetch', '--no-tags', '--quiet', '--end-of-options', $url, ...$specs]);

    if ($result['exitCode'] !== 0) {
        throw new ReleaseTransactionFailure(
            'Could not read ' . GITHUB_ORG . "/{$key}: " . redactCredentials($result['stderr']),
        );
    }

    if ($refs->main !== null) {
        requireFetchedRef($run, $key, $localMain, 'main', $refs->main);
    }

    if ($refs->tag === null) {
        return;
    }

    requireFetchedRef($run, $key, $localTag, $tagRef, $refs->tag);

    // ls-remote reports where an annotated tag peels to. This checkout
    // holds the tag object now, so that report is checked rather than
    // trusted — it decides which commit a published version means.
    $peeled = resolveObject($run, "{$localTag}^{commit}", "read the commit {$tagRef} names on {$key}");

    if ($peeled !== $refs->taggedCommit()) {
        throw new ReleaseTransactionFailure(
            GITHUB_ORG . "/{$key} reported {$tagRef} as {$refs->taggedCommit()}, but it names {$peeled} here.",
        );
    }
}

/**
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requireFetchedRef(callable $run, string $key, string $local, string $remote, string $expected): void
{
    $fetched = resolveObject($run, $local, "read the fetched {$remote} of {$key}");

    if ($fetched !== $expected) {
        throw new ReleaseTransactionFailure(
            "{$remote} on " . GITHUB_ORG . "/{$key} moved from {$expected} to {$fetched} while this round was "
            . 'reading it.',
        );
    }
}

/**
 * The whole round, decided before anything is written.
 *
 * Order matters here. Everything that can end the round runs first — a
 * dirty or shallow checkout, a candidate the manifest does not describe,
 * a version whose predecessor was never published, a remote it cannot
 * read, a published version that disagrees with what this round stages,
 * a main sharing no history with the release. Only once every package
 * has a complete, validated set of ref updates does a transaction exist
 * at all, and only apply writes anything. A package
 * failing here means the round writes nothing, including for the
 * packages already validated ahead of it.
 *
 * @param array<string, mixed> $manifest
 * @param list<array{key: string, version: string}> $candidates in publish order
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @param callable(string, string): PublicationRefs $refsFor
 * @param (callable(string): string)|null $urlFor where each split repository lives
 * @throws ReleaseTransactionFailure
 */
function preflightRelease(
    array $manifest,
    array $candidates,
    string $projectRoot,
    callable $run,
    callable $refsFor,
    ?callable $urlFor = null,
): ReleaseTransaction {
    $urlFor ??= splitRepositoryUrl(...);
    $keys = [];

    foreach ($candidates as $candidate) {
        $key = $candidate['key'];
        $entry = $manifest['packages'][$key] ?? null;

        if (!is_array($entry)) {
            throw new ReleaseTransactionFailure("The plan names {$key}, which is not a package in this manifest.");
        }

        if ($entry['version'] !== $candidate['version']) {
            throw new ReleaseTransactionFailure(
                "The plan releases {$key} v{$candidate['version']}, but the manifest carries v{$entry['version']}.",
            );
        }

        $keys[] = $key;
    }

    requirePublishableCheckout($run);
    $source = resolveObject($run, 'HEAD^{commit}', 'resolve the commit being released');

    foreach ($candidates as $candidate) {
        requirePublishedPredecessor($run, $refsFor, $candidate['key'], $candidate['version'], $source);
    }

    $date = sourceCommitDate($run, $source);
    $staged = stageReleaseGroup($manifest, $keys, $source, $date, $projectRoot, $run);
    $publications = [];

    foreach ($candidates as $candidate) {
        $key = $candidate['key'];
        $version = $candidate['version'];
        $tagRef = "refs/tags/v{$version}";
        $refs = $refsFor($key, "v{$version}");
        $url = $urlFor($key);

        fetchPublishedRefs($run, $key, $url, $tagRef, $refs);
        $commit = publicationCommitFor($run, $key, $version, $staged, $refs, $source, $date);

        $publications[] = new PackagePublication(
            $key,
            $version,
            $url,
            $commit,
            refUpdatesFor(
                $key,
                $tagRef,
                $refs,
                $commit,
                static fn (string $ancestor, string $descendant): bool => isAncestorOf($run, $ancestor, $descendant),
            ),
        );
    }

    return new ReleaseTransaction($source, $staged, $publications);
}

/**
 * The checkout has to be able to produce one deterministic release
 * commit. A tracked change already sitting in the index or the worktree
 * would ride into every split repository; truncated history cannot
 * answer which version came before this one.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requirePublishableCheckout(callable $run): void
{
    $shallow = trim(gitOutput($run, ['rev-parse', '--is-shallow-repository'], 'check whether the checkout is shallow'));

    if ($shallow !== 'false') {
        throw new ReleaseTransactionFailure(
            'This is a shallow checkout, so the version before each release cannot be read — fetch with depth 0.',
        );
    }

    $status = gitOutput($run, ['status', '--porcelain', '--untracked-files=no'], 'check the checkout for local changes');

    if (trim($status) !== '') {
        throw new ReleaseTransactionFailure(
            'The checkout carries changes to tracked files, which would be published as part of this release.',
        );
    }
}

/**
 * Writes one repository's refs, or reports why it could not.
 *
 * One push carries the tag and the branch together: --atomic makes the
 * remote apply both or neither, so this round never leaves a repository
 * tagged with a branch that was not updated the way two separate pushes
 * do. Each update carries the exact remote value preflight read, so a
 * concurrent publication that moved the ref first makes this push fail
 * rather than overwrite it.
 *
 * Repositories are still written one at a time, because git has no way
 * to update two of them together. A round interrupted between two
 * repositories leaves earlier ones published and later ones untouched,
 * which the next round reconciles.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $push
 * @throws ReleaseTransactionFailure
 */
function pushPublication(callable $push, PackagePublication $publication): void
{
    if ($publication->updates === []) {
        return;
    }

    $leases = array_map(static fn (RefUpdate $update): string => $update->lease(), $publication->updates);
    $refspecs = array_map(static fn (RefUpdate $update): string => $update->refspec(), $publication->updates);
    $result = $push(['push', '--atomic', ...$leases, '--end-of-options', $publication->url, ...$refspecs]);

    if ($result['exitCode'] === 0) {
        return;
    }

    $stderr = redactCredentials($result['stderr']);

    if (str_contains($stderr, 'atomic') && str_contains($stderr, 'does not support')) {
        throw new ReleaseTransactionFailure(
            $publication->repository() . ' does not accept an atomic push, so its tag and branch cannot be '
            . "updated together: {$stderr}",
        );
    }

    throw new ReleaseTransactionFailure(
        'Could not publish ' . $publication->repository() . " v{$publication->version}: {$stderr}",
    );
}

/**
 * Proves a transaction file describes this checkout's own preflight.
 *
 * The file is the whole input to the one step that holds the deploy
 * credential, so it is a capability rather than a report: it can send a
 * push to any repository the credential opens. Every object it names is
 * therefore re-derived here against the checkout that preflighted it —
 * the commit being released, the release commit staged from it, each
 * package's version and published tree, and the ancestry every lease
 * claims — and a file naming anything this checkout cannot account for
 * publishes nothing. All of it runs before the credential is taken, so
 * such a file never reaches a process that could push at all.
 *
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requireTransactionMatchesCheckout(ReleaseTransaction $transaction, callable $run): void
{
    $source = resolveObject($run, 'HEAD^{commit}', 'resolve the commit being released');

    if ($transaction->source !== $source) {
        throw new ReleaseTransactionFailure(
            "The transaction was preflighted from {$transaction->source}, but this checkout is at {$source}.",
        );
    }

    $staged = resolveObject($run, STAGING_REF, 'read the release commit this round staged');

    if ($transaction->staged !== $staged) {
        throw new ReleaseTransactionFailure(
            "The transaction publishes {$transaction->staged}, which is not the release commit {$staged} this "
            . 'checkout staged.',
        );
    }

    foreach ($transaction->packages as $publication) {
        requirePublicationMatchesCheckout($run, $publication, $staged);
    }
}

/**
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $run
 * @throws ReleaseTransactionFailure
 */
function requirePublicationMatchesCheckout(callable $run, PackagePublication $publication, string $staged): void
{
    $key = $publication->key;
    $carried = manifestVersionAt($run, $staged, $key);

    if ($carried !== $publication->version) {
        throw new ReleaseTransactionFailure(
            "The transaction publishes {$key} v{$publication->version}, which is not the version the staged "
            . 'release commit carries.',
        );
    }

    requirePublicationCommit($run, $key, $publication->commit, stagedPrefixTree($run, $key, $staged));

    // A lease with a value behind it is a branch being moved forward.
    // git enforces the lease against the remote; what it cannot tell is
    // whether the move is a fast-forward, and a rewind is the one thing
    // a publication must never write.
    foreach ($publication->updates as $update) {
        if ($update->expected !== null && !isAncestorOf($run, $update->expected, $update->value)) {
            throw new ReleaseTransactionFailure(
                "The transaction would move {$update->ref} on " . $publication->repository()
                . " from {$update->expected} to {$update->value}, which does not contain it.",
            );
        }
    }
}

/**
 * @param callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} $push
 * @param callable(string): void $report
 * @throws ReleaseTransactionFailure
 */
function applyTransaction(ReleaseTransaction $transaction, callable $push, callable $report): void
{
    foreach ($transaction->packages as $publication) {
        $report("{$publication->key} v{$publication->version} [{$publication->action()}] -> {$publication->commit}");
        pushPublication($push, $publication);
    }
}

/**
 * A git runner that can authenticate to the split repositories, for the
 * one call that writes.
 *
 * The credential reaches git through an askpass helper reading it from
 * that child's own environment. It is in no URL (which git copies into
 * its error text and its own config), no argument (which every process
 * on the machine can read), no config value, and no file — the helper
 * script holds the lookup, never the value.
 *
 * @param callable(array<string, string>): (callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}) $runnerFor
 * @return callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
 */
function authenticatedRunner(
    callable $runnerFor,
    #[SensitiveParameter] string $token,
    string $askpassPath,
): callable {
    /** @var array<string, string> $ambient */
    $ambient = getenv();

    return $runnerFor([
        ...$ambient,
        'GIT_ASKPASS' => $askpassPath,
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'KINETIS_RELEASE_USERNAME' => CREDENTIAL_USERNAME,
        'KINETIS_RELEASE_TOKEN' => $token,
    ]);
}

/** The helper git runs to answer a credential prompt. Holds no credential itself. */
function askpassScript(): string
{
    return <<<'SH'
        #!/bin/sh
        # git asks for the username first and the password second. Both
        # come from this one process's environment; nothing is stored.
        case "$1" in
            Username*) printf '%s\n' "$KINETIS_RELEASE_USERNAME" ;;
            *) printf '%s\n' "$KINETIS_RELEASE_TOKEN" ;;
        esac
        SH;
}

/**
 * Takes the deploy credential out of this process's environment and
 * hands it back.
 *
 * Reading it once and removing it is what keeps every later child — a
 * git read, anything a diagnostic shells out to — from inheriting it.
 * Only authenticatedRunner() puts it back, for one call.
 *
 * @throws ReleaseTransactionFailure
 */
function takeCredential(): string
{
    $token = getenv(CREDENTIAL_VARIABLE);
    putenv(CREDENTIAL_VARIABLE);
    unset($_ENV[CREDENTIAL_VARIABLE], $_SERVER[CREDENTIAL_VARIABLE]);

    if (!is_string($token) || trim($token) === '') {
        throw new ReleaseTransactionFailure(
            CREDENTIAL_VARIABLE . ' is not set, so this round has nothing to publish with.',
        );
    }

    return $token;
}

/**
 * @param list<string> $argv
 * @return array{command: string, options: array<string, string>, problems: list<string>}
 */
function parseTransactionArguments(array $argv): array
{
    $known = ['--plan', '--out', '--transaction', '--root'];
    $command = array_shift($argv) ?? '';
    $options = [];
    $problems = [];

    if (!in_array($command, ['preflight', 'apply'], true)) {
        $problems[] = 'The first argument names the step to run: preflight or apply.';
    }

    foreach ($argv as $arg) {
        $separator = strpos($arg, '=');
        $name = $separator === false ? $arg : substr($arg, 0, $separator);

        if (!in_array($name, $known, true)) {
            $problems[] = "Unknown option: {$arg}";

            continue;
        }

        if (isset($options[$name])) {
            $problems[] = "{$name} is given more than once.";

            continue;
        }

        $value = $separator === false ? '' : trim(substr($arg, $separator + 1));

        if ($value === '') {
            $problems[] = "{$name} needs a value.";

            continue;
        }

        $options[$name] = $value;
    }

    // Only a step that was actually named has required options; an
    // unknown one is already reported, and listing what it would have
    // needed reads as though it were a step.
    $required = match ($command) {
        'preflight' => ['--plan', '--out'],
        'apply' => ['--transaction'],
        default => [],
    };

    foreach ($required as $option) {
        if (!isset($options[$option])) {
            $problems[] = "{$command} needs {$option}.";
        }
    }

    return ['command' => $command, 'options' => $options, 'problems' => $problems];
}

/**
 * The candidates a release plan names, in the order it put them.
 *
 * @return list<array{key: string, version: string}>
 * @throws ReleaseTransactionFailure
 */
function readPlanCandidates(string $json): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ReleaseTransactionFailure('The release plan is not readable JSON: ' . $e->getMessage());
    }

    if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
        throw new ReleaseTransactionFailure(
            'The release plan did not resolve every candidate, so nothing is published.',
        );
    }

    $candidates = $decoded['candidates'] ?? null;

    if (!is_array($candidates)) {
        throw new ReleaseTransactionFailure('The release plan lists no candidates.');
    }

    $parsed = [];

    foreach ($candidates as $candidate) {
        $key = is_array($candidate) ? ($candidate['key'] ?? null) : null;
        $version = is_array($candidate) ? ($candidate['version'] ?? null) : null;

        if (!is_string($key) || preg_match(PACKAGE_KEY_PATTERN, $key) !== 1) {
            throw new ReleaseTransactionFailure('The release plan names a candidate with no usable package key.');
        }

        if (!is_string($version) || parseVersion($version) === null) {
            throw new ReleaseTransactionFailure("The release plan gives {$key} no usable version.");
        }

        $parsed[] = ['key' => $key, 'version' => $version];
    }

    return $parsed;
}

/** @throws ReleaseTransactionFailure */
function readFileOrFail(string $path, string $what): string
{
    $contents = @file_get_contents($path);

    if ($contents === false) {
        throw new ReleaseTransactionFailure("Could not read the {$what} at {$path}.");
    }

    return $contents;
}

/**
 * @param array<string, string> $options
 * @throws ReleaseTransactionFailure
 */
function runPreflight(array $options): int
{
    $projectRoot = $options['--root'] ?? PROJECT_ROOT;
    $manifest = loadManifestOrReport($projectRoot);

    if ($manifest === null) {
        return 1;
    }

    $candidates = readPlanCandidates(readFileOrFail($options['--plan'], 'release plan'));

    if ($candidates === []) {
        echo "Nothing to publish — the release plan names no candidates.\n";

        return 0;
    }

    $transaction = preflightRelease(
        $manifest,
        $candidates,
        $projectRoot,
        gitRunnerFor($projectRoot, REMOTE_TIMEOUT_SECONDS),
        memoizeRefLookups(publicationRefsOnGitHub(...)),
    );

    try {
        writeFileChecked($options['--out'], $transaction->toJson());
    } catch (CheckedWriteFailure $e) {
        throw new ReleaseTransactionFailure('Could not write the transaction: ' . $e->getMessage());
    }

    foreach ($transaction->packages as $publication) {
        echo "{$publication->key} v{$publication->version} [{$publication->action()}] -> {$publication->commit}\n";
    }

    return 0;
}

/**
 * @param array<string, string> $options
 * @throws ReleaseTransactionFailure
 */
function runApply(array $options): int
{
    $projectRoot = $options['--root'] ?? PROJECT_ROOT;
    $run = gitRunnerFor($projectRoot, REMOTE_TIMEOUT_SECONDS);

    // Everything that can refuse the round runs while this process
    // still holds no credential to push with.
    $transaction = ReleaseTransaction::fromJson(readFileOrFail($options['--transaction'], 'transaction'));
    requireTransactionMatchesCheckout($transaction, $run);

    $askpass = tempnam(sys_get_temp_dir(), 'kinetis-askpass-');

    if ($askpass === false) {
        throw new ReleaseTransactionFailure('Could not create the askpass helper this publication needs.');
    }

    try {
        if (@file_put_contents($askpass, askpassScript() . "\n") === false) {
            throw new ReleaseTransactionFailure("Could not write the askpass helper at {$askpass}.");
        }

        // git runs the helper, so a mode this did not set means the
        // credential would be answered by whatever else can read it.
        if (!@chmod($askpass, 0o700)) {
            throw new ReleaseTransactionFailure("Could not restrict the askpass helper at {$askpass} to this user.");
        }

        applyTransaction(
            $transaction,
            authenticatedRunner(
                static fn (#[SensitiveParameter] array $environment): callable => gitRunnerFor(
                    $projectRoot,
                    REMOTE_TIMEOUT_SECONDS,
                    GIT_OUTPUT_LIMIT,
                    null,
                    $environment,
                ),
                takeCredential(),
                $askpass,
            ),
            static function (string $line): void {
                echo "{$line}\n";
            },
        );
    } finally {
        @unlink($askpass);
    }

    return 0;
}

/**
 * @param list<string> $argv
 */
function transactionMain(array $argv = []): int
{
    $arguments = parseTransactionArguments(array_slice($argv, 1));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    try {
        return $arguments['command'] === 'apply'
            ? runApply($arguments['options'])
            : runPreflight($arguments['options']);
    } catch (ReleaseTransactionFailure $e) {
        fwrite(STDERR, redactCredentials($e->getMessage()) . "\n");

        return 1;
    }
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(transactionMain($argv ?? []));
}
