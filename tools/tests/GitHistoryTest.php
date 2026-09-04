<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use ComparisonBase;
use HistoryUnavailable;
use NativeProcessBoundary;
use ProcessBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../git-history.php';

/**
 * The real process boundary with one step replaced by a failure.
 *
 * The failing step never reaches the real implementation — a stub that
 * delegates the behaviour it is meant to simulate proves nothing. The
 * steps that are not under test do delegate, so a test still runs a real
 * git process and reads its real output.
 */
final class StubProcessBoundary implements ProcessBoundary
{
    private readonly NativeProcessBoundary $real;

    public int $closeProcessCalls = 0;

    public int $terminateCalls = 0;

    /** Set once the real status has reported the child finished. */
    private bool $childExited = false;

    /**
     * @param 'select'|'read'|'selectAfterExit'|'readAfterExit'|'status'|'terminate'|'closeProcess'|null $failAt
     *        A `...AfterExit` mode reports the child finished at the
     *        first status call, with its output still buffered, and then
     *        fails the step named. That is the state the drain after the
     *        exit exists for, and reporting it rather than waiting to
     *        observe it is what makes the branch reachable every run.
     */
    public function __construct(private readonly ?string $failAt = null)
    {
        $this->real = new NativeProcessBoundary();
    }

    public function select(array &$read, float $seconds): int|false
    {
        if ($this->failAt === 'select' || ($this->failAt === 'selectAfterExit' && $this->childExited)) {
            return false;
        }

        return $this->real->select($read, $seconds);
    }

    public function read(mixed $stream, int $length): string|false
    {
        if ($this->failAt === 'read' || ($this->failAt === 'readAfterExit' && $this->childExited)) {
            return false;
        }

        return $this->real->read($stream, $length);
    }

    private function reportsAnExitedChild(): bool
    {
        return $this->failAt === 'selectAfterExit' || $this->failAt === 'readAfterExit';
    }

    public function atEnd(mixed $stream): bool
    {
        return $this->real->atEnd($stream);
    }

    public function closeStream(mixed $stream): void
    {
        $this->real->closeStream($stream);
    }

    /** @return array{running: bool, exitcode: int}|false */
    public function status(mixed $process): array|false
    {
        if ($this->failAt === 'status') {
            return false;
        }

        if ($this->reportsAnExitedChild()) {
            $this->childExited = true;

            return ['running' => false, 'exitcode' => 0];
        }

        $status = $this->real->status($process);

        if (!$status['running']) {
            $this->childExited = true;
        }

        return $status;
    }

    public function terminate(mixed $process, int $signal): bool
    {
        $this->terminateCalls++;

        if ($this->failAt === 'terminate') {
            // Not delegated: a kill that reports failure must not have
            // been delivered, or the test proves nothing.
            return false;
        }

        return $this->real->terminate($process, $signal);
    }

    public function closeProcess(mixed $process): int
    {
        $this->closeProcessCalls++;

        if ($this->failAt === 'closeProcess') {
            // Not delegated: the handle is released when it falls out of
            // scope, and the child is already gone by then.
            return -1;
        }

        return $this->real->closeProcess($process);
    }
}

final class GitHistoryTest extends TestCase
{
    /** @var list<string> */
    private array $repositories = [];

    protected function tearDown(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        foreach ($this->repositories as $repository) {
            exec('rm -rf ' . escapeshellarg($repository));
        }

        $this->repositories = [];
    }

    /**
     * @param callable(string): bool $commitExists
     * @param callable(): bool $isShallow
     */
    private function resolve(
        ?string $override = null,
        ?callable $commitExists = null,
        ?callable $isShallow = null,
    ): ComparisonBase {
        return resolveComparisonBase(
            static fn (string $ref): string => str_repeat('a', 40),
            $commitExists ?? static fn (string $ref): bool => true,
            $isShallow ?? static fn (): bool => false,
            $override,
        );
    }

    public function test_an_explicit_before_sha_becomes_the_resolved_comparison_commit(): void
    {
        putenv('GITHUB_EVENT_BEFORE=' . str_repeat('b', 40));

        self::assertSame(str_repeat('a', 40), $this->resolve()->commit);
    }

    /**
     * Every git call downstream uses the resolved object id, not the
     * name it came from, so one lookup decides which object is read.
     */
    public function test_the_resolved_commit_is_what_the_base_carries_not_the_name_given(): void
    {
        $base = resolveComparisonBase(
            static fn (string $ref): string => str_repeat('c', 40),
            static fn (string $ref): bool => true,
            static fn (): bool => false,
            'origin/main',
        );

        self::assertSame(str_repeat('c', 40), $base->commit);
    }

