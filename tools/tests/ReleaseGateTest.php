<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use HistoryUnavailable;
use HttpBoundary;
use NativeHttpBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGateFailure;
use RequiredWorkflow;

require_once __DIR__ . '/../release-gate.php';

/** Answers the gate's API reads from a fixed table. */
final class StubHttpBoundary implements HttpBoundary
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, array{status: int, body: string}> $responses keyed by a substring of the URL */
    public function __construct(private readonly array $responses)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    #[\Override]
    public function get(string $url, array $headers, float $timeoutSeconds): array
    {
        $this->requested[] = $url;

        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => ''];
    }
}

/**
 * Answers a listing one page at a time, the way the API does: a full
 * page means there may be more, and a short one is the end.
 */
final class PagedHttpBoundary implements HttpBoundary
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, array{collection: string, pages: list<list<mixed>>}> $listings keyed by a substring of the URL */
    public function __construct(private readonly array $listings)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    #[\Override]
    public function get(string $url, array $headers, float $timeoutSeconds): array
    {
        $this->requested[] = $url;
        $page = preg_match('/[?&]page=(\d+)/', $url, $m) === 1 ? (int) $m[1] : 1;

        foreach ($this->listings as $needle => $listing) {
            if (str_contains($url, $needle)) {
                return [
                    'status' => 200,
                    'body' => (string) json_encode(
                        [$listing['collection'] => $listing['pages'][$page - 1] ?? []],
                        JSON_THROW_ON_ERROR,
                    ),
                ];
            }
        }

        return ['status' => 404, 'body' => ''];
    }
}

/**
 * Stands in for the https:// stream so what the boundary asks the
 * stream layer for can be read back.
 */
final class RecordingStreamWrapper
{
    /** @var array<string, array<string, mixed>> the context options, wrapper by wrapper */
    public static array $options = [];

    /** @var resource|null */
    public $context;

    /**
     * Clears what an earlier open recorded, through a call: assigning
     * the empty array at the call site narrows the property to it for
     * the rest of that scope, ahead of the open that fills it.
     */
    public static function forget(): void
    {
        self::$options = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$options = is_resource($this->context) ? stream_context_get_options($this->context) : [];

        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}

/**
 * The gate decides whether one exact commit may be published, so every
 * test here is about a state that must not be read as a pass.
 */
final class ReleaseGateTest extends TestCase
{
    private const SHA = '9f1c0a0e1f3b7a2d4c5e6f708192a3b4c5d6e7f8';

    private const OTHER_SHA = '1a2b3c4d5e6f708192a3b4c5d6e7f89f1c0a0e1f';

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            exec('rm -rf ' . escapeshellarg($directory));
        }

