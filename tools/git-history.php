<?php

declare(strict_types=1);

/**
 * Reading the comparison point every version check needs: what the
 * manifest looked like before this change, and which files moved since.
 *
 * The distinction this file keeps is between history that isn't there
 * and history this checkout can't see, since a silent skip on the second
 * turns every version rule off for exactly the push those rules exist to
 * check. resolveComparisonBase() states which is which.
 */

require_once __DIR__ . '/manifest-schema.php';

final class HistoryUnavailable extends RuntimeException
{
}

/**
 * Where a run compares against, and why. A null $commit means the checks
 * are skipped, and $reason says which provable state produced that.
 */
final class ComparisonBase
{
    public function __construct(
        public readonly ?string $commit,
        public readonly string $reason,
    ) {
    }
}

/** How long a single git invocation may run before it is killed. */
const GIT_TIMEOUT_SECONDS = 60;

/** How often the loop rechecks the deadline and the child's status. */
const GIT_POLL_INTERVAL_SECONDS = 0.05;

/** Per-stream cap on captured output; the rest is read and dropped. */
const GIT_OUTPUT_LIMIT = 1_048_576;

/**
 * The ref syntax a --base argument may use: a full object id, or a plain
 * ref name.
 *
 * Narrower than what git itself accepts, and for one reason each. A
 * value starting with `-` reads as an option; `:` introduces path syntax
 * that changes which object is fetched; `..`, `^` and `@{` are range and
 * reversal syntax. None have a use here, and each turns one argument
 * into a different request.
 */
const BASE_REF_PATTERN = '/^(?:[0-9a-f]{40}|[0-9a-f]{64}|[A-Za-z0-9][A-Za-z0-9._\/-]*)$/';

/** GitHub sends an all-zero SHA (40 hex for SHA-1, 64 for SHA-256). */
function isZeroSha(string $sha): bool
{
    return preg_match('/^0{40}$|^0{64}$/', $sha) === 1;
}

/**
 * @return string|null a description of why $ref can't be used as a
 *         comparison base, or null when it's acceptable
 */
function baseRefProblem(string $ref): ?string
{
    if ($ref === '') {
        return 'the comparison base is empty';
    }

    if (preg_match(BASE_REF_PATTERN, $ref) !== 1
        || str_contains($ref, '..')
        || str_ends_with($ref, '/')
        || str_ends_with($ref, '.lock')
    ) {
        return 'the comparison base must be a full commit id or a plain ref name';
    }

    // Only GitHub's own before-SHA carries the "no prior ref" meaning,
    // and it arrives through the environment. Spelled on the command
    // line it is a commit that does not exist.
    if (isZeroSha($ref)) {
        return 'the all-zero commit id is not a comparison base';
    }

    return null;
}

/**
 * Resolves what to compare against, fail-closed.
 *
 * An explicit base — CI's GITHUB_EVENT_BEFORE, or --base on the command
 * line — must resolve to a commit that is present. With no explicit
 * base, exactly two states skip the comparison: a non-shallow repository
 * whose HEAD has no parent, and GitHub's all-zero before-SHA. A shallow
 * repository is neither, and is rejected: its HEAD has no visible parent
 * whether or not one exists, so a root commit and truncated history are
 * the same observation there, and only one of them is safe to skip on.
 *
 * @param callable(string): string $resolveCommit exact object id for a
 *        rev, throwing HistoryUnavailable when git can't resolve it
 * @param callable(string): bool $commitExists whether a rev names a
 *        commit here, throwing HistoryUnavailable when git itself fails
 * @param callable(): bool $isShallow
 * @throws HistoryUnavailable
 */
