<?php

declare(strict_types=1);

/**
 * Decides whether the exact monorepo commit being released passed the
 * checks a publication is allowed to rely on.
 *
 * Usage: php tools/release-gate.php --sha=<commit> --repo=<owner/name>
 *
 * A release publishes content, not a branch position, so every run this
 * waits on has to belong to the commit being published: a run for an
 * earlier commit on main differs from this one by exactly the change
 * being released. Runs are therefore looked up by head SHA, and each
 * required workflow must have one that concluded successfully.
 *
 * What counts as a run for this commit is narrow, because everything
 * this gate accepts is something it publishes on. A record is evidence
 * only when it is a push to main, at this exact SHA, of one of the three
 * workflow files below — identified by the path GitHub reports, since a
 * run's display name is whatever the `name:` field said at the time and
 * two workflows can carry the same one. A pull request's own runs sit at
 * the same head SHA as the push that merged it and are not accepted for
 * any of the three: a green pull request alongside a red main is exactly
 * the state a publication must not read as success.
 *
 * REQUIRED_WORKFLOWS names the workflows that run on every push to main
 * with no path filter, so "no run at this SHA" is a real failure for
 * each of them rather than a filter deciding not to. Integration is
 * deliberately not among them: it is path-filtered, and requiring it at
 * a fixed SHA means re-deriving that filter here, which is exactly the
 * machinery this gate exists without. Integration's result on the
 * pull request is what a merge is judged on.
 *
 * Waiting is bounded. A required workflow still running at the deadline
 * fails the gate, as does every state that is not a proven success,
 * because the alternative is publishing on the strength of a question
 * nobody answered.
 *
 * Reads the repository's own results with the workflow's ephemeral
 * token, taken from GH_TOKEN. The deploy credential is not available to
 * this step and is never needed by it. The token goes only to
 * api.github.com, in a request header, and is never carried across a
 * redirect.
 */

/**
 * Every workflow whose success this publication depends on, by the file
 * path GitHub reports for a run, with the name this gate calls it in a
 * message.
 */
const REQUIRED_WORKFLOWS = [
    '.github/workflows/ci.yml' => 'CI',
    '.github/workflows/monorepo-validate.yml' => 'Monorepo Validate',
    '.github/workflows/semgrep.yml' => 'Semgrep',
];

/** How long the gate waits for those workflows to finish. */
const GATE_TIMEOUT_SECONDS = 2400;

/** How long the gate leaves between two rounds of reads. */
const GATE_POLL_SECONDS = 20;

/** A single read still has to end. */
const GATE_REQUEST_TIMEOUT_SECONDS = 30;

/** The one origin this gate sends its token to. */
const GITHUB_API_ORIGIN = 'https://api.github.com';

final class ReleaseGateFailure extends RuntimeException
{
}

/**
 * One GET against the GitHub API. Injectable so the decision below is
 * testable without a network call.
 *
 * @return array<string, mixed> the decoded response body
 * @throws ReleaseGateFailure on any status other than 200, on a
 *         redirect, or on a body that is not the expected shape
 */
function readGitHubApi(string $path, string $token): array
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: kinetis-release-gate',
        ],
        'timeout' => GATE_REQUEST_TIMEOUT_SECONDS,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
    ]]);

    $body = @file_get_contents(GITHUB_API_ORIGIN . $path, false, $context);
    $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) === 1
        ? (int) $m[1]
        : 0;

    if ($body === false || $status !== 200) {
        throw new ReleaseGateFailure("GET {$path} answered {$status} rather than a result the gate can read.");
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new ReleaseGateFailure("GET {$path} did not answer with a JSON object.");
    }

    /** @var array<string, mixed> */
    return $decoded;
}

/**
 * Whether one run record is evidence about the commit being released: a
 * push to main, at this exact SHA, of a required workflow file.
 *
 * The query already asks the API for the push runs on main at this SHA.
 * Every field it filtered by is checked again here, because a filter is
 * a request and these four values are what the gate's decision means.
 *
 * @param array<string, mixed> $record
 */
function isRequiredRun(array $record, string $sha): bool
{
    return isset($record['path']) && is_string($record['path']) && isset(REQUIRED_WORKFLOWS[$record['path']])
        && ($record['event'] ?? null) === 'push'
        && ($record['head_branch'] ?? null) === 'main'
        && ($record['head_sha'] ?? null) === $sha;
}

/**
 * One run's state.
 *
 * @param array<string, mixed> $record
 * @return 'success'|'failed'|'pending'
 */
function runState(array $record): string
{
    return match (true) {
        ($record['status'] ?? null) !== 'completed' => 'pending',
        ($record['conclusion'] ?? null) === 'success' => 'success',
        default => 'failed',
    };
}

/**
 * One workflow's state, from every run recorded for it.
 *
 * A workflow can have more than one run at a SHA — a rerun, or a
 * scheduled retry — and the API decides what order it lists them in. A
 * success settles the workflow whenever it arrives, since a rerun is the
 * ordinary way a commit turns green; otherwise an unfinished run keeps
 * the workflow open, and anything else is a failure.
 *
 * @param non-empty-list<'success'|'failed'|'pending'> $states
 * @return 'success'|'failed'|'pending'
 */
