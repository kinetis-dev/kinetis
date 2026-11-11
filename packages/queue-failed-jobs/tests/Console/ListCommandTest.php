<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Console;

use InvalidArgumentException;
use Kinetis\Console\CommandArguments;
use Kinetis\QueueFailedJobs\Console\ListCommand;
use Kinetis\QueueFailedJobs\Tests\Fixtures\CommandOutput;

final class ListCommandTest extends CommandTestCase
{
    public function test_a_sensitive_argument_is_printed_redacted_and_the_rest_as_stored(): void
    {
        $id = $this->store->record(self::failedJob());
        $output = new CommandOutput();

        $exit = new ListCommand($this->store, $output->output)->run(CommandArguments::parse([]));

        $printed = $output->takeStdout();
        self::assertSame(0, $exit);
        self::assertStringContainsString($id, $printed);
        self::assertStringContainsString('args: {"userId":7,"token":"[redacted]"}', $printed);
        self::assertStringNotContainsString('tok-secret', $printed);
        self::assertStringContainsString('connection: (bound)  queue: mail', $printed);
        self::assertStringContainsString('attempts: 3/3', $printed);
        self::assertStringContainsString('RuntimeException: delivery failed', $printed);
    }

    public function test_a_class_that_no_longer_autoloads_prints_every_argument_redacted(): void
    {
        $this->store->record(self::failedJob(class: 'App\Jobs\Removed', args: ['email' => 'a@example.com']));
        $output = new CommandOutput();

        new ListCommand($this->store, $output->output)->run(CommandArguments::parse([]));

        $printed = $output->takeStdout();
        self::assertStringContainsString('args: {"email":"[redacted]"}', $printed);
        self::assertStringNotContainsString('a@example.com', $printed);
    }

    public function test_records_print_oldest_first_and_stop_at_the_limit(): void
    {
        $this->store->record(self::failedJob(queue: 'third', failedAt: 300));
        $this->store->record(self::failedJob(queue: 'first', failedAt: 100));
        $this->store->record(self::failedJob(queue: 'second', failedAt: 200));
        $output = new CommandOutput();

        new ListCommand($this->store, $output->output)->run(CommandArguments::parse(['--limit=2']));

        $printed = $output->takeStdout();
        self::assertLessThan(strpos($printed, 'queue: second'), strpos($printed, 'queue: first'));
        self::assertStringNotContainsString('queue: third', $printed);
    }

    public function test_the_connection_filters_select_the_records_a_worker_wrote(): void
    {
        $this->store->record(self::failedJob(connection: null, queue: 'bound'));
        $this->store->record(self::failedJob(connection: 'jobs', queue: 'named'));

        $bound = new CommandOutput();
        new ListCommand($this->store, $bound->output)->run(CommandArguments::parse(['--bound']));
        $named = new CommandOutput();
        new ListCommand($this->store, $named->output)->run(CommandArguments::parse(['--connection=jobs']));

        $boundText = $bound->takeStdout();
        $namedText = $named->takeStdout();
        self::assertStringContainsString('queue: bound', $boundText);
        self::assertStringNotContainsString('queue: named', $boundText);
        self::assertStringContainsString('connection: jobs  queue: named', $namedText);
        self::assertStringNotContainsString('queue: bound', $namedText);
    }

    public function test_an_empty_store_says_so(): void
    {
        $output = new CommandOutput();

        self::assertSame(0, new ListCommand($this->store, $output->output)->run(CommandArguments::parse([])));
        self::assertSame("No failed jobs.\n", $output->takeStdout());
        self::assertSame('', $output->takeStderr());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'both connection selectors' => [['--connection=jobs', '--bound']];
        yield 'connection without a value' => [['--connection']];
        yield 'connection outside the grammar' => [['--connection=Jobs']];
        yield 'invalid queue name' => [['--queue=not a queue']];
        yield 'zero limit' => [['--limit=0']];
        yield 'non-numeric limit' => [['--limit=ten']];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected_before_the_store_is_read(array $argv): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ListCommand($this->store, new CommandOutput()->output)->run(CommandArguments::parse($argv));
    }
}