function resolveComparisonBase(
    callable $resolveCommit,
    callable $commitExists,
    callable $isShallow,
    ?string $override = null,
): ComparisonBase {
    if ($override !== null) {
        $problem = baseRefProblem($override);

        if ($problem !== null) {
            throw new HistoryUnavailable("--base={$override}: {$problem}");
        }

        return new ComparisonBase($resolveCommit($override), '');
    }

    $before = comparisonRefFromEnvironment();

    if ($before !== null && isZeroSha($before)) {
        return new ComparisonBase(
            null,
            'GitHub reported no commit before this push (all-zero before SHA) — the ref had no prior history.',
        );
    }

    if ($before !== null) {
        $problem = baseRefProblem($before);

        if ($problem !== null) {
            throw new HistoryUnavailable("GITHUB_EVENT_BEFORE: {$problem}");
        }

        return new ComparisonBase($resolveCommit($before), '');
    }

    if ($isShallow()) {
        throw new HistoryUnavailable(
            'This is a shallow checkout, so HEAD has no visible parent whether or not one exists — '
            . 'deepen the fetch (fetch-depth: 0), or name a base that is present with --base.',
        );
    }

    if (!$commitExists('HEAD^')) {
        return new ComparisonBase(null, "HEAD has no parent commit — this is the repository's first commit.");
    }

    return new ComparisonBase($resolveCommit('HEAD^'), '');
}

/**
 * GITHUB_EVENT_BEFORE carries github.event.before, set by the workflow
 * on the push trigger only. It is absent for a local run and for the
 * pull_request trigger, which names its base explicitly instead.
 */
function comparisonRefFromEnvironment(): ?string
{
    $before = getenv('GITHUB_EVENT_BEFORE');

    if ($before === false) {
        return null;
    }

    $before = trim($before);

    return $before === '' ? null : $before;
}

/**
 * The process and stream calls runCommand() makes. Injectable so the
 * paths a working git will not produce on demand — a read that fails, a
 * child that survives SIGKILL — can be exercised deterministically.
 */
interface ProcessBoundary
{
    /**
     * @param list<resource> $read narrowed in place to the ready streams
     * @return int|false
     */
    public function select(array &$read, float $seconds): int|false;

    /**
     * @param resource $stream
     * @return string|false false is a read failure, never end of file
     */
    public function read(mixed $stream, int $length): string|false;

    /** @param resource $stream */
    public function atEnd(mixed $stream): bool;

    /** @param resource $stream */
    public function closeStream(mixed $stream): void;

    /**
     * @param resource $process
     * @return array{running: bool, exitcode: int}|false
     */
    public function status(mixed $process): array|false;

    /** @param resource $process */
    public function terminate(mixed $process, int $signal): bool;

    /** @param resource $process */
    public function closeProcess(mixed $process): int;
}

final class NativeProcessBoundary implements ProcessBoundary
{
    #[\Override]
    public function select(array &$read, float $seconds): int|false
    {
        $write = null;
        $except = null;
        [$whole, $microseconds] = splitDuration($seconds);

        return @stream_select($read, $write, $except, $whole, $microseconds);
    }

    #[\Override]
    public function read(mixed $stream, int $length): string|false
    {
        \assert(is_resource($stream));
        \assert($length > 0);

        return @fread($stream, $length);
    }

    #[\Override]
    public function atEnd(mixed $stream): bool
    {
        \assert(is_resource($stream));

        return feof($stream);
    }

    #[\Override]
    public function closeStream(mixed $stream): void
    {
        \assert(is_resource($stream));
        @fclose($stream);
    }

    /** @return array{running: bool, exitcode: int} */
    #[\Override]
    public function status(mixed $process): array
    {
        \assert(is_resource($process));

        $status = @proc_get_status($process);

        return ['running' => $status['running'], 'exitcode' => $status['exitcode']];
    }

    #[\Override]
    public function terminate(mixed $process, int $signal): bool
    {
        \assert(is_resource($process));

        return @proc_terminate($process, $signal);
    }

    #[\Override]
    public function closeProcess(mixed $process): int
    {
        \assert(is_resource($process));

        return proc_close($process);
    }
}

/**
 * Runs git under a deadline, killing it when the deadline passes.
 *
 * $environment replaces the child's whole environment rather than adding
 * to it, so a caller handing git a credential decides exactly what else
 * that one process can see. Null inherits this process's environment,
 * which is what every read here wants.
 *
 * That array is the one argument here that can hold a credential, and an
 * argument is what PHP prints in the stack trace of an error nobody
 * caught. #[SensitiveParameter] is what keeps the value out of that
 * trace; it carries the credential through every hop, so every hop
 * declares it.
 *
 * @param list<string> $args
 * @param array<string, string>|null $environment
 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
 */
