<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Console;

use InvalidArgumentException;
use Kinetis\Console\CommandArguments;
use Kinetis\QueueFailedJobs\Console\ListCommand;

final class ListCommandTest extends CommandTestCase
{
    public function test_a_sensitive_argument_is_printed_redacted_and_the_rest_as_stored(): void
    {
        $id = $this->store->record(self::failedJob());
        $output = self::memoryStream();

        $exit = new ListCommand($this->store, $output)->run(CommandArguments::parse([]));

        $printed = self::contents($output);
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
        $output = self::memoryStream();

        new ListCommand($this->store, $output)->run(CommandArguments::parse([]));

        $printed = self::contents($output);
        self::assertStringContainsString('args: {"email":"[redacted]"}', $printed);
        self::assertStringNotContainsString('a@example.com', $printed);
    }

    public function test_records_print_oldest_first_and_stop_at_the_limit(): void
    {
        $this->store->record(self::failedJob(queue: 'third', failedAt: 300));
        $this->store->record(self::failedJob(queue: 'first', failedAt: 100));
        $this->store->record(self::failedJob(queue: 'second', failedAt: 200));
        $output = self::memoryStream();

        new ListCommand($this->store, $output)->run(CommandArguments::parse(['--limit=2']));

        $printed = self::contents($output);
        self::assertLessThan(strpos($printed, 'queue: second'), strpos($printed, 'queue: first'));
        self::assertStringNotContainsString('queue: third', $printed);
    }

    public function test_the_connection_filters_select_the_records_a_worker_wrote(): void
    {
        $this->store->record(self::failedJob(connection: null, queue: 'bound'));
        $this->store->record(self::failedJob(connection: 'jobs', queue: 'named'));

        $bound = self::memoryStream();
        new ListCommand($this->store, $bound)->run(CommandArguments::parse(['--bound']));
        $named = self::memoryStream();
        new ListCommand($this->store, $named)->run(CommandArguments::parse(['--connection=jobs']));

        self::assertStringContainsString('queue: bound', self::contents($bound));
        self::assertStringNotContainsString('queue: named', self::contents($bound));
        self::assertStringContainsString('connection: jobs  queue: named', self::contents($named));
        self::assertStringNotContainsString('queue: bound', self::contents($named));
    }

    public function test_an_empty_store_says_so(): void
    {
        $output = self::memoryStream();

        self::assertSame(0, new ListCommand($this->store, $output)->run(CommandArguments::parse([])));
        self::assertSame("No failed jobs.\n", self::contents($output));
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

        new ListCommand($this->store, self::memoryStream())->run(CommandArguments::parse($argv));
    }
}
