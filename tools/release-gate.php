<?php

declare(strict_types=1);

/**
 * Decides whether one exact monorepo commit has passed everything a
 * publication is allowed to rely on.
 *
 * Usage: php tools/release-gate.php --sha=<commit> --repo=<owner/name>
 *                                   [--timeout=<seconds>] [--root=<dir>]
 *
 * A release publishes content, not a branch position, so the checks it
 * waits on have to belong to the commit being published. A run for an
 * earlier commit on main proves nothing about this one: the two differ
 * by exactly the change being released. Every required workflow is
 * therefore matched on head SHA, and a run that reports success while
 * every job that does the work was skipped counts as a failure — a
 * path-filtered workflow can report a green run for a commit it never
 * examined.
 *
 * A workflow that filters on paths is required unless this commit's own
 * diff proves it inapplicable, read from that workflow's own filter
 * rather than a copy of it kept here.
 *
 * Waiting is bounded. A gate still pending at the deadline fails, and so
 * does every other state that is not a proven success, because the
 * alternative is publishing on the strength of a question nobody
 * answered.
 *
 * Reads the repository's own check results with the workflow's ephemeral
 * token. The deploy credential is not available to this step and is
 * never needed by it. The token this step does hold is taken out of the
 * environment once the git reads are done, goes only to
 * https://api.github.com, and is never carried across a redirect.
 */

require_once __DIR__ . '/git-history.php';

/** How long the gate waits for the required workflows to finish. */
const GATE_TIMEOUT_SECONDS = 2400;

/** How long the gate leaves between two rounds of API reads. */
const GATE_POLL_SECONDS = 20;

/** A single API read still has to end. */
const GATE_REQUEST_TIMEOUT_SECONDS = 30;

/** The conclusions that mean a job did not do its work. */
const GATE_FAILED_CONCLUSIONS = ['failure', 'cancelled', 'timed_out', 'action_required', 'stale'];

/** The one origin this gate sends its token to. */
const GITHUB_API_ORIGIN = 'https://api.github.com';

/** The environment variable the gate reads the repository's own results with. */
const GATE_CREDENTIAL_VARIABLE = 'GH_TOKEN';

/** How many records the API is asked for at a time; its own maximum. */
const GATE_PAGE_SIZE = 100;

/** How many pages of one listing the gate reads before giving up on it. */
const GATE_MAX_PAGES = 20;

/** How many records one listing may carry in total. */
const GATE_MAX_RECORDS = GATE_PAGE_SIZE * GATE_MAX_PAGES;

final class ReleaseGateFailure extends RuntimeException
{
}

/**
 * One workflow the gate requires.
 *
 * $jobPattern names the jobs that do the work this gate is actually
 * waiting on. CI's per-package matrix is the whole point of requiring
 * CI: a run whose matrix never expanded has validated nothing, however
 * green it looks.
 *
 * $workflowFile is set for a workflow that filters on paths, and is
 * where the filter is read from, so this file and that one cannot drift
 * apart.
 */
final class RequiredWorkflow
{
    public function __construct(
        public readonly string $name,
        public readonly string $jobPattern,
        public readonly ?string $workflowFile = null,
    ) {
    }
}

/**
 * Every workflow whose result a publication depends on.
 *
 * Not the whole pipeline. SonarQube and Infection report on quality
 * trends rather than on whether this commit is correct, and gating a
 * release on either would stop publication for reasons that are not
 * about the released content.
 *
 * @return list<RequiredWorkflow>
 */
function requiredWorkflows(): array
{
    return [
        new RequiredWorkflow('CI', '/\(PHP \d+\.\d+\)$/'),
        new RequiredWorkflow('Monorepo Validate', '//'),
        new RequiredWorkflow('Semgrep', '//'),
        new RequiredWorkflow('Integration', '//', '.github/workflows/integration.yml'),
    ];
}

/**
 * The reads the gate makes against the GitHub API. Injectable for
 * testing.
 *
 * The headers carry the token, so every implementation of this sends
 * them to the URL it was given and nowhere else.
 */
interface HttpBoundary
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, #[SensitiveParameter] array $headers, float $timeoutSeconds): array;
}

