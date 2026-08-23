<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandDispatcher;
use Kinetis\Console\CommandRegistry;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Console\Fixtures\MaintenanceController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandDispatcherTest extends TestCase
{
    private function registry(): CommandRegistry
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        return $registry;
    }

    private function dispatcher(): CommandDispatcher
    {
        $app = new AppScope();
        $app->boot();

        return new CommandDispatcher($app);
    }

    public function test_a_command_with_no_parameters_returns_zero_by_default(): void
    {
        $command = $this->registry()->findCommand('app:no-args');
        self::assertNotNull($command);

        self::assertSame(0, $this->dispatcher()->run($command, []));
    }

    public function test_a_command_receives_its_parsed_arguments(): void
    {
        $command = $this->registry()->findCommand('app:with-args');
        self::assertNotNull($command);

        self::assertSame(2, $this->dispatcher()->run($command, ['one', 'two']));
    }

    public function test_a_commands_own_int_return_value_becomes_the_exit_code(): void
    {
        $command = $this->registry()->findCommand('app:explicit-failure');
        self::assertNotNull($command);

        self::assertSame(2, $this->dispatcher()->run($command, []));
    }

    public function test_a_thrown_exception_propagates_rather_than_being_swallowed(): void
    {
        $command = $this->registry()->findCommand('app:throws');
        self::assertNotNull($command);

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->run($command, []);
    }
}
