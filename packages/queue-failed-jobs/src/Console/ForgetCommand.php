<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use InvalidArgumentException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;

/**
 * Deletes one failed-job record without retrying it. Exits 1 when no
 * record has the id.
 */
final readonly class ForgetCommand
{
    public function __construct(
        private SqlFailedJobStore $store,
        private Output $output,
    ) {}

    #[Command('queue:forget', description: 'Delete a failed job without retrying it: queue:forget <id>')]
    public function run(CommandArguments $arguments): int
    {
        $id = $arguments->get(0);

        if ($id === null || \count($arguments->all()) !== 1) {
            throw new InvalidArgumentException('Pass the id of one failed job: queue:forget <id>.');
        }

        if ($this->store->find($id) === null) {
            $this->output->error("No failed job \"{$id}\".");

            return 1;
        }

        $this->store->forget($id);
        $this->output->line("Forgot failed job \"{$id}\".");

        return 0;
    }
}