function runGit(
    array $args,
    string $workingDirectory,
    int|float $timeoutSeconds = GIT_TIMEOUT_SECONDS,
    int $outputLimit = GIT_OUTPUT_LIMIT,
    ?ProcessBoundary $boundary = null,
    #[SensitiveParameter] ?array $environment = null,
): array {
    return runCommand(['git', ...$args], $workingDirectory, $timeoutSeconds, $outputLimit, $boundary, $environment);
}

/**
 * Runs one command under a deadline, killing it when the deadline passes.
 *
 * Two things are watched at once. Draining stdout and stderr keeps the
 * child from blocking on a full pipe; watching the process is what makes
 * the deadline reachable, since a child can close both pipes and keep
 * running. See endProcess() for what the deadline does and does not
 * bound.
 *
 * Every way the answer can come out incomplete returns a nonzero exit
 * code — a failed select or read, output past $outputLimit, bytes still
 * arriving when the deadline passes, a child whose end could not be
 * established — because a caller reading NUL-separated paths cannot tell
 * a short list from a whole one.
 *
 * @param non-empty-list<string> $command
 * @param array<string, string>|null $environment
 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
 */
function runCommand(
    array $command,
    string $workingDirectory,
    int|float $timeoutSeconds = GIT_TIMEOUT_SECONDS,
    int $outputLimit = GIT_OUTPUT_LIMIT,
    ?ProcessBoundary $boundary = null,
    #[SensitiveParameter] ?array $environment = null,
): array {
    $boundary ??= new NativeProcessBoundary();
    $name = basename($command[0]);
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $environment);

    if (!is_resource($process)) {
        return commandFailure("{$name} could not be started");
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $captured = ['', ''];
    $truncated = false;
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = null;
    $failure = null;
    $timedOut = false;

    while (true) {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            $timedOut = true;

            break;
        }

        if ($open !== []) {
            $failure = drainOnce($boundary, $name, $open, $captured, $truncated, min($remaining, GIT_POLL_INTERVAL_SECONDS), $outputLimit);

            if ($failure !== null) {
                break;
            }
        } else {
            // Both pipes are closed and the child may still be running.
            // Sleeping the poll interval keeps the status check regular
            // without spinning on the CPU until the deadline.
            usleep((int) (min($remaining, GIT_POLL_INTERVAL_SECONDS) * 1_000_000));
        }

        $status = $boundary->status($process);

        if ($status === false) {
            $failure = "{$name} status became unreadable";

            break;
        }

        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            // The child is gone; whatever it wrote is still in the
            // pipes, and all of it belongs to the answer.
            $failure = drainRemaining($boundary, $name, $open, $captured, $truncated, $deadline, $outputLimit);

            break;
        }
    }

    $endProblem = endProcess($boundary, $name, $process, $open, terminate: $exitCode === null);

    if ($timedOut) {
        return commandFailure(
            trim("{$name} did not finish within {$timeoutSeconds}s " . (string) $endProblem),
            $captured[0],
            timedOut: true,
            truncated: $truncated,
        );
    }

    if ($failure !== null) {
        return commandFailure(trim($failure . ' ' . (string) $endProblem), $captured[0], truncated: $truncated);
    }

    if ($truncated) {
        return commandFailure(
            "{$name} wrote more than {$outputLimit} bytes, so its output is incomplete",
            $captured[0],
            truncated: true,
        );
    }

    // A child whose end could not be established is a child whose run
    // never finished as far as this code knows, so what it wrote is not
    // an answer either.
    if ($endProblem !== null) {
        return commandFailure($endProblem, $captured[0]);
    }

    return [
        'exitCode' => $exitCode ?? -1,
        'stdout' => $captured[0],
        'stderr' => $captured[1],
        'timedOut' => false,
        'truncated' => false,
    ];
}

/** @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool} */
function commandFailure(string $reason, string $stdout = '', bool $timedOut = false, bool $truncated = false): array
{
    return [
        'exitCode' => -1,
        'stdout' => $stdout,
        'stderr' => $reason,
        'timedOut' => $timedOut,
        'truncated' => $truncated,
    ];
}

