<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use NativeProcessBoundary;
use PackagePublication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProcessBoundary;
use PublicationRefs;
use RefUpdate;
use ReleaseTransaction;
use ReleaseTransactionFailure;
use RuntimeException;

require_once __DIR__ . '/../release-transaction.php';

/**
 * The real process boundary with its first wait replaced by an error
 * nobody expects.
 *
 * A returned failure is a value the runner handles; a thrown one is the
 * case this exists for, since PHP renders the arguments of every frame
 * it unwinds — and one of those frames is holding the credential.
 */
final class ThrowingProcessBoundary implements ProcessBoundary
{
    private readonly NativeProcessBoundary $real;

    public function __construct()
    {
        $this->real = new NativeProcessBoundary();
    }

    public function select(array &$read, float $seconds): int|false
    {
        throw new RuntimeException('the wait went wrong in a way nobody planned for');
    }

    public function read(mixed $stream, int $length): string|false
    {
        return $this->real->read($stream, $length);
    }

    public function atEnd(mixed $stream): bool
    {
        return $this->real->atEnd($stream);
    }

    public function closeStream(mixed $stream): void
    {
        $this->real->closeStream($stream);
    }

    /** @return array{running: bool, exitcode: int} */
    public function status(mixed $process): array
    {
        return $this->real->status($process);
    }

    public function terminate(mixed $process, int $signal): bool
    {
        return $this->real->terminate($process, $signal);
    }

    public function closeProcess(mixed $process): int
    {
        return $this->real->closeProcess($process);
    }
}

/**
 * The publication, against real repositories wherever the answer depends
 * on git rather than on this code.
 *
 * Every remote here is a real bare repository in a temporary directory,
 * reached by path. Ref state, ancestry, atomic pushes and lease
 * rejection are all things git decides, and a fake that answers for it
 * would be asserting this test's own idea of git rather than git's.
 */
