<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\FailedJobRetrier;
use Kinetis\QueueFailedJobs\RetryResult;
use Kinetis\QueueFailedJobs\RetryStatus;
use Kinetis\QueueFailedJobs\Tests\Console\CommandTestCase;
use Kinetis\QueueFailedJobs\Tests\Fixtures\RecordingQueue;
use Kinetis\QueueFailedJobs\Tests\Fixtures\RejectingJob;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use RuntimeException;

/**
 * Named connections build a real backend through QueueFactory, so a
 * successful push onto one is covered in tests/Integration.
 */
final class FailedJobRetrierTest extends CommandTestCase
{
    private RecordingQueue $boundQueue;

    private FailedJobRetrier $retrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundQueue = new RecordingQueue();
        $app = new AppScope();
        $app->instance(QueueInterface::class, $this->boundQueue);
        $app->boot();
        $this->retrier = new FailedJobRetrier($this->store, $app, new Config([]));
    }

    public function test_a_record_is_pushed_with_its_stored_queue_and_cap_then_forgotten(): void
    {
        $id = $this->store->record(self::failedJob(queue: 'mail', maxAttempts: 5));

        $result = $this->retrier->retry($id);

        self::assertEquals(RetryResult::retried($id, SensitiveJob::class, 'mail'), $result);
        self::assertNull($result->reason);
        self::assertCount(1, $this->boundQueue->pushed);
        self::assertEquals(new SensitiveJob(7, 'tok-secret'), $this->boundQueue->pushed[0]['job']);
        self::assertSame('mail', $this->boundQueue->pushed[0]['queue']);
        self::assertSame(5, $this->boundQueue->pushed[0]['maxAttempts']);
        self::assertNull($this->store->find($id));
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $result = $this->retrier->retry('0123456789abcdef0123456789abcdef');

        self::assertSame(RetryStatus::NotFound, $result->status);
        self::assertNull($result->class);
        self::assertNull($result->queue);
        self::assertSame([], $this->boundQueue->pushed);
    }

    public function test_a_batch_reports_each_failure_keeps_its_record_and_goes_on(): void
    {
        $removed = $this->store->record(self::failedJob(class: 'App\Jobs\Removed'));
        $drifted = $this->store->record(self::failedJob(args: ['userId' => 7, 'token' => 'tok-secret', 'extra' => 1]));
        $missing = '0123456789abcdef0123456789abcdef';
        $valid = $this->store->record(self::failedJob());

        $results = $this->retrier->retryMany([$removed, $drifted, $missing, $valid]);

        self::assertSame([$removed, $drifted, $missing, $valid], array_map(static fn (RetryResult $r): string => $r->id, $results));
        self::assertSame(
            [RetryStatus::Failed, RetryStatus::Failed, RetryStatus::NotFound, RetryStatus::Retried],
            array_map(static fn (RetryResult $r): RetryStatus => $r->status, $results),
        );
        self::assertSame('Cannot reconstruct "App\Jobs\Removed": that class no longer exists.', $results[0]->reason);
        self::assertStringContainsString('"$extra", which matches no constructor parameter', (string) $results[1]->reason);
        self::assertSame('mail', $results[1]->queue);
        self::assertNotNull($this->store->find($removed));
        self::assertNotNull($this->store->find($drifted));
        self::assertNull($this->store->find($valid));
        self::assertCount(1, $this->boundQueue->pushed);
    }

    public function test_a_refused_push_fails_and_keeps_the_record(): void
    {
        $id = $this->store->record(self::failedJob());
        $this->boundQueue->refusePush = true;

        $result = $this->retrier->retry($id);

        self::assertEquals(RetryResult::failed($id, SensitiveJob::class, 'mail', 'The backend refused the push.'), $result);
        self::assertNotNull($this->store->find($id), 'the record survives a failed push');
    }

    public function test_an_unconfigured_connection_fails_and_keeps_the_record(): void
    {
        $id = $this->store->record(self::failedJob(connection: 'jobs'));

        $result = $this->retrier->retry($id);

        self::assertSame(RetryStatus::Failed, $result->status);
        self::assertStringContainsString('QUEUE_JOBS_CONNECTION', (string) $result->reason);
        self::assertNotNull($this->store->find($id));
    }

    public function test_a_store_refusing_the_forget_after_a_push_propagates(): void
    {
        $id = $this->store->record(self::failedJob());
        $this->link->refuseForget = true;

        try {
            $this->retrier->retry($id);
            self::fail('Expected the store failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('The database refused the delete.', $e->getMessage());
        }

        self::assertCount(1, $this->boundQueue->pushed);
    }

    public function test_a_constructor_quoting_an_argument_is_reported_without_it(): void
    {
        $id = $this->store->record(self::failedJob(class: RejectingJob::class, args: ['email' => 'a@example.com']));

        $result = $this->retrier->retry($id);

        self::assertSame(RetryStatus::Failed, $result->status);
        self::assertSame(
            'Cannot reconstruct "' . RejectingJob::class . '": its constructor threw InvalidArgumentException.',
            $result->reason,
        );
        self::assertStringNotContainsString('a@example.com', var_export($result, true));
    }

    public function test_matching_retries_the_oldest_records_up_to_the_limit_across_pages(): void
    {
        // Every record failing in the same second, so the order and the
        // walk depend on the id half of the cursor.
        for ($i = 0; $i < 150; $i++) {
            $this->store->record(self::failedJob(failedAt: 1_000));
        }

        $oldest = array_keys($this->link->rows);
        sort($oldest);

        $results = $this->retrier->retryMatching(limit: 120);

        self::assertSame(\array_slice($oldest, 0, 120), array_map(static fn (RetryResult $r): string => $r->id, $results));
        self::assertCount(120, $this->boundQueue->pushed);
        $remaining = array_keys($this->link->rows);
        sort($remaining);
        self::assertSame(\array_slice($oldest, 120), $remaining);
    }

    public function test_matching_applies_the_selection(): void
    {
        $bound = $this->store->record(self::failedJob(queue: 'mail', failedAt: 1_000));
        $this->store->record(self::failedJob(queue: 'reports', failedAt: 1_000));
        $this->store->record(self::failedJob(connection: 'jobs', queue: 'mail', failedAt: 1_000));

        $results = $this->retrier->retryMatching(null, 'mail');

        self::assertCount(1, $results);
        self::assertSame($bound, $results[0]->id);
        self::assertSame(RetryStatus::Retried, $results[0]->status);
    }

    public function test_matching_stops_at_a_record_that_failed_after_the_call_started(): void
    {
        $this->store->record(self::failedJob(failedAt: 1_000));
        $later = $this->store->record(self::failedJob(failedAt: time() + 3600));
        $this->store->record(self::failedJob(failedAt: time() + 7200));

        $results = $this->retrier->retryMatching();

        self::assertCount(1, $results);
        self::assertCount(1, $this->boundQueue->pushed);
        self::assertContains($later, array_keys($this->link->rows));
        self::assertCount(2, $this->link->rows);
    }

    public function test_the_limit_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The retry limit must be at least 1, got 0.');

        $this->retrier->retryMatching(limit: 0);
    }
}
