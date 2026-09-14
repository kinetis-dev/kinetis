<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\Events\MigrationRolledBack;

final class MigrationEventRecorder
{
    /** @var list<MigrationApplied|MigrationRolledBack> */
    public array $events = [];

    #[Listener]
    public function onApplied(MigrationApplied $event): void
    {
        $this->events[] = $event;
    }

    #[Listener]
    public function onRolledBack(MigrationRolledBack $event): void
    {
        $this->events[] = $event;
    }
}
