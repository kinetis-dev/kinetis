<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\FailedJobRecorderInterface;
use Kinetis\QueueFailedJobs\PackageBootstrap;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store on the database connection FAILED_JOBS_DB_CONNECTION_NAME
 * selects. Environment-gated; see {@see Backend}. A retry onto a named
 * queue connection needs `kinetis_queue_jobs`, which other suites sharing
 * these servers own, so it is checked in tests-integration/run.php.
 */
final class NamedConnectionTest extends TestCase
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
