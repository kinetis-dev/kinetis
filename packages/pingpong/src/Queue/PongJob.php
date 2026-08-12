<?php

declare(strict_types=1);

namespace App\Queue;

use App\Events\ActionEvent;
use App\Repositories\PingRepository;
use Kinetis\Events\EventDispatcher;
use Kinetis\Queue\Job;

/**
 * Pongs a queued ping. Picked up and run by the queue-worker container.
 */
final readonly class PongJob implements Job
{
    public function __construct(
        public int $id,
    ) {}

    public function handle(PingRepository $pings, EventDispatcher $events): void
    {
        $events->dispatch(new ActionEvent('queue', $this->id));
        $pings->markPonged($this->id);
        $events->dispatch(new ActionEvent('socket', $this->id, 'queued'));
    }
}
