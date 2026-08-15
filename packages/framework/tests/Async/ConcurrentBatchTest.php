<?php

declare(strict_types=1);

namespace Kinetis\Tests\Async;

use Kinetis\Async\ConcurrentBatch;
use Kinetis\Async\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Fiber;

final class ConcurrentBatchTest extends TestCase
{
    public function test_jobs_record_results_by_index(): void
    {
        $batch = new ConcurrentBatch(2);

        ($batch->jobFor(1, static fn (): string => 'second'))();
        ($batch->jobFor(0, static fn (): string => 'first'))();

        self::assertSame(['first', 'second'], $batch->results());
    }

    public function test_a_job_records_its_failure_instead_of_throwing(): void
    {
        $batch = new ConcurrentBatch(1);
        $job = $batch->jobFor(0, static fn () => throw new RuntimeException('boom'));

        $job();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $batch->results();
    }

    public function test_the_first_failure_in_task_order_wins(): void
    {
        $batch = new ConcurrentBatch(3);

        ($batch->jobFor(2, static fn () => throw new RuntimeException('later')))();
        ($batch->jobFor(0, static fn (): string => 'fine'))();
        ($batch->jobFor(1, static fn () => throw new RuntimeException('earlier')))();

        $this->expectExceptionMessage('earlier');
        $batch->results();
    }

    public function test_await_returns_immediately_once_every_job_finished(): void
    {
        $batch = new ConcurrentBatch(1);
        ($batch->jobFor(0, static fn (): int => 1))();

        $batch->await();

        self::assertSame([1], $batch->results());
    }

    public function test_await_diagnoses_the_first_unfinished_task_as_deadlocked(): void
    {
        $batch = new ConcurrentBatch(2);
        ($batch->jobFor(0, static fn (): string => 'finished'))();

        // Task 1 parks its Fiber with nothing registered to resume it —
        // the loop runs dry with the caller still waiting.
        $fiber = new Fiber(static function () use ($batch): void {
            ($batch->jobFor(1, static function (): void {
                Fiber::suspend();
            }))();
        });
        $fiber->start();

        $this->expectException(DeadlockException::class);
        $this->expectExceptionMessageMatches('/index 1/');
        $batch->await();
    }
}
