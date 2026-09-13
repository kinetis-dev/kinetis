<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use InvalidArgumentException;
use Kinetis\Async\Timer;
use Kinetis\Testing\Exception\LoopLivenessInconclusiveException;

use function Kinetis\Async\concurrently;

/**
 * Observes whether the event loop turns while an operation is in flight.
 * See docs/testing.md, "Proving a path keeps the loop responsive".
 *
 * The sentinel and the operation run as two concurrently() tasks, sentinel
 * first: its timer is registered before the operation starts, so it is
 * already due when an operation that blocked past the interval returns, and
 * it can only resume on a loop turn, which cannot happen before the
 * operation's task has started. Waiting on both tasks leaves no watcher
 * behind on any return or throw.
 */
final class LoopLiveness
{
    /**
     * @param callable(): mixed $operation
     * @return bool true when the sentinel resumed while $operation was in
     *     flight; false when $operation ran for at least $sentinelSeconds
     *     and finished before the sentinel could resume
     * @throws LoopLivenessInconclusiveException when $operation finished in
     *     less than $sentinelSeconds and the sentinel had not resumed
     */
    public static function turnedDuring(callable $operation, float $sentinelSeconds = 0.02): bool
    {
        if (!\is_finite($sentinelSeconds) || $sentinelSeconds <= 0.0) {
            throw new InvalidArgumentException(\sprintf(
                'The sentinel interval must be a finite number of seconds greater than zero, got %s.',
                $sentinelSeconds,
            ));
        }

        $inFlight = false;
        $turned = false;
        $elapsedNanoseconds = 0;

        concurrently([
            static function () use ($sentinelSeconds, &$inFlight, &$turned): void {
                Timer::delay($sentinelSeconds);
                $turned = $inFlight;
            },
            static function () use ($operation, &$inFlight, &$elapsedNanoseconds): void {
                $inFlight = true;
                $start = \hrtime(true);

                try {
                    $operation();
                } finally {
                    $elapsedNanoseconds = \hrtime(true) - $start;
                    $inFlight = false;
                }
            },
        ]);

        // concurrently() runs the sentinel closure before returning, but
        // PHPStan does not model its by-reference write to this local.
        if ($turned) { // @phpstan-ignore if.alwaysFalse
            return true;
        }

        if ($elapsedNanoseconds < $sentinelSeconds * 1e9) {
            throw LoopLivenessInconclusiveException::operationFinishedBeforeSentinel(
                $elapsedNanoseconds / 1e9,
                $sentinelSeconds,
            );
        }

        return false;
    }
}