/**
 * Reads whatever is ready on the still-open pipes, closing each one as
 * it ends.
 *
 * fread() answering false is a read that failed, not a stream that
 * ended; only an empty read on a stream at end of file is the end. The
 * two are reported differently because one of them means the output is
 * incomplete.
 *
 * Bytes past $outputLimit are dropped and the fact recorded: they still
 * have to leave the pipe or the child blocks writing them, but a process
 * producing output without end must not take this one's memory with it.
 *
 * @param array<1|2, resource> $open
 * @param array{0: string, 1: string} $captured
 * @param-out array<1|2, resource> $open
 * @param-out array{0: string, 1: string} $captured
 * @return string|null the reason draining failed, or null
 */
function drainOnce(
    ProcessBoundary $boundary,
    string $name,
    array &$open,
    array &$captured,
    bool &$truncated,
    float $waitSeconds,
    int $outputLimit,
): ?string {
    if ($open === []) {
        return null;
    }

    $read = array_values($open);
    $ready = $boundary->select($read, $waitSeconds);

    if ($ready === false) {
        return "waiting on {$name} output failed";
    }

    if ($ready === 0) {
        return null;
    }

    foreach ($open as $index => $pipe) {
        if (!in_array($pipe, $read, true)) {
            continue;
        }

        $chunk = $boundary->read($pipe, 65536);

        if ($chunk === false) {
            return "reading {$name} output failed";
        }

        if ($chunk === '') {
            if ($boundary->atEnd($pipe)) {
                $boundary->closeStream($pipe);
                unset($open[$index]);
            }

            continue;
        }

        $captureIndex = $index === 1 ? 0 : 1;
        $room = $outputLimit - strlen($captured[$captureIndex]);

        if (strlen($chunk) > $room) {
            $truncated = true;
        }

        if ($room > 0) {
            $captured[$captureIndex] .= substr($chunk, 0, $room);
        }
    }

    return null;
}

/**
 * Drains until both pipes reach end of file, so nothing the child wrote
 * before exiting is abandoned unread. Bounded by the same deadline as
 * everything else; bytes still arriving when it passes are a failure,
 * since the alternative is returning part of an answer as all of it.
 *
 * @param array<1|2, resource> $open
 * @param array{0: string, 1: string} $captured
 * @param-out array<1|2, resource> $open
 * @param-out array{0: string, 1: string} $captured
 * @return string|null
 */
function drainRemaining(
    ProcessBoundary $boundary,
    string $name,
    array &$open,
    array &$captured,
    bool &$truncated,
    float $deadline,
    int $outputLimit,
): ?string {
    while ($open !== []) {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            return "{$name} output was still arriving when the deadline passed";
        }

        $failure = drainOnce($boundary, $name, $open, $captured, $truncated, min($remaining, GIT_POLL_INTERVAL_SECONDS), $outputLimit);

        if ($failure !== null) {
            return $failure;
        }
    }

    return null;
}

/**
 * Closes the pipes, kills a child that overran, and reaps it.
 *
 * The reap is not optional and skipping it buys nothing: PHP waits for
 * the child when the process handle is released, so declining to call
 * proc_close() here only moves the same wait to an unmarked point with
 * no result to report. It is called on every path.
 *
 * What the deadline bounds is how long this code watches git, not how
 * long the child takes to disappear. Two things can extend the call past
 * it, and both are reported rather than assumed away:
 *
 *   - The kill can fail to be delivered. proc_terminate() answering
 *     false leaves a child that may still be running, and the reap then
 *     waits for as long as it runs. The deadline is no bound at all in
 *     that case, so the run fails and says why.
 *   - A process in an uninterruptible kernel wait does not react to a
 *     delivered SIGKILL until the wait ends. Nothing here can shorten
 *     that.
 *
 * Descendants the child spawned are neither signalled nor waited for, so
 * a `git` alias that forks leaves its own children running.
 *
 * @param resource $process
 * @param array<int, resource> $open
 * @return string|null why the child's end could not be established
 */
