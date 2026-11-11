<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use InvalidArgumentException;
use Kinetis\Container\AppScope;
use Kinetis\Testing\TestApplication;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The commands run under TestApplication::command() read the
 * configuration the test booted with, not the process environment, so a
 * test pointing the application at its own database migrates that one.
 *
 * This package's own root is the project: its PSR-4 root holds the
 * commands, and it has no migrations/ directory, so the selection is
 * decided before any database is reached.
 */
final class TestApplicationCommandTest extends TestCase
{
    public function test_a_config_override_reaches_the_command(): void
    {
        $application = TestApplication::boot(
            \dirname(__DIR__),
            ['MIGRATE_CONNECTION_NAME' => 'Overridden'],
            static function (AppScope $app): void {
                $app->instance(LoggerInterface::class, new NullLogger());
            },
        );

        try {
            $result = $application->command('migrate:status');
        } finally {
            $application->dispose();
        }

        self::assertSame(1, $result->exitCode);
        self::assertInstanceOf(InvalidArgumentException::class, $result->exception);
        self::assertStringStartsWith(
            'Invalid connection name "Overridden" from MIGRATE_CONNECTION_NAME',
            $result->exception->getMessage(),
        );
        self::assertSame('', $result->output);
    }
}
