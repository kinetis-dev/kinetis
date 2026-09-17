<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Events\ActionEvent;
use Closure;
use Kinetis\Events\Listener;

/**
 * Stands in for App\Listeners\ActionEventListener: it records what the
 * dispatcher handed it instead of broadcasting. The hook runs where the
 * real listener reaches the broadcaster, which is where a test asks what
 * the database had already done by then.
 */
final class RecordingActionListener
{
    /** @var list<array{stage: string, id: ?int}> */
    public array $events = [];

    public ?Closure $onEvent = null;

    #[Listener]
    public function record(ActionEvent $event): void
    {
        $this->events[] = ['stage' => $event->stage, 'id' => $event->id];

        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
    }
}
