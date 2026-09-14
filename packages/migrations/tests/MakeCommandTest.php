<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Console\CommandArguments;
use Kinetis\Migrations\Console\MakeCommand;
use Kinetis\Migrations\Tests\Fixtures\OutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * migrate:make needs no database, so what it prints is proven here in a
 * real project directory — the one the Composer bin proxy names, which is
 * how bin/kinetis finds the project it runs in.
 */
final class MakeCommandTest extends TestCase
{
    private string $projectRoot;

    private mixed $composerBinDir;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/kinetis-bridge-make-' . \bin2hex(\random_bytes(8));
        \mkdir($this->projectRoot . '/vendor/bin', recursive: true);
        $this->composerBinDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $this->projectRoot . '/vendor/bin';
    }

    protected function tearDown(): void
    {
        $GLOBALS['_composer_bin_dir'] = $this->composerBinDir;

        foreach (\glob($this->projectRoot . '/migrations/*.php') ?: [] as $file) {
            \unlink($file);
        }

        if (\is_dir($this->projectRoot . '/migrations')) {
            \rmdir($this->projectRoot . '/migrations');
        }

        \rmdir($this->projectRoot . '/vendor/bin');
        \rmdir($this->projectRoot . '/vendor');
        \rmdir($this->projectRoot);
    }

    public function test_it_creates_a_migration_in_the_project_and_prints_its_path(): void
    {
        [$exitCode, $output] = OutputCapture::of(
            \STDOUT,
            static fn (): int => new MakeCommand()->run(new CommandArguments(['create widgets'], [])),
        );

        $files = \glob($this->projectRoot . '/migrations/*.php') ?: [];
        self::assertSame(0, $exitCode);
        self::assertCount(1, $files);
        self::assertSame("Created {$files[0]}\n", $output);
    }

    public function test_it_prints_its_usage_without_a_description(): void
    {
        [$exitCode, $output] = OutputCapture::of(
            \STDERR,
            static fn (): int => new MakeCommand()->run(new CommandArguments([], [])),
        );

        self::assertSame(1, $exitCode);
        self::assertSame("Usage: kinetis migrate:make <description>\n", $output);
        self::assertSame([], \glob($this->projectRoot . '/migrations/*.php') ?: []);
    }
}
