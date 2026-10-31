<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Console;

use InvalidArgumentException;
use Kinetis\Console\CommandArguments;
use Kinetis\QueueFailedJobs\Console\ForgetCommand;
use Kinetis\QueueFailedJobs\Console\PruneCommand;

final class ForgetAndPruneCommandTest extends CommandTestCase
{
    public function test_forget_deletes_the_one_record(): void
    {
        $id = $this->store->record(self::failedJob());
        $kept = $this->store->record(self::failedJob());
        $output = self::memoryStream();

        self::assertSame(0, new ForgetCommand($this->store, $output)->run(CommandArguments::parse([$id])));
        self::assertSame([$kept], array_keys($this->link->rows));
        self::assertSame("Forgot failed job \"{$id}\".\n", self::contents($output));
    }

    public function test_forget_of_an_unknown_id_exits_1(): void
    {
        $output = self::memoryStream();

        self::assertSame(1, new ForgetCommand($this->store, $output)->run(CommandArguments::parse(['nope'])));
        self::assertSame("No failed job \"nope\".\n", self::contents($output));
    }

    public function test_forget_takes_exactly_one_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ForgetCommand($this->store, self::memoryStream())->run(CommandArguments::parse(['a', 'b']));
    }

    public function test_prune_deletes_records_older_than_the_age_only(): void
    {
        $old = $this->store->record(self::failedJob(failedAt: time() - 3 * 3600));
        $recent = $this->store->record(self::failedJob(failedAt: time() - 3600 + 60));
        $output = self::memoryStream();

        self::assertSame(0, new PruneCommand($this->store, $output)->run(CommandArguments::parse(['--hours=1'])));
        self::assertSame([$recent], array_keys($this->link->rows));
        self::assertArrayNotHasKey($old, $this->link->rows);
        self::assertSame("Pruned 1 failed job(s) older than 1 hour(s).\n", self::contents($output));
    }

    public function test_prune_requires_an_age(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--hours is required');

        new PruneCommand($this->store, self::memoryStream())->run(CommandArguments::parse([]));
    }

    public function test_the_store_rejects_a_negative_age_and_an_empty_page(): void
    {
        try {
            $this->store->prune(-1);
            self::fail('Expected a negative age to be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);

        $this->store->list(0);
    }
}
