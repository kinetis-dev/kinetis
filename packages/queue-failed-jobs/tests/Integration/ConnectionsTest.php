<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\FailedJobRecorderInterface;
use Kinetis\QueueFailedJobs\Console\RetryCommand;
use Kinetis\QueueFailedJobs\PackageBootstrap;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use Kinetis\QueueSql\SqlQueueFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two named-connection paths: the store on the database connection
 * FAILED_JOBS_DB_CONNECTION_NAME selects, and a retry onto the queue
 * connection a record names. Environment-gated; see {@see Backend}.
 */
final class ConnectionsTest extends TestCase
{
    /** @var list<MysqlLink|PostgresLink> */
    private array $links = [];

    protected function tearDown(): void
    {
        foreach ($this->links as $link) {
            $link->close();
        }

        $this->links = [];
    }

    /**
     * `default` is MySQL and `reports` is Postgres, so where the row lands
     * shows which connection the bootstrapped store used.
     */
    public function test_the_store_writes_to_the_named_database_connection_only(): void
    {
        $config = new Config([
            ...Backend::keys('mysql'),
            ...Backend::keys('pgsql', 'reports'),
            'FAILED_JOBS_DB_CONNECTION_NAME' => 'reports',
        ]);
        $default = $this->link($config, 'default');
        $reports = $this->link($config, 'reports');
        Backend::migrate($default, Backend::failedJobsStubs(), 'kinetis_failed_jobs');
        Backend::migrate($reports, Backend::failedJobsStubs(), 'kinetis_failed_jobs');

        $app = new AppScope();
        new PackageBootstrap()->register($app, $config);
        $app->boot();

        $recorder = $app->get(FailedJobRecorderInterface::class);
        self::assertSame($app->get(SqlFailedJobStore::class), $recorder, 'one store, one link');

        $id = $recorder->record(new FailedJob(null, 'default', SensitiveJob::class, ['userId' => 1, 'token' => 't'], null, 1, RuntimeException::class, 'boom', time()));
        $app->dispose();

        self::assertSame(1, self::rowsWithId($reports, $id));
        self::assertSame(0, self::rowsWithId($default, $id));
    }

    /**
     * A record naming queue connection `jobs` is pushed through
     * QueueFactory onto that connection — kinetis/queue-sql on the MySQL
     * server here — with its class, arguments, queue and cap, and the
     * record is then forgotten.
     */
    public function test_a_retry_pushes_onto_the_named_queue_connection_the_record_names(): void
    {
        $config = new Config([
            ...Backend::keys('mysql'),
            ...Backend::keys('mysql', 'jobs'),
            'QUEUE_JOBS_CONNECTION' => 'sql',
        ]);
        $link = $this->link($config, 'default');
        Backend::migrate($link, Backend::failedJobsStubs(), 'kinetis_failed_jobs');
        Backend::migrate($link, \dirname(__DIR__, 2) . '/vendor/kinetis/queue-sql/resources/migrations', 'kinetis_queue_jobs');

        $store = new SqlFailedJobStore($link);
        $id = $store->record(new FailedJob('jobs', 'mail', SensitiveJob::class, ['userId' => 7, 'token' => 'tok-secret'], 5, 5, RuntimeException::class, 'boom', time() - 60));

        $app = new AppScope();
        $app->boot();
        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $exit = new RetryCommand($store, $app->createRequestScope(), $config, $output)->run(CommandArguments::parse([$id]));
        $app->dispose();

        self::assertSame(0, $exit);
        self::assertNull($store->find($id));

        $queue = SqlQueueFactory::fromConfig($config, 'jobs');
        $popped = $queue->pop(1, ['mail']);
        self::assertNotNull($popped);
        self::assertSame(SensitiveJob::class, $popped->class);
        self::assertSame(['userId' => 7, 'token' => 'tok-secret'], $popped->args);
        self::assertSame('mail', $popped->queue);
        self::assertSame(5, $popped->maxAttempts);
        self::assertSame(1, $popped->attempts, 'a retry is a fresh job');
        $queue->ack($popped);
        $queue->dispose();
    }

    private function link(Config $config, string $connection): MysqlLink|PostgresLink
    {
        return $this->links[] = ConnectionFactory::fromConfig($config, $connection);
    }

    private static function rowsWithId(MysqlLink|PostgresLink $link, string $id): int
    {
        $row = $link->execute('SELECT COUNT(*) AS c FROM kinetis_failed_jobs WHERE id = ?', [$id])->fetchRow();

        return (int) ($row['c'] ?? -1);
    }
}
