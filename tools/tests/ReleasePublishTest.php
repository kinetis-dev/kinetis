<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;
use PublicationRefs;
use SourceCommit;

require_once __DIR__ . '/../release-publish.php';

final class ReleasePublishTest extends TestCase
{
    /** The date every fixture commit is made at, so a fixture is a fixture. */
    private const string FIXTURE_DATE = '2026-01-02T03:04:05+00:00';

    public function test_a_repository_already_carrying_both_refs_is_skipped(): void
    {
        $refs = new PublicationRefs(main: 'abc123', tag: 'abc123');

        self::assertSame('skip', publicationAction('demo', '1.0.0', 'abc123', $refs));
    }

    public function test_an_empty_repository_is_published(): void
    {
        $refs = new PublicationRefs(main: null, tag: null);

        self::assertSame('push', publicationAction('demo', '1.0.0', 'abc123', $refs));
    }

    public function test_a_tag_written_without_its_branch_is_repaired(): void
    {
        $refs = new PublicationRefs(main: 'older', tag: 'abc123');

        self::assertSame('push', publicationAction('demo', '1.0.0', 'abc123', $refs));
    }

    public function test_a_branch_written_without_its_tag_is_completed(): void
    {
        $refs = new PublicationRefs(main: 'abc123', tag: null);

        self::assertSame('push', publicationAction('demo', '1.0.0', 'abc123', $refs));
    }

    public function test_a_tag_naming_different_content_is_a_hard_failure(): void
    {
        $this->expectExceptionMessage('kinetis-dev/demo already publishes v1.0.0 as other999');

        publicationAction('demo', '1.0.0', 'abc123', new PublicationRefs(main: 'other999', tag: 'other999'));
    }

    public function test_the_plan_file_is_read_in_order(): void
    {
        $candidates = planCandidates(['candidates' => [
            ['key' => 'framework', 'version' => '1.4.3', 'problems' => []],
            ['key' => 'pingpong', 'version' => '1.0.1', 'problems' => []],
        ]]);

        self::assertSame(['framework', 'pingpong'], array_column($candidates, 'key'));
        self::assertSame(['1.4.3', '1.0.1'], array_column($candidates, 'version'));
    }

    public function test_a_plan_with_no_candidates_list_is_rejected(): void
    {
        $this->expectExceptionMessage('The plan file has no candidates list.');

        planCandidates(['ok' => true]);
    }

    public function test_a_candidate_missing_its_version_is_rejected(): void
    {
        $this->expectExceptionMessage('A plan candidate is missing its key or version.');

        planCandidates(['candidates' => [['key' => 'framework']]]);
    }

    public function test_the_push_leases_the_main_it_read(): void
    {
        $command = publicationPush('demo', '1.0.1', 'split99', new PublicationRefs(main: 'observed11', tag: null));

        self::assertSame([
            'git', 'push', '--atomic',
            '--force-with-lease=refs/heads/main:observed11',
            'https://github.com/kinetis-dev/demo.git',
            'split99:refs/heads/main',
            'split99:refs/tags/v1.0.1',
        ], $command);
    }

    public function test_a_repository_with_no_main_is_leased_as_one_that_must_not_have_one(): void
    {
        $command = publicationPush('demo', '1.0.0', 'split99', new PublicationRefs(main: null, tag: null));

        self::assertContains('--force-with-lease=refs/heads/main:', $command);
    }

    public function test_the_tag_is_never_forced(): void
    {
        $command = publicationPush('demo', '1.0.0', 'split99', new PublicationRefs(main: 'observed11', tag: null));

        self::assertContains('split99:refs/tags/v1.0.0', $command);
        self::assertNotContains('+split99:refs/tags/v1.0.0', $command);
    }

