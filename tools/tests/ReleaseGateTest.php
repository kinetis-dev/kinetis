<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../release-gate.php';

final class ReleaseGateTest extends TestCase
{
    private const string CI = '.github/workflows/ci.yml';
    private const string VALIDATE = '.github/workflows/monorepo-validate.yml';
    private const string SEMGREP = '.github/workflows/semgrep.yml';
    private const string SHA = 'deadbeef';

    public function test_every_required_workflow_succeeding_is_a_pass(): void
    {
        $verdict = gateVerdict(self::states('success', 'success', 'success'));

        self::assertTrue($verdict['done']);
        self::assertSame([], $verdict['problems']);
    }

    public function test_a_failed_required_workflow_is_a_problem_rather_than_a_wait(): void
    {
        $verdict = gateVerdict(self::states('failed', 'success', 'success'));

        self::assertSame(['CI did not succeed for this commit.'], $verdict['problems']);
    }

    public function test_a_running_workflow_is_waited_on(): void
    {
        $verdict = gateVerdict(self::states('pending', 'success', 'success'));

        self::assertFalse($verdict['done']);
        self::assertSame(['CI'], $verdict['waiting']);
        self::assertSame([], $verdict['problems']);
    }

    public function test_a_workflow_with_no_run_at_this_commit_is_waited_on(): void
    {
        $verdict = gateVerdict([self::VALIDATE => 'success', self::SEMGREP => 'success']);

        self::assertSame(['CI'], $verdict['waiting']);
    }

    public function test_a_workflow_outside_the_required_set_is_ignored(): void
    {
        $verdict = gateVerdict([
            ...self::states('success', 'success', 'success'),
            '.github/workflows/sonarqube.yml' => 'failed',
        ]);

        self::assertTrue($verdict['done']);
        self::assertSame([], $verdict['problems']);
    }

    public function test_a_completed_successful_run_reads_as_success(): void
    {
        $states = self::statesFor([self::pushRun(self::CI, 'completed', 'success')]);

        self::assertSame([self::CI => 'success'], $states);
    }

    public function test_an_unfinished_run_reads_as_pending(): void
    {
        $states = self::statesFor([self::pushRun(self::CI, 'in_progress', null)]);

        self::assertSame([self::CI => 'pending'], $states);
    }

    public function test_a_cancelled_run_reads_as_failed(): void
    {
        $states = self::statesFor([self::pushRun(self::CI, 'completed', 'cancelled')]);

        self::assertSame([self::CI => 'failed'], $states);
    }

    /**
     * A rerun after a failure is the ordinary way a commit's CI turns
     * green, so any successful run at this SHA settles that workflow —
     * whichever order the API happens to list the two runs in.
     */
    public function test_a_successful_rerun_settles_a_workflow_that_failed_first(): void
    {
        $failedFirst = self::statesFor([
            self::pushRun(self::CI, 'completed', 'failure'),
            self::pushRun(self::CI, 'completed', 'success'),
        ]);
        $successFirst = self::statesFor([
            self::pushRun(self::CI, 'completed', 'success'),
            self::pushRun(self::CI, 'completed', 'failure'),
        ]);

        self::assertSame([self::CI => 'success'], $failedFirst);
        self::assertSame([self::CI => 'success'], $successFirst);
    }

    public function test_a_rerun_still_running_keeps_a_failed_workflow_open_whichever_order_it_arrives_in(): void
    {
        $failedFirst = self::statesFor([
            self::pushRun(self::CI, 'completed', 'failure'),
            self::pushRun(self::CI, 'in_progress', null),
        ]);
        $pendingFirst = self::statesFor([
            self::pushRun(self::CI, 'in_progress', null),
            self::pushRun(self::CI, 'completed', 'failure'),
        ]);

        self::assertSame([self::CI => 'pending'], $failedFirst);
        self::assertSame([self::CI => 'pending'], $pendingFirst);
    }

