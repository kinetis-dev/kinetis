<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\Console\RetryCommand;
use Kinetis\QueueFailedJobs\Tests\Fixtures\RecordingQueue;
use Kinetis\QueueFailedJobs\Tests\Fixtures\SensitiveJob;

/**
 * Named connections build a real backend through QueueFactory, so a
 * successful push onto one is covered in tests/Integration.
 */
final class RetryCommandTest extends CommandTestCase
{
    private RecordingQueue $boundQueue;

    private AppScope $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundQueue = new RecordingQueue();
        $this->app = new AppScope();
        $this->app->instance(QueueInterface::class, $this->boundQueue);
        $this->app->boot();
    }

    public function test_a_bound_queue_record_is_pushed_onto_the_bound_queue_then_forgotten(): void
    {
        $id = $this->store->record(self::failedJob(connection: null, queue: 'mail', maxAttempts: 5));
        $output = self::memoryStream();

        $exit = $this->command($output)->run(CommandArguments::parse([$id]));

        self::assertSame(0, $exit);
        self::assertCount(1, $this->boundQueue->pushed);
        $pushed = $this->boundQueue->pushed[0];
        self::assertEquals(new SensitiveJob(7, 'tok-secret'), $pushed['job']);
        self::assertSame('mail', $pushed['queue']);
        self::assertSame(5, $pushed['maxAttempts']);
        self::assertNull($this->store->find($id));
        self::assertStringNotContainsString('tok-secret', self::contents($output));
    }

    public function test_a_record_deferring_to_the_worker_default_is_pushed_without_a_cap(): void
    {
        $id = $this->store->record(self::failedJob(maxAttempts: null));

        $this->command(self::memoryStream())->run(CommandArguments::parse([$id]));

        self::assertNull($this->boundQueue->pushed[0]['maxAttempts']);
    }

    public function test_a_class_that_no_longer_autoloads_keeps_its_record_and_fails_the_command(): void
    {
        $id = $this->store->record(self::failedJob(class: 'App\Jobs\Removed', args: ['email' => 'a@example.com']));
        $output = self::memoryStream();

        $exit = $this->command($output)->run(CommandArguments::parse([$id]));

        self::assertSame(1, $exit);
        self::assertSame([], $this->boundQueue->pushed);
        self::assertNotNull($this->store->find($id));
        self::assertStringContainsString("Could not retry \"{$id}\" (App\\Jobs\\Removed)", self::contents($output));
        self::assertStringNotContainsString('a@example.com', self::contents($output));
    }

    public function test_a_connection_no_longer_configured_keeps_its_record_and_fails_the_command(): void
    {
        $id = $this->store->record(self::failedJob(connection: 'jobs'));
        $output = self::memoryStream();

        $exit = $this->command($output)->run(CommandArguments::parse([$id]));

        self::assertSame(1, $exit);
        self::assertNotNull($this->store->find($id));
        self::assertStringContainsString('QUEUE_JOBS_CONNECTION', self::contents($output));
    }

    public function test_an_unknown_id_fails_the_command_and_the_others_still_retry(): void
    {
        $id = $this->store->record(self::failedJob());
        $output = self::memoryStream();

        $exit = $this->command($output)->run(CommandArguments::parse(['0123456789abcdef0123456789abcdef', $id]));

        self::assertSame(1, $exit);
        self::assertCount(1, $this->boundQueue->pushed);
        self::assertStringContainsString('Retried 1 failed job(s); 1 could not be retried.', self::contents($output));
    }

    public function test_all_retries_every_selected_record_across_pages_and_keeps_the_failures(): void
    {
        // More than one page, every record failing in the same second, so
        // the walk depends on the id half of the cursor.
        for ($i = 0; $i < 150; $i++) {
            $this->store->record(self::failedJob(failedAt: 1_000));
        }

        $kept = $this->store->record(self::failedJob(class: 'App\Jobs\Removed', failedAt: 1_000));
        $other = $this->store->record(self::failedJob(connection: 'jobs', failedAt: 1_000));
        $output = self::memoryStream();

        $exit = $this->command($output)->run(CommandArguments::parse(['--all', '--bound']));

        self::assertSame(1, $exit);
        self::assertCount(150, $this->boundQueue->pushed);
        self::assertEqualsCanonicalizing([$kept, $other], array_keys($this->link->rows), 'the failed retry and the unselected record remain');
        self::assertStringContainsString('Retried 150 failed job(s); 1 could not be retried.', self::contents($output));
    }

    public function test_all_leaves_a_record_that_failed_after_the_run_started(): void
    {
        $this->store->record(self::failedJob(failedAt: 1_000));
        $later = $this->store->record(self::failedJob(failedAt: time() + 3600));

        $exit = $this->command(self::memoryStream())->run(CommandArguments::parse(['--all']));

        self::assertSame(0, $exit);
        self::assertCount(1, $this->boundQueue->pushed);
        self::assertSame([$later], array_keys($this->link->rows));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'neither ids nor --all' => [[]];
        yield 'ids and --all' => [['--all', '0123456789abcdef0123456789abcdef']];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSelections')]
    public function test_the_selection_must_be_ids_or_all(array $argv): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->command(self::memoryStream())->run(CommandArguments::parse($argv));
    }

    /**
     * @param resource $output
     */
    private function command(mixed $output): RetryCommand
    {
        return new RetryCommand($this->store, $this->app->createRequestScope(), new Config([]), $output);
    }
}
