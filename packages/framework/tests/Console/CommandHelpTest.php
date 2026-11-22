<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandDefinition;
use Kinetis\Console\CommandHelp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandHelpTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function argumentLists(): iterable
    {
        yield 'long flag' => [['--help'], true];
        yield 'short flag' => [['-h'], true];
        yield 'flag after other arguments' => [['first', '--force', '-h'], true];
        yield 'no arguments' => [[], false];
        yield 'help as a positional value' => [['help'], false];
        yield 'help as a key-value option' => [['--help=yes'], false];
        yield 'flag as a prefix' => [['--helpful', '-hx'], false];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('argumentLists')]
    public function test_only_an_exact_help_flag_requests_help(array $arguments, bool $requested): void
    {
        self::assertSame($requested, CommandHelp::requested($arguments));
    }

    public function test_a_commands_help_shows_its_usage_and_description(): void
    {
        self::assertSame(
            "Usage:\n  kinetis migrate:make [arguments]\n\nScaffold a migration file.\n",
            CommandHelp::forCommand($this->command('migrate:make', 'Scaffold a migration file.', takesArguments: true)),
        );
    }

    public function test_an_empty_description_leaves_no_description_block(): void
    {
        self::assertSame(
            "Usage:\n  kinetis cache:warm [arguments]\n",
            CommandHelp::forCommand($this->command('cache:warm', '', takesArguments: true)),
        );
    }

    public function test_a_command_that_takes_no_arguments_shows_none_in_its_usage(): void
    {
        self::assertSame(
            "Usage:\n  kinetis build\n\nCompiles caches.\n",
            CommandHelp::forCommand($this->command('build', 'Compiles caches.', takesArguments: false)),
        );
    }

    public function test_the_command_list_aligns_descriptions_after_the_longest_name(): void
    {
        self::assertSame(
            "Available commands:\n"
            . "  build          Compiles caches.\n"
            . "  routes:list\n"
            . "  migrate:make   Scaffold a migration file.\n",
            CommandHelp::commandList([
                $this->command('build', 'Compiles caches.'),
                $this->command('routes:list', ''),
                $this->command('migrate:make', 'Scaffold a migration file.'),
            ]),
        );
    }

    public function test_the_console_help_shows_the_usage_line_and_the_command_list(): void
    {
        self::assertSame(
            "Usage:\n  kinetis <command> [arguments]\n\nAvailable commands:\n  build   Compiles caches.\n",
            CommandHelp::forConsole([$this->command('build', 'Compiles caches.')]),
        );
    }

    private function command(string $name, string $description, bool $takesArguments = false): CommandDefinition
    {
        return new CommandDefinition($name, $description, self::class, 'run', $takesArguments);
    }
}
