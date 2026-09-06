<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use Amp\Cancellation;
use Amp\TimeoutCancellation;

/**
 * One absolute budget for one logical operation, taken from the
 * monotonic clock so a system time adjustment cannot extend or collapse
 * it. The same instance covers connection establishment, TLS, AUTH,
 * SELECT, the write, the reply, cluster discovery, and every redirect
 * hop of that operation — a redirect does not buy more time.
 *
 * Cluster discovery carves a share of what remaining() reports for each
 * seed it tries, so no one seed can spend the whole budget. Every such
 * share ends inside this one.
 *
 * cancellation() hands out one TimeoutCancellation for the whole
 * budget, created on first use and shared by every wait the operation
 * makes. Its timer is unreferenced, so a pending operation never keeps
 * the event loop alive on the budget's account alone, and it is
 * cancelled once the operation releases this instance.
 *
 * A cancellation retains the exception it raised, and that exception's
 * trace is the stack the operation was suspended on when the budget ran
 * out. One of those stacks is amphp/byte-stream's write, whose frames
 * hold the payload as their own unmarked argument. So every parameter
 * carrying this class or a cancellation taken from it is marked
 * `#[\SensitiveParameter]`, exactly like one carrying a key, a value or
 * a password: a trace that held the budget would reach the payload
 * through it.
 */
final class Deadline
{
    private ?Cancellation $cancellation = null;

    private function __construct(private readonly float $expiresAt) {}

    public static function in(float $seconds): self
    {
        return new self(self::now() + $seconds);
    }

    /** Seconds left, zero once the budget is spent. */
    public function remaining(): float
    {
        return max(0.0, $this->expiresAt - self::now());
    }

    /**
     * True once nothing is left. A dispatch made on a spent budget
     * would be cancelled the instant it began, so callers reject it
     * before any byte is written instead.
     */
    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation ??= new TimeoutCancellation(
            $this->remaining(),
            'The Redis operation budget expired.',
        );
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
