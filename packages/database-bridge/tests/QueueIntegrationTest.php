<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\DanglingTransactionHolder;
use Kinetis\DatabaseBridge\Tests\Fixtures\DanglingTransactionJob;
use Kinetis\DatabaseBridge\Tests\Fixtures\InMemoryLogger;
use Kinetis\DatabaseBridge\Tests\Fixtures\NoOpJob;
use Kinetis\DatabaseBridge\Tests\Fixtures\SingleJobQueue;
use Kinetis\DatabaseBridge\Tests\Fixtures\ThrowingDanglingTransactionJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\SyncQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The TransactionGuard wiring this package's bootstrap installs, end to
 * end through QueueWorker and SyncQueue, which create a RequestScope per
 * job — real rollback, not merely that a callback was registered.
 */
final class QueueIntegrationTest extends TestCase
{
    public function test_a_queue_worker_rolls_back_a_transaction_left_open_by_a_job_that_completes_successfully(): void
    {
        $queue = new SingleJobQueue();
        $queue->push(new DanglingTransactionJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker(self::app(), $queue);
        self::assertTrue($worker->processNext());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
        self::assertSame([1], $queue->acked, 'the job itself succeeded — the dispose hook rolling back the leftover transaction does not turn that into a failure');
    }

    public function test_a_queue_worker_rolls_back_a_transaction_left_open_by_a_job_that_throws(): void
    {
        $queue = new SingleJobQueue();
        $queue->push(new ThrowingDanglingTransactionJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker(self::app(), $queue);
        self::assertTrue($worker->processNext());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
        self::assertSame([], $queue->acked);
    }

    /**
     * A job that never resolves TransactionGuard constructs none, so its
     * logger is never asked to report anything.
     */
    public function test_a_queue_worker_job_that_never_resolves_transaction_guard_remains_a_no_op(): void
    {
        $logger = new InMemoryLogger();
        $app = self::app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new SingleJobQueue();
        $queue->push(new NoOpJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker($app, $queue);
        self::assertTrue($worker->processNext());

        self::assertSame([1], $queue->acked);
        self::assertNull(DanglingTransactionHolder::$link);
        self::assertSame([], $logger->records, 'no transaction was ever opened, so there is nothing to roll back or warn about');
    }

    public function test_sync_queue_rolls_back_a_transaction_left_open_by_a_job_that_completes_successfully(): void
    {
        DanglingTransactionHolder::$link = null;

        (new SyncQueue(self::app()))->push(new DanglingTransactionJob());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * Unlike QueueWorker, SyncQueue lets a job's exception propagate to
     * the caller rather than swallowing it — but the scope it created
     * still disposes first, so the rollback still happens before that
     * exception reaches this test.
     */
    public function test_sync_queue_rolls_back_a_transaction_left_open_by_a_job_that_throws(): void
    {
        DanglingTransactionHolder::$link = null;

        try {
            (new SyncQueue(self::app()))->push(new ThrowingDanglingTransactionJob());
            self::fail('Expected the job\'s exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('deliberate failure', $e->getMessage());
        }

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * @param (callable(AppScope): void)|null $beforeBoot registrations must
     *        happen before boot() locks the container
     */
    private static function app(?callable $beforeBoot = null): AppScope
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();

        return $app;
    }
}