function settledState(array $states): string
{
    return match (true) {
        in_array('success', $states, true) => 'success',
        in_array('pending', $states, true) => 'pending',
        default => 'failed',
    };
}

/**
 * Every required workflow GitHub records for one exact commit, as
 * workflow path => the state its runs settle on. A workflow with no run
 * this gate accepts is absent from the result.
 *
 * @param callable(string): array<string, mixed> $read
 * @return array<string, string> workflow path => 'success', 'failed', or 'pending'
 * @throws ReleaseGateFailure
 */
function workflowStates(string $repo, string $sha, callable $read): array
{
    $body = $read("/repos/{$repo}/actions/runs?head_sha={$sha}&event=push&branch=main&per_page=100");

    if (!isset($body['workflow_runs']) || !is_array($body['workflow_runs'])) {
        throw new ReleaseGateFailure("The run listing for {$sha} carried no workflow_runs.");
    }

    $seen = [];

    foreach ($body['workflow_runs'] as $record) {
        if (!is_array($record) || !isRequiredRun($record, $sha)) {
            continue;
        }

        /** @var string $path */
        $path = $record['path'];
        $seen[$path][] = runState($record);
    }

    return array_map(settledState(...), $seen);
}

/**
 * The gate's verdict for one round of reads.
 *
 * @param array<string, string> $states
 * @return array{done: bool, problems: list<string>, waiting: list<string>}
 */
function gateVerdict(array $states): array
{
    $problems = [];
    $waiting = [];

    foreach (REQUIRED_WORKFLOWS as $path => $workflow) {
        match ($states[$path] ?? 'missing') {
            'success' => null,
            'failed' => $problems[] = "{$workflow} did not succeed for this commit.",
            default => $waiting[] = $workflow,
        };
    }

    return ['done' => $waiting === [], 'problems' => $problems, 'waiting' => $waiting];
}

/**
 * @param list<string> $argv
 * @return array{sha: ?string, repo: ?string, problems: list<string>}
 */
function parseGateArguments(array $argv): array
{
    $values = ['sha' => null, 'repo' => null];
    $problems = [];

    foreach ($argv as $arg) {
        $matched = false;

        foreach (array_keys($values) as $option) {
            if (str_starts_with($arg, "--{$option}=")) {
                $values[$option] = trim(substr($arg, strlen($option) + 3));
                $matched = true;
            }
        }

        if (!$matched) {
            $problems[] = "Unknown option: {$arg}";
        }
    }

    foreach ($values as $option => $value) {
        if ($value === null || $value === '') {
            $problems[] = "--{$option} is required.";
        }
    }

    return [...$values, 'problems' => $problems];
}

/**
 * @param callable(string): array<string, mixed> $read
 * @param callable(int): mixed $wait the return value is unused
 * @throws ReleaseGateFailure
 */
function waitForRequiredWorkflows(string $repo, string $sha, callable $read, callable $wait, int $deadline): void
{
    while (true) {
        $verdict = gateVerdict(workflowStates($repo, $sha, $read));

        if ($verdict['problems'] !== []) {
            throw new ReleaseGateFailure(implode(' ', $verdict['problems']));
        }

        if ($verdict['done']) {
            echo 'Every required workflow succeeded for ' . substr($sha, 0, 12) . ".\n";

            return;
        }

        if (time() >= $deadline) {
            throw new ReleaseGateFailure(
                'Still waiting on ' . implode(', ', $verdict['waiting']) . ' at the deadline. '
                . 'A publication does not proceed on an unfinished check.',
            );
        }

        echo 'Waiting on ' . implode(', ', $verdict['waiting']) . "…\n";
        $wait(GATE_POLL_SECONDS);
    }
}

/** @param list<string> $argv */
function gateMain(array $argv = []): int
{
    $arguments = parseGateArguments(array_slice($argv, 1));

    if ($arguments['problems'] !== []) {
        foreach ($arguments['problems'] as $problem) {
            fwrite(STDERR, "{$problem}\n");
        }

        return 1;
    }

    $token = getenv('GH_TOKEN');

    if (!is_string($token) || $token === '') {
        fwrite(STDERR, "GH_TOKEN is not set — the gate reads this repository's own results with it.\n");

        return 1;
    }

    try {
        waitForRequiredWorkflows(
            (string) $arguments['repo'],
            (string) $arguments['sha'],
            static fn (string $path): array => readGitHubApi($path, $token),
            sleep(...),
            time() + GATE_TIMEOUT_SECONDS,
        );
    } catch (ReleaseGateFailure $e) {
        fwrite(STDERR, '::error::' . $e->getMessage() . "\n");

        return 1;
    }

    return 0;
}

// See generate-composer.php for the entry-point guard and the Psalm
// suppression it carries.
/** @psalm-suppress ParadoxicalCondition */
if (current(get_included_files()) === __FILE__) {
    exit(gateMain($argv ?? []));
}
