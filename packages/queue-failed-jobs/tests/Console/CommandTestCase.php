<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Console;

use Kinetis\Queue\FailedJob;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\Tests\Fixtures\FailedJobsTableLink;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class CommandTestCase extends TestCase
{
    protected FailedJobsTableLink $link;

    protected SqlFailedJobStore $store;

    protected function setUp(): void
    {
        $this->link = new FailedJobsTableLink();
        $this->store = new SqlFailedJobStore($this->link);
    }

    /**
     * @param array<string, mixed> $args
     */
    protected static function failedJob(
        ?string $connection = null,
        string $queue = 'mail',
        string $class = SensitiveJob::class,
        array $args = ['userId' => 7, 'token' => 'tok-secret'],
        ?int $maxAttempts = 3,
        int $failedAt = 1_800_000_000,
    ): FailedJob {
        /** @var class-string<\Kinetis\Queue\Job> $class */
        return new FailedJob($connection, $queue, $class, $args, $maxAttempts, 3, RuntimeException::class, 'delivery failed', $failedAt);
    }

    /**
     * @return resource
     */
    protected static function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    protected static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
