<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/kinetis against a throwaway project, built the way
 * BinKinetisDisposalTest builds one: a composer.json with an App\ PSR-4
 * root, a bootstrap.php, one fixture command, and the Composer bin-proxy
 * glue that makes ProjectRoot::detect() resolve the temp directory.
 *
 * The bootstrap and the command each write their own marker file, so a
 * test can tell whether either one ran.
 */
final class BinKinetisHelpTest extends TestCase
{
    private const string COMMAND_USAGE = "Usage:\n  kinetis fixture:write-marker [arguments]\n\nWrites a marker file.\n";

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/kinetis-bin-help-' . bin2hex(random_bytes(8));
        mkdir($this->projectDir . '/src', recursive: true);
        mkdir($this->projectDir . '/vendor/bin', recursive: true);

        file_put_contents(
            $this->projectDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_THROW_ON_ERROR),
        );

        file_put_contents($this->projectDir . '/bootstrap.php', <<<PHP
            <?php

            declare(strict_types=1);

            return static function (): void {
                file_put_contents({$this->phpString($this->bootstrapMarker())}, 'ran');
            };
            PHP);

        file_put_contents($this->projectDir . '/src/WriteMarkerCommand.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Console\CommandArguments;

            final readonly class WriteMarkerCommand
            {
                #[Command('fixture:write-marker', description: 'Writes a marker file.')]
                public function run(CommandArguments \$arguments): int
                {
                    file_put_contents({$this->phpString($this->commandMarker())}, json_encode(\$arguments->all()));

                    return 0;
                }
            }
            PHP);

        $frameworkDir = dirname(__DIR__, 2);

        file_put_contents(
            $this->projectDir . '/vendor/autoload.php',
            <<<PHP
                <?php
                require {$this->phpString($frameworkDir . '/vendor/autoload.php')};
                spl_autoload_register(static function (string \$class): void {
                    if (!str_starts_with(\$class, 'App\\\\')) {
                        return;
                    }
                    \$path = {$this->phpString($this->projectDir . '/src/')} . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                    if (is_file(\$path)) {
                        require \$path;
                    }
                });
                PHP,
        );

        file_put_contents(
            $this->projectDir . '/vendor/bin/kinetis',
            <<<PHP
                <?php
                \$GLOBALS['_composer_autoload_path'] = {$this->phpString($this->projectDir . '/vendor/autoload.php')};
                \$GLOBALS['_composer_bin_dir'] = {$this->phpString($this->projectDir . '/vendor/bin')};
                require {$this->phpString($frameworkDir . '/bin/kinetis')};
                PHP,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function commandHelpRequests(): iterable
    {
        yield 'long flag' => [['--help']];
        yield 'short flag' => [['-h']];
        yield 'flag after other arguments' => [['first', '--force', '-h']];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('commandHelpRequests')]
    public function test_a_help_flag_prints_the_commands_usage_without_bootstrapping_or_running_it(array $arguments): void
    {
        $result = $this->runBinKinetis(['fixture:write-marker', ...$arguments]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame(self::COMMAND_USAGE, $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertFileDoesNotExist($this->commandMarker(), 'the command did not run');
        self::assertFileDoesNotExist($this->bootstrapMarker(), 'bootstrap.php did not run');
    }

    public function test_without_a_help_flag_the_command_bootstraps_and_runs_with_its_arguments(): void
    {
        $result = $this->runBinKinetis(['fixture:write-marker', 'first', '--force', '--help=no']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertFileExists($this->bootstrapMarker());
        self::assertSame('["first"]', file_get_contents($this->commandMarker()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function consoleHelpRequests(): iterable
    {
        yield 'long flag' => ['--help'];
        yield 'short flag' => ['-h'];
        yield 'help' => ['help'];
    }

    #[DataProvider('consoleHelpRequests')]
    public function test_console_help_lists_the_commands_on_stdout(string $request): void
    {
        $result = $this->runBinKinetis([$request]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringStartsWith("Usage:\n  kinetis <command> [arguments]\n\nAvailable commands:\n", $result['stdout']);
        self::assertMatchesRegularExpression('/^  fixture:write-marker +Writes a marker file\.$/m', $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertFileDoesNotExist($this->bootstrapMarker());
    }

    public function test_no_arguments_still_prints_usage_on_stderr_and_exits_1(): void
    {
        $result = $this->runBinKinetis([]);

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith("Usage: kinetis <command>\n\nAvailable commands:\n", $result['stderr']);
        self::assertStringContainsString('fixture:write-marker', $result['stderr']);
    }

    public function test_an_unknown_command_still_reports_on_stderr_and_exits_1_even_with_a_help_flag(): void
    {
        $result = $this->runBinKinetis(['fixture:missing', '--help']);

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith("Unknown command \"fixture:missing\".\n\nAvailable commands:\n", $result['stderr']);
    }

    /**
     * An application command named `help` keeps its name: the console
     * help answers `help` only while nothing else claims it.
     */
    public function test_a_discovered_help_command_runs_instead_of_the_console_help(): void
    {
        file_put_contents($this->projectDir . '/src/HelpCommand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;

            final readonly class HelpCommand
            {
                #[Command('help')]
                public function run(): int
                {
                    fwrite(STDOUT, "APPLICATION_HELP\n");

                    return 0;
                }
            }
            PHP);

        $result = $this->runBinKinetis(['help']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("APPLICATION_HELP\n", $result['stdout']);
    }

    private function commandMarker(): string
    {
        return $this->projectDir . '/command-ran';
    }

    private function bootstrapMarker(): string
    {
        return $this->projectDir . '/bootstrap-ran';
    }

    private function phpString(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * @param list<string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runBinKinetis(array $arguments): array
    {
        $process = proc_open(
            ['php', $this->projectDir . '/vendor/bin/kinetis', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectDir,
            ['PATH' => getenv('PATH') ?: ''],
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
