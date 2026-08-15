<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Closure;
use Fiber;
use SplQueue;

/**
 * Resident worker Fibers that run submitted jobs and then *park* (a raw
 * Fiber::suspend() with the pool holding the reference) instead of
 * terminating, so the next job reuses a live Fiber rather than paying
 * for a fresh one.
 *
 * Why this exists: constructing and discarding a Fiber allocates and
 * frees a whole C stack — an mmap/mprotect/madvise/munmap cycle per
 * Fiber. Those syscalls take the *process-wide* kernel address-space
 * lock and trigger cross-CPU TLB shootdowns, so under a threaded worker
 * runtime a fan-out that builds one Fiber per task serializes every
 * worker thread in the process against the same lock — capping the
 * whole process's fan-out throughput near two threads' worth regardless
 * of thread count, with the CPU idle. Parked residents make the steady
 * state allocation-free, which is what removes the ceiling: on an
 * 8-vCPU host, resident reuse measures roughly 3× the throughput of
 * per-task construction on a 20-query fan-out route.
 *
 * The pool is deliberately per *PHP thread* (a static on a ZTS build is
 * thread-local, and each FrankenPHP worker thread runs its own event
 * loop), holds only Fibers that are idle — a job that suspends on I/O
 * keeps its Fiber out of the idle list until the job finishes — and
 * retains at most MAX_IDLE parked residents; beyond that a finishing
 * worker terminates normally and its stack is freed.
 *
 * Jobs must not let exceptions escape: a throw would unwind the
 * resident worker loop itself. concurrently() wraps every task in its
 * own try/catch before submitting, which is why submit() can keep that
 * as a contract instead of paying for a second guard here.
 *
 * @internal Only {@see concurrently()} should submit jobs.
 */
final class FiberPool
{
    /**
     * Enough residents for several overlapping wide fan-outs; a burst
     * beyond it still runs (fresh Fibers are created on demand), the
     * surplus just isn't retained afterwards.
     */
    private const int MAX_IDLE = 64;

    private static ?self $instance = null;

    /** @var list<Fiber<void, void, void, void>> */
    private array $idle = [];

    /** @var SplQueue<Closure(): void> */
    private readonly SplQueue $jobs;

    private function __construct()
    {
        /** @var SplQueue<Closure(): void> $queue */
        $queue = new SplQueue();
        $this->jobs = $queue;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Enqueues a job and hands it to a parked resident, or starts a new
     * worker Fiber when none is idle. Returns as soon as the job first
     * suspends (or completes synchronously), exactly like starting a
     * dedicated Fiber would.
     *
     * @param Closure(): void $job Must catch its own exceptions.
     */
    public function submit(Closure $job): void
    {
        $this->jobs->enqueue($job);

        $worker = \array_pop($this->idle);

        if ($worker !== null) {
            $worker->resume();

            return;
        }

        $fiber = new Fiber(function (): void {
            while (true) {
                if ($this->jobs->isEmpty()) {
                    if (\count($this->idle) >= self::MAX_IDLE) {
                        return;
                    }

                    // @phpstan-ignore assign.propertyType (Fiber::getCurrent() is this very worker — never null here, template types unknowable)
                    $this->idle[] = Fiber::getCurrent();
                    Fiber::suspend();

                    continue;
                }

                ($this->jobs->dequeue())();
            }
        });

        $fiber->start();
    }
}