    /**
     * The lease is only worth what git does with it, so this runs the
     * command the publication builds against a real repository.
     */
    public function test_a_lease_publishes_against_the_main_that_was_read(): void
    {
        [$root, $remote] = self::repositoryAndRemote();
        $split = self::splitOf($root);
        run($root, ['git', 'push', '--quiet', $remote, 'HEAD:refs/heads/main']);
        $observed = self::remoteMain($root, $remote);

        $result = self::push($root, $remote, $split, new PublicationRefs(main: $observed, tag: null));

        self::assertSame(0, $result['code'], $result['err']);
        self::assertSame($split, self::remoteMain($root, $remote));
        self::assertSame($split, self::remoteRef($root, $remote, 'refs/tags/v1.0.0'));
    }

    public function test_a_main_that_moved_after_it_was_read_is_refused_rather_than_overwritten(): void
    {
        [$root, $remote] = self::repositoryAndRemote();
        run($root, ['git', 'push', '--quiet', $remote, 'HEAD:refs/heads/main']);
        $observed = self::remoteMain($root, $remote);

        // Somebody else writes that branch between the read and the push.
        self::commit($root, 'a change nobody in this round knows about');
        run($root, ['git', 'push', '--quiet', '--force', $remote, 'HEAD:refs/heads/main']);
        $moved = self::remoteMain($root, $remote);

        $result = self::push($root, $remote, self::splitOf($root), new PublicationRefs(main: $observed, tag: null));

        // "stale info" is git rejecting the lease itself, rather than
        // the refspec being a non-fast-forward.
        self::assertStringContainsString('stale info', $result['err']);
        self::assertNotSame(0, $result['code']);
        self::assertSame($moved, self::remoteMain($root, $remote));
        self::assertSame('', self::remoteRef($root, $remote, 'refs/tags/v1.0.0'));
    }

    public function test_a_main_that_appeared_after_an_empty_repository_was_read_is_refused(): void
    {
        [$root, $remote] = self::repositoryAndRemote();
        run($root, ['git', 'push', '--quiet', $remote, 'HEAD:refs/heads/main']);

        // The refs were read while the repository was still empty.
        $result = self::push($root, $remote, self::splitOf($root), new PublicationRefs(main: null, tag: null));

        self::assertStringContainsString('stale info', $result['err']);
        self::assertNotSame(0, $result['code']);
    }

    public function test_the_source_commit_is_read_before_anything_is_staged(): void
    {
        [$root] = self::repositoryAndRemote();
        $source = sourceCommit($root);

        self::assertSame(trim((string) git($root, 'rev-parse', 'HEAD')), $source->sha);
        self::assertSame(trim((string) git($root, 'show', '--no-patch', '--format=%cI', 'HEAD')), $source->date);
        self::assertSame(strtotime(self::FIXTURE_DATE), strtotime($source->date));
    }

    public function test_every_synthetic_commit_is_dated_by_its_source(): void
    {
        $identity = releaseCommitIdentity(new SourceCommit(sha: str_repeat('a', 40), date: self::FIXTURE_DATE));

        self::assertSame(self::FIXTURE_DATE, $identity['GIT_AUTHOR_DATE']);
        self::assertSame(self::FIXTURE_DATE, $identity['GIT_COMMITTER_DATE']);
        self::assertSame(RELEASE_COMMIT_NAME, $identity['GIT_AUTHOR_NAME']);
        self::assertSame(RELEASE_COMMIT_NAME, $identity['GIT_COMMITTER_NAME']);
        self::assertSame(RELEASE_COMMIT_EMAIL, $identity['GIT_AUTHOR_EMAIL']);
        self::assertSame(RELEASE_COMMIT_EMAIL, $identity['GIT_COMMITTER_EMAIL']);
    }

    /**
     * The whole point of the fixed identity: a workflow rerun from the
     * same source commit and the same plan has to offer the same commit
     * for the version it is publishing, or publicationAction() reads a
     * rerun as two contents claiming one version.
     */
    public function test_two_runs_from_one_source_split_to_the_same_commit(): void
    {
        [$firstHead, $firstSplit] = self::stagedRelease();
        [$secondHead, $secondSplit] = self::stagedRelease();

        self::assertSame($firstHead, $secondHead);
        self::assertSame($firstSplit, $secondSplit);
    }

