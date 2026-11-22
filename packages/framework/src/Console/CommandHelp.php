<?php

declare(strict_types=1);

namespace Kinetis\Console;

/**
 * The console's help text, built only from discovered command
 * definitions so it can be printed before any configuration, bootstrap,
 * or command runs. bin/kinetis and TestApplication::command() both
 * consult requested() before dispatching.
 */
final class CommandHelp
{
    private function __construct() {}

    /**
     * Whether a command's own arguments ask for its help instead of
     * running it.
     *
     * @param list<string> $arguments what follows the command name
     */
    public static function requested(array $arguments): bool
    {
        return in_array('--help', $arguments, true) || in_array('-h', $arguments, true);
    }

    public static function forCommand(CommandDefinition $command): string
    {
        $help = "Usage:\n  kinetis {$command->name}" . ($command->takesArguments ? ' [arguments]' : '') . "\n";

        return $command->description === '' ? $help : $help . "\n{$command->description}\n";
    }

    /**
     * @param list<CommandDefinition> $commands
     */
    public static function forConsole(array $commands): string
    {
        return "Usage:\n  kinetis <command> [arguments]\n\n" . self::commandList($commands);
    }

    /**
     * @param list<CommandDefinition> $commands
     */
    public static function commandList(array $commands): string
    {
        // Width of the name column, so the descriptions line up whatever
        // commands the installed packages and the application contribute.
        $width = 0;

        foreach ($commands as $command) {
            $width = max($width, strlen($command->name));
        }

        $list = "Available commands:\n";

        foreach ($commands as $command) {
            $list .= '  ' . ($command->description === ''
                ? $command->name
                : str_pad($command->name, $width) . '   ' . $command->description) . "\n";
        }

        return $list;
    }
}