function endProcess(ProcessBoundary $boundary, string $name, mixed $process, array $open, bool $terminate): ?string
{
    foreach ($open as $pipe) {
        $boundary->closeStream($pipe);
    }

    $killFailed = $terminate && !$boundary->terminate($process, 9);
    $reapFailed = $boundary->closeProcess($process) === -1;

    if ($killFailed) {
        return "the {$name} process could not be killed, so the deadline did not bound this call";
    }

    return $reapFailed ? "the {$name} process could not be reaped" : null;
}

/**
 * stream_select() takes whole seconds and microseconds separately, so a
 * fractional wait has to be split rather than truncated — passing zero
 * microseconds for the last fraction of a second turns the loop into a
 * spin.
 *
 * @return array{int, int}
 */
function splitDuration(float $seconds): array
{
    if ($seconds <= 0) {
        return [0, 0];
    }

    $whole = (int) $seconds;
    $microseconds = (int) round(($seconds - $whole) * 1_000_000);

    if ($microseconds >= 1_000_000) {
        $whole++;
        $microseconds -= 1_000_000;
    }

    return [$whole, $microseconds];
}

/**
 * A git runner bound to one checkout. Every function below takes one so
 * its handling of git's answer can be exercised without arranging a real
 * repository that produces that answer — some of them, a working git
 * will not produce at all.
 *
 * @param array<string, string>|null $environment
 * @return callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
 */
function gitRunnerFor(
    string $workingDirectory,
    int|float $timeoutSeconds = GIT_TIMEOUT_SECONDS,
    int $outputLimit = GIT_OUTPUT_LIMIT,
    ?ProcessBoundary $boundary = null,
    #[SensitiveParameter] ?array $environment = null,
): callable {
    return static fn (array $args): array => runGit(array_values($args), $workingDirectory, $timeoutSeconds, $outputLimit, $boundary, $environment);
}

/**
 * git writes remote URLs into its own error text, and a CI remote URL
 * carries the token that reaches it. Everything reported out of this
 * file goes through here first.
 */
function redactCredentials(string $text): string
{
    $redacted = (string) preg_replace('#(\w+://)[^/@\s]*@#', '$1***@', $text);
    $redacted = (string) preg_replace('/\b(gh[pousr]_|github_pat_)[A-Za-z0-9_]{10,}/', '$1***', $redacted);
    $redacted = trim($redacted);

    return strlen($redacted) > 400 ? substr($redacted, 0, 400) . '…' : $redacted;
}

/**
 * Whether $ref names a commit here.
 *
 * git distinguishes the two answers this has to keep apart: exit 1 is
 * "no such commit", and anything else is git failing — not a repository,
 * a broken object store, no git binary at all. Reporting the second as
 * "absent" is what lets a broken checkout look like a clean first
 * commit.
 *
 * @throws HistoryUnavailable
 */
function gitCommitExists(string $ref, string $workingDirectory, ?callable $run = null): bool
{
    $run ??= gitRunnerFor($workingDirectory);
    // --end-of-options stops a ref that looks like a flag from becoming
    // one; ^{commit} rejects a ref resolving to a tree or a blob, and
    // --verify rejects an ambiguous name rather than picking a side.
    $result = $run(['rev-parse', '--verify', '--quiet', '--end-of-options', "{$ref}^{commit}"]);

    if ($result['exitCode'] === 0) {
        if (isObjectId(trim($result['stdout']))) {
            return true;
        }

        throw new HistoryUnavailable("git reported success for {$ref} without naming an object id");
    }

    // Exit 1 is the one answer that means the commit is not here.
    if ($result['exitCode'] === 1 && !$result['timedOut']) {
        return false;
    }

    throw new HistoryUnavailable(
        "git could not look up {$ref}: " . redactCredentials($result['stderr']),
    );
}

function isObjectId(string $candidate): bool
{
    return preg_match('/^[0-9a-f]{40}$|^[0-9a-f]{64}$/', $candidate) === 1;
}

/**
 * The exact object id $ref names. Every later git call uses that id
 * rather than the name it came from, so one resolution decides which
 * object is read and nothing downstream re-interprets the argument.
 *
 * @throws HistoryUnavailable
 */