    public function test_a_split_of_a_package_that_is_not_there_fails_plainly(): void
    {
        [$root] = self::repositoryAndRemote();

        $this->expectExceptionMessage('git subtree split produced no split commit for packages/absent');

        splitCommit($root, 'absent');
    }

    public function test_the_monorepo_main_is_read_from_the_checkout_own_remote(): void
    {
        [$root, $remote] = self::repositoryAndRemote();
        run($root, ['git', 'remote', 'add', 'origin', $remote]);
        run($root, ['git', 'push', '--quiet', $remote, 'HEAD:refs/heads/main']);

        self::assertSame(trim((string) git($root, 'rev-parse', 'HEAD')), monorepoMainSha($root));
    }

    public function test_a_remote_carrying_no_main_is_not_evidence_that_this_round_is_current(): void
    {
        [$root, $remote] = self::repositoryAndRemote();
        run($root, ['git', 'remote', 'add', 'origin', $remote]);

        $this->expectExceptionMessage("Could not read refs/heads/main from the monorepo's own remote");

        monorepoMainSha($root);
    }

    public function test_a_round_publishes_while_its_commit_is_still_main(): void
    {
        $source = new SourceCommit(sha: str_repeat('a', 40), date: self::FIXTURE_DATE);

        requireCurrentSource($source, static fn (): string => str_repeat('a', 40));

        self::assertTrue(true, 'a current source publishes without comment');
    }

    public function test_a_rerun_after_main_moved_on_publishes_nothing(): void
    {
        $source = new SourceCommit(sha: str_repeat('a', 40), date: self::FIXTURE_DATE);

        $this->expectExceptionMessage(
            "The monorepo's main is " . str_repeat('b', 40) . ', and this run releases ' . str_repeat('a', 40),
        );

        requireCurrentSource($source, static fn (): string => str_repeat('b', 40));
    }

    public function test_the_credential_helper_never_writes_the_token_to_disk(): void
    {
        $helper = self::helper('ghp_secretvalue');

        try {
            self::assertStringNotContainsString('ghp_secretvalue', (string) file_get_contents($helper['path']));
            self::assertSame('ghp_secretvalue', $helper['environment']['RELEASE_DEPLOY_TOKEN']);
            self::assertSame($helper['path'], $helper['environment']['GIT_ASKPASS']);
            self::assertSame('0700', substr(sprintf('%o', (int) fileperms($helper['path'])), -4));
        } finally {
            unlink($helper['path']);
        }
    }

    public function test_each_helper_is_written_to_a_path_of_its_own(): void
    {
        $first = self::helper('ghp_secretvalue');
        $second = self::helper('ghp_secretvalue');

        try {
            self::assertNotSame($first['path'], $second['path']);
        } finally {
            unlink($first['path']);
            unlink($second['path']);
        }
    }

    /**
     * The publication runs the generator, three git commands, the
     * splitter and two remote reads before it pushes anything. None of
     * them has any use for the deploy credential, and every one of them
     * inherits this process's environment.
     */
    public function test_the_token_is_gone_from_every_child_but_the_push(): void
    {
        $helper = self::helper('ghp_secretvalue');
        $directory = dirname($helper['path']);
        $read = ['sh', '-c', 'printf %s "${RELEASE_DEPLOY_TOKEN-}"'];

        try {
            self::assertFalse(getenv('RELEASE_DEPLOY_TOKEN'));
            self::assertArrayNotHasKey('RELEASE_DEPLOY_TOKEN', $_ENV);
            self::assertSame('', run($directory, $read)['out']);
            self::assertSame('ghp_secretvalue', run($directory, $read, $helper['environment'])['out']);
        } finally {
            unlink($helper['path']);
        }
    }

    public function test_a_missing_credential_stops_before_anything_is_written(): void
    {
        putenv('RELEASE_DEPLOY_TOKEN');

        $this->expectExceptionMessage('RELEASE_DEPLOY_TOKEN is not set');

        credentialHelper(sys_get_temp_dir());
    }