    /**
     * A merged pull request's own runs sit at the same head SHA as the
     * push that merged it. Accepting one would let a green pull request
     * publish a commit whose main run failed.
     */
    public function test_a_pull_request_success_does_not_answer_for_a_failed_main_run(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'pull_request', 'head_branch' => 'topic',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
            self::pushRun(self::CI, 'completed', 'failure'),
        ]);

        self::assertSame([self::CI => 'failed'], $states);
        self::assertSame(['CI did not succeed for this commit.'], gateVerdict($states)['problems']);
    }

    public function test_a_pull_request_success_does_not_answer_for_a_pending_main_run(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'pull_request', 'head_branch' => 'topic',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
            self::pushRun(self::CI, 'queued', null),
        ]);

        self::assertSame([self::CI => 'pending'], $states);
        self::assertContains('CI', gateVerdict($states)['waiting']);
    }

    public function test_a_pull_request_run_alone_leaves_the_workflow_unanswered(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'pull_request', 'head_branch' => 'topic',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([], $states);
        self::assertContains('CI', gateVerdict($states)['waiting']);
    }

    /**
     * A run's display name is whatever its `name:` field said when it
     * ran, and nothing stops two workflows from carrying the same one.
     * The file path is what identifies the workflow.
     */
    public function test_workflows_are_identified_by_path_rather_than_display_name(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'push', 'head_branch' => 'main',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'failure'],
            ['name' => 'CI', 'path' => self::SEMGREP, 'event' => 'push', 'head_branch' => 'main',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([self::CI => 'failed', self::SEMGREP => 'success'], $states);
    }

    public function test_a_renamed_workflow_still_answers_for_its_own_file(): void
    {
        $states = self::statesFor([
            ['name' => 'Monorepo checks', 'path' => self::VALIDATE, 'event' => 'push', 'head_branch' => 'main',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([self::VALIDATE => 'success'], $states);
    }

    public function test_a_run_at_another_commit_is_not_evidence_about_this_one(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'push', 'head_branch' => 'main',
                'head_sha' => 'another', 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([], $states);
    }

    public function test_a_run_on_another_branch_is_not_evidence_about_main(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => self::CI, 'event' => 'push', 'head_branch' => 'release/1.x',
                'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([], $states);
    }

    public function test_a_workflow_outside_the_required_files_is_not_recorded(): void
    {
        $states = self::statesFor([
            ['name' => 'CI', 'path' => '.github/workflows/sonarqube.yml', 'event' => 'push',
                'head_branch' => 'main', 'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
        ]);

        self::assertSame([], $states);
    }

    public function test_the_listing_asks_for_this_commit_own_push_runs_on_main(): void
    {
        $asked = null;
        workflowStates('kinetis-dev/kinetis', self::SHA, function (string $path) use (&$asked): array {
            $asked = $path;

            return ['workflow_runs' => []];
        });

        self::assertSame(
            '/repos/kinetis-dev/kinetis/actions/runs?head_sha=deadbeef&event=push&branch=main&per_page=100',
            $asked,
        );
    }

    public function test_a_listing_with_no_runs_key_ends_the_run(): void
    {
        $this->expectExceptionMessage('carried no workflow_runs');

        workflowStates('kinetis-dev/kinetis', self::SHA, static fn (): array => ['message' => 'Not Found']);
    }

    public function test_the_gate_returns_once_everything_is_green(): void
    {
        $waits = 0;
        waitForRequiredWorkflows(
            'kinetis-dev/kinetis',
            self::SHA,
            self::listing(self::runsFor('completed', 'success')),
            function () use (&$waits): void {
                $waits++;
            },
            time() + 60,
        );

        self::assertSame(0, $waits);
    }

    public function test_the_gate_waits_and_then_passes(): void
    {
        $rounds = 0;
        $read = function () use (&$rounds): array {
            $rounds++;

            return ['workflow_runs' => $rounds === 1
                ? self::runsFor('in_progress', null)
                : self::runsFor('completed', 'success')];
        };

        waitForRequiredWorkflows('kinetis-dev/kinetis', self::SHA, $read, static fn (): null => null, time() + 60);

        self::assertSame(2, $rounds);
    }

    public function test_a_workflow_still_running_at_the_deadline_fails(): void
    {
        $this->expectExceptionMessage('Still waiting on CI, Monorepo Validate, Semgrep at the deadline.');

        waitForRequiredWorkflows(
            'kinetis-dev/kinetis',
            self::SHA,
            self::listing(self::runsFor('in_progress', null)),
            static fn (): null => null,
            time() - 1,
        );
    }

    public function test_a_failure_ends_the_run_without_waiting_for_the_deadline(): void
    {
        $this->expectExceptionMessage('Semgrep did not succeed for this commit.');

        waitForRequiredWorkflows(
            'kinetis-dev/kinetis',
            self::SHA,
            self::listing([
                self::pushRun(self::CI, 'completed', 'success'),
                self::pushRun(self::VALIDATE, 'completed', 'success'),
                self::pushRun(self::SEMGREP, 'completed', 'failure'),
            ]),
            static fn (): null => null,
            time() + 3600,
        );
    }

    public function test_both_arguments_are_required(): void
    {
        self::assertSame(
            ['--sha is required.', '--repo is required.'],
            parseGateArguments([])['problems'],
        );
    }

    public function test_an_unknown_option_is_named(): void
    {
        self::assertContains('Unknown option: --nope', parseGateArguments(['--nope'])['problems']);
    }

    public function test_the_two_arguments_are_read(): void
    {
        $parsed = parseGateArguments(['--sha=abc', '--repo=kinetis-dev/kinetis']);

        self::assertSame([], $parsed['problems']);
        self::assertSame('abc', $parsed['sha']);
        self::assertSame('kinetis-dev/kinetis', $parsed['repo']);
    }

    /** @return array<string, string> */
    private static function states(string $ci, string $validate, string $semgrep): array
    {
        return [self::CI => $ci, self::VALIDATE => $validate, self::SEMGREP => $semgrep];
    }

    /** One push run on main for this commit. @return array<string, mixed> */
    private static function pushRun(string $path, string $status, ?string $conclusion): array
    {
        return [
            'name' => REQUIRED_WORKFLOWS[$path] ?? 'Something',
            'path' => $path,
            'event' => 'push',
            'head_branch' => 'main',
            'head_sha' => self::SHA,
            'status' => $status,
            'conclusion' => $conclusion,
        ];
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array<string, string>
     */
    private static function statesFor(array $runs): array
    {
        return workflowStates('kinetis-dev/kinetis', self::SHA, self::listing($runs));
    }

    /** @return list<array<string, mixed>> */
    private static function runsFor(string $status, ?string $conclusion): array
    {
        return array_map(
            static fn (string $path): array => self::pushRun($path, $status, $conclusion),
            array_keys(REQUIRED_WORKFLOWS),
        );
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return callable(string): array<string, mixed>
     */
    private static function listing(array $runs): callable
    {
        return static fn (): array => ['workflow_runs' => $runs];
    }
}
