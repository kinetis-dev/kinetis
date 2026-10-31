<?php

declare(strict_types=1);

/**
 * A retry onto the named queue connection a record names, end to end:
 * the record is pushed through QueueFactory onto kinetis/queue-sql on the
 * MySQL server at MYSQL_HOST, with its class, arguments, queue and cap,
 * and is then forgotten. Run by its own CI job rather than the PHPUnit
 * suite, because `kinetis_queue_jobs` on the shared coverage servers
 * belongs to other suites.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Queue\FailedJob;
use Kinetis\QueueFailedJobs\Console\RetryCommand;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use Kinetis\QueueFailedJobs\Tests\Integration\Backend;
use Kinetis\QueueSql\SqlQueueFactory;

function check(string $label, bool $condition): void
{
    echo ($condition ? 'OK   ' : 'FAIL ') . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

if (getenv('MYSQL_HOST') === false) {
    fwrite(STDERR, "MYSQL_HOST is not set.\n");
    exit(1);
}

$config = new Config([
    ...Backend::keys('mysql'),
    ...Backend::keys('mysql', 'jobs'),
    'QUEUE_JOBS_CONNECTION' => 'sql',
]);
$link = ConnectionFactory::fromConfig($config);
Backend::migrate($link, Backend::failedJobsStubs(), 'kinetis_failed_jobs');
Backend::migrate($link, __DIR__ . '/../vendor/kinetis/queue-sql/resources/migrations', 'kinetis_queue_jobs');

$store = new SqlFailedJobStore($link);
$id = $store->record(new FailedJob(
    'jobs',
    'mail',
    SensitiveJob::class,
    ['userId' => 7, 'token' => 'tok-secret'],
    5,
    5,
    RuntimeException::class,
    'boom',
    time() - 60,
));

$app = new AppScope();
$app->boot();
$output = fopen('php://memory', 'r+');
assert(is_resource($output));

$exit = new RetryCommand($store, $app->createRequestScope(), $config, $output)->run(CommandArguments::parse([$id]));
$app->dispose();

check('queue:retry exits 0', $exit === 0);
check('the record is forgotten after the push', $store->find($id) === null);

$queue = SqlQueueFactory::fromConfig($config, 'jobs');
$popped = $queue->pop(1, ['mail']);
check('the job is on the record\'s queue of the named connection', $popped !== null);
check('the class is the recorded one', $popped?->class === SensitiveJob::class);
check('the arguments are the raw recorded ones', $popped?->args === ['userId' => 7, 'token' => 'tok-secret']);
check('the recorded maxAttempts is pushed', $popped?->maxAttempts === 5);
check('the retry is a fresh job', $popped?->attempts === 1);
$queue->ack($popped);
$queue->dispose();
$link->close();

echo "ALL CHECKS PASSED\n";
