<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Queue\FailedJob;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\StoredFailedJob;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SqlFailedJobStore against the shipped migration stubs on MySQL (or
 * MariaDB) and Postgres. Environment-gated; see {@see Backend}.
 */
final class SqlFailedJobStoreTest extends TestCase
{
    private MysqlLink|PostgresLink|null $link = null;

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->link = null;
    }

    /**
     * @return iterable<string, array{'mysql'|'pgsql'}>
     */
    public static function dialects(): iterable
    {
        yield 'MySQL family' => ['mysql'];
        yield 'Postgres' => ['pgsql'];
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     */
    #[DataProvider('dialects')]
    public function test_a_record_round_trips_through_find_list_forget_and_prune(string $dialect): void
    {
        $store = $this->store($dialect);
        $message = str_repeat('é', 1500);
        $job = new FailedJob(
            null,
            'mail',
            SensitiveJob::class,
            ['userId' => 7, 'token' => 'tok-secret', 'ratio' => 4.0, 'tags' => ['a', 'b'], 'none' => null],
            null,
            1,
            RuntimeException::class,
            $message,
            time() - 7200,
        );

        $id = $store->record($job);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $id);
        self::assertEquals(new StoredFailedJob($id, $job), $store->find($id));
        self::assertSame(4.0, $store->find($id)?->job->args['ratio'], 'an integral float stays a float');
        self::assertSame(FailedJob::MAX_EXCEPTION_MESSAGE_BYTES, \strlen((string) $store->find($id)?->job->exceptionMessage));
        self::assertEquals([new StoredFailedJob($id, $job)], $store->list(10));

        $recent = $store->record(self::job(failedAt: time()));
        self::assertSame(1, $store->prune(3600));
        self::assertNull($store->find($id));
        self::assertNotNull($store->find($recent));

        $store->forget($recent);
        self::assertNull($store->find($recent));
        self::assertSame([], $store->list(10));
    }

    /**
     * An SQS message admits 256 KiB, so the arguments column must hold
     * more than MySQL TEXT's 64 KiB.
     *
     * @param 'mysql'|'pgsql' $dialect
     */
    #[DataProvider('dialects')]
    public function test_a_job_as_large_as_an_sqs_message_is_recorded_in_full(string $dialect): void
    {
        $store = $this->store($dialect);
        $job = new FailedJob(null, 'default', SensitiveJob::class, ['userId' => 1, 'token' => str_repeat('x', 256 * 1024)], 3, 3, RuntimeException::class, 'boom', time());

        $id = $store->record($job);

        self::assertEquals(new StoredFailedJob($id, $job), $store->find($id));
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     */
    #[DataProvider('dialects')]
    public function test_keyset_pages_cover_records_failed_in_the_same_second_exactly_once(string $dialect): void
    {
        $store = $this->store($dialect);
        $ids = [];

        for ($i = 0; $i < 7; $i++) {
            $ids[] = $store->record(self::job(failedAt: 1_000));
        }

        $ids[] = $earliest = $store->record(self::job(failedAt: 999));

        $seen = [];
        $after = null;

        do {
            $page = $store->list(3, after: $after);
            array_push($seen, ...array_map(static fn (StoredFailedJob $s): string => $s->id, $page));
            $after = $page === [] ? null : $page[\count($page) - 1];
        } while (\count($page) === 3);

        $sameSecond = \array_slice($ids, 0, 7);
        sort($sameSecond);
        self::assertSame([$earliest, ...$sameSecond], $seen);
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     */
    #[DataProvider('dialects')]
    public function test_the_connection_and_queue_filters_select_byte_exactly(string $dialect): void
    {
        $store = $this->store($dialect);
        $bound = $store->record(self::job(connection: null, queue: 'mail'));
        $jobs = $store->record(self::job(connection: 'jobs', queue: 'mail'));
        $jobsReports = $store->record(self::job(connection: 'jobs', queue: 'reports'));
        $store->record(self::job(connection: 'other', queue: 'Mail'));

        $ids = static fn (array $page): array => array_map(static fn (StoredFailedJob $s): string => $s->id, $page);

        self::assertSame([$bound], $ids($store->list(10, connection: null)));
        self::assertEqualsCanonicalizing([$jobs, $jobsReports], $ids($store->list(10, connection: 'jobs')));
        self::assertEqualsCanonicalizing([$bound, $jobs], $ids($store->list(10, queue: 'mail')));
        self::assertSame([$jobsReports], $ids($store->list(10, connection: 'jobs', queue: 'reports')));
        self::assertCount(4, $store->list(10));
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     */
    private function store(string $dialect): SqlFailedJobStore
    {
        $this->link = ConnectionFactory::fromConfig(new Config(Backend::keys($dialect)));
        Backend::migrate($this->link, Backend::failedJobsStubs(), 'kinetis_failed_jobs');

        return new SqlFailedJobStore($this->link);
    }

    private static function job(?string $connection = null, string $queue = 'default', int $failedAt = 1_800_000_000): FailedJob
    {
        return new FailedJob($connection, $queue, SensitiveJob::class, ['userId' => 1, 'token' => 't'], 3, 3, RuntimeException::class, 'boom', $failedAt);
    }
}