        $this->directories = [];
    }

    public function test_a_successful_run_whose_jobs_did_the_work_satisfies_the_gate(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/')],
            self::SHA,
            [$this->runFor('CI', self::SHA, 'completed', 'success')],
            $this->jobs([['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success']]),
        );

        self::assertSame('satisfied', $result['state']);
    }

    /**
     * The state this gate exists for: a path-filtered workflow reports a
     * green run for a commit whose matrix never expanded. Nothing about
     * the released content was checked.
     */
    public function test_a_successful_run_whose_meaningful_jobs_all_skipped_fails_the_gate(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/')],
            self::SHA,
            [$this->runFor('CI', self::SHA, 'completed', 'success')],
            $this->jobs([['name' => 'Detect changed areas', 'status' => 'completed', 'conclusion' => 'success']]),
        );

        self::assertSame('failed', $result['state']);
        self::assertStringContainsString('without running the jobs this gate requires', $result['reasons'][0]);
    }

    /** @return iterable<string, array{string}> */
    public static function jobConclusionsThatFail(): iterable
    {
        yield 'a failure' => ['failure'];
        yield 'a cancellation' => ['cancelled'];
        yield 'a timeout' => ['timed_out'];
        yield 'work waiting on approval' => ['action_required'];
        yield 'a stale result' => ['stale'];
    }

    #[DataProvider('jobConclusionsThatFail')]
    public function test_a_job_that_did_not_finish_its_work_fails_the_gate(string $conclusion): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/')],
            self::SHA,
            [$this->runFor('CI', self::SHA, 'completed', 'success')],
            $this->jobs([
                ['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success'],
                ['name' => 'queue (PHP 8.5)', 'status' => 'completed', 'conclusion' => $conclusion],
            ]),
        );

        self::assertSame('failed', $result['state']);
    }

    public function test_a_failed_run_fails_the_gate(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('Semgrep', '//')],
            self::SHA,
            [$this->runFor('Semgrep', self::SHA, 'completed', 'failure')],
            $this->jobs([]),
        );

        self::assertSame('failed', $result['state']);
        self::assertStringContainsString('concluded failure', $result['reasons'][0]);
    }

    public function test_a_run_still_going_leaves_the_gate_pending(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('Semgrep', '//')],
            self::SHA,
            [$this->runFor('Semgrep', self::SHA, 'in_progress', null)],
            $this->jobs([]),
        );

        self::assertSame('pending', $result['state']);
    }

    public function test_a_required_workflow_with_no_run_at_all_leaves_the_gate_pending(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('Monorepo Validate', '//')],
            self::SHA,
            [],
            $this->jobs([]),
        );

        self::assertSame('pending', $result['state']);
        self::assertStringContainsString('has not started', $result['reasons'][0]);
    }

    /**
     * A release publishes content, and a run for a different commit says
     * nothing about this one — the two differ by exactly the change being
     * released.
     */
    public function test_a_success_belonging_to_another_commit_is_not_this_commit_s_success(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '//')],
            self::SHA,
            [$this->runFor('CI', self::OTHER_SHA, 'completed', 'success')],
            $this->jobs([['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success']]),
        );

        self::assertSame('pending', $result['state']);
    }

    public function test_a_re_run_is_judged_by_its_newest_attempt(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '//')],
            self::SHA,
            [
                ['name' => 'CI', 'id' => 1, 'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'failure'],
                ['name' => 'CI', 'id' => 2, 'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
            ],
            $this->jobs([['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success']]),
        );

        self::assertSame('satisfied', $result['state']);
    }

    public function test_one_failing_workflow_fails_the_whole_gate_even_while_another_waits(): void
    {
        $result = evaluateGate(
            [new RequiredWorkflow('CI', '//'), new RequiredWorkflow('Semgrep', '//')],
            self::SHA,
            [$this->runFor('CI', self::SHA, 'completed', 'failure')],
            $this->jobs([]),
        );

        self::assertSame('failed', $result['state']);
    }

    // --- waiting ----------------------------------------------------------

    public function test_the_wait_ends_as_soon_as_the_gate_is_satisfied(): void
    {
        $calls = 0;
        $slept = 0;
        $result = waitForGate(
            static function () use (&$calls): array {
                $calls++;

                return $calls > 1 ? ['state' => 'satisfied', 'reasons' => []] : ['state' => 'pending', 'reasons' => []];
            },
            static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            },
            static fn (): float => 0.0,
            600.0,
        );

        self::assertSame('satisfied', $result['state']);
        self::assertSame(2, $calls);
        self::assertSame(GATE_POLL_SECONDS, $slept);
    }

    public function test_a_failure_ends_the_wait_without_sleeping(): void
    {
        $slept = 0;
        $result = waitForGate(
            static fn (): array => ['state' => 'failed', 'reasons' => ['CI concluded failure']],
            static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            },
            static fn (): float => 0.0,
            600.0,
        );

        self::assertSame('failed', $result['state']);
        self::assertSame(0, $slept);
    }

    /** A workflow that never starts must not hold a publication open. */
    public function test_a_gate_still_pending_at_the_deadline_fails(): void
    {
        $now = 0.0;
        $result = waitForGate(
            static fn (): array => ['state' => 'pending', 'reasons' => ['CI has not started']],
            static function (int $seconds) use (&$now): void {
                $now += $seconds;
            },
            static function () use (&$now): float {
                return $now;
            },
            (float) GATE_POLL_SECONDS,
        );

        self::assertSame('failed', $result['state']);
        self::assertStringContainsString('still waiting after', $result['reasons'][1]);
    }

    // --- reading the API ---------------------------------------------------

    /** @return iterable<string, array{array{status: int, body: string}}> */
    public static function unusableApiResponses(): iterable
    {
        yield 'a rate limit' => [['status' => 403, 'body' => '{"message": "rate limited"}']];
        yield 'a missing repository' => [['status' => 404, 'body' => '']];
        yield 'a read that failed outright' => [['status' => 0, 'body' => '']];
        yield 'a login page' => [['status' => 200, 'body' => '<html>login</html>']];
        yield 'a document with no runs' => [['status' => 200, 'body' => '{"total_count": 0}']];
        yield 'a run in an unreadable shape' => [['status' => 200, 'body' => '{"workflow_runs": [{"name": 7}]}']];
    }

    /** @param array{status: int, body: string} $response */
    #[DataProvider('unusableApiResponses')]
    public function test_an_answer_the_gate_cannot_read_stops_it(array $response): void
    {
        $this->expectException(ReleaseGateFailure::class);

        workflowRunsFor(new StubHttpBoundary(['/actions/runs' => $response]), 'kinetis-dev/kinetis', self::SHA, 'token');
    }

    public function test_the_runs_are_asked_for_by_head_sha(): void
    {
        $http = new StubHttpBoundary(['/actions/runs' => ['status' => 200, 'body' => '{"workflow_runs": []}']]);

        workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');

        self::assertStringContainsString('head_sha=' . self::SHA, $http->requested[0]);
    }

    /**
     * A single per_page=100 read answers about the newest hundred runs
     * and nothing else. A busy commit has more than that, and the one
     * that decides the gate can be any of them — so reading one page and
     * calling it the whole set is how an older success stands in for a
     * newer failure.
     */
    public function test_a_newer_run_on_a_later_page_is_not_missed(): void
    {
        $older = [];

        for ($id = 1; $id <= 100; $id++) {
            $older[] = $this->runFor('CI', self::SHA, 'completed', 'success', $id);
        }

        $http = new PagedHttpBoundary(['/actions/runs?' => ['collection' => 'workflow_runs', 'pages' => [
            $older,
            [$this->runFor('CI', self::SHA, 'completed', 'failure', 200)],
        ]]]);

        $result = evaluateGate(
            [new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/')],
            self::SHA,
            workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token'),
            $this->jobs([['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success']]),
        );

        self::assertSame('failed', $result['state']);
        self::assertCount(2, $http->requested);
    }

    /** The same for a matrix wider than one page: the failure is on the second. */
    public function test_a_failed_job_on_a_later_page_is_not_missed(): void
    {
        $succeeded = [];

        for ($i = 1; $i <= 100; $i++) {
            $succeeded[] = ['name' => "package{$i} (PHP 8.4)", 'status' => 'completed', 'conclusion' => 'success'];
        }

        $http = new PagedHttpBoundary(['/jobs' => ['collection' => 'jobs', 'pages' => [
            $succeeded,
            [['name' => 'queue (PHP 8.5)', 'status' => 'completed', 'conclusion' => 'failure']],
        ]]]);

        $result = evaluateJobs(
            new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/'),
            workflowJobsFor($http, 'kinetis-dev/kinetis', 12, 'token'),
        );

        self::assertSame('failed', $result['state']);
        self::assertStringContainsString('queue (PHP 8.5)', $result['reason']);
    }

    public function test_a_listing_that_never_ends_stops_the_gate(): void
    {
        $full = array_fill(0, 100, $this->runFor('CI', self::SHA, 'completed', 'success', 1));
        $http = new PagedHttpBoundary(['/actions/runs?' => [
            'collection' => 'workflow_runs',
            'pages' => array_fill(0, GATE_MAX_PAGES + 5, $full),
        ]]);

        $this->expectException(ReleaseGateFailure::class);
        $this->expectExceptionMessageMatches('/did not end within/');

        workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');
    }

    public function test_a_page_carrying_more_than_it_was_asked_for_stops_the_gate(): void
    {
        $http = new PagedHttpBoundary(['/actions/runs?' => [
            'collection' => 'workflow_runs',
            'pages' => [array_fill(0, GATE_PAGE_SIZE + 1, $this->runFor('CI', self::SHA, 'completed', 'success', 1))],
        ]]);

        $this->expectException(ReleaseGateFailure::class);
        $this->expectExceptionMessageMatches('/more records than a page/');

        workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');
    }

    public function test_a_listing_that_is_not_a_list_stops_the_gate(): void
    {
        $http = new StubHttpBoundary([
            '/actions/runs' => ['status' => 200, 'body' => '{"workflow_runs": {"first": {"name": "CI"}}}'],
        ]);

        $this->expectException(ReleaseGateFailure::class);

        workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');
    }

    /**
     * A 3xx carrying an Authorization header to wherever its Location
     * names is the one answer this gate refuses outright rather than
     * following.
     */
    public function test_a_redirect_is_an_answer_the_gate_refuses(): void
    {
        $http = new StubHttpBoundary(['/actions/runs' => ['status' => 302, 'body' => '']]);

        $this->expectException(ReleaseGateFailure::class);
        $this->expectExceptionMessageMatches('/redirect/');

        workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');
    }

    /** @return iterable<string, array{string}> */
    public static function urlsTheTokenNeverReaches(): iterable
    {
        yield 'another host' => ['https://example.invalid/repos/x/actions/runs'];
        yield 'a host that merely starts the same way' => ['https://api.github.com.example.invalid/actions/runs'];
        yield 'a userinfo prefix' => ['https://api.github.com@example.invalid/actions/runs'];
        yield 'plain http' => ['http://api.github.com/actions/runs'];
        yield 'another port' => ['https://api.github.com:8443/actions/runs'];
    }

    /** The bearer token is in the headers, so where a read goes is where the token goes. */
    #[DataProvider('urlsTheTokenNeverReaches')]
    public function test_the_token_goes_to_the_github_api_and_nowhere_else(string $url): void
    {
        $http = new StubHttpBoundary([]);

        $this->expectException(ReleaseGateFailure::class);
        $this->expectExceptionMessageMatches('/sends its token nowhere else/');

        try {
            readApi($http, $url, apiHeaders('token'), 'a read that must not happen');
        } finally {
            self::assertSame([], $http->requested);
        }
    }

    /**
     * PHP's default is to follow a redirect with every header it was
     * given, which would hand the bearer token to whatever host the
     * Location names.
     */
    public function test_the_boundary_asks_the_stream_layer_not_to_follow_a_redirect(): void
    {
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', RecordingStreamWrapper::class);

        try {
            RecordingStreamWrapper::forget();
            new NativeHttpBoundary()->get('https://api.github.com/x', apiHeaders('token'), 5.0);
        } finally {
            stream_wrapper_restore('https');
        }

        $http = RecordingStreamWrapper::$options['http'];
        $header = $http['header'];

        self::assertSame(0, $http['follow_location']);
        self::assertIsString($header);
        self::assertStringContainsString('Authorization: Bearer token', $header);
    }

    public function test_a_run_and_its_jobs_are_read_as_they_arrive(): void
    {
        $http = new StubHttpBoundary([
            '/actions/runs/12/jobs' => ['status' => 200, 'body' => json_encode(['jobs' => [
                ['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success'],
            ]], JSON_THROW_ON_ERROR)],
            '/actions/runs?' => ['status' => 200, 'body' => json_encode(['workflow_runs' => [
                ['name' => 'CI', 'id' => 12, 'head_sha' => self::SHA, 'status' => 'completed', 'conclusion' => 'success'],
            ]], JSON_THROW_ON_ERROR)],
        ]);

        $runs = workflowRunsFor($http, 'kinetis-dev/kinetis', self::SHA, 'token');
        $jobs = workflowJobsFor($http, 'kinetis-dev/kinetis', 12, 'token');

        self::assertSame('CI', $runs[0]['name']);
        self::assertSame('core (PHP 8.4)', $jobs[0]['name']);
    }

    // --- which workflows apply ---------------------------------------------

    public function test_the_integration_filter_is_read_from_the_workflow_itself(): void
    {
        $paths = workflowPushPaths(__DIR__ . '/../../.github/workflows/integration.yml');

        self::assertContains('packages/**', $paths);
        self::assertContains('.github/workflows/integration.yml', $paths);
    }

    public function test_a_workflow_with_no_readable_filter_stays_required(): void
    {
        $workflows = applicableWorkflows(
            [new RequiredWorkflow('Integration', '//', '.github/workflows/nothing-here.yml')],
            ['docs/index.md'],
            (string) realpath(__DIR__ . '/../..'),
        );

        self::assertCount(1, $workflows);
    }

    public function test_a_path_filtered_workflow_is_required_when_the_diff_reaches_it(): void
    {
        $workflows = applicableWorkflows(
            [new RequiredWorkflow('Integration', '//', '.github/workflows/integration.yml')],
            ['packages/queue/src/Queue.php'],
            (string) realpath(__DIR__ . '/../..'),
        );

        self::assertCount(1, $workflows);
    }

    /**
     * A commit that changes only release metadata cannot have run a
     * workflow filtered on packages/, so requiring it would wait forever.
     */
    public function test_a_path_filtered_workflow_is_dropped_when_the_diff_proves_it_inapplicable(): void
    {
        $workflows = applicableWorkflows(
            [new RequiredWorkflow('Integration', '//', '.github/workflows/integration.yml')],
            ['packages.manifest.json'],
            (string) realpath(__DIR__ . '/../..'),
        );

        self::assertSame([], $workflows);
    }

    /** @return iterable<string, array{list<string>, list<string>, bool}> */
    public static function pathFilters(): iterable
    {
        yield 'a directory glob' => [['packages/queue/src/Queue.php'], ['packages/**'], true];
        yield 'a directory glob missing' => [['docs/queue.md'], ['packages/**'], false];
        yield 'an exact file' => [['packages.manifest.json'], ['packages.manifest.json'], true];
        yield 'a file that only looks similar' => [['packages.manifest.json.bak'], ['packages.manifest.json'], false];
        yield 'a single-segment glob' => [['docs/queue.md'], ['docs/*.md'], true];
        yield 'a single-segment glob not crossing a slash' => [['docs/api/queue.md'], ['docs/*.md'], false];
        yield 'nothing changed' => [[], ['packages/**'], false];
    }

    /**
     * @param list<string> $changed
     * @param list<string> $patterns
     */
    #[DataProvider('pathFilters')]
    public function test_a_path_filter_matches_what_github_would_match(array $changed, array $patterns, bool $expected): void
    {
        self::assertSame($expected, pathFilterMatches($changed, $patterns));
    }

    public function test_every_workflow_this_gate_requires_exists_in_the_repository(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $names = [];

        foreach (glob("{$root}/.github/workflows/*.yml") ?: [] as $file) {
            if (preg_match('/^name:\s*(.+)$/m', (string) file_get_contents($file), $m) === 1) {
                $names[] = trim($m[1]);
            }
        }

        foreach (requiredWorkflows() as $workflow) {
            self::assertContains($workflow->name, $names, "{$workflow->name} names no workflow in this repository");
        }
    }

    /**
     * Sonar and Infection report on quality trends rather than on whether
     * this commit is correct, and a release blocked on either would stop
     * for reasons that are not about the content being published.
     */
    public function test_the_gate_requires_the_four_workflows_that_judge_this_commit(): void
    {
        $names = array_map(static fn (RequiredWorkflow $w): string => $w->name, requiredWorkflows());

        self::assertSame(['CI', 'Monorepo Validate', 'Semgrep', 'Integration'], $names);
    }

    /**
     * A version bump changes the manifest and nothing else — the
     * generated composer.json carries no version field — and that commit
     * is the one a release gates on. Without the manifest in this
     * filter, the matrix that validates the released content is the one
     * thing that never runs for it.
     */
    public function test_a_manifest_change_activates_the_package_matrix(): void
    {
        $ci = (string) file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');
        $start = (int) strpos($ci, 'filters: |');
        $filters = substr($ci, $start, (int) strpos($ci, 'docs:', $start) - $start);

        self::assertStringContainsString("- 'packages.manifest.json'", $filters);
        self::assertStringContainsString("if: needs.changes.outputs.packages == 'true'", $ci);
    }

    // --- the token this gate reads with --------------------------------------

    public function test_a_missing_token_stops_the_gate_rather_than_reading_anonymously(): void
    {
        putenv(GATE_CREDENTIAL_VARIABLE);

        $this->expectException(ReleaseGateFailure::class);
        $this->expectExceptionMessageMatches('/is not set/');

        takeGateCredential();
    }

    public function test_reading_the_token_removes_it_from_the_environment(): void
    {
        putenv(GATE_CREDENTIAL_VARIABLE . '=ghs_secretvaluenobodyshouldsee');

        try {
            self::assertSame('ghs_secretvaluenobodyshouldsee', takeGateCredential());
            self::assertFalse(getenv(GATE_CREDENTIAL_VARIABLE));
            self::assertArrayNotHasKey(GATE_CREDENTIAL_VARIABLE, getenv());
        } finally {
            putenv(GATE_CREDENTIAL_VARIABLE);
        }
    }

    /**
     * Reading what the commit changed spawns git, and a child inherits
     * whatever is in this process's environment. So the whole diff is
     * read while the token is still ambient and taken only afterwards —
     * a read that fails proves the order, because the token is still
     * there to be found.
     */
    public function test_the_diff_is_read_before_the_token_leaves_the_environment(): void
    {
        $repository = $this->repositoryWithAParent();
        putenv(GATE_CREDENTIAL_VARIABLE . '=ghs_secretvaluenobodyshouldsee');

        try {
            runGate(
                ['--sha' => self::OTHER_SHA, '--repo' => 'kinetis-dev/kinetis', '--root' => $repository],
                new StubHttpBoundary([]),
                static function (int $seconds): void {
                },
                static fn (): float => 0.0,
            );
            self::fail('a diff this checkout cannot read must stop the gate');
        } catch (HistoryUnavailable $e) {
            self::assertSame('ghs_secretvaluenobodyshouldsee', getenv(GATE_CREDENTIAL_VARIABLE));
        } finally {
            putenv(GATE_CREDENTIAL_VARIABLE);
        }
    }

    /** Once the reads are done, the token is taken out and stays out. */
    public function test_a_gate_that_ran_leaves_no_token_behind_for_a_later_child(): void
    {
        $repository = $this->repositoryWithAParent();
        $sha = trim($this->git($repository, 'rev-parse', 'HEAD'));
        putenv(GATE_CREDENTIAL_VARIABLE . '=ghs_secretvaluenobodyshouldsee');
        ob_start();

        try {
            $exit = runGate(
                ['--sha' => $sha, '--repo' => 'kinetis-dev/kinetis', '--root' => $repository],
                $this->everyRequiredWorkflowPassing($sha),
                static function (int $seconds): void {
                },
                static fn (): float => 0.0,
            );

            self::assertSame(0, $exit);
            self::assertFalse(getenv(GATE_CREDENTIAL_VARIABLE));
        } finally {
            ob_end_clean();
            putenv(GATE_CREDENTIAL_VARIABLE);
        }
    }

    // --- arguments -----------------------------------------------------------

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidInvocations(): iterable
    {
        yield 'no arguments' => [[], 'The gate needs --sha.'];
        yield 'an unknown option' => [['--sha=' . self::SHA, '--repo=a/b', '--wait'], 'Unknown option: --wait'];
        yield 'a short sha' => [['--sha=abc', '--repo=a/b'], '--sha needs the full commit id of the commit being released.'];
        yield 'a repository with no owner' => [['--sha=' . self::SHA, '--repo=kinetis'], '--repo needs an owner/name pair.'];
        yield 'a timeout that is not a number' => [
            ['--sha=' . self::SHA, '--repo=a/b', '--timeout=soon'],
            '--timeout needs a whole number of seconds.',
        ];
        yield 'a repeated sha' => [
            ['--sha=' . self::SHA, '--sha=' . self::OTHER_SHA, '--repo=a/b'],
            '--sha is given more than once.',
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('invalidInvocations')]
    public function test_an_invalid_invocation_is_refused(array $args, string $expected): void
    {
        self::assertContains($expected, parseGateArguments($args)['problems']);
    }

    public function test_a_valid_invocation_carries_its_options_through(): void
    {
        $parsed = parseGateArguments(['--sha=' . self::SHA, '--repo=kinetis-dev/kinetis', '--timeout=60']);

        self::assertSame([], $parsed['problems']);
        self::assertSame(self::SHA, $parsed['options']['--sha']);
        self::assertSame('60', $parsed['options']['--timeout']);
    }

    // --- fixtures ------------------------------------------------------------

    /** A checkout with a parent commit, so there is a diff to read at all. */
    private function repositoryWithAParent(): string
    {
        $repository = sys_get_temp_dir() . '/kinetis-gate-' . bin2hex(random_bytes(6));
        mkdir($repository, 0o777, true);
        $this->directories[] = $repository;

        $this->git($repository, 'init', '-q', '-b', 'main');
        $this->git($repository, 'config', 'user.email', 'test@example.com');
        $this->git($repository, 'config', 'user.name', 'test');

        foreach (['first', 'second'] as $round) {
            file_put_contents("{$repository}/packages.manifest.json", "{\"round\": \"{$round}\"}\n");
            $this->git($repository, 'add', '-A');
            $this->git($repository, 'commit', '-q', '-m', $round);
        }

        return $repository;
    }

    /** Every workflow the gate requires, green, with a job that did the work. */
    private function everyRequiredWorkflowPassing(string $sha): StubHttpBoundary
    {
        $runs = [];
        $id = 1;

        foreach (requiredWorkflows() as $workflow) {
            $runs[] = $this->runFor($workflow->name, $sha, 'completed', 'success', $id++);
        }

        return new StubHttpBoundary([
            '/jobs' => ['status' => 200, 'body' => (string) json_encode(
                ['jobs' => [['name' => 'core (PHP 8.4)', 'status' => 'completed', 'conclusion' => 'success']]],
                JSON_THROW_ON_ERROR,
            )],
            '/actions/runs?' => ['status' => 200, 'body' => (string) json_encode(
                ['workflow_runs' => $runs],
                JSON_THROW_ON_ERROR,
            )],
        ]);
    }

    private function git(string $repository, string ...$args): string
    {
        // A variadic collects named arguments under string keys, so the
        // vector git is handed is built as a list here.
        $vector = array_values($args);
        $result = runGit($vector, $repository);

        self::assertSame(0, $result['exitCode'], "git {$vector[0]} failed: {$result['stderr']}");

        return $result['stdout'];
    }

    /** @return array{name: string, id: int, head_sha: string, status: string, conclusion: string|null} */
    private function runFor(string $name, string $sha, string $status, ?string $conclusion, int $id = 1): array
    {
        return ['name' => $name, 'id' => $id, 'head_sha' => $sha, 'status' => $status, 'conclusion' => $conclusion];
    }

    /**
     * @param list<array{name: string, status: string, conclusion: string|null}> $jobs
     * @return callable(int): list<array{name: string, status: string, conclusion: string|null}>
     */
    private function jobs(array $jobs): callable
    {
        return static fn (int $runId): array => $jobs;
    }
}