    public function test_a_base_git_cannot_resolve_fails_rather_than_skipping(): void
    {
        $this->expectException(HistoryUnavailable::class);

        resolveComparisonBase(
            static fn (string $ref): string => throw new HistoryUnavailable("no such commit {$ref}"),
            static fn (string $ref): bool => false,
            static fn (): bool => false,
            'origin/main',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableBaseRefs(): iterable
    {
        yield 'an option' => ['--upload-pack=touch /tmp/pwned', 'full commit id or a plain ref name'];
        yield 'a short option' => ['-n', 'full commit id or a plain ref name'];
        yield 'path syntax' => ['HEAD:packages.manifest.json', 'full commit id or a plain ref name'];
        yield 'a bare colon' => [':/etc/passwd', 'full commit id or a plain ref name'];
        yield 'a range' => ['main..HEAD', 'full commit id or a plain ref name'];
        yield 'a parent suffix' => ['HEAD^', 'full commit id or a plain ref name'];
        yield 'a reflog selector' => ['main@{yesterday}', 'full commit id or a plain ref name'];
        yield 'a newline' => ["main\nrm -rf /", 'full commit id or a plain ref name'];
        yield 'a null byte' => ["main\0", 'full commit id or a plain ref name'];
        yield 'a tab' => ["main\tx", 'full commit id or a plain ref name'];
        yield 'a space' => ['origin main', 'full commit id or a plain ref name'];
        yield 'a trailing slash' => ['refs/heads/', 'full commit id or a plain ref name'];
        yield 'a lock name' => ['refs/heads/main.lock', 'full commit id or a plain ref name'];
        yield 'empty' => ['', 'empty'];
        yield 'the all-zero id' => [str_repeat('0', 40), 'not a comparison base'];
    }

    #[DataProvider('unusableBaseRefs')]
    public function test_an_unusable_base_argument_is_refused_before_git_runs(string $ref, string $expected): void
    {
        $problem = baseRefProblem($ref);

        self::assertNotNull($problem);
        self::assertStringContainsString($expected, $problem);
    }

    #[DataProvider('unusableBaseRefs')]
    public function test_an_unusable_base_argument_never_reaches_the_resolver(string $ref, string $expected): void
    {
        $reached = false;

        try {
            resolveComparisonBase(
                function (string $r) use (&$reached): string {
                    $reached = true;

                    return str_repeat('a', 40);
                },
                static fn (string $r): bool => true,
                static fn (): bool => false,
                $ref,
            );
            self::fail("--base={$ref} must be refused");
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }

        self::assertFalse($reached, 'the argument must not reach git at all');
    }

    /** @return iterable<string, array{string}> */
    public static function usableBaseRefs(): iterable
    {
        yield 'a sha-1 object id' => [str_repeat('a', 40)];
        yield 'a sha-256 object id' => [str_repeat('a', 64)];
        yield 'a branch' => ['main'];
        yield 'a remote-tracking branch' => ['origin/main'];
        yield 'a full ref path' => ['refs/remotes/origin/main'];
        yield 'a tag with dots' => ['v1.2.3'];
        yield 'HEAD' => ['HEAD'];
    }

    #[DataProvider('usableBaseRefs')]
    public function test_a_usable_base_argument_is_accepted(string $ref): void
    {
        self::assertNull(baseRefProblem($ref));
    }

    /**
     * The all-zero id means "no prior ref" only where GitHub sends it.
     * Typed on the command line it names a commit that does not exist.
     */
    public function test_the_all_zero_id_skips_from_the_environment_and_is_refused_from_the_command_line(): void
    {
        putenv('GITHUB_EVENT_BEFORE=' . str_repeat('0', 40));
        $base = $this->resolve();

        self::assertNull($base->commit);
        self::assertStringContainsString('all-zero before SHA', $base->reason);

        putenv('GITHUB_EVENT_BEFORE');
        $this->expectException(HistoryUnavailable::class);
        $this->resolve(override: str_repeat('0', 40));
    }

    public function test_a_sha_256_all_zero_before_sha_is_recognised_too(): void
    {
        putenv('GITHUB_EVENT_BEFORE=' . str_repeat('0', 64));

        self::assertNull($this->resolve()->commit);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function shaShapes(): iterable
    {
        yield '40 zeros' => [str_repeat('0', 40), true];
        yield '64 zeros' => [str_repeat('0', 64), true];
        yield 'a real sha' => [str_repeat('a', 40), false];
        yield '39 zeros' => [str_repeat('0', 39), false];
        yield 'zeros with a digit' => [str_repeat('0', 39) . '1', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('shaShapes')]
    public function test_zero_sha_detection(string $sha, bool $expected): void
    {
        self::assertSame($expected, isZeroSha($sha));
    }

    public function test_a_malformed_before_sha_from_the_environment_fails(): void
    {
        putenv('GITHUB_EVENT_BEFORE=--upload-pack=whatever');

        $this->expectException(HistoryUnavailable::class);

        $this->resolve();
    }

    public function test_no_environment_variable_compares_against_the_resolved_parent(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        $base = $this->resolve(commitExists: static fn (string $ref): bool => $ref === 'HEAD^');

        self::assertSame(str_repeat('a', 40), $base->commit);
    }

    /**
     * A non-shallow repository whose HEAD has no parent is the one state
     * where skipping is provable: git can see the whole history and
     * there is none before this commit.
     */
    public function test_a_non_shallow_root_commit_skips_with_its_own_reason(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        $base = $this->resolve(commitExists: static fn (string $ref): bool => false);

        self::assertNull($base->commit);
        self::assertStringContainsString('no parent commit', $base->reason);
    }

    /**
     * A shallow clone reports the same "HEAD has no parent" as a genuine
     * root commit, so the two cannot be told apart from that answer.
     * Skipping on it turns every version rule off for a truncated
     * checkout.
     */
    public function test_a_shallow_checkout_with_no_named_base_fails_instead_of_skipping(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        try {
            $this->resolve(
                commitExists: static fn (string $ref): bool => false,
                isShallow: static fn (): bool => true,
            );
            self::fail('a shallow checkout with no base must fail');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString('shallow', $e->getMessage());
            self::assertStringContainsString('fetch-depth', $e->getMessage());
        }
    }

    /**
     * A shallow checkout is workable when a base is named and present: the history the checks need is provably there.
     */
    public function test_a_shallow_checkout_with_a_resolvable_base_compares_normally(): void
    {
        $base = $this->resolve(override: 'origin/main', isShallow: static fn (): bool => true);

        self::assertSame(str_repeat('a', 40), $base->commit);
    }

    public function test_a_shallowness_probe_that_fails_propagates(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        $this->expectException(HistoryUnavailable::class);

        $this->resolve(isShallow: static fn (): bool => throw new HistoryUnavailable('git could not answer'));
    }

    public function test_credentials_in_git_output_are_redacted(): void
    {
        $redacted = redactCredentials(
            'fatal: could not read from https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz@github.com/kinetis-dev/queue.git',
        );

        self::assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz', $redacted);
        self::assertStringContainsString('***@github.com', $redacted);
    }

    public function test_a_bare_token_outside_a_url_is_redacted(): void
    {
        $redacted = redactCredentials('remote rejected: github_pat_11ABCDEFG0abcdefghijklmnop is invalid');

        self::assertStringNotContainsString('11ABCDEFG0abcdefghijklmnop', $redacted);
        self::assertStringContainsString('github_pat_***', $redacted);
    }

    public function test_long_git_output_is_truncated(): void
    {
        $redacted = redactCredentials(str_repeat('x', 5000));

        self::assertStringEndsWith('…', $redacted);
        self::assertSame(400, strlen(rtrim($redacted, '…')));
    }

    public function test_nul_separated_output_keeps_paths_holding_unusual_bytes(): void
    {
        $paths = splitNulSeparatedPaths("packages/a/one two.php\0packages/a/\"quoted\".php\0packages/a/tab\there.php\0");

        self::assertSame([
            'packages/a/one two.php',
            'packages/a/"quoted".php',
            "packages/a/tab\there.php",
        ], $paths);
    }

    public function test_empty_diff_output_is_no_paths(): void
    {
        self::assertSame([], splitNulSeparatedPaths(''));
        self::assertSame([], splitNulSeparatedPaths("\0"));
    }

    public function test_a_git_call_that_outruns_its_deadline_is_killed_and_reported(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $started = microtime(true);
        $result = runGit(['-c', 'alias.stall=!sleep 30', 'stall'], $repository, timeoutSeconds: 0.5);
        $elapsed = microtime(true) - $started;

        self::assertTrue($result['timedOut']);
        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('did not finish', $result['stderr']);
        self::assertLessThan(10.0, $elapsed, 'the deadline, not the child, decides when this returns');
    }

    /**
     * The case a pipe-driven loop alone never ends: the child closes
     * both pipes and keeps running. Waiting on it then is a wait with no
     * end, so the process itself has to be watched alongside its output.
     */
    public function test_a_child_that_closes_its_pipes_and_keeps_running_still_hits_the_deadline(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $started = microtime(true);
        $result = runGit(
            ['-c', 'alias.quiet=!sh -c "exec 1>&- 2>&-; sleep 30"', 'quiet'],
            $repository,
            timeoutSeconds: 0.5,
        );
        $elapsed = microtime(true) - $started;

        self::assertTrue($result['timedOut']);
        self::assertLessThan(10.0, $elapsed, 'closing the pipes must not hand the deadline to the child');
    }

    /**
     * The opposite case, and the reason the loop watches the pipes too:
     * a child that exits leaves its output behind in them, and that
     * output is the answer.
     */
    public function test_output_written_before_the_child_exits_is_captured_in_full(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $result = runGit(['-c', 'alias.say=!printf "one\ntwo\n"', 'say'], $repository, timeoutSeconds: 10);

        self::assertSame(0, $result['exitCode']);
        self::assertFalse($result['timedOut']);
        self::assertSame("one\ntwo\n", $result['stdout']);
    }

    public function test_a_nonzero_exit_is_reported_with_its_code_and_stderr(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $result = runGit(['-c', 'alias.nope=!sh -c "echo bad >&2; exit 3"', 'nope'], $repository, timeoutSeconds: 10);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('bad', $result['stderr']);
    }

    /**
     * A process writing without end must not take this one's memory with
     * it. The bytes still leave the pipe, or the child blocks writing
     * them; they are not all kept, and the call fails rather than
     * returning the part that fit.
     */
    public function test_runaway_output_is_bounded_and_fails_rather_than_returning_part_of_itself(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $limit = 4096;

        $result = runGit(
            ['-c', "alias.flood=!sh -c \"yes x | head -c 200000\"", 'flood'],
            $repository,
            timeoutSeconds: 20,
            outputLimit: $limit,
        );

        self::assertFalse($result['timedOut'], 'the child must not stall on a pipe nobody drains');
        self::assertTrue($result['truncated']);
        self::assertNotSame(0, $result['exitCode']);
        self::assertSame($limit, strlen($result['stdout']));
    }

    /**
     * The ordinary timeout, against a child that sleeps far past it. What matters is that the call returns on the deadline
     * rather than on the child.
     */
    public function test_a_sleeping_child_is_killed_and_reaped_on_the_deadline(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary();

        $started = microtime(true);
        $result = runGit(
            ['-c', 'alias.stall=!sleep 60', 'stall'],
            $repository,
            timeoutSeconds: 0.5,
            boundary: $boundary,
        );
        $elapsed = microtime(true) - $started;

        self::assertTrue($result['timedOut']);
        self::assertNotSame(0, $result['exitCode']);
        self::assertLessThan(15.0, $elapsed, 'the deadline, not the child, decides when this returns');
        self::assertSame(1, $boundary->terminateCalls);
        self::assertSame(1, $boundary->closeProcessCalls, 'the child is reaped rather than abandoned');
    }

    /**
     * The same, for a child that closes both pipes first: a loop reading
     * only the pipes would wait on it with no end.
     */
    public function test_a_child_that_closes_its_pipes_and_keeps_running_is_killed_on_the_deadline(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary();

        $started = microtime(true);
        $result = runGit(
            ['-c', 'alias.quiet=!sh -c "exec 1>&- 2>&-; sleep 60"', 'quiet'],
            $repository,
            timeoutSeconds: 0.5,
            boundary: $boundary,
        );
        $elapsed = microtime(true) - $started;

        self::assertTrue($result['timedOut']);
        self::assertLessThan(15.0, $elapsed, 'closing the pipes must not hand the deadline to the child');
        self::assertSame(1, $boundary->terminateCalls);
        self::assertSame(1, $boundary->closeProcessCalls);
    }

    public function test_a_run_that_finishes_on_its_own_is_reaped_without_being_killed(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary();

        $result = runGit(['-c', 'alias.say=!echo hi', 'say'], $repository, timeoutSeconds: 10, boundary: $boundary);

        self::assertSame(0, $result['exitCode']);
        self::assertSame("hi\n", $result['stdout']);
        self::assertSame(0, $boundary->terminateCalls);
        self::assertSame(1, $boundary->closeProcessCalls);
    }

    /**
     * A kill that is never delivered leaves a child still running, and
     * the reap then waits for as long as it runs — the deadline stops
     * bounding the call. The result has to say so rather than come back
     * looking like an ordinary timeout.
     */
    public function test_a_kill_that_is_not_delivered_is_reported_rather_than_assumed(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary(failAt: 'terminate');

        $started = microtime(true);
        $result = runGit(
            // Short-lived on purpose: with the kill refused, the reap
            // runs for whatever is left of the child.
            ['-c', 'alias.stall=!sleep 1', 'stall'],
            $repository,
            timeoutSeconds: 0.2,
            boundary: $boundary,
        );
        $elapsed = microtime(true) - $started;

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('could not be killed', $result['stderr']);
        self::assertStringContainsString('did not bound this call', $result['stderr']);
        self::assertSame(1, $boundary->terminateCalls);
        self::assertSame(1, $boundary->closeProcessCalls, 'the child is still reaped');
        self::assertGreaterThan(
            0.5,
            $elapsed,
            'the documented consequence: the call runs for the child, not for the deadline',
        );
    }

    public function test_a_kill_that_is_not_delivered_cannot_come_back_as_a_clean_run(): void
    {
        $repository = $this->repositoryWithOneCommit();

        // A child that finishes on its own before the deadline never
        // reaches terminate(), so force the deadline with a slow one.
        $result = runGit(
            ['-c', 'alias.stall=!sleep 1', 'stall'],
            $repository,
            timeoutSeconds: 0.2,
            boundary: new StubProcessBoundary(failAt: 'terminate'),
        );

        self::assertTrue($result['timedOut']);
        self::assertNotSame(0, $result['exitCode']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function drainFailures(): iterable
    {
        yield 'select fails' => ['select', 'waiting on git output failed'];
        yield 'read fails' => ['read', 'reading git output failed'];
        yield 'select fails after the child exits' => ['selectAfterExit', 'waiting on git output failed'];
        yield 'read fails after the child exits' => ['readAfterExit', 'reading git output failed'];
    }

    /**
     * Whatever git wrote before the failure is part of an answer nobody
     * finished reading, so the run fails. Coming back with exit 0 and a
     * short stdout is the shape a caller cannot tell from a real one.
     */
    /** @param 'select'|'read'|'selectAfterExit'|'readAfterExit' $failAt */
    #[DataProvider('drainFailures')]
    public function test_a_failure_while_reading_output_never_comes_back_as_a_clean_run(
        string $failAt,
        string $expected,
    ): void {
        $repository = $this->repositoryWithOneCommit();

        $result = runGit(
            // Enough output that the pipes still hold data throughout,
            // so a failure has something left to be a failure about.
            ['-c', 'alias.flood=!sh -c "yes kinetis | head -c 200000"', 'flood'],
            $repository,
            timeoutSeconds: 20,
            boundary: new StubProcessBoundary(failAt: $failAt),
        );

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString($expected, $result['stderr']);
        self::assertFalse($result['timedOut']);
    }

    public function test_a_status_that_cannot_be_read_never_comes_back_as_a_clean_run(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary(failAt: 'status');

        $result = runGit(['-c', 'alias.say=!echo hi', 'say'], $repository, timeoutSeconds: 10, boundary: $boundary);

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('status became unreadable', $result['stderr']);
        self::assertSame(1, $boundary->terminateCalls, 'a child of unknown state is killed');
        self::assertSame(1, $boundary->closeProcessCalls);
    }

    /**
     * A child whose end this code could not establish is a child whose
     * run never finished as far as it knows, so what it wrote is not an
     * answer.
     */
    public function test_a_reap_that_fails_turns_an_otherwise_clean_run_into_a_failure(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $boundary = new StubProcessBoundary(failAt: 'closeProcess');

        $result = runGit(['-c', 'alias.say=!echo hi', 'say'], $repository, timeoutSeconds: 10, boundary: $boundary);

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('could not be reaped', $result['stderr']);
        self::assertSame(1, $boundary->closeProcessCalls);
    }

    public function test_a_reap_that_fails_does_not_turn_truncated_output_into_success(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $result = runGit(
            ['-c', 'alias.say=!printf "aaaaaaaaaa\0bbbbbbbbbb\0"', 'say'],
            $repository,
            timeoutSeconds: 10,
            outputLimit: 11,
            boundary: new StubProcessBoundary(failAt: 'closeProcess'),
        );

        self::assertNotSame(0, $result['exitCode']);
        self::assertTrue($result['truncated']);
    }

    /**
     * The failure this guards against end to end: the first package's
     * path and its NUL terminator fit under the cap, a second package's
     * path lies beyond it, and the shorter list is a syntactically
     * perfect NUL-separated answer. Returning it would let that second
     * package's files change with no version bump.
     */
    public function test_a_diff_cut_on_a_record_boundary_throws_rather_than_omitting_a_package(): void
    {
        $repository = $this->repositoryWithOneCommit();
        mkdir("{$repository}/packages/zzz", 0o777, true);
        file_put_contents("{$repository}/packages/demo/src/Thing.php", "<?php // changed\n");
        file_put_contents("{$repository}/packages/zzz/Other.php", "<?php\n");
        $this->git($repository, 'add', '-A');

        $commit = gitResolveCommit('HEAD', $repository);
        $whole = changedPackagePaths($commit, $repository);

        self::assertSame(['packages/demo/src/Thing.php', 'packages/zzz/Other.php'], $this->sorted($whole));

        // Exactly the first record and its terminator.
        $cap = strlen("packages/demo/src/Thing.php\0");
        $cut = runGit(
            ['diff', '--no-renames', '-z', '--name-only', '--end-of-options', $commit, '--', 'packages'],
            $repository,
            outputLimit: $cap,
        );

        self::assertSame("packages/demo/src/Thing.php\0", $cut['stdout'], 'the visible prefix is a whole record');
        self::assertSame(['packages/demo/src/Thing.php'], splitNulSeparatedPaths($cut['stdout']));
        self::assertTrue($cut['truncated']);

        try {
            changedPackagePaths($commit, $repository, gitRunnerFor($repository, outputLimit: $cap));
            self::fail('an incomplete diff must not come back as a complete one');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString('Could not diff', $e->getMessage());
            self::assertStringNotContainsString('packages/zzz', $e->getMessage());
        }
    }

    /** @return iterable<string, array{float, int, int}> */
    public static function fractionalDurations(): iterable
    {
        yield 'a whole second' => [1.0, 1, 0];
        yield 'half a second' => [0.5, 0, 500_000];
        yield 'the poll interval' => [0.05, 0, 50_000];
        yield 'just over a second' => [1.25, 1, 250_000];
        yield 'zero' => [0.0, 0, 0];
        yield 'negative' => [-1.0, 0, 0];
    }

    /**
     * A sub-second wait split as whole seconds alone becomes a zero
     * timeout, and the loop spins on the CPU until the deadline.
     */
    #[DataProvider('fractionalDurations')]
    public function test_a_fractional_wait_keeps_its_microseconds(float $seconds, int $whole, int $microseconds): void
    {
        self::assertSame([$whole, $microseconds], splitDuration($seconds));
    }


    public function test_git_failing_to_start_is_reported_rather_than_looking_like_a_clean_run(): void
    {
        $result = runGit(['status'], '/this/directory/does/not/exist');

        self::assertNotSame(0, $result['exitCode']);
    }

    public function test_a_real_repository_reports_which_commits_exist(): void
    {
        $repository = $this->repositoryWithOneCommit();

        self::assertTrue(gitCommitExists('HEAD', $repository));
        self::assertFalse(gitCommitExists('HEAD^', $repository), 'the first commit has no parent');
        self::assertFalse(gitCommitExists(str_repeat('d', 40), $repository));
    }

    /**
     * git answers "no such commit" with exit 1 and "I could not run"
     * with anything else. Folding the second into false is what lets a
     * broken checkout read as a clean first commit.
     */
    public function test_a_lookup_outside_a_repository_fails_rather_than_reporting_the_commit_absent(): void
    {
        $outside = sys_get_temp_dir() . '/kinetis-not-a-repo-' . bin2hex(random_bytes(6));
        $this->repositories[] = $outside;
        mkdir($outside, 0o777, true);

        $this->expectException(HistoryUnavailable::class);

        gitCommitExists('HEAD', $outside);
    }

    public function test_a_real_repository_reports_that_it_is_not_shallow(): void
    {
        self::assertFalse(gitIsShallow($this->repositoryWithOneCommit()));
    }

    public function test_a_shallow_clone_reports_that_it_is_shallow(): void
    {
        self::assertTrue(gitIsShallow($this->shallowCloneOf($this->repositoryWithTwoCommits())));
    }

    public function test_a_shallowness_probe_outside_a_repository_fails(): void
    {
        $outside = sys_get_temp_dir() . '/kinetis-not-a-repo-' . bin2hex(random_bytes(6));
        $this->repositories[] = $outside;
        mkdir($outside, 0o777, true);

        $this->expectException(HistoryUnavailable::class);

        gitIsShallow($outside);
    }

    /**
     * @param array{exitCode?: int, stdout?: string, stderr?: string, timedOut?: bool, truncated?: bool} $result
     * @return callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
     */
    private function gitReturning(array $result): callable
    {
        return static fn (array $args): array => [
            'exitCode' => $result['exitCode'] ?? 0,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'timedOut' => $result['timedOut'] ?? false,
            'truncated' => $result['truncated'] ?? false,
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function successesThatNameNoObject(): iterable
    {
        yield 'nothing at all' => [''];
        yield 'whitespace' => ["  \n"];
        yield 'a short id' => ['abc123'];
        yield 'a message' => ['warning: something happened'];
        yield 'a ref name' => ['refs/heads/main'];
        yield 'uppercase hex' => [str_repeat('A', 40)];
    }

    /**
     * Exit 0 is not by itself an answer. rev-parse succeeding without
     * naming an object leaves the question open, and reading that as "no
     * such commit" is what makes a broken checkout look clean — false is
     * reserved for the exit-1 absence.
     */
    #[DataProvider('successesThatNameNoObject')]
    public function test_a_successful_lookup_that_names_no_object_fails_rather_than_reporting_absence(
        string $stdout,
    ): void {
        try {
            gitCommitExists('HEAD', '/unused', $this->gitReturning(['stdout' => $stdout]));
            self::fail('a success that names nothing must throw');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString('without naming an object id', $e->getMessage());
        }
    }

    public function test_a_successful_lookup_naming_an_object_reports_the_commit_present(): void
    {
        $found = gitCommitExists('HEAD', '/unused', $this->gitReturning(['stdout' => str_repeat('a', 40) . "\n"]));

        self::assertTrue($found);
    }

    public function test_only_exit_one_reports_a_commit_absent(): void
    {
        self::assertFalse(gitCommitExists('HEAD', '/unused', $this->gitReturning(['exitCode' => 1])));

        foreach ([2, 128, -1] as $exitCode) {
            try {
                gitCommitExists('HEAD', '/unused', $this->gitReturning(['exitCode' => $exitCode]));
                self::fail("exit {$exitCode} must not read as absence");
            } catch (HistoryUnavailable) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_lookup_that_timed_out_never_reports_absence(): void
    {
        $this->expectException(HistoryUnavailable::class);

        gitCommitExists('HEAD', '/unused', $this->gitReturning(['exitCode' => 1, 'timedOut' => true]));
    }

    public function test_resolving_a_ref_to_something_that_is_not_an_object_id_fails(): void
    {
        $this->expectException(HistoryUnavailable::class);

        gitResolveCommit('HEAD', '/unused', $this->gitReturning(['stdout' => "refs/heads/main\n"]));
    }

    /** @return iterable<string, array{string, ?bool}> */
    public static function shallowAnswers(): iterable
    {
        yield 'true' => ["true\n", true];
        yield 'false' => ["false\n", false];
        yield 'nothing' => ['', null];
        yield 'whitespace' => ["  \n", null];
        yield 'a different word' => ["perhaps\n", null];
        yield 'capitalised' => ["True\n", null];
        yield 'both words' => ["true\nfalse\n", null];
    }

    /**
     * This answer decides whether a parentless HEAD is a root commit or
     * a missing one, so anything other than git's own two words is no
     * answer and the run stops.
     */
    #[DataProvider('shallowAnswers')]
    public function test_the_shallowness_check_accepts_only_git_own_two_words(string $stdout, ?bool $expected): void
    {
        $run = $this->gitReturning(['stdout' => $stdout]);

        if ($expected === null) {
            $this->expectException(HistoryUnavailable::class);

            gitIsShallow('/unused', $run);

            return;
        }

        self::assertSame($expected, gitIsShallow('/unused', $run));
    }

    public function test_resolving_a_ref_returns_a_full_object_id(): void
    {
        $repository = $this->repositoryWithOneCommit();

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', gitResolveCommit('HEAD', $repository));
    }

    public function test_resolving_a_ref_that_is_not_there_fails(): void
    {
        $this->expectException(HistoryUnavailable::class);

        gitResolveCommit(str_repeat('e', 40), $this->repositoryWithOneCommit());
    }

    public function test_reading_the_manifest_at_a_commit_returns_it_decoded(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $manifest = readManifestAtCommit(gitResolveCommit('HEAD', $repository), $repository);

        self::assertSame('1.0.0', $manifest['packages']['demo']['version']);
    }

    public function test_reading_the_manifest_at_a_commit_that_has_none_fails(): void
    {
        $repository = $this->repositoryWithOneCommit();

        $this->expectException(HistoryUnavailable::class);

        readManifestAtCommit(str_repeat('0', 39) . '1', $repository);
    }

    /**
     * A manifest that git can read but nothing can parse. Treating that
     * as "no history" would pass every version check on the one push
     * whose history is broken.
     */
    public function test_a_malformed_historical_manifest_fails_rather_than_skipping(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $this->replaceManifest($repository, "{ not json at all\n");

        try {
            readManifestAtCommit(gitResolveCommit('HEAD', $repository), $repository);
            self::fail('a malformed historical manifest must throw');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidHistoricalManifests(): iterable
    {
        yield 'no packages map' => ['{"defaults": {}}', "'packages' is missing"];
        yield 'a scalar root' => ['"a string"', 'expected a JSON object'];
        yield 'a list root' => ['[1, 2]', 'expected a JSON object'];
        yield 'a noncanonical version' => [
            self::historicalManifest(['version' => '1.0.01']),
            'not a canonical X.Y.Z version',
        ];
        yield 'a version off the 1.x line' => [
            self::historicalManifest(['version' => '2.0.0']),
            'not on the 1.x line',
        ];
        yield 'a mistyped field' => [
            self::historicalManifest(['description' => 7]),
            "'description' must be a non-empty single-line string",
        ];
        yield 'a mistyped sibling list' => [
            self::historicalManifest(['requires' => ['ghost' => '^1.0']]),
            "'requires' must be a list",
        ];
        yield 'a sibling that is not a package' => [
            self::historicalManifest(['requires' => ['ghost']]),
            'not a package in this manifest',
        ];
        yield 'a self-referencing sibling' => [
            self::historicalManifest(['requires' => ['demo']]),
            'lists the package itself',
        ];
        yield 'an unsafe package key' => [
            self::historicalManifest(key: '../etc'),
            'not a safe package key',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function historicalManifest(array $overrides = [], string $key = 'demo'): string
    {
        return json_encode(
            [
                'defaults' => ManifestFixture::defaults(),
                'packages' => [
                    $key => [
                        'name' => "kinetis/{$key}",
                        'description' => 'the demo package',
                        'namespace' => 'Kinetis\\Demo\\',
                        'version' => '1.0.0',
                        ...$overrides,
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The historical document goes through the same structure and
     * version rules as the one on disk, minus the checks that describe
     * the working tree rather than that commit.
     */
    #[DataProvider('invalidHistoricalManifests')]
    public function test_a_historical_manifest_that_fails_the_schema_fails_the_run(string $json, string $expected): void
    {
        $repository = $this->repositoryWithOneCommit();
        $this->replaceManifest($repository, $json . "\n");

        try {
            readManifestAtCommit(gitResolveCommit('HEAD', $repository), $repository);
            self::fail('an invalid historical manifest must throw');
        } catch (HistoryUnavailable $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function test_diffing_against_a_commit_git_cannot_read_fails(): void
    {
        $this->expectException(HistoryUnavailable::class);

        changedPackagePaths(str_repeat('0', 39) . '1', $this->repositoryWithOneCommit());
    }

    /**
     * Rename detection collapses a move into its destination alone,
     * which loses the package that gave the file up — and that package's
     * next release drops it.
     */
    public function test_a_cross_package_move_is_attributed_to_both_packages(): void
    {
        $repository = $this->repositoryWithOneCommit();
        mkdir("{$repository}/packages/other", 0o777, true);
        rename("{$repository}/packages/demo/src/Thing.php", "{$repository}/packages/other/Thing.php");
        $this->git($repository, 'add', '-A');

        $changed = changedPackagePaths(gitResolveCommit('HEAD', $repository), $repository);

        self::assertContains('packages/demo/src/Thing.php', $changed);
        self::assertContains('packages/other/Thing.php', $changed);
    }

    public function test_a_within_package_rename_is_attributed_to_that_package_only(): void
    {
        $repository = $this->repositoryWithOneCommit();
        rename("{$repository}/packages/demo/src/Thing.php", "{$repository}/packages/demo/src/Renamed.php");
        $this->git($repository, 'add', '-A');

        $changed = changedPackagePaths(gitResolveCommit('HEAD', $repository), $repository);

        self::assertSame(
            ['packages/demo/src/Renamed.php', 'packages/demo/src/Thing.php'],
            $this->sorted($changed),
        );
    }

    public function test_a_move_of_a_path_holding_spaces_and_quotes_survives_intact(): void
    {
        $repository = $this->repositoryWithOneCommit();
        $awkward = 'a file "with" spaces.php';
        file_put_contents("{$repository}/packages/demo/src/{$awkward}", "<?php\n");
        $this->git($repository, 'add', '-A');
        $this->git($repository, 'commit', '-q', '-m', 'add an awkward path');

        mkdir("{$repository}/packages/other/src", 0o777, true);
        rename("{$repository}/packages/demo/src/{$awkward}", "{$repository}/packages/other/src/{$awkward}");
        $this->git($repository, 'add', '-A');

        $changed = changedPackagePaths(gitResolveCommit('HEAD', $repository), $repository);

        self::assertSame([
            "packages/demo/src/{$awkward}",
            "packages/other/src/{$awkward}",
        ], $this->sorted($changed));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function sorted(array $paths): array
    {
        sort($paths);

        return $paths;
    }

    private function replaceManifest(string $repository, string $contents): void
    {
        file_put_contents("{$repository}/packages.manifest.json", $contents);
        $this->git($repository, 'commit', '-q', '-am', 'replace the manifest');
    }

    private function repositoryWithOneCommit(): string
    {
        $repository = sys_get_temp_dir() . '/kinetis-history-' . bin2hex(random_bytes(6));
        $this->repositories[] = $repository;
        mkdir("{$repository}/packages/demo/src", 0o777, true);

        file_put_contents("{$repository}/packages/demo/src/Thing.php", "<?php\n");
        file_put_contents("{$repository}/packages.manifest.json", ManifestFixture::json());

        $this->git($repository, 'init', '-q', '-b', 'main');
        $this->git($repository, 'config', 'user.email', 'test@example.com');
        $this->git($repository, 'config', 'user.name', 'test');
        $this->git($repository, 'add', '-A');
        $this->git($repository, 'commit', '-q', '-m', 'initial');

        return $repository;
    }

    private function repositoryWithTwoCommits(): string
    {
        $repository = $this->repositoryWithOneCommit();
        file_put_contents("{$repository}/packages.manifest.json", ManifestFixture::json(['demo' => '1.0.1']));
        $this->git($repository, 'commit', '-q', '-am', 'demo: bump');

        return $repository;
    }

    private function shallowCloneOf(string $repository): string
    {
        $clone = sys_get_temp_dir() . '/kinetis-shallow-' . bin2hex(random_bytes(6));
        $this->repositories[] = $clone;
        $result = runGit(['clone', '--depth', '1', '-q', 'file://' . $repository, $clone], sys_get_temp_dir());

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        return $clone;
    }

    private function git(string $repository, string ...$args): void
    {
        $result = runGit(array_values($args), $repository);

        self::assertSame(0, $result['exitCode'], "git {$args[0]} failed: {$result['stderr']}");
    }
}