function gitResolveCommit(string $ref, string $workingDirectory, ?callable $run = null): string
{
    $run ??= gitRunnerFor($workingDirectory);
    $result = $run(['rev-parse', '--verify', '--quiet', '--end-of-options', "{$ref}^{commit}"]);
    $commit = trim($result['stdout']);

    if ($result['exitCode'] !== 0 || $commit === '') {
        throw new HistoryUnavailable(
            "The comparison base {$ref} is not a commit in this checkout — "
            . 'deepen the fetch (fetch-depth: 0) so the version checks can read it. '
            . redactCredentials($result['stderr']),
        );
    }

    if (!isObjectId($commit)) {
        throw new HistoryUnavailable("git resolved {$ref} to something that is not an object id");
    }

    return $commit;
}

/**
 * Whether this checkout has truncated history. The answer decides
 * whether a parentless HEAD is a root commit or a missing one, so
 * anything other than git's own two words is no answer at all.
 *
 * @throws HistoryUnavailable
 */
function gitIsShallow(string $workingDirectory, ?callable $run = null): bool
{
    $run ??= gitRunnerFor($workingDirectory);
    $result = $run(['rev-parse', '--is-shallow-repository']);

    if ($result['exitCode'] !== 0) {
        throw new HistoryUnavailable(
            'git could not report whether this checkout is shallow: ' . redactCredentials($result['stderr']),
        );
    }

    return match (trim($result['stdout'])) {
        'true' => true,
        'false' => false,
        default => throw new HistoryUnavailable(
            'git answered the shallow check with something other than true or false',
        ),
    };
}

/**
 * The manifest as of $commit, held to the same structure and version
 * rules as the one on disk. A historical document those rules reject
 * can't be compared against meaningfully, and treating it as "no
 * history" would pass every version check on the one push whose history
 * is broken.
 *
 * @return array<string, mixed>
 * @throws HistoryUnavailable
 */
function readManifestAtCommit(string $commit, string $workingDirectory, ?callable $run = null): array
{
    $run ??= gitRunnerFor($workingDirectory);
    $result = $run(['show', '--end-of-options', "{$commit}:packages.manifest.json"]);

    if ($result['exitCode'] !== 0) {
        throw new HistoryUnavailable(
            "Could not read packages.manifest.json at {$commit}: " . redactCredentials($result['stderr']),
        );
    }

    try {
        $decoded = decodeManifest($result['stdout']);
    } catch (ManifestUnreadable $e) {
        throw new HistoryUnavailable("packages.manifest.json at {$commit}: " . $e->getMessage());
    }

    // Without a projectRoot the on-disk checks are skipped — packages/
    // describes the working tree, not this commit — while every
    // structural and version rule still applies.
    $problems = manifestProblems($decoded);

    if ($problems !== []) {
        throw new HistoryUnavailable(
            "packages.manifest.json at {$commit} is not a valid manifest: " . implode('; ', array_slice($problems, 0, 3)),
        );
    }

    /** @var array<string, mixed> */
    return $decoded;
}

/**
 * Tracked paths under packages/ that differ between $commit and the
 * working tree.
 *
 * --no-renames is load-bearing. git's default rename detection collapses
 * a moved file into one entry naming only its destination, which hides
 * the source package of a cross-package move — the package that lost the
 * file needs its own bump just as much as the one that gained it. With
 * renames off, the move reads as a deletion on one side and an addition
 * on the other, and both packages are attributed. -z emits paths raw and
 * NUL-separated, so a path holding a space, a quote or a non-UTF-8 byte
 * survives instead of arriving quoted and escaped.
 *
 * @return list<string>
 * @throws HistoryUnavailable
 */
function changedPackagePaths(string $commit, string $workingDirectory, ?callable $run = null): array
{
    $run ??= gitRunnerFor($workingDirectory);
    $result = $run(['diff', '--no-renames', '-z', '--name-only', '--end-of-options', $commit, '--', 'packages']);

    if ($result['exitCode'] !== 0) {
        throw new HistoryUnavailable(
            "Could not diff packages/ against {$commit}: " . redactCredentials($result['stderr']),
        );
    }

    return splitNulSeparatedPaths($result['stdout']);
}

/**
 * @return list<string>
 */
function splitNulSeparatedPaths(string $output): array
{
    return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
}
