<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Console\WorkCommand;
use Kinetis\Queue\FailedJobRecorderInterface;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\PackageBootstrap;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * With the package installed, queue:work resolves the recorder before it
 * prints "started", so a store it cannot build stops the worker first.
 * Resolving a store against a real server is covered in tests/Integration.
 */
final class PackageBootstrapTest extends TestCase
{
    public function test_both_ids_are_bound_explicitly(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertTrue($app->has(SqlFailedJobStore::class));
        self::assertTrue($app->has(FailedJobRecorderInterface::class));
    }

    /**
     * @return iterable<string, array{array<string, string>, class-string<Throwable>, string}>
     */
    public static function unbuildableStores(): iterable
    {
        yield 'connection name outside the grammar' => [
            ['FAILED_JOBS_DB_CONNECTION_NAME' => 'Reports', 'DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret'],
            InvalidArgumentException::class,
            'Invalid connection name "Reports" from FAILED_JOBS_DB_CONNECTION_NAME',
        ];
        yield 'default connection not configured' => [
            [],
            MissingConfigException::class,
            'DB_CONNECTION',
        ];
        yield 'named connection not configured' => [
            ['FAILED_JOBS_DB_CONNECTION_NAME' => 'reports', 'DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret'],
            MissingConfigException::class,
            'DB_REPORTS_CONNECTION',
        ];
        yield 'invalid dialect' => [
            ['DB_CONNECTION' => 'sqlite', 'DB_PASSWORD' => 'secret'],
            InvalidArgumentException::class,
            'DB_CONNECTION must be "mysql" or "pgsql"',
        ];
    }

    /**
     * @param array<string, string> $values
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('unbuildableStores')]
    public function test_queue_work_fails_before_started_when_the_store_cannot_be_built(
        array $values,
        string $exception,
        string $message,
    ): void {
        $config = new Config($values);
        $app = new AppScope();
        new PackageBootstrap()->register($app, $config);
        $app->bind(QueueInterface::class, static function (): QueueInterface {
            throw new RuntimeException('The queue must not be resolved before the recorder.');
        });
        $app->boot();

        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);
        $errorOutput = fopen('php://memory', 'r+');
        self::assertIsResource($errorOutput);

        try {
            new WorkCommand($app->createRequestScope(), $config, $output, $errorOutput)->run(CommandArguments::parse([]));
            self::fail("Expected {$exception}.");
        } catch (Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }

        rewind($output);
        self::assertSame('', stream_get_contents($output));
    }
}
