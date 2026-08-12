<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\BuildCommand;
use Kinetis\Console\CommandArguments;
use PHPUnit\Framework\TestCase;

final class BuildCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/kinetis_build_command_test_' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function test_writes_all_four_cache_files_and_returns_success(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);

        $exitCode = $command->run(CommandArguments::parse([]));

        self::assertSame(0, $exitCode);
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/http.php');
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/mcp.php');
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/openapi.php');
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/commands.php');
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/events.php');
    }

    public function test_a_plain_rebuild_removes_a_stale_file_an_old_version_left_behind(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));

        $stalePath = $this->projectRoot . '/.kinetis-cache/stale-from-an-old-version.php';
        file_put_contents($stalePath, "<?php\n");
        self::assertFileExists($stalePath);

        $command->run(CommandArguments::parse([]));

        self::assertFileDoesNotExist($stalePath);
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/commands.php');
    }

    public function test_destroy_removes_the_cache_directory_without_rebuilding_it(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/commands.php');

        $exitCode = $command->run(CommandArguments::parse(['--destroy']));

        self::assertSame(0, $exitCode);
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.kinetis-cache');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Not glob('*') — the cache lives under the dot-directory
        // .kinetis-cache, which a bare "*" glob pattern never matches.
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $entry = $directory . '/' . $name;

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
            } else {
                unlink($entry);
            }
        }

        rmdir($directory);
    }
}
