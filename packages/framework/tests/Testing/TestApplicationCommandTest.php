<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use InvalidArgumentException;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Container\AppScope;
use Kinetis\Testing\TestApplication;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Testing\Fixtures\CommandProject\Journal;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class TestApplicationCommandTest extends TestCase
{
    private const string PROJECT_ROOT = __DIR__ . '/Fixtures/CommandProject';

    private InMemoryLogger $logger;

    private TestApplication $application;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
        $this->application = TestApplication::boot(
            self::PROJECT_ROOT,
            ['APP_ENV' => 'development'],
            function (AppScope $app): void {
                $app->instance(LoggerInterface::class, $this->logger);
            },
        );
    }

    protected function tearDown(): void
    {
        $this->application->dispose();
    }

    public function test_stdout_and_stderr_are_captured_separately_without_colour(): void
    {
        $result = $this->application->command('fixture:greet', ['Ada']);

        self::assertSame(0, $result->exitCode);
        self::assertSame("Hello, Ada\n", $result->output);
        self::assertSame("greeted one person\n", $result->errorOutput);
        self::assertNull($result->exception);
    }

    public function test_a_commands_own_exit_code_and_stderr_reach_the_result(): void
    {
        $result = $this->application->command('fixture:greet');

        self::assertSame(2, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame("Usage: fixture:greet <name>\n", $result->errorOutput);
    }

    public function test_a_thrown_command_is_returned_not_rethrown(): void
    {
        $result = $this->application->command('fixture:fail');

        self::assertSame(1, $result->exitCode);
        self::assertSame("starting\n", $result->output);
        self::assertInstanceOf(RuntimeException::class, $result->exception);
        self::assertSame('fixture failure', $result->exception->getMessage());
        self::assertSame(['failed: fixture:fail'], $this->application->get(Journal::class)->entries);
        self::assertSame($result->exception, $this->logger->records[0]['context']['exception']);
    }

    /**
     * The command resolves a request-scoped binding whose dispose callback
     * closes it, the way a database transaction guard does. Only a command
     * resolved through the runner's request scope sees that binding and
     * has the callback run when the scope is disposed.
     */
    public function test_a_request_scoped_binding_is_disposed_with_the_commands_scope(): void
    {
        $result = $this->application->command('fixture:transactional');

        self::assertSame(0, $result->exitCode);
        self::assertNull($result->exception);
        self::assertSame(['began', 'rolled back'], $this->application->get(Journal::class)->entries);
    }

    public function test_the_application_stays_booted_across_commands(): void
    {
        $this->application->command('fixture:transactional');
        $this->application->command('fixture:transactional');

        self::assertFalse($this->application->app->isDisposed());
        self::assertSame(
            ['began', 'rolled back', 'began', 'rolled back'],
            $this->application->get(Journal::class)->entries,
        );
    }

    /**
     * A bootstrap: false command runs against the already-booted
     * application, with an Output bound like any other command.
     */
    public function test_a_framework_command_without_bootstrap_runs_through_the_helper(): void
    {
        $result = $this->application->command('routes:list');

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        self::assertStringStartsWith("Global middleware (outermost to innermost):\n", $result->output);
        self::assertSame('', $result->errorOutput);
    }

    public function test_an_unknown_command_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No discovered command is named "fixture:missing".');

        $this->application->command('fixture:missing');
    }

    /**
     * Discovery is live even in production: a command added after the
     * project's compiled cache was written is still found.
     */
    public function test_commands_are_discovered_live_not_from_the_compiled_cache(): void
    {
        $root = sys_get_temp_dir() . '/kinetis_test_application_command_' . bin2hex(random_bytes(8));
        mkdir($root . '/src', recursive: true);
        file_put_contents($root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]));

        $store = new CacheStore($root . '/.kinetis-cache');
        $store->write(new Compiler()->compileProject(new DiscoveryContext($root)));

        $namespace = 'App\\StaleCache' . bin2hex(random_bytes(4));
        $directory = $root . '/src/' . substr($namespace, 4);
        mkdir($directory);
        file_put_contents($directory . '/LateCommand.php', <<<PHP
            <?php
            declare(strict_types=1);
            namespace {$namespace};
            use Kinetis\\Console\\Attributes\\Command;
            final class LateCommand
            {
                #[Command('app:late')]
                public function run(): int
                {
                    return 3;
                }
            }
            PHP);
        // Discovery registers only classes that load; nothing autoloads
        // this temporary project.
        require $directory . '/LateCommand.php';

        $application = TestApplication::boot($root, ['APP_ENV' => 'production']);

        try {
            $cached = $store->load();
            self::assertNotNull($cached);
            self::assertSame([], array_filter(
                $cached->commands->commands,
                static fn (array $command): bool => $command['name'] === 'app:late',
            ));
            self::assertSame(3, $application->command('app:late')->exitCode);
        } finally {
            $application->dispose();
            unlink($directory . '/LateCommand.php');
            rmdir($directory);
            unlink($store->path());
            rmdir($root . '/.kinetis-cache');
            rmdir($root . '/src');
            unlink($root . '/composer.json');
            rmdir($root);
        }
    }
}
