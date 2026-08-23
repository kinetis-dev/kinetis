<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandArguments;
use PHPUnit\Framework\TestCase;

final class CommandArgumentsTest extends TestCase
{
    public function test_positional_arguments_are_available_by_index(): void
    {
        $arguments = CommandArguments::parse(['first', 'second']);

        self::assertSame('first', $arguments->get(0));
        self::assertSame('second', $arguments->get(1));
        self::assertNull($arguments->get(2));
        self::assertSame(['first', 'second'], $arguments->all());
    }

    public function test_a_key_value_option_is_read_by_name(): void
    {
        $arguments = CommandArguments::parse(['--queue=high,default']);

        self::assertSame('high,default', $arguments->option('queue'));
        self::assertTrue($arguments->hasOption('queue'));
    }

    public function test_a_bare_flag_has_no_string_value_but_is_present(): void
    {
        $arguments = CommandArguments::parse(['--dry-run']);

        self::assertTrue($arguments->hasOption('dry-run'));
        self::assertNull($arguments->option('dry-run'));
    }

    public function test_a_missing_option_falls_back_to_the_given_default(): void
    {
        $arguments = CommandArguments::parse([]);

        self::assertFalse($arguments->hasOption('queue'));
        self::assertSame('default', $arguments->option('queue', 'default'));
    }

    public function test_positional_arguments_and_options_can_be_mixed(): void
    {
        $arguments = CommandArguments::parse(['send', '--to=ops@example.com', '--dry-run']);

        self::assertSame(['send'], $arguments->all());
        self::assertSame('ops@example.com', $arguments->option('to'));
        self::assertTrue($arguments->hasOption('dry-run'));
    }
}
