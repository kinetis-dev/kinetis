<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActionEvent;
use Kinetis\Broadcasting\Broadcaster;
use Kinetis\Events\Listener;

/**
 * Republishes every ActionEvent so the dashboard can flash it, over
 * kinetis/broadcasting's driver-agnostic Broadcaster rather than a
 * Soketi-specific client directly — the public diagram channel every
 * stage flashes on, plus a private, per-visitor notification the moment
 * a ping is ponged, demonstrating kinetis/broadcasting's private-channel
 * authorization (see App\Broadcasting\NotificationChannelAuthorizer).
 */
final readonly class ActionEventListener
{
    public const string PUBLIC_CHANNEL = 'ping-pong';

    public const string PRIVATE_CHANNEL = 'private-notifications';

    public function __construct(
        private Broadcaster $broadcaster,
    ) {}

    #[Listener]
    public function onActionEvent(ActionEvent $event): void
    {
        $this->broadcaster->broadcast(self::PUBLIC_CHANNEL, 'action', [
            'stage' => $event->stage,
            'id' => $event->id,
            'scenario' => $event->scenario,
        ]);

        if ($event->stage === 'socket') {
            $this->broadcaster->broadcast(self::PRIVATE_CHANNEL, 'pong.notified', [
                'id' => $event->id,
                'scenario' => $event->scenario,
            ]);
        }
    }
}
