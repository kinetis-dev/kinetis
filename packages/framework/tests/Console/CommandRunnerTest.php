<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandRegistry;
use Kinetis\Console\CommandRunner;
use Kinetis\Console\Output;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Tests\Console\Fixtures\MaintenanceController;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Testing\Fixtures\CommandProject\CommandFailedRecorder;
use Kinetis\Tests\Testing\Fixtures\CommandProject\GreetCommand;
use Kinetis\Tests\Testing\Fixtures\CommandProject\Journal;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class CommandRunnerTest extends TestCase
{
    private InMemoryLogger $logger;

    private Journal $journal;

    private ?RequestScope $scope = null;

    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
        $this->journal = new Journal();
    }

    /**
     * @param (callable(): void)|null $onDispose registered on the command's request scope
     */
    private function app(?callable $onDispose = null): AppScope
    {
        $listeners = new EventListenerRegistry();
        $listeners->register(CommandFailedRecorder::class);

        $app = new AppScope();
        $app->instance(LoggerInterface::class, $this->logger);
        $app->instance(EventListenerRegistry::class, $listeners);
        $app->instance(Journal::class, $this->journal);
        $app->onRequestScopeCreated(function (RequestScope $scope) use ($onDispose): void {
            $this->scope = $scope;

            if ($onDispose !== null) {
                $scope->onDispose($onDispose);
            }
        });
        $app->boot();

        return $app;
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(string $name, array $arguments = [], ?AppScope $app = null, ?Throwable &$failure = null): int
    {
        $registry = new CommandRegistry();
        $registry->register(MaintenanceController::class);
        $registry->register(GreetCommand::class);
        $command = $registry->findCommand($name);
        self::assertNotNull($command);

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        return new CommandRunner($app ?? $this->app())->run($command, $arguments, new Output($stdout, $stderr, false, false), $failure);
    }

    /**
     * @param resource $stream
     */
    private static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_a_command_with_no_parameters_returns_zero_by_default(): void
    {
        self::assertSame(0, $this->runCommand('app:no-args', failure: $failure));
        self::assertNull($failure);
        self::assertTrue($this->scope?->isDisposed());
    }

    public function test_a_command_receives_its_parsed_arguments(): void
    {
        self::assertSame(2, $this->runCommand('app:with-args', ['one', 'two']));
    }

    public function test_a_commands_own_int_return_value_becomes_the_exit_code(): void
    {
        self::assertSame(2, $this->runCommand('app:explicit-failure'));
    }

    /**
     * The Output is bound on the command's own request scope, so the
     * command's constructor receives exactly the streams the caller gave.
     */
    public function test_the_command_writes_through_the_output_bound_on_its_scope(): void
    {
        self::assertSame(0, $this->runCommand('fixture:greet', ['Ada']));
        self::assertSame("Hello, Ada\n", self::contents($this->stdout));
        self::assertSame("greeted one person\n", self::contents($this->stderr));
    }

    /**
     * Output is never bound on the application scope: a command resolved
     * there could not be built at all.
     */
    public function test_the_output_does_not_outlive_the_command_scope(): void
    {
        $app = $this->app();
        $this->runCommand('fixture:greet', ['Ada'], $app);

        self::assertFalse($app->has(Output::class));
    }

    public function test_a_thrown_command_exits_1_and_is_logged_reported_disposed_and_exposed(): void
    {
        self::assertSame(1, $this->runCommand('app:throws', failure: $failure));

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('deliberately broken', $failure->getMessage());
        self::assertSame(['failed: app:throws'], $this->journal->entries);
        self::assertTrue($this->scope?->isDisposed());
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame($failure, $this->logger->records[0]['context']['exception']);
    }

    public function test_a_scope_disposal_failure_after_success_exits_70(): void
    {
        $app = $this->app(static function (): void {
            throw new RuntimeException('dispose failed');
        });

        self::assertSame(70, $this->runCommand('app:no-args', app: $app, failure: $failure));
        self::assertNull($failure);
        self::assertTrue($this->scope?->isDisposed());
        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith('Request scope disposal failed', $this->logger->records[0]['message']);
    }

    public function test_a_scope_disposal_failure_keeps_a_deliberate_non_zero_exit_code(): void
    {
        $app = $this->app(static function (): void {
            throw new RuntimeException('dispose failed');
        });

        self::assertSame(2, $this->runCommand('app:explicit-failure', app: $app));
    }

    public function test_a_scope_disposal_failure_keeps_a_thrown_commands_exit_1(): void
    {
        $app = $this->app(static function (): void {
            throw new RuntimeException('dispose failed');
        });

        self::assertSame(1, $this->runCommand('app:throws', app: $app, failure: $failure));
        self::assertSame('deliberately broken', $failure?->getMessage());
        self::assertCount(2, $this->logger->records);
    }
}