final class ReleaseTransactionTest extends TestCase
{
    /** A commit id no fixture ever produces, for the states git is not asked about. */
    private const ABSENT_COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @see self::ABSENT_COMMIT */
    private const OTHER_COMMIT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @see self::ABSENT_COMMIT */
    private const THIRD_COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            exec('rm -rf ' . escapeshellarg($directory));
        }

        $this->directories = [];
    }

    // --- what a publication commit has to be ------------------------------

    /**
     * Nothing downstream builds a refspec from an id that has not been
     * proven to be a commit carrying this round's own content: an id
     * that is not one is how content nobody staged reaches a published
     * repository.
     */
    public function test_a_publication_that_names_something_that_is_not_a_commit_is_refused(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $head = trim($this->git($repository, 'rev-parse', 'HEAD'));
        $run = gitRunnerFor($repository);
        $tree = trim($this->git($repository, 'rev-parse', 'HEAD^{tree}'));

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/rather than a commit/');

        requirePublicationCommit($run, 'queue', $tree, stagedPrefixTree($run, 'queue', $head));
    }

    public function test_a_publication_whose_tree_is_not_the_prefix_is_refused(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0'], 'storage' => ['version' => '1.0.0']]);
        $head = trim($this->git($repository, 'rev-parse', 'HEAD'));
        $run = gitRunnerFor($repository);
        $wrong = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', "{$head}:packages/storage")));

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/does not describe what this round is publishing/');

        requirePublicationCommit($run, 'queue', $wrong, stagedPrefixTree($run, 'queue', $head));
    }

    /**
     * A version already on the remote is verified rather than rebuilt.
     * Content that disagrees with it is a re-pointed tag, whatever
     * parent a new commit would have been given.
     */
    public function test_a_published_version_carrying_other_content_is_never_re_pointed(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0'], 'storage' => ['version' => '1.0.0']]);
        $head = trim($this->git($repository, 'rev-parse', 'HEAD'));
        $run = gitRunnerFor($repository);
        $published = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', "{$head}:packages/storage")));

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/never re-pointed/');

        requirePublishedVersion($run, 'queue', '1.0.0', $published, stagedPrefixTree($run, 'queue', $head));
    }

    // --- what the remote state means -------------------------------------

    public function test_an_unpublished_package_gets_both_refs_with_absence_leased(): void
    {
        $updates = refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(null, null, null),
            self::ABSENT_COMMIT,
            $this->neverAsked(),
        );

        self::assertSame(['refs/tags/v1.0.0', 'refs/heads/main'], array_map(static fn (RefUpdate $u): string => $u->ref, $updates));
        self::assertSame('--force-with-lease=refs/tags/v1.0.0:', $updates[0]->lease());
        self::assertSame('--force-with-lease=refs/heads/main:', $updates[1]->lease());
    }

    public function test_a_finished_package_needs_nothing(): void
    {
        $updates = refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::ABSENT_COMMIT, null, self::ABSENT_COMMIT),
            self::ABSENT_COMMIT,
            $this->neverAsked(),
        );

        self::assertSame([], $updates);
    }

    /** The half-published round: the tag is right, main never caught up. */
    public function test_a_correct_tag_with_a_stale_main_repairs_only_main(): void
    {
        $updates = refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::ABSENT_COMMIT, null, self::OTHER_COMMIT),
            self::ABSENT_COMMIT,
            static fn (string $ancestor, string $descendant): bool => $ancestor === self::OTHER_COMMIT,
        );

        self::assertCount(1, $updates);
        self::assertSame('refs/heads/main', $updates[0]->ref);
        self::assertSame(self::OTHER_COMMIT, $updates[0]->expected);
        self::assertSame(self::ABSENT_COMMIT, $updates[0]->value);
    }

    public function test_a_main_that_already_carries_a_newer_release_is_never_rolled_back(): void
    {
        $updates = refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::ABSENT_COMMIT, null, self::OTHER_COMMIT),
            self::ABSENT_COMMIT,
            static fn (string $ancestor, string $descendant): bool => $ancestor === self::ABSENT_COMMIT,
        );

        self::assertSame([], $updates);
    }

    public function test_a_tag_naming_a_different_commit_ends_the_round(): void
    {
        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/never re-pointed|names/');

        refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::OTHER_COMMIT, null, self::OTHER_COMMIT),
            self::ABSENT_COMMIT,
            $this->neverAsked(),
        );
    }

    public function test_an_annotated_tag_is_judged_by_the_commit_it_peels_to(): void
    {
        $updates = refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::OTHER_COMMIT, self::ABSENT_COMMIT, self::ABSENT_COMMIT),
            self::ABSENT_COMMIT,
            $this->neverAsked(),
        );

        self::assertSame([], $updates);
    }

    public function test_a_main_sharing_no_history_ends_the_round(): void
    {
        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/shares no history/');

        refUpdatesFor(
            'queue',
            'refs/tags/v1.0.0',
            new PublicationRefs(self::ABSENT_COMMIT, null, self::OTHER_COMMIT),
            self::ABSENT_COMMIT,
            static fn (string $ancestor, string $descendant): bool => false,
        );
    }

    public function test_an_indeterminate_ancestry_answer_ends_the_round(): void
    {
        $run = static fn (array $args): array => [
            'exitCode' => 128,
            'stdout' => '',
            'stderr' => 'fatal: not a git repository',
            'timedOut' => false,
            'truncated' => false,
        ];

        $this->expectException(ReleaseTransactionFailure::class);

        isAncestorOf($run, self::ABSENT_COMMIT, self::OTHER_COMMIT);
    }

    // --- the whole round, against real repositories -----------------------

    public function test_a_first_release_publishes_the_tag_and_the_branch(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);

        $pushed = $this->publish($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        self::assertSame('publish', $pushed->packages[0]->action());
        self::assertSame($pushed->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
        self::assertSame($pushed->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    /**
     * Two release states in quick succession — the shape a serialized
     * publication group has to survive. The second round must leave the
     * first version tagged where it was and main on the newer release.
     */
    public function test_a_second_release_moves_main_forward_and_leaves_the_first_tag_alone(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $first = $this->publish($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $second = $this->publish($repository, $remotes, [['key' => 'queue', 'version' => '1.0.1']]);

        self::assertNotSame($first->packages[0]->commit, $second->packages[0]->commit);
        self::assertSame($first->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.1'));
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    /**
     * The regression this design exists for.
     *
     * Each round runs in a checkout that has never seen the one before
     * it, so the only thing tying two releases together is what the
     * published repository already carries. A round that derived its
     * ancestry from anything local — a cached mapping, a ref left behind
     * by an earlier run — would produce a second release sharing no
     * history with the first, and every later publication would refuse
     * to move main.
     */
    public function test_two_consecutive_releases_from_independent_clones_publish_one_line_of_history(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $candidate = static fn (string $version): array => [['key' => 'queue', 'version' => $version]];

        $first = $this->publish($this->cloneOf($repository), $remotes, $candidate('1.0.0'));
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $second = $this->publish($this->cloneOf($repository), $remotes, $candidate('1.0.1'));

        $published = "{$remotes}/queue.git";

        self::assertSame(
            $first->packages[0]->commit,
            trim($this->git($published, 'rev-parse', "{$second->packages[0]->commit}^")),
        );
        self::assertSame(
            0,
            runGit(
                ['merge-base', '--is-ancestor', $first->packages[0]->commit, $second->packages[0]->commit],
                $published,
            )['exitCode'],
        );
        self::assertSame($first->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    /**
     * main is where a release is built, not the tag before it. A branch
     * carrying something the monorepo never published still ends up
     * behind the next release rather than beside it.
     */
    public function test_a_release_is_built_on_whatever_main_carries(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        $ahead = $this->advanceRemoteMain($remotes, 'queue');

        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $second = $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.1']]);

        self::assertSame(
            $ahead,
            trim($this->git("{$remotes}/queue.git", 'rev-parse', "{$second->packages[0]->commit}^")),
        );
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    /** Re-running a finished round writes nothing at all. */
    public function test_a_finished_round_is_a_no_op_when_it_runs_again(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $this->publish($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        $pushes = [];
        $again = $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        applyTransaction($again, $this->recordingPush($pushes), static function (string $line): void {
        });

        self::assertSame('none', $again->packages[0]->action());
        self::assertSame([], $pushes);
    }

    /**
     * A round interrupted after its tag landed, finished from a checkout
     * that never ran it. The tag is verified against what the round
     * stages and kept exactly where it is; only the branch moves.
     */
    public function test_a_tag_that_landed_without_its_branch_is_repaired_on_the_next_round(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $first = $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $second = $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.1']]);

        // The state a tag push that landed without its branch leaves
        // behind: v1.0.1 is tagged, main still carries v1.0.0.
        $this->git("{$remotes}/queue.git", 'update-ref', 'refs/heads/main', $first->packages[0]->commit);

        $recovery = $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.1']]);

        self::assertSame('repair-main', $recovery->packages[0]->action());
        self::assertSame($second->packages[0]->commit, $recovery->packages[0]->commit);
        self::assertSame($first->packages[0]->commit, $recovery->packages[0]->updates[0]->expected);
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
        self::assertSame($second->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.1'));
    }

    public function test_a_main_already_ahead_of_the_round_is_left_where_it_is(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $this->publish($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        $ahead = $this->advanceRemoteMain($remotes, 'queue');

        $round = $this->preflight($this->cloneOf($repository), $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        self::assertSame('none', $round->packages[0]->action());
        self::assertSame($ahead, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    public function test_a_tag_already_naming_another_commit_stops_the_round_before_any_write(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $foreign = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', 'HEAD^{tree}')));
        $this->git($repository, 'push', "{$remotes}/queue.git", "{$foreign}:refs/tags/v1.0.0");

        try {
            $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
            self::fail('a conflicting tag must end the round');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('re-pointed', $e->getMessage());
        }

        self::assertSame($foreign, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
        self::assertNull($this->remoteRef($remotes, 'queue', 'refs/heads/main'));
    }

    /**
     * A published version fixes what the round publishes, so a branch
     * that went somewhere else is a repository nothing here can
     * reconcile. Moving main onto the tag would discard whatever it
     * carries.
     */
    public function test_a_main_of_unrelated_history_stops_the_round_before_any_write(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $published = $this->publish($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        $foreign = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', 'HEAD^{tree}')));
        $this->git($repository, 'push', '--force', "{$remotes}/queue.git", "{$foreign}:refs/heads/main");

        try {
            $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
            self::fail('an unrelated main must end the round');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('shares no history', $e->getMessage());
        }

        self::assertSame($foreign, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
        self::assertSame($published->packages[0]->commit, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
    }

    /**
     * A package failing preflight means the round writes nothing, not
     * even for the packages already validated ahead of it.
     */
    public function test_a_later_package_failing_preflight_leaves_every_earlier_one_unpublished(): void
    {
        $repository = $this->monorepo([
            'framework' => ['version' => '1.0.0'],
            'queue' => ['version' => '1.0.0', 'requires' => ['framework']],
        ]);
        $remotes = $this->remotesFor(['framework', 'queue']);
        $foreign = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', 'HEAD^{tree}')));
        $this->git($repository, 'push', "{$remotes}/queue.git", "{$foreign}:refs/tags/v1.0.0");

        try {
            $this->preflight($repository, $remotes, [
                ['key' => 'framework', 'version' => '1.0.0'],
                ['key' => 'queue', 'version' => '1.0.0'],
            ]);
            self::fail('a published version that disagrees with the round must end it');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('never re-pointed', $e->getMessage());
        }

        self::assertNull($this->remoteRef($remotes, 'framework', 'refs/tags/v1.0.0'));
        self::assertSame($foreign, $this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
    }

    /**
     * A push that fails partway leaves earlier repositories published, so
     * the next round has to finish the rest and leave the first alone.
     */
    public function test_a_round_that_fails_partway_is_finished_by_the_next_one(): void
    {
        $repository = $this->monorepo([
            'framework' => ['version' => '1.0.0'],
            'queue' => ['version' => '1.0.0', 'requires' => ['framework']],
        ]);
        $remotes = $this->remotesFor(['framework', 'queue']);
        $candidates = [['key' => 'framework', 'version' => '1.0.0'], ['key' => 'queue', 'version' => '1.0.0']];
        $round = $this->preflight($repository, $remotes, $candidates);
        $real = gitRunnerFor($repository);

        try {
            applyTransaction(
                $round,
                static fn (array $args): array => in_array("{$remotes}/queue.git", $args, true)
                    ? ['exitCode' => 1, 'stdout' => '', 'stderr' => 'fatal: the remote hung up', 'timedOut' => false, 'truncated' => false]
                    : $real($args),
                static function (string $line): void {
                },
            );
            self::fail('a failed push must end the round');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('Could not publish', $e->getMessage());
        }

        self::assertNotNull($this->remoteRef($remotes, 'framework', 'refs/tags/v1.0.0'));
        self::assertNull($this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));

        $retry = $this->publish($repository, $remotes, $candidates);

        self::assertSame('none', $retry->packages[0]->action());
        self::assertSame('publish', $retry->packages[1]->action());
        self::assertNotNull($this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
    }

    /**
     * A lease is what one preflight read, and a remote that moved since
     * makes it stale. The push has to lose rather than overwrite work
     * this round never saw.
     */
    public function test_a_lease_that_has_gone_stale_refuses_the_push(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $round = $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);
        $foreign = $this->commitTree($repository, trim($this->git($repository, 'rev-parse', 'HEAD^{tree}')));
        $this->git($repository, 'push', "{$remotes}/queue.git", "{$foreign}:refs/heads/main");

        try {
            $this->apply($repository, $round);
            self::fail('a stale lease must refuse the push');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('Could not publish', $e->getMessage());
        }

        self::assertSame($foreign, $this->remoteRef($remotes, 'queue', 'refs/heads/main'));
        self::assertNull($this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.0'));
    }

    /**
     * The dev graph has a real cycle — persistence and mcp each carry the
     * other as a development dependency. The round stages and publishes
     * both; only runtime requires order anything.
     */
    public function test_a_development_dependency_cycle_publishes_both_packages(): void
    {
        $repository = $this->monorepo([
            'framework' => ['version' => '1.0.0'],
            'persistence' => ['version' => '1.0.0', 'requires' => ['framework'], 'requiresDev' => ['mcp']],
            'mcp' => ['version' => '1.0.0', 'requires' => ['framework'], 'requiresDev' => ['persistence']],
        ]);
        $remotes = $this->remotesFor(['framework', 'persistence', 'mcp']);
        $manifest = $this->manifestOf($repository);
        $order = publishOrder($manifest, ['framework', 'persistence', 'mcp']);
        $candidates = array_map(static fn (string $key): array => ['key' => $key, 'version' => '1.0.0'], $order);

        $round = $this->publish($repository, $remotes, $candidates);

        self::assertSame('framework', $round->packages[0]->key);

        foreach (['framework', 'persistence', 'mcp'] as $key) {
            self::assertNotNull($this->remoteRef($remotes, $key, 'refs/tags/v1.0.0'), $key);
            self::assertNotNull($this->remoteRef($remotes, $key, 'refs/heads/main'), $key);
        }

        $released = json_decode(
            trim($this->git($repository, 'show', "{$round->staged}:packages/persistence/composer.json")),
            true,
        );

        self::assertSame('^1.0.0', $released['require-dev']['kinetis/mcp']);
    }

    // --- the version before this one --------------------------------------

    public function test_a_version_whose_predecessor_was_never_published_ends_the_round(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);

        try {
            $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.1']]);
            self::fail('an unpublished predecessor must end the round');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringContainsString('v1.0.0', $e->getMessage());
            self::assertStringContainsString('Release the commit', $e->getMessage());
        }

        self::assertNull($this->remoteRef($remotes, 'queue', 'refs/tags/v1.0.1'));
    }

    public function test_a_package_the_manifest_has_only_ever_carried_at_one_version_has_no_predecessor(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.2.0']]);
        $run = gitRunnerFor($repository);

        self::assertNull(previousManifestVersion($run, 'queue', '1.2.0', trim($this->git($repository, 'rev-parse', 'HEAD'))));
    }

    public function test_the_previous_version_is_read_from_the_manifest_history(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.1.0']]);
        $run = gitRunnerFor($repository);

        self::assertSame(
            '1.0.1',
            previousManifestVersion($run, 'queue', '1.1.0', trim($this->git($repository, 'rev-parse', 'HEAD'))),
        );
    }

    /** A package added to an existing manifest starts its own line. */
    public function test_a_package_added_later_has_no_predecessor(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1'], 'storage' => ['version' => '1.0.0']]);
        $run = gitRunnerFor($repository);

        self::assertNull(previousManifestVersion($run, 'storage', '1.0.0', trim($this->git($repository, 'rev-parse', 'HEAD'))));
    }

    public function test_a_predecessor_the_version_policy_rejects_ends_the_round(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.4.0']]);
        $run = gitRunnerFor($repository);

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/version policy rejects/');

        requirePublishedPredecessor(
            $run,
            static fn (string $repo, string $tag): PublicationRefs => new PublicationRefs(self::ABSENT_COMMIT, null, null),
            'queue',
            '1.4.0',
            trim($this->git($repository, 'rev-parse', 'HEAD')),
        );
    }

    // --- staging ----------------------------------------------------------

    /**
     * Two independent runs of one source commit have to produce the same
     * objects, or every retry force-pushes a new commit that carries
     * identical content.
     */
    public function test_one_source_commit_stages_and_publishes_the_same_objects_every_time(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $clone = $this->cloneOf($repository);
        $remotes = $this->remotesFor(['queue']);
        $candidates = [['key' => 'queue', 'version' => '1.0.0']];

        $first = $this->preflight($repository, $remotes, $candidates);
        $second = $this->preflight($clone, $remotes, $candidates);

        self::assertSame($first->source, $second->source);
        self::assertSame($first->staged, $second->staged);
        self::assertSame($first->packages[0]->commit, $second->packages[0]->commit);
    }

    public function test_staging_leaves_the_checkout_as_it_found_it(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $head = trim($this->git($repository, 'rev-parse', 'HEAD'));

        $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        self::assertSame($head, trim($this->git($repository, 'rev-parse', 'HEAD')));
        self::assertSame('', trim($this->git($repository, 'status', '--porcelain', '--untracked-files=no')));
    }

    public function test_a_checkout_carrying_local_changes_cannot_stage_a_release(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        file_put_contents("{$repository}/packages/queue/src/Thing.php", "<?php // edited\n");

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/changes to tracked files/');

        requirePublishableCheckout(gitRunnerFor($repository));
    }

    public function test_a_shallow_checkout_cannot_stage_a_release(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $this->bumpTo($repository, ['queue' => ['version' => '1.0.1']]);
        $shallow = $this->scratch('shallow');
        $this->git($repository, 'clone', '--depth', '1', '-q', 'file://' . $repository, $shallow);

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/shallow/');

        requirePublishableCheckout(gitRunnerFor($shallow));
    }

    // --- the transaction file ---------------------------------------------

    public function test_a_transaction_round_trips_through_its_own_file(): void
    {
        $transaction = new ReleaseTransaction(self::ABSENT_COMMIT, self::OTHER_COMMIT, [
            new PackagePublication('queue', '1.0.0', splitRepositoryUrl('queue'), self::ABSENT_COMMIT, [
                new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT),
                new RefUpdate('refs/heads/main', self::OTHER_COMMIT, self::ABSENT_COMMIT),
            ]),
        ]);

        $read = ReleaseTransaction::fromJson($transaction->toJson());

        self::assertSame($transaction->toJson(), $read->toJson());
        self::assertSame('publish', $read->packages[0]->action());
    }

    /**
     * A structurally complete transaction document, so a case below can
     * change exactly one thing about it and nothing else.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function transactionDocument(array $overrides = []): array
    {
        return [
            'source' => self::ABSENT_COMMIT,
            'staged' => self::OTHER_COMMIT,
            'packages' => [[
                'key' => 'queue',
                'version' => '1.0.0',
                'url' => splitRepositoryUrl('queue'),
                'commit' => self::THIRD_COMMIT,
                'action' => 'publish',
                'updates' => [
                    ['ref' => 'refs/tags/v1.0.0', 'expected' => null, 'value' => self::THIRD_COMMIT],
                    ['ref' => 'refs/heads/main', 'expected' => self::OTHER_COMMIT, 'value' => self::THIRD_COMMIT],
                ],
            ]],
            ...$overrides,
        ];
    }

    /**
     * Everything a transaction file may not say.
     *
     * The file is the whole input to the one process that holds a
     * credential able to write to every split repository, so each of
     * these is a way of turning that process into a push of something
     * this code never computed.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableTransactions(): iterable
    {
        yield 'no document' => [[]];
        yield 'a key nothing here writes' => [self::transactionDocument(['note' => 'hello'])];
        yield 'no staged commit' => [['source' => self::ABSENT_COMMIT, 'packages' => []]];
        yield 'a source that is not an object id' => [self::transactionDocument(['source' => 'HEAD'])];

        $entry = static function (array $changes): array {
            $document = self::transactionDocument();
            $document['packages'][0] = [...$document['packages'][0], ...$changes];

            return $document;
        };

        yield 'a package entry missing a key' => [$entry(['action' => null, 'updates' => null])];
        yield 'a foreign repository' => [$entry(['url' => 'https://example.invalid/queue.git'])];
        yield 'a commit that is not an object id' => [$entry(['commit' => ''])];
        yield 'a version that is not a version' => [$entry(['version' => 'latest'])];
        yield 'an action its updates do not match' => [$entry(['action' => 'none'])];

        $updates = static fn (array $list): array => $entry(['updates' => $list]);
        $tag = ['ref' => 'refs/tags/v1.0.0', 'expected' => null, 'value' => self::THIRD_COMMIT];
        $main = ['ref' => 'refs/heads/main', 'expected' => null, 'value' => self::THIRD_COMMIT];

        yield 'a ref this publication never writes' => [$updates([[...$tag, 'ref' => 'refs/heads/gh-pages']])];
        yield 'a ref for another version' => [$updates([[...$tag, 'ref' => 'refs/tags/v9.9.9']])];
        yield 'a ref that is not a ref at all' => [$updates([[...$tag, 'ref' => '--upload-pack=touch']])];
        yield 'a value that is not this entry\'s commit' => [$updates([[...$tag, 'value' => self::OTHER_COMMIT]])];
        yield 'a value that is not an object id' => [$updates([[...$tag, 'value' => '']])];
        yield 'an expected value that is not an object id' => [$updates([$tag, [...$main, 'expected' => 'main']])];
        yield 'a lease on the version being published' => [$updates([[...$tag, 'expected' => self::OTHER_COMMIT]])];
        yield 'the same ref twice' => [$updates([$tag, $tag])];
        yield 'an update with a key nothing here writes' => [$updates([[...$tag, 'force' => true]])];
        yield 'an update that is not an object' => [$updates(['refs/heads/main'])];

        $twice = self::transactionDocument();
        $twice['packages'][] = $twice['packages'][0];

        yield 'the same package twice' => [$twice];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('unusableTransactions')]
    public function test_a_transaction_that_names_no_usable_update_is_refused(array $document): void
    {
        $this->expectException(ReleaseTransactionFailure::class);

        ReleaseTransaction::fromJson(self::encode($document));
    }

    public function test_a_transaction_that_is_not_readable_json_is_refused(): void
    {
        $this->expectException(ReleaseTransactionFailure::class);

        ReleaseTransaction::fromJson('nope');
    }

    public function test_an_unusable_transaction_value_is_described_without_letting_the_file_run_away(): void
    {
        $document = self::transactionDocument();
        $document['packages'][0]['updates'][0]['ref'] = str_repeat('x', 5000);

        try {
            ReleaseTransaction::fromJson(self::encode($document));
            self::fail('a ref this publication never writes must be refused');
        } catch (ReleaseTransactionFailure $e) {
            self::assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function test_no_refspec_a_transaction_produces_can_delete_a_ref(): void
    {
        $update = new RefUpdate('refs/heads/main', null, self::ABSENT_COMMIT);

        self::assertSame(self::ABSENT_COMMIT . ':refs/heads/main', $update->refspec());
        self::assertStringStartsNotWith(':', $update->refspec());
    }

    // --- the transaction as a capability -----------------------------------

    public function test_a_preflighted_transaction_is_accepted_by_the_checkout_that_wrote_it(): void
    {
        ['repository' => $repository, 'document' => $document] = $this->preflightedTransaction();
        $transaction = ReleaseTransaction::fromJson(self::encode($document));

        requireTransactionMatchesCheckout($transaction, gitRunnerFor($repository));

        self::assertSame('publish', $transaction->packages[0]->action());
    }

    /**
     * Every way a transaction file can name something the checkout that
     * preflighted it did not produce.
     *
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function tamperedTransactions(): iterable
    {
        yield 'a source commit from somewhere else' => [static function (array $document): array {
            $document['source'] = self::ABSENT_COMMIT;

            return $document;
        }];

        yield 'a release commit this checkout never staged' => [static function (array $document): array {
            $document['staged'] = $document['source'];

            return $document;
        }];

        yield 'a commit that publishes another tree' => [static function (array $document): array {
            $document['packages'][0]['commit'] = $document['staged'];

            foreach (array_keys($document['packages'][0]['updates']) as $index) {
                $document['packages'][0]['updates'][$index]['value'] = $document['staged'];
            }

            return $document;
        }];

        yield 'a version the staged release commit does not carry' => [static function (array $document): array {
            $document['packages'][0]['version'] = '2.0.0';
            $document['packages'][0]['updates'][0]['ref'] = 'refs/tags/v2.0.0';

            return $document;
        }];

        yield 'a lease the published commit does not contain' => [static function (array $document): array {
            $document['packages'][0]['action'] = 'repair-main';
            $document['packages'][0]['updates'] = [[
                'ref' => 'refs/heads/main',
                'expected' => $document['source'],
                'value' => $document['packages'][0]['commit'],
            ]];

            return $document;
        }];
    }

    /**
     * The file decides where the one credential-bearing process pushes,
     * so a file this checkout cannot account for has to be refused
     * before that process has a credential at all. The token still
     * sitting in the environment is what proves it was never taken.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $tamper
     */
    #[DataProvider('tamperedTransactions')]
    public function test_a_tampered_transaction_takes_no_credential_and_pushes_nothing(callable $tamper): void
    {
        ['repository' => $repository, 'document' => $document] = $this->preflightedTransaction();
        $path = "{$repository}/transaction.json";
        file_put_contents($path, self::encode($tamper($document)));
        putenv(CREDENTIAL_VARIABLE . '=ghp_secretvaluenobodyshouldsee');

        try {
            runApply(['--transaction' => $path, '--root' => $repository]);
            self::fail('a transaction this checkout cannot account for must be refused');
        } catch (ReleaseTransactionFailure $e) {
            self::assertSame('ghp_secretvaluenobodyshouldsee', getenv(CREDENTIAL_VARIABLE));
        } finally {
            putenv(CREDENTIAL_VARIABLE);
        }
    }

    /**
     * A transaction file for a real preflighted round, naming the split
     * repositories the way release.yml's own preflight step writes it
     * rather than this test's local remotes.
     *
     * @return array{repository: string, document: array<string, mixed>}
     */
    private function preflightedTransaction(): array
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);
        $transaction = $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.0']]);

        /** @var array<string, mixed> $document */
        $document = json_decode($transaction->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $document['packages'][0]['url'] = splitRepositoryUrl('queue');

        return ['repository' => $repository, 'document' => $document];
    }

    /** @param array<string, mixed> $document */
    private static function encode(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // --- the plan this reads ----------------------------------------------

    public function test_a_plan_with_unresolved_candidates_publishes_nothing(): void
    {
        $this->expectException(ReleaseTransactionFailure::class);

        readPlanCandidates('{"ok": false, "candidates": [{"key": "queue", "version": "1.0.0", "problems": ["x"]}]}');
    }

    public function test_a_plan_keeps_the_order_it_was_written_in(): void
    {
        $candidates = readPlanCandidates(
            '{"ok": true, "candidates": [{"key": "framework", "version": "1.0.0"}, {"key": "queue", "version": "1.2.3"}]}',
        );

        self::assertSame([['key' => 'framework', 'version' => '1.0.0'], ['key' => 'queue', 'version' => '1.2.3']], $candidates);
    }

    /** @return iterable<string, array{string}> */
    public static function unusablePlans(): iterable
    {
        yield 'not JSON' => ['nope'];
        yield 'no candidate list' => ['{"ok": true}'];
        yield 'a candidate with no key' => ['{"ok": true, "candidates": [{"version": "1.0.0"}]}'];
        yield 'a key that is not a package key' => ['{"ok": true, "candidates": [{"key": "../x", "version": "1.0.0"}]}'];
        yield 'a candidate with no version' => ['{"ok": true, "candidates": [{"key": "queue", "version": "latest"}]}'];
    }

    #[DataProvider('unusablePlans')]
    public function test_an_unusable_plan_is_refused(string $json): void
    {
        $this->expectException(ReleaseTransactionFailure::class);

        readPlanCandidates($json);
    }

    public function test_a_plan_naming_a_package_the_manifest_does_not_have_ends_the_round(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/not a package in this manifest/');

        $this->preflight($repository, $remotes, [['key' => 'storage', 'version' => '1.0.0']]);
    }

    public function test_a_plan_disagreeing_with_the_manifest_version_ends_the_round(): void
    {
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $remotes = $this->remotesFor(['queue']);

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/but the manifest carries/');

        $this->preflight($repository, $remotes, [['key' => 'queue', 'version' => '1.0.1']]);
    }

    // --- arguments ---------------------------------------------------------

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidInvocations(): iterable
    {
        yield 'no step' => [[], 'The first argument names the step to run: preflight or apply.'];
        yield 'an unknown step' => [['publish'], 'The first argument names the step to run: preflight or apply.'];
        yield 'an unknown option' => [['preflight', '--dry-run'], 'Unknown option: --dry-run'];
        yield 'an empty plan' => [['preflight', '--plan=', '--out=x'], '--plan needs a value.'];
        yield 'a repeated plan' => [['preflight', '--plan=a', '--plan=b', '--out=x'], '--plan is given more than once.'];
        yield 'a preflight with no out' => [['preflight', '--plan=a'], 'preflight needs --out.'];
        yield 'an apply with no transaction' => [['apply'], 'apply needs --transaction.'];
    }

    /** @param list<string> $args */
    #[DataProvider('invalidInvocations')]
    public function test_an_invalid_invocation_is_refused(array $args, string $expected): void
    {
        self::assertContains($expected, parseTransactionArguments($args)['problems']);
    }

    public function test_an_unnamed_step_is_not_told_which_options_it_would_have_needed(): void
    {
        self::assertSame(
            ['The first argument names the step to run: preflight or apply.'],
            parseTransactionArguments([])['problems'],
        );
    }

    public function test_a_valid_invocation_carries_its_options_through(): void
    {
        $parsed = parseTransactionArguments(['preflight', '--plan=plan.json', '--out=t.json', '--root=/app']);

        self::assertSame([], $parsed['problems']);
        self::assertSame('preflight', $parsed['command']);
        self::assertSame('plan.json', $parsed['options']['--plan']);
        self::assertSame('/app', $parsed['options']['--root']);
    }

    // --- the credential -----------------------------------------------------

    public function test_a_missing_credential_stops_apply_rather_than_publishing_anonymously(): void
    {
        putenv(CREDENTIAL_VARIABLE);

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/is not set/');

        takeCredential();
    }

    /**
     * Reading the credential takes it out of this process, so no later
     * child — a git read, a diagnostic — inherits it.
     */
    public function test_reading_the_credential_removes_it_from_the_environment(): void
    {
        putenv(CREDENTIAL_VARIABLE . '=ghp_secretvaluenobodyshouldsee');

        try {
            self::assertSame('ghp_secretvaluenobodyshouldsee', takeCredential());
            self::assertFalse(getenv(CREDENTIAL_VARIABLE));
            self::assertArrayNotHasKey(CREDENTIAL_VARIABLE, getenv());
        } finally {
            putenv(CREDENTIAL_VARIABLE);
        }
    }

    public function test_the_askpass_helper_holds_no_credential_of_its_own(): void
    {
        self::assertStringNotContainsString('ghp_', askpassScript());
        self::assertStringContainsString('$KINETIS_RELEASE_TOKEN', askpassScript());
    }

    /**
     * An error nobody catches is printed with a stack trace, and PHP
     * puts every frame's arguments in that trace. The environment handed
     * to the one authenticated child is the only argument here carrying
     * the credential, so every function it passes through on the way to
     * the child declares it sensitive.
     */
    public function test_an_unexpected_error_cannot_render_the_credential_the_call_was_carrying(): void
    {
        $token = 'ghp_secretvaluenobodyshouldsee';
        $repository = $this->monorepo(['queue' => ['version' => '1.0.0']]);
        $run = gitRunnerFor($repository, 5, GIT_OUTPUT_LIMIT, new ThrowingProcessBoundary(), [
            'KINETIS_RELEASE_TOKEN' => $token,
        ]);

        try {
            $run(['rev-parse', 'HEAD']);
            self::fail('the boundary must fail the call');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($token, $e->getTraceAsString());
            self::assertStringContainsString('SensitiveParameterValue', $e->getTraceAsString());
        }
    }

    /**
     * git copies a remote URL into its own error text and its own
     * config, and every process on the machine can read another's
     * arguments. The credential goes through neither.
     */
    public function test_the_credential_reaches_git_through_the_environment_and_nowhere_else(): void
    {
        $token = 'ghp_secretvaluenobodyshouldsee';
        $seen = [];
        $environments = [];

        $runner = authenticatedRunner(
            static function (array $environment) use (&$seen, &$environments): callable {
                $environments[] = $environment;

                return static function (array $args) use (&$seen): array {
                    $seen = $args;

                    return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'truncated' => false];
                };
            },
            $token,
            '/tmp/askpass',
        );

        pushPublication($runner, new PackagePublication(
            'queue',
            '1.0.0',
            splitRepositoryUrl('queue'),
            self::ABSENT_COMMIT,
            [new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT)],
        ));

        self::assertStringNotContainsString($token, implode(' ', $seen));
        self::assertContains('https://github.com/kinetis-dev/queue.git', $seen);
        self::assertSame($token, $environments[0]['KINETIS_RELEASE_TOKEN']);
        self::assertSame('/tmp/askpass', $environments[0]['GIT_ASKPASS']);
        self::assertSame('0', $environments[0]['GIT_TERMINAL_PROMPT']);
    }

    public function test_a_transaction_file_carries_no_credential(): void
    {
        $transaction = new ReleaseTransaction(self::ABSENT_COMMIT, self::OTHER_COMMIT, [
            new PackagePublication('queue', '1.0.0', splitRepositoryUrl('queue'), self::ABSENT_COMMIT, [
                new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT),
            ]),
        ]);

        self::assertStringNotContainsString('token', $transaction->toJson());
        self::assertStringNotContainsString('@', $transaction->toJson());
    }

    public function test_a_push_failure_reports_without_repeating_the_credential(): void
    {
        $push = static fn (array $args): array => [
            'exitCode' => 128,
            'stdout' => '',
            'stderr' => 'fatal: could not read from https://x-access-token:ghp_abcdefghijklmnop@github.com/kinetis-dev/queue.git',
            'timedOut' => false,
            'truncated' => false,
        ];

        try {
            pushPublication($push, new PackagePublication(
                'queue',
                '1.0.0',
                splitRepositoryUrl('queue'),
                self::ABSENT_COMMIT,
                [new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT)],
            ));
            self::fail('a failed push must be reported');
        } catch (ReleaseTransactionFailure $e) {
            self::assertStringNotContainsString('ghp_abcdefghijklmnop', $e->getMessage());
            self::assertStringContainsString('***@github.com', $e->getMessage());
        }
    }

    public function test_a_remote_without_atomic_push_support_is_named_as_such(): void
    {
        $push = static fn (array $args): array => [
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'fatal: the receiving end does not support --atomic push',
            'timedOut' => false,
            'truncated' => false,
        ];

        $this->expectException(ReleaseTransactionFailure::class);
        $this->expectExceptionMessageMatches('/does not accept an atomic push/');

        pushPublication($push, new PackagePublication(
            'queue',
            '1.0.0',
            splitRepositoryUrl('queue'),
            self::ABSENT_COMMIT,
            [new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT)],
        ));
    }

    public function test_one_push_carries_the_tag_and_the_branch_together(): void
    {
        $seen = [];
        $calls = 0;
        $push = function (array $args) use (&$seen, &$calls): array {
            $calls++;
            $seen = $args;

            return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'truncated' => false];
        };

        pushPublication($push, new PackagePublication(
            'queue',
            '1.0.0',
            splitRepositoryUrl('queue'),
            self::ABSENT_COMMIT,
            [
                new RefUpdate('refs/tags/v1.0.0', null, self::ABSENT_COMMIT),
                new RefUpdate('refs/heads/main', self::OTHER_COMMIT, self::ABSENT_COMMIT),
            ],
        ));

        self::assertSame(1, $calls);
        self::assertContains('--atomic', $seen);
        self::assertContains('--force-with-lease=refs/tags/v1.0.0:', $seen);
        self::assertContains('--force-with-lease=refs/heads/main:' . self::OTHER_COMMIT, $seen);
        self::assertContains(self::ABSENT_COMMIT . ':refs/tags/v1.0.0', $seen);
        self::assertContains(self::ABSENT_COMMIT . ':refs/heads/main', $seen);
    }

    // --- what the workflow around this promises ----------------------------

    /**
     * The credential is a step-level decision, and a workflow that hands
     * it to a validation, checkout or download step has given it to
     * every process those steps run.
     */
    public function test_the_release_workflow_names_the_credential_in_one_step_only(): void
    {
        $steps = preg_split('/\n      - /', $this->releaseWorkflow()) ?: [];
        $carrying = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, CREDENTIAL_VARIABLE),
        ));

        self::assertCount(1, $carrying);
        self::assertStringContainsString('release-transaction.php apply', $carrying[0]);
    }

    /**
     * One constant group, so two pushes to main cannot publish side by
     * side, and no cancellation, so a publication is never killed
     * between two repositories.
     */
    public function test_the_release_workflow_serializes_publication_and_never_cancels(): void
    {
        self::assertMatchesRegularExpression(
            '/^concurrency:\n  group: (?!.*\$\{\{)\S+\n  cancel-in-progress: false$/m',
            $this->releaseWorkflow(),
        );
    }

    /**
     * actions/checkout's persisted credential overrides every
     * https://github.com/ push URL, which would authenticate the
     * publication as an identity with no access to the split
     * repositories.
     */
    public function test_the_release_workflow_persists_no_checkout_credential(): void
    {
        self::assertStringContainsString('persist-credentials: false', $this->releaseWorkflow());
    }

    public function test_the_release_workflow_gates_before_it_preflights_or_publishes(): void
    {
        $workflow = $this->releaseWorkflow();

        self::assertLessThan(
            strpos($workflow, 'release-transaction.php preflight'),
            strpos($workflow, 'release-gate.php'),
        );
        self::assertLessThan(
            strpos($workflow, 'release-transaction.php apply'),
            strpos($workflow, 'release-transaction.php preflight'),
        );
    }

    private function releaseWorkflow(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../.github/workflows/release.yml');
    }

    // --- fixtures ----------------------------------------------------------

    /** @return callable(string, string): bool */
    private function neverAsked(): callable
    {
        return function (string $ancestor, string $descendant): bool {
            self::fail('ancestry must not be asked about here');
        };
    }

    /**
     * @param array<int, array<string, mixed>> $pushes
     * @return callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
     */
    private function recordingPush(array &$pushes): callable
    {
        return static function (array $args) use (&$pushes): array {
            $pushes[] = $args;

            return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'truncated' => false];
        };
    }

    private function scratch(string $label): string
    {
        $path = sys_get_temp_dir() . "/kinetis-{$label}-" . bin2hex(random_bytes(6));
        $this->directories[] = $path;

        return $path;
    }

    /** @param array<string, array{version: string, requires?: list<string>, requiresDev?: list<string>}> $packages */
    private function monorepo(array $packages): string
    {
        $repository = $this->scratch('release');
        mkdir($repository, 0o777, true);

        foreach (array_keys($packages) as $key) {
            mkdir("{$repository}/packages/{$key}/src", 0o777, true);
            file_put_contents("{$repository}/packages/{$key}/src/Thing.php", "<?php // {$key}\n");
        }

        file_put_contents("{$repository}/packages.manifest.json", $this->manifestJson($packages));
        $this->git($repository, 'init', '-q', '-b', 'main');
        $this->git($repository, 'config', 'user.email', 'test@example.com');
        $this->git($repository, 'config', 'user.name', 'test');
        $this->git($repository, 'add', '-A');
        $this->git($repository, 'commit', '-q', '-m', 'initial');

        return $repository;
    }

    /** @param array<string, array{version: string, requires?: list<string>, requiresDev?: list<string>}> $packages */
    private function bumpTo(string $repository, array $packages): void
    {
        $current = $this->manifestOf($repository)['packages'];
        $merged = [];

        foreach ($packages as $key => $spec) {
            $merged[$key] = [
                'version' => $spec['version'],
                'requires' => $spec['requires'] ?? ($current[$key]['requires'] ?? []),
                'requiresDev' => $spec['requiresDev'] ?? ($current[$key]['requiresDev'] ?? []),
            ];
        }

        // A release carries a change to the package, not only to the
        // manifest, so a fixture that bumped a version alone would
        // publish two versions of identical content and prove nothing
        // about what a round derives from the package's own tree.
        foreach ($merged as $key => $spec) {
            if (!is_dir("{$repository}/packages/{$key}/src")) {
                mkdir("{$repository}/packages/{$key}/src", 0o777, true);
            }

            file_put_contents("{$repository}/packages/{$key}/src/Thing.php", "<?php // {$key} {$spec['version']}\n");
        }

        file_put_contents("{$repository}/packages.manifest.json", $this->manifestJson($merged));
        $this->git($repository, 'add', '-A');
        $this->git($repository, 'commit', '-q', '-m', 'bump');
    }

    /** @param array<string, array{version: string, requires?: list<string>, requiresDev?: list<string>}> $packages */
    private function manifestJson(array $packages): string
    {
        $entries = [];

        foreach ($packages as $key => $spec) {
            $entries[$key] = [
                'name' => "kinetis/{$key}",
                'description' => "the {$key} package",
                'namespace' => 'Kinetis\\' . ucfirst($key) . '\\',
                'version' => $spec['version'],
                'requires' => $spec['requires'] ?? [],
                'requiresDev' => $spec['requiresDev'] ?? [],
            ];
        }

        return json_encode(
            ['defaults' => ManifestFixture::defaults(), 'packages' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string, mixed> */
    private function manifestOf(string $repository): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents("{$repository}/packages.manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $keys */
    private function remotesFor(array $keys): string
    {
        $remotes = $this->scratch('remotes');
        mkdir($remotes, 0o777, true);

        foreach ($keys as $key) {
            $this->git($remotes, 'init', '--bare', '-q', '-b', 'main', "{$remotes}/{$key}.git");
        }

        return $remotes;
    }

    /** @return callable(string): string */
    private function urlFor(string $remotes): callable
    {
        return static fn (string $key): string => "{$remotes}/{$key}.git";
    }

    /** @return callable(string, string): PublicationRefs */
    private function refsFor(string $repository, callable $urlFor): callable
    {
        return static fn (string $key, string $tag): PublicationRefs
            => publicationRefs($key, $tag, gitRunnerFor($repository), $urlFor);
    }

    private function remoteRef(string $remotes, string $key, string $ref): ?string
    {
        $records = refLookup($key, [$ref], gitRunnerFor($remotes), $this->urlFor($remotes));

        return $records[$ref] ?? null;
    }

    /** A commit with a fixed identity and date, so the same tree is always the same commit. */
    private function commitTree(string $repository, string $tree, ?string $parent = null): string
    {
        $args = ['commit-tree', $tree];

        if ($parent !== null) {
            $args[] = '-p';
            $args[] = $parent;
        }

        $args[] = '-m';
        $args[] = 'split';

        $result = runGit($args, $repository, GIT_TIMEOUT_SECONDS, GIT_OUTPUT_LIMIT, null, [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'GIT_AUTHOR_NAME' => 'split',
            'GIT_AUTHOR_EMAIL' => 'split@example.com',
            'GIT_COMMITTER_NAME' => 'split',
            'GIT_COMMITTER_EMAIL' => 'split@example.com',
            'GIT_AUTHOR_DATE' => '2026-01-01T00:00:00+00:00',
            'GIT_COMMITTER_DATE' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        return trim($result['stdout']);
    }

    private function cloneOf(string $repository): string
    {
        $clone = $this->scratch('clone');
        $this->git($repository, 'clone', '-q', 'file://' . $repository, $clone);

        return $clone;
    }

    /** Moves a remote main on to a commit that already contains this release. */
    private function advanceRemoteMain(string $remotes, string $key): string
    {
        $main = (string) $this->remoteRef($remotes, $key, 'refs/heads/main');
        $ahead = $this->commitTree(
            "{$remotes}/{$key}.git",
            trim($this->git("{$remotes}/{$key}.git", 'rev-parse', "{$main}^{tree}")),
            $main,
        );
        $this->git("{$remotes}/{$key}.git", 'update-ref', 'refs/heads/main', $ahead);

        return $ahead;
    }

    /** @param list<array{key: string, version: string}> $candidates */
    private function preflight(string $repository, string $remotes, array $candidates): ReleaseTransaction
    {
        $urlFor = $this->urlFor($remotes);

        return preflightRelease(
            $this->manifestOf($repository),
            $candidates,
            $repository,
            gitRunnerFor($repository),
            $this->refsFor($repository, $urlFor),
            $urlFor,
        );
    }

    private function apply(string $repository, ReleaseTransaction $transaction): void
    {
        applyTransaction($transaction, gitRunnerFor($repository), static function (string $line): void {
        });
    }

    /** @param list<array{key: string, version: string}> $candidates */
    private function publish(string $repository, string $remotes, array $candidates): ReleaseTransaction
    {
        $transaction = $this->preflight($repository, $remotes, $candidates);
        $this->apply($repository, $transaction);

        return $transaction;
    }

    private function git(string $repository, string ...$args): string
    {
        $result = runGit(array_values($args), $repository);

        self::assertSame(0, $result['exitCode'], "git {$args[0]} failed: {$result['stderr']}");

        return $result['stdout'];
    }
}
