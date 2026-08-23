<?php

declare(strict_types=1);

namespace App\Console;

use App\Events\ActionEvent;
use App\Repositories\PingRepository;
use Kinetis\Console\Attributes\Command;
use Kinetis\Events\EventDispatcher;

/**
 * The "cron" ping scenario: creates and pongs its own ping, every 5
 * seconds, independent of any browser action.
 */
final readonly class PongCronCommand
{
    public function __construct(
        private PingRepository $pings,
        private EventDispatcher $events,
    ) {}

    #[Command('pings:pong-cron', description: 'Creates and pongs a cron-driven ping')]
    public function run(): int
    {
        $id = $this->pings->create('cron');
        $this->pings->markPonged($id);

        $this->events->dispatch(new ActionEvent('cron', $id, 'cron'));
        $this->events->dispatch(new ActionEvent('socket', $id, 'cron'));

        return 0;
    }
}
