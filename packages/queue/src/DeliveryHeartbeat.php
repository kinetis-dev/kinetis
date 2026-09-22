<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * Keeps one delivery's reservation alive for as long as its handler
 * runs, so a job that legitimately outlasts its backend's visibility
 * window is not handed to a second worker while the first is still
 * working on it.
 *
 * One repeat watcher, at half the backend's window, so a renewal that
 * is late by a whole interval still lands inside the window it is
 * extending. The watcher is unreferenced the instant it exists: a
 * referenced one would keep the event loop alive by itself, and
 * {@see \Kinetis\Async\ConcurrentBatch} reads "the loop ran out of
 * watchers" as the signal that a task deadlocked — a heartbeat holding
 * the loop open would hide that, turning a job's own deadlock into a
 * hang for as long as the job kept running.
 *
 * Revolt runs a watcher's callback on a Fiber of its own, so a renewal
 * suspends on its backend's I/O while the handler keeps running, and
 * the driver re-arms a repeat watcher *before* invoking it. A slow
 * renewal can therefore still be in flight when the next tick comes
 * due; $renewing suppresses that tick rather than opening a second
 * request against the same receipt.
 *
 * **A renewal never decides anything about the job.** It cannot fail
 * the handler, settle the delivery or stop the loop: every throwable
 * is caught here, and later ticks keep trying, because renewal is
 * idempotent and one refused write must not surrender the rest of a
 * lease that may still be extendable. What survives is a count and the
 * last exception, which {@see QueueWorker} logs once after the job's
 * own outcome is recorded.
 *
 * **stop() joins.** SQS renews and releases with the same
 * `ChangeMessageVisibility` call, so a renewal still in flight when the
 * worker released the job for a retry could overwrite the backoff the
 * release just set. stop() therefore cancels the watcher and then waits
 * out whatever is already running, bounded by the adapter's own
 * operation timeout, before the worker settles anything. A handler that
 * never yields to the event loop — a CPU-bound loop — is never renewed
 * at all: nothing here can interrupt it.
 *
 * @internal QueueWorker owns the whole lifecycle; nothing else
 *     constructs one.
 */
final class DeliveryHeartbeat
{
    private ?string $watcherId = null;

    /** Whether a renewal call is in flight right now — see the class docblock. */
    private bool $renewing = false;

    /**
     * Non-null exactly while stop() is parked waiting for an in-flight
     * renewal, so the renewal resumes a suspension that really is
     * suspended. Revolt's own Suspension::resume() throws otherwise.
     *
     * @var Suspension<null>|null
     */
    private ?Suspension $joiner = null;

    private int $failures = 0;

    private ?Throwable $lastFailure = null;

    private function __construct(
        private readonly RenewableQueueInterface $queue,
        private readonly QueuedJob $job,
    ) {}

    /**
     * Arms the watcher for the job about to run. Its first tick is due
     * half a window in — see this class's own docblock for why that
     * interval.
     */
    public static function start(RenewableQueueInterface $queue, QueuedJob $job): self
    {
        $heartbeat = new self($queue, $job);

        // Half the window in float seconds, so a one-second window still
        // gets a real interval (0.5) instead of collapsing to an integer
        // 0 that would spin the loop.
        $watcherId = EventLoop::repeat(
            $queue->visibilityTimeoutSeconds() / 2,
            static function () use ($heartbeat): void {
                $heartbeat->tick();
            },
        );

        EventLoop::unreference($watcherId);
        $heartbeat->watcherId = $watcherId;

        return $heartbeat;
    }

    /**
     * Cancels the watcher and joins an in-flight renewal, after which
     * nothing this object started is still running or scheduled. Never
     * throws: a failed join is recorded as a renewal failure like any
     * other, since it leaves the same thing unknown — whether the window
     * was extended.
     */
    public function stop(): void
    {
        if ($this->watcherId !== null) {
            // Cancel also drops a tick already queued for this turn of
            // the loop: Revolt skips a callback whose identifier is gone.
            EventLoop::cancel($this->watcherId);
            $this->watcherId = null;
        }

        if (!$this->renewing) {
            return;
        }

        try {
            // Assigned and suspended with nothing in between, so a
            // renewal can never observe a joiner that has not parked yet.
            $this->joiner = EventLoop::getSuspension();
            $this->joiner->suspend();
        } catch (Throwable $e) {
            $this->recordFailure($e);
        } finally {
            $this->joiner = null;
        }
    }

    /**
     * How many renewal attempts failed for this delivery. Zero is the
     * ordinary case, including for a job that finished before the first
     * tick was ever due.
     */
    public function failureCount(): int
    {
        return $this->failures;
    }

    public function lastFailure(): ?Throwable
    {
        return $this->lastFailure;
    }

    private function tick(): void
    {
        if ($this->renewing) {
            return;
        }

        $this->renewing = true;

        try {
            $this->queue->renew($this->job);
        } catch (Throwable $e) {
            $this->recordFailure($e);
        } finally {
            $this->renewing = false;
            $this->joiner?->resume();
        }
    }

    private function recordFailure(Throwable $e): void
    {
        $this->failures++;
        $this->lastFailure = $e;
    }
}