    public function test_the_helper_answers_the_two_prompts_git_makes(): void
    {
        $helper = self::helper('ghp_secretvalue');
        $directory = dirname($helper['path']);

        try {
            $username = run($directory, [$helper['path'], 'Username for https://github.com:'], $helper['environment']);
            $password = run($directory, [$helper['path'], 'Password for https://github.com:'], $helper['environment']);

            self::assertSame("x-access-token\n", $username['out']);
            self::assertSame("ghp_secretvalue\n", $password['out']);
        } finally {
            unlink($helper['path']);
        }
    }

    /** @return array{path: string, environment: array<string, string>} */
    private static function helper(string $token): array
    {
        putenv("RELEASE_DEPLOY_TOKEN={$token}");
        $directory = sys_get_temp_dir() . '/kinetis-publish-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o755, recursive: true);

        return credentialHelper($directory);
    }

    /**
     * A monorepo-shaped scratch repository and an empty remote to publish
     * into. Every commit in it is made at one fixed date under one fixed
     * identity, so two of them are the same repository.
     *
     * @return array{string, string} the checkout, and the remote's path
     */
    private static function repositoryAndRemote(): array
    {
        $root = sys_get_temp_dir() . '/kinetis-publish-' . bin2hex(random_bytes(6));
        $remote = "{$root}.git";
        mkdir("{$root}/packages/demo", 0o755, recursive: true);
        run($root, ['git', 'init', '--quiet', '--initial-branch=main']);
        run(sys_get_temp_dir(), ['git', 'init', '--quiet', '--bare', '--initial-branch=main', $remote]);
        file_put_contents("{$root}/README.md", "the monorepo\n");
        file_put_contents("{$root}/packages/demo/Demo.php", "<?php\n");
        self::commit($root, 'the package');

        return [$root, $remote];
    }

    /**
     * One release round's staging and split, from a repository built the
     * same way every time: the release commit is made with exactly the
     * identity the publication gives it.
     *
     * @return array{string, string} the release commit, and its split
     */
    private static function stagedRelease(): array
    {
        [$root] = self::repositoryAndRemote();
        $source = sourceCommit($root);
        file_put_contents("{$root}/packages/demo/composer.json", "{\"name\": \"kinetis/demo\"}\n");
        run($root, ['git', 'add', '--', 'packages/demo/composer.json']);
        run($root, [
            'git', 'commit', '--quiet', '--allow-empty', '-m', 'release: demo v1.0.0',
        ], releaseCommitIdentity($source));

        return [trim((string) git($root, 'rev-parse', 'HEAD')), splitCommit($root, 'demo')];
    }

    private static function commit(string $root, string $message): void
    {
        run($root, ['git', 'add', '-A']);
        run($root, ['git', 'commit', '--quiet', '--allow-empty', '-m', $message], [
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_AUTHOR_DATE' => self::FIXTURE_DATE,
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_DATE' => self::FIXTURE_DATE,
        ]);
    }

    private static function splitOf(string $root): string
    {
        return splitCommit($root, 'demo');
    }

    /**
     * The publication's own push command, aimed at a local remote: the
     * lease, the refspecs and --atomic are the ones production builds.
     *
     * @return array{code: int, out: string, err: string}
     */
    private static function push(string $root, string $remote, string $split, PublicationRefs $refs): array
    {
        $command = publicationPush('demo', '1.0.0', $split, $refs);
        $position = array_search(splitRepositoryUrl('demo'), $command, true);
        self::assertIsInt($position);
        $command[$position] = $remote;

        return run($root, $command);
    }

    private static function remoteMain(string $root, string $remote): string
    {
        return self::remoteRef($root, $remote, 'refs/heads/main');
    }

    /** The commit a remote's ref points at, or '' when it has none. */
    private static function remoteRef(string $root, string $remote, string $ref): string
    {
        $line = trim((string) git($root, 'ls-remote', $remote, $ref));

        return $line === '' ? '' : trim(explode("\t", $line)[0]);
    }
}
