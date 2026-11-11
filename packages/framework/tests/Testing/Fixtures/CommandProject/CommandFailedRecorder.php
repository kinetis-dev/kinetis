<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

use Kinetis\Console\Events\CommandFailed;
use Kinetis\Events\Listener;

final readonly class CommandFailedRecorder
{
    public function __construct(
        private Journal $journal,
    ) {}

    #[Listener]
    public function onCommandFailed(CommandFailed $event): void
    {
        $this->journal->entries[] = "failed: {$event->commandName}";
    }
}
