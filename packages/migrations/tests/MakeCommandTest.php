<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Migrations\Console\MakeCommand;
use Kinetis\Migrations\Tests\Fixtures\CommandOutput;
use Kinetis\Runtime\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * migrate:make needs no database, so what it prints is proven here in a
 * real project directory — the ProjectRoot the entry point binds, which
 * is how the command finds the project it runs in.
 */
final class MakeCommandTest extends TestCase
{
    private string $projectRoot;

    private CommandOutput $capture;

    private RequestScope $scope;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/kinetis-bridge-make-' . \bin2hex(\random_bytes(8));
        \mkdir($this->projectRoot);

        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(ProjectRoot::class, new ProjectRoot($this->projectRoot));
        $app->boot();
        $this->scope = $app->createRequestScope();
        $this->capture = new CommandOutput();
        $this->scope->instance(Output::class, $this->capture->output);
    }

    private function make(CommandArguments $arguments): int
    {
        return $this->scope->get(MakeCommand::class)->run($arguments);
    }

    protected function tearDown(): void
    {
        foreach ([...\glob($this->projectRoot . '/migrations/*/*.php') ?: [], ...\glob($this->projectRoot . '/migrations/*.php') ?: []] as $file) {
            \unlink($file);
        }

        foreach ([...\glob($this->projectRoot . '/migrations/*', \GLOB_ONLYDIR) ?: [], $this->projectRoot . '/migrations'] as $directory) {
            if (\is_dir($directory)) {
                \rmdir($directory);
            }
        }

        \rmdir($this->projectRoot);
    }

    public function test_it_creates_a_migration_in_the_project_and_prints_its_path(): void
    {
        $exitCode = $this->make(new CommandArguments(['create widgets'], []));
        $output = $this->capture->takeStdout();

        $files = \glob($this->projectRoot . '/migrations/*.php') ?: [];
        self::assertSame(0, $exitCode);
        self::assertCount(1, $files);
        self::assertSame("Created {$files[0]}\n", $output);
        self::assertSame('', $this->capture->takeStderr());
    }

    public function test_a_selected_connection_gets_the_migration_in_its_own_directory(): void
    {
        $exitCode = $this->make(new CommandArguments(['create reports'], ['connection' => 'reporting']));
        $output = $this->capture->takeStdout();

        $files = \glob($this->projectRoot . '/migrations/reporting/*_create_reports.php') ?: [];
        self::assertSame(0, $exitCode);
        self::assertCount(1, $files);
        self::assertSame("Created {$files[0]}\n", $output);
        self::assertSame([], \glob($this->projectRoot . '/migrations/*.php') ?: []);
    }

    public function test_an_invalid_connection_creates_nothing(): void
    {
        try {
            $this->make(new CommandArguments(['create reports'], ['connection' => 'Reporting']));
            self::fail('An invalid connection name must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('Invalid connection name "Reporting" from --connection', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->projectRoot . '/migrations');
    }

    public function test_it_prints_its_usage_without_a_description(): void
    {
        $exitCode = $this->make(new CommandArguments([], []));

        self::assertSame(1, $exitCode);
        self::assertSame('', $this->capture->takeStdout());
        self::assertSame("Usage: kinetis migrate:make <description>\n", $this->capture->takeStderr());
        self::assertSame([], \glob($this->projectRoot . '/migrations/*.php') ?: []);
    }
}
