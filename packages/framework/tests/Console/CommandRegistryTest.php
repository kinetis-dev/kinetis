<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandRegistry;
use Kinetis\Console\Exception\InvalidCommandException;
use Kinetis\Console\BuildCommand;
use Kinetis\Tests\Console\Fixtures\CacheWarmupController;
use Kinetis\Tests\Console\Fixtures\DuplicateCommandNameA;
use Kinetis\Tests\Console\Fixtures\DuplicateCommandNameB;
use Kinetis\Tests\Console\Fixtures\InvalidCommandTooManyParams;
use Kinetis\Tests\Console\Fixtures\InvalidCommandWrongParamType;
use Kinetis\Tests\Console\Fixtures\MaintenanceController;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function test_registers_every_attributed_method(): void
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        $names = array_map(static fn ($command) => $command->name, $registry->commands());
        self::assertSame(
            ['app:no-args', 'app:with-args', 'app:explicit-failure', 'app:throws'],
            $names,
        );
    }

    public function test_find_command_returns_the_matching_definition(): void
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        $command = $registry->findCommand('app:with-args');

        self::assertNotNull($command);
        self::assertSame(MaintenanceController::class, $command->controllerClass);
        self::assertSame('withArgs', $command->controllerMethod);
        self::assertTrue($command->takesArguments);
    }

    public function test_a_command_with_no_parameters_does_not_take_arguments(): void
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        $command = $registry->findCommand('app:no-args');

        self::assertNotNull($command);
        self::assertFalse($command->takesArguments);
    }

    public function test_find_command_returns_null_for_an_unknown_name(): void
    {
        $registry = new CommandRegistry();

        self::assertNull($registry->findCommand('does-not-exist'));
    }

    public function test_a_command_method_with_too_many_parameters_throws(): void
    {
        $registry = new CommandRegistry();

        $this->expectException(InvalidCommandException::class);
        $registry->register(InvalidCommandTooManyParams::class);
    }

    public function test_a_command_method_with_the_wrong_parameter_type_throws(): void
    {
        $registry = new CommandRegistry();

        $this->expectException(InvalidCommandException::class);
        $registry->register(InvalidCommandWrongParamType::class);
    }

    public function test_registering_a_duplicate_command_name_throws(): void
    {
        $registry = new CommandRegistry();
        $registry->register(DuplicateCommandNameA::class);

        $this->expectException(InvalidCommandException::class);
        $this->expectExceptionMessage('app:duplicate');
        $registry->register(DuplicateCommandNameB::class);
    }

    public function test_to_array_from_array_round_trip_preserves_every_command(): void
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        $reloaded = CommandRegistry::fromArray($registry->toArray());

        self::assertEquals($registry->commands(), $reloaded->commands());
    }

    public function test_commands_boot_the_application_by_default(): void
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);

        self::assertTrue($registry->findCommand('app:no-args')?->bootstrap);
    }

    public function test_a_command_can_opt_out_of_application_bootstrap(): void
    {
        $registry = new CommandRegistry();
        $registry->register(CacheWarmupController::class);

        self::assertFalse($registry->findCommand('app:warmup')?->bootstrap);
    }

    public function test_bootstrap_opt_out_survives_the_cache_round_trip(): void
    {
        $registry = new CommandRegistry();
        $registry->register(CacheWarmupController::class);
        $registry->register(MaintenanceController::class);

        $reloaded = CommandRegistry::fromArray($registry->toArray());

        self::assertFalse($reloaded->findCommand('app:warmup')?->bootstrap);
        self::assertTrue($reloaded->findCommand('app:no-args')?->bootstrap);
    }

    public function test_build_runs_without_application_bootstrap(): void
    {
        $registry = new CommandRegistry();
        $registry->register(BuildCommand::class);

        self::assertFalse($registry->findCommand('build')?->bootstrap);
    }
}
