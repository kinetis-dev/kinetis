<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use HistoryUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../validate-manifest.php';

/**
 * The whole-branch view the push-ready checklist runs on: a feature
 * branch is checked against its merge base with the integration branch,
 * not against HEAD^, so the one bump an early commit made is what every
 * later commit is measured against.
 */
final class BranchBaseTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    private string $repository = '';

    private string $base = '';

    protected function setUp(): void
    {
        $this->repository = $this->scratchDirectory('kinetis-branch');
        mkdir("{$this->repository}/packages/demo/src", 0o777, true);

        $this->writeManifest('1.0.0');
        file_put_contents("{$this->repository}/packages/demo/src/Thing.php", "<?php\n");
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'base');
        $this->base = gitResolveCommit('HEAD', $this->repository);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            exec('rm -rf ' . escapeshellarg($directory));
        }

        $this->directories = [];
    }

    /**
     * The sequence this exists for: an early commit makes the one bump
     * the change is allowed, and later review commits touch more files in
     * the same package. Measured from the branch's base, that is one
     * bump covering everything. Measured from HEAD^, the later commits
     * look unbumped and a second bump gets added, skipping a version
     * that never releases.
     */
    public function test_an_early_bump_covers_later_commits_in_the_same_branch(): void
    {
        $this->commitBumpAndEdit();
        $this->commitReviewEdits();

        $result = $this->check($this->base);

        self::assertSame([], $result['versionBump']);
        self::assertSame([], $result['contentBump']);
    }

    public function test_the_same_branch_measured_from_head_caret_demands_a_bump_it_must_not_have(): void
    {
        $this->commitBumpAndEdit();
        $this->commitReviewEdits();

        $problems = $this->check(null)['contentBump'];

        self::assertCount(1, $problems);
        self::assertStringContainsString("'version' was not bumped", $problems[0]);
    }

    public function test_a_second_bump_on_the_same_branch_is_rejected_as_a_skipped_release(): void
    {
        $this->commitBumpAndEdit();

        $this->writeManifest('1.0.2');
        file_put_contents("{$this->repository}/packages/demo/src/Thing.php", "<?php // fixed, reviewed\n");
        $this->git('commit', '-q', '-am', 'demo: address review');

        $problems = $this->check($this->base)['versionBump'];

        self::assertCount(1, $problems);
        self::assertStringContainsString('jumped from 1.0.0 to 1.0.2', $problems[0]);
    }

    public function test_a_branch_that_changes_a_package_without_any_bump_is_rejected(): void
    {
        file_put_contents("{$this->repository}/packages/demo/src/Thing.php", "<?php // changed\n");
        $this->git('commit', '-q', '-am', 'demo: change the thing');

        $problems = $this->check($this->base)['contentBump'];

        self::assertCount(1, $problems);
        self::assertStringContainsString("package files changed but 'version' was not bumped", $problems[0]);
    }

    public function test_a_base_ref_that_does_not_exist_fails(): void
    {
        $this->expectException(HistoryUnavailable::class);

        $this->check('origin/main');
    }

    /** @return iterable<string, array{string}> */
    public static function unusableBases(): iterable
    {
        yield 'an option' => ['--upload-pack=whatever'];
        yield 'path syntax' => ['HEAD:packages.manifest.json'];
        yield 'a range' => ['main..HEAD'];
        yield 'a control byte' => ["main\nx"];
        yield 'the all-zero id' => [self::zeroSha()];
    }

    #[DataProvider('unusableBases')]
    public function test_an_unusable_base_never_reaches_git(string $base): void
    {
        $this->expectException(HistoryUnavailable::class);

        $this->check($base);
    }

    /**
     * A checkout too shallow to reach the named base. Skipping here would
     * turn every version rule off for exactly the branch being checked.
     */
    public function test_a_shallow_checkout_cannot_reach_the_base_and_fails(): void
    {
        $shallow = $this->shallowClone();

        self::assertFalse(gitCommitExists($this->base, $shallow), 'the precondition: the base is not in the clone');

        $this->expectException(HistoryUnavailable::class);

        checkAgainstHistory($this->manifestOnDisk($shallow), $this->base, $shallow);
    }

    /**
     * The same shallow checkout with no base named: its HEAD reports no
     * parent whether or not one exists, so there is nothing to tell a
     * truncated branch apart from a root commit and the run fails.
     */
    public function test_a_shallow_checkout_with_no_base_named_fails_rather_than_skipping(): void
    {
        $shallow = $this->shallowClone();

        try {
            checkAgainstHistory($this->manifestOnDisk($shallow), null, $shallow);
            self::fail('a shallow checkout with no base must fail');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString('shallow', $e->getMessage());
        }
    }

    /**
     * The one state that still skips: a full checkout whose HEAD has no
     * parent.
     */
    public function test_the_repositorys_first_commit_skips_explicitly(): void
    {
        $result = checkAgainstHistory($this->manifestOnDisk($this->repository), null, $this->repository);

        self::assertSame([], $result['versionBump']);
        self::assertSame([], $result['contentBump']);
        self::assertNotNull($result['skipped']);
        self::assertStringContainsString('no parent commit', $result['skipped']);
    }

    public function test_the_base_argument_is_read_off_the_command_line(): void
    {
        $parsed = parseValidatorArguments(['--base=origin/main']);

        self::assertSame([], $parsed['problems']);
        self::assertSame('origin/main', $parsed['base']);
        self::assertNull(parseValidatorArguments([])['base']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidValidatorInvocations(): iterable
    {
        yield 'an unknown option' => [['--deep'], 'Unknown option: --deep'];
        yield 'an empty base' => [['--base='], '--base needs a commit id or a ref name.'];
        yield 'a whitespace base' => [['--base=   '], '--base needs a commit id or a ref name.'];
        yield 'a repeated base' => [['--base=main', '--base=other'], '--base is given more than once.'];
    }

    /**
     * An empty --base read as "no override" would compare against HEAD^
     * instead — a different question than the one asked, answered
     * silently.
     *
     * @param list<string> $args
     */
    #[DataProvider('invalidValidatorInvocations')]
    public function test_an_invalid_validator_invocation_is_refused(array $args, string $expected): void
    {
        $problems = parseValidatorArguments($args)['problems'];

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' | ', $problems));
    }

    /**
     * The whole invocation is judged before the manifest is read, so a
     * bad argument cannot reach a git call or a check.
     */
    public function test_an_invalid_invocation_stops_the_run_before_it_reads_anything(): void
    {
        self::assertSame(1, validatorMain(['tools/validate-manifest.php', '--base=']));
    }

    private function commitBumpAndEdit(): void
    {
        $this->writeManifest('1.0.1');
        file_put_contents("{$this->repository}/packages/demo/src/Thing.php", "<?php // fixed\n");
        $this->git('commit', '-q', '-am', 'demo: fix the thing');
    }

    private function commitReviewEdits(): void
    {
        file_put_contents("{$this->repository}/packages/demo/src/Thing.php", "<?php // fixed, reviewed\n");
        file_put_contents("{$this->repository}/packages/demo/README.md", "reviewed\n");
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'demo: address review');
    }

    /** @return array{versionBump: list<string>, contentBump: list<string>, skipped: ?string} */
    private function check(?string $base): array
    {
        return checkAgainstHistory($this->manifestOnDisk($this->repository), $base, $this->repository);
    }

    /** @return array<string, mixed> */
    private function manifestOnDisk(string $directory): array
    {
        /** @var array<string, mixed> */
        return json_decode(
            (string) file_get_contents("{$directory}/packages.manifest.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function writeManifest(string $version): void
    {
        file_put_contents(
            "{$this->repository}/packages.manifest.json",
            ManifestFixture::json(['demo' => $version]),
        );
    }

    private function shallowClone(): string
    {
        // A shallow clone of a single-commit repository still has that
        // commit's parent absent, so give it a second commit first.
        $this->commitBumpAndEdit();
        $clone = $this->scratchDirectory('kinetis-shallow');
        $result = runGit(['clone', '--depth', '1', '-q', 'file://' . $this->repository, $clone], sys_get_temp_dir());

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        return $clone;
    }

    private static function zeroSha(): string
    {
        return str_repeat('0', 40);
    }

    private function git(string ...$args): void
    {
        $result = runGit(array_values($args), $this->repository);

        self::assertSame(0, $result['exitCode'], "git {$args[0]} failed: {$result['stderr']}");
    }

    private function scratchDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . "/{$prefix}-" . bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        return $directory;
    }
}
