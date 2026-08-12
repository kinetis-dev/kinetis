<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActionEvent;
use App\Services\SoketiPublisher;
use Kinetis\Events\Listener;

/**
 * Republishes every ActionEvent to Soketi, so the dashboard can flash it.
 */
final readonly class ActionEventListener
{
    public function __construct(
        private SoketiPublisher $soketi,
    ) {}

    #[Listener]
    public function onActionEvent(ActionEvent $event): void
    {
        $this->soketi->actionOccurred($event->stage, $event->id, $event->scenario);
    }
}