final class NativeHttpBoundary implements HttpBoundary
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    #[\Override]
    public function get(string $url, #[SensitiveParameter] array $headers, float $timeoutSeconds): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $lines),
            'timeout' => $timeoutSeconds,
            // A 404 or a 403 is an answer this gate has to see and refuse
            // on, not a warning to swallow and a false to interpret.
            'ignore_errors' => true,
            // PHP's default is to follow a redirect while keeping every
            // header it was given, which would hand the Authorization
            // header to whatever host the Location names. Nothing here
            // follows one: a 3xx is reported as the status it is, and
            // readApi refuses it.
            'follow_location' => 0,
            'max_redirects' => 1,
        ]]);

        // The stream layer writes the response lines into this scope,
        // and writes nothing at all when the read produced no response,
        // so the empty list is what "no status line" looks like here.
        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);

        // A read that failed reached no response line to report a status
        // from, and its body is nothing rather than an empty one.
        if ($body === false) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => statusFromHeaders($http_response_header), 'body' => $body];
    }
}

/**
 * The status of the response.
 *
 * Redirects are not followed, so there is one response and one status
 * line that matters; an informational line ahead of it is not the
 * answer, which is why the last one wins.
 *
 * @param list<string> $headers
 */
function statusFromHeaders(array $headers): int
{
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match('#^HTTP/[0-9.]+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return $status;
}

/**
 * Refuses to send the token anywhere but the GitHub API.
 *
 * Every URL this gate reads is built here rather than followed out of a
 * response, so this is a check on its own arithmetic — and the one
 * place that would have to be wrong for a bearer token to leave
 * api.github.com.
 *
 * @throws ReleaseGateFailure
 */
function requireGitHubApiUrl(string $url): void
{
    $parts = parse_url($url);

    if (
        !is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || ($parts['host'] ?? null) !== 'api.github.com'
        || isset($parts['port'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new ReleaseGateFailure('This gate reads ' . GITHUB_API_ORIGIN . ' and sends its token nowhere else.');
    }
}

/**
 * One API read, decoded, or the reason the gate cannot proceed.
 *
 * Anything other than a 200 carrying a JSON document is indeterminate: a
 * rate limit, a revoked token, a proxy's error page, a truncated read.
 * None of them say the commit passed. A 3xx is called out separately
 * because it is the one status that would otherwise look like an
 * ordinary failure while meaning something this gate refuses to do.
 *
 * @param array<string, string> $headers
 * @return array<mixed>
 * @throws ReleaseGateFailure
 */
function readApi(HttpBoundary $http, string $url, #[SensitiveParameter] array $headers, string $what): array
{
    requireGitHubApiUrl($url);
    $response = $http->get($url, $headers, GATE_REQUEST_TIMEOUT_SECONDS);

    if ($response['status'] >= 300 && $response['status'] < 400) {
        throw new ReleaseGateFailure(
            "Could not read {$what}: the API answered with a redirect (HTTP {$response['status']}), which this "
            . 'gate does not follow — a followed redirect would carry its token to whatever host it names.',
        );
    }

    if ($response['status'] !== 200) {
        throw new ReleaseGateFailure("Could not read {$what} (HTTP {$response['status']}).");
    }

    try {
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ReleaseGateFailure("Could not read {$what}: " . $e->getMessage());
    }

    if (!is_array($decoded)) {
        throw new ReleaseGateFailure("Could not read {$what}: the API answered with no document.");
    }

    return $decoded;
}

/**
 * Every record of one API listing, not just the first page of it.
 *
 * A single per_page=100 read is an answer about the newest hundred
 * records and nothing else, and the run or the job this gate turns on
 * can sit past that: a busy commit has more runs than a page holds, and
 * a large matrix has more jobs. Reading one page and treating it as the
 * whole set is how a truncated read becomes a false pass.
 *
 * Page numbers are generated here rather than followed out of a Link
 * header, so there is no chain to loop and no URL the response gets to
 * choose. Everything the API can do to make a listing unreadable — a
 * page carrying more than it was asked for, a listing that never ends,
 * more records than this reads — fails the gate rather than shortening
 * the answer.
 *
 * @param array<string, string> $headers
 * @return list<mixed>
 * @throws ReleaseGateFailure
 */
function readApiListing(
    HttpBoundary $http,
    string $url,
    #[SensitiveParameter] array $headers,
    string $what,
    string $collection,
): array {
    $separator = str_contains($url, '?') ? '&' : '?';
    $records = [];

    for ($page = 1; $page <= GATE_MAX_PAGES; $page++) {
        $document = readApi(
            $http,
            "{$url}{$separator}per_page=" . GATE_PAGE_SIZE . "&page={$page}",
            $headers,
            $what,
        );
        $items = $document[$collection] ?? null;

        if (!is_array($items) || !array_is_list($items)) {
            throw new ReleaseGateFailure("The API listed no {$collection} for {$what}.");
        }

        if (count($items) > GATE_PAGE_SIZE) {
            throw new ReleaseGateFailure(
                "Could not read {$what}: page {$page} carries more records than a page of " . GATE_PAGE_SIZE
                . ' holds.',
            );
        }

        foreach ($items as $item) {
            $records[] = $item;
        }

        if (count($items) < GATE_PAGE_SIZE) {
            return $records;
        }
    }

    throw new ReleaseGateFailure(
        "Could not read {$what}: it did not end within " . GATE_MAX_RECORDS . ' records, so this gate cannot say '
        . 'it read all of them.',
    );
}

/**
 * The runs GitHub reports for one exact commit.
 *
 * @return list<array{name: string, id: int, head_sha: string, status: string, conclusion: string|null}>
 * @throws ReleaseGateFailure
 */
function workflowRunsFor(
    HttpBoundary $http,
    string $repository,
    string $sha,
    #[SensitiveParameter] string $token,
): array {
    $runs = readApiListing(
        $http,
        GITHUB_API_ORIGIN . "/repos/{$repository}/actions/runs?head_sha={$sha}",
        apiHeaders($token),
        "the workflow runs for {$sha}",
        'workflow_runs',
    );
    $parsed = [];

    foreach ($runs as $run) {
        if (!is_array($run)) {
            continue;
        }

        $name = $run['name'] ?? null;
        $id = $run['id'] ?? null;
        $headSha = $run['head_sha'] ?? null;
        $status = $run['status'] ?? null;
        $conclusion = $run['conclusion'] ?? null;

        if (!is_string($name) || !is_int($id) || !is_string($headSha) || !is_string($status)) {
            throw new ReleaseGateFailure("The API described a workflow run for {$sha} in a shape this gate cannot read.");
        }

        $parsed[] = [
            'name' => $name,
            'id' => $id,
            'head_sha' => $headSha,
            'status' => $status,
            'conclusion' => is_string($conclusion) ? $conclusion : null,
        ];
    }

    return $parsed;
}

/**
 * The jobs of one run.
 *
 * @return list<array{name: string, status: string, conclusion: string|null}>
 * @throws ReleaseGateFailure
 */
function workflowJobsFor(
    HttpBoundary $http,
    string $repository,
    int $runId,
    #[SensitiveParameter] string $token,
): array {
    $jobs = readApiListing(
        $http,
        GITHUB_API_ORIGIN . "/repos/{$repository}/actions/runs/{$runId}/jobs",
        apiHeaders($token),
        "the jobs of run {$runId}",
        'jobs',
    );
    $parsed = [];

    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }

        $name = $job['name'] ?? null;
        $status = $job['status'] ?? null;
        $conclusion = $job['conclusion'] ?? null;

        if (!is_string($name) || !is_string($status)) {
            throw new ReleaseGateFailure("The API described a job of run {$runId} in a shape this gate cannot read.");
        }

        $parsed[] = ['name' => $name, 'status' => $status, 'conclusion' => is_string($conclusion) ? $conclusion : null];
    }

    return $parsed;
}

/** @return array<string, string> */
function apiHeaders(#[SensitiveParameter] string $token): array
{
    return [
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2022-11-28',
        'User-Agent' => 'kinetis-release-gate',
        'Authorization' => "Bearer {$token}",
    ];
}

/**
 * What one required workflow's result says about this commit.
 *
 * @param list<array{name: string, id: int, head_sha: string, status: string, conclusion: string|null}> $runs
 * @param callable(int): list<array{name: string, status: string, conclusion: string|null}> $jobsFor
 * @return array{state: 'satisfied'|'pending'|'failed', reason: string}
 */
function evaluateWorkflow(RequiredWorkflow $workflow, string $sha, array $runs, callable $jobsFor): array
{
    $matching = array_values(array_filter(
        $runs,
        static fn (array $run): bool => $run['name'] === $workflow->name && $run['head_sha'] === $sha,
    ));

    if ($matching === []) {
        return ['state' => 'pending', 'reason' => "{$workflow->name} has not started for {$sha}"];
    }

    // A re-run creates a new run for the same commit; the newest one is
    // the answer, and an older failed attempt is not held against it.
    usort($matching, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
    $run = $matching[count($matching) - 1];

    if ($run['status'] !== 'completed') {
        return ['state' => 'pending', 'reason' => "{$workflow->name} is {$run['status']} for {$sha}"];
    }

    if ($run['conclusion'] !== 'success') {
        return ['state' => 'failed', 'reason' => "{$workflow->name} concluded {$run['conclusion']} for {$sha}"];
    }

    return evaluateJobs($workflow, $jobsFor($run['id']));
}

/**
 * Whether a successful run actually did the work.
 *
 * A workflow that filters on paths reports success when its jobs are all
 * skipped, and a release-metadata-only commit is exactly the shape that
 * produces one. So a run counts only when a job that does the work
 * succeeded, and any job that failed, was cancelled, or timed out makes
 * the run a failure regardless of what the run itself concluded.
 *
 * @param list<array{name: string, status: string, conclusion: string|null}> $jobs
 * @return array{state: 'satisfied'|'pending'|'failed', reason: string}
 */
function evaluateJobs(RequiredWorkflow $workflow, array $jobs): array
{
    $worked = false;

    foreach ($jobs as $job) {
        if (in_array($job['conclusion'], GATE_FAILED_CONCLUSIONS, true)) {
            return [
                'state' => 'failed',
                'reason' => "{$workflow->name}'s job \"{$job['name']}\" concluded {$job['conclusion']}",
            ];
        }

        if ($job['conclusion'] === 'success' && preg_match($workflow->jobPattern, $job['name']) === 1) {
            $worked = true;
        }
    }

    if (!$worked) {
        return [
            'state' => 'failed',
            'reason' => "{$workflow->name} reported success without running the jobs this gate requires",
        ];
    }

    return ['state' => 'satisfied', 'reason' => "{$workflow->name} passed"];
}

/**
 * The whole gate, in one reading of the API.
 *
 * @param list<RequiredWorkflow> $required
 * @param list<array{name: string, id: int, head_sha: string, status: string, conclusion: string|null}> $runs
 * @param callable(int): list<array{name: string, status: string, conclusion: string|null}> $jobsFor
 * @return array{state: 'satisfied'|'pending'|'failed', reasons: list<string>}
 */
function evaluateGate(array $required, string $sha, array $runs, callable $jobsFor): array
{
    $reasons = [];
    $state = 'satisfied';

    foreach ($required as $workflow) {
        $result = evaluateWorkflow($workflow, $sha, $runs, $jobsFor);
        $reasons[] = $result['reason'];

        if ($result['state'] === 'failed') {
            $state = 'failed';
        } elseif ($result['state'] === 'pending' && $state !== 'failed') {
            $state = 'pending';
        }
    }

    return ['state' => $state, 'reasons' => $reasons];
}

/**
 * Waits for the gate, and only for as long as it was given.
 *
 * A failure ends the wait immediately: a required workflow that
 * concluded is not going to conclude differently. Pending is the only
 * state worth waiting on, and the deadline turns it into a failure so
 * that a workflow which never starts cannot hold a publication open.
 *
 * @param callable(): array{state: 'satisfied'|'pending'|'failed', reasons: list<string>} $evaluate
 * @param callable(int): void $sleep
 * @param callable(): float $now
 * @return array{state: 'satisfied'|'failed', reasons: list<string>}
 */
function waitForGate(callable $evaluate, callable $sleep, callable $now, float $timeoutSeconds): array
{
    $deadline = $now() + $timeoutSeconds;

    while (true) {
        $result = $evaluate();

        if ($result['state'] !== 'pending') {
            return ['state' => $result['state'], 'reasons' => $result['reasons']];
        }

        if ($now() + GATE_POLL_SECONDS > $deadline) {
            return [
                'state' => 'failed',
                'reasons' => [
                    ...$result['reasons'],
                    "the gate was still waiting after {$timeoutSeconds}s",
                ],
            ];
        }

        $sleep(GATE_POLL_SECONDS);
    }
}

/**
 * The paths a workflow's push trigger filters on.
 *
 * Read from the workflow itself so a filter and this gate's idea of it
 * cannot drift. A workflow whose filter cannot be read is treated as
 * having none, which makes it unconditionally required.
 *
 * @return list<string>
 */
function workflowPushPaths(string $workflowPath): array
{
    $contents = @file_get_contents($workflowPath);

    if ($contents === false) {
        return [];
    }

    $paths = [];
    $inPush = false;
    $inPaths = false;

    foreach (explode("\n", $contents) as $line) {
        $line = rtrim($line, "\r");

        if (preg_match('/^  (\w+):\s*$/', $line, $m) === 1) {
            $inPush = $m[1] === 'push';
            $inPaths = false;

            continue;
        }

        if ($inPush && preg_match('/^    paths:\s*$/', $line) === 1) {
            $inPaths = true;

            continue;
        }

        if ($inPaths && preg_match("/^      - '?([^'#]+?)'?\s*$/", $line, $m) === 1) {
            $paths[] = $m[1];

            continue;
        }

        if ($inPaths && trim($line) !== '') {
            $inPaths = false;
        }
    }

    return $paths;
}

/**
 * Whether a changed path matches one of a workflow's filter patterns.
 *
 * GitHub's filter syntax, narrowed to what these workflows use: `*`
 * stays inside one path segment, `**` crosses them, and everything else
 * is literal.
 *
 * @param list<string> $changed
 * @param list<string> $patterns
 */
function pathFilterMatches(array $changed, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        $regex = '#^' . str_replace(
            ['\*\*/', '\*\*', '\*'],
            ['(?:.*/)?', '.*', '[^/]*'],
            preg_quote($pattern, '#'),
        ) . '$#';

        foreach ($changed as $path) {
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The workflows this commit's own diff makes applicable.
 *
 * A path-filtered workflow is dropped only when the exact diff being
 * released proves it could not have run. A diff this checkout cannot
 * establish leaves every workflow required.
 *
 * @param list<RequiredWorkflow> $workflows
 * @param list<string> $changed
 * @return list<RequiredWorkflow>
 */
function applicableWorkflows(array $workflows, array $changed, string $projectRoot): array
{
    $applicable = [];

    foreach ($workflows as $workflow) {
        if ($workflow->workflowFile === null) {
            $applicable[] = $workflow;

            continue;
        }

        $patterns = workflowPushPaths("{$projectRoot}/{$workflow->workflowFile}");

        if ($patterns === [] || pathFilterMatches($changed, $patterns)) {
            $applicable[] = $workflow;
        }
    }

    return $applicable;
}

/**
 * The paths this push changed, or every path when there is no base to
 * compare against.
 *
 * @return list<string>|null null when there is no base to compare against
 * @throws HistoryUnavailable
 */
function changedPathsForRelease(string $sha, string $projectRoot): ?array
{
    $base = resolveComparisonBase(
        static fn (string $ref): string => gitResolveCommit($ref, $projectRoot),
        static fn (string $ref): bool => gitCommitExists($ref, $projectRoot),
        static fn (): bool => gitIsShallow($projectRoot),
    );

    if ($base->commit === null) {
        return null;
    }

    $run = gitRunnerFor($projectRoot);
    $result = $run(['diff', '--no-renames', '-z', '--name-only', '--end-of-options', $base->commit, $sha]);

    if ($result['exitCode'] !== 0) {
        throw new HistoryUnavailable(
            "Could not read what {$sha} changed: " . redactCredentials($result['stderr']),
        );
    }

    return splitNulSeparatedPaths($result['stdout']);
}

/**
 * @param list<string> $argv
 * @return array{options: array<string, string>, problems: list<string>}
 */
function parseGateArguments(array $argv): array
{
    $known = ['--sha', '--repo', '--timeout', '--root'];
    $options = [];
    $problems = [];

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

    foreach (['--sha', '--repo'] as $option) {
        if (!isset($options[$option])) {
            $problems[] = "The gate needs {$option}.";
        }
    }

    if (isset($options['--sha']) && !isObjectId($options['--sha'])) {
        $problems[] = '--sha needs the full commit id of the commit being released.';
    }

    if (isset($options['--repo']) && preg_match('#^[\w.-]+/[\w.-]+$#', $options['--repo']) !== 1) {
        $problems[] = '--repo needs an owner/name pair.';
    }

    if (isset($options['--timeout']) && preg_match('/^[1-9][0-9]*$/', $options['--timeout']) !== 1) {
        $problems[] = '--timeout needs a whole number of seconds.';
    }

    return ['options' => $options, 'problems' => $problems];
}

/**
 * @param array<string, string> $options
 * @param callable(int): void $sleep
 * @param callable(): float $now
 * @throws ReleaseGateFailure|HistoryUnavailable
 */
function runGate(array $options, HttpBoundary $http, callable $sleep, callable $now): int
{
    $sha = $options['--sha'];
    $repository = $options['--repo'];
    $projectRoot = $options['--root'] ?? PROJECT_ROOT;

    // Every git child this runs would inherit the token from this
    // process's environment, and none of them has any use for it. So the
    // whole diff is read first, and the token is taken — and removed —
    // only once nothing else is going to be spawned.
    $changed = changedPathsForRelease($sha, $projectRoot);
    $required = $changed === null
        ? requiredWorkflows()
        : applicableWorkflows(requiredWorkflows(), $changed, $projectRoot);

    foreach ($required as $workflow) {
        echo "requires: {$workflow->name}\n";
    }

    $token = takeGateCredential();
    $result = waitForGate(
        static fn (): array => evaluateGate(
            $required,
            $sha,
            workflowRunsFor($http, $repository, $sha, $token),
            static fn (int $runId): array => workflowJobsFor($http, $repository, $runId, $token),
        ),
        $sleep,
        $now,
        (float) ($options['--timeout'] ?? GATE_TIMEOUT_SECONDS),
    );

    foreach ($result['reasons'] as $reason) {
        echo "  {$reason}\n";
    }

    if ($result['state'] !== 'satisfied') {
        fwrite(STDERR, "{$sha} has not passed everything a release depends on.\n");

        return 1;
    }

    echo "{$sha} passed every required check.\n";

    return 0;
}

/**
 * Takes the gate's read token out of this process's environment and
 * hands it back.
 *
 * Reading it once and removing it is what keeps a later child from
 * inheriting it. Nothing after this point spawns one, and the token goes
 * only to the API reads.
 *
 * @throws ReleaseGateFailure
 */
function takeGateCredential(): string
{
    $token = getenv(GATE_CREDENTIAL_VARIABLE);
    putenv(GATE_CREDENTIAL_VARIABLE);
    unset($_ENV[GATE_CREDENTIAL_VARIABLE], $_SERVER[GATE_CREDENTIAL_VARIABLE]);

    if (!is_string($token) || trim($token) === '') {
        throw new ReleaseGateFailure(
            GATE_CREDENTIAL_VARIABLE . " is not set, so this gate cannot read the repository's own results.",
        );
    }

    return $token;
}

/**
 * @param list<string> $argv
 */
function gateMain(array $argv = []): int
{
    $arguments = parseGateArguments(array_slice($argv, 1));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    try {
        return runGate(
            $arguments['options'],
            new NativeHttpBoundary(),
            static function (int $seconds): void {
                sleep($seconds);
            },
            static fn (): float => microtime(true),
        );
    } catch (ReleaseGateFailure | HistoryUnavailable $e) {
        fwrite(STDERR, redactCredentials($e->getMessage()) . "\n");

        return 1;
    }
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(gateMain($argv ?? []));
}
