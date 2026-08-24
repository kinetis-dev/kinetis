<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\BroadcasterInterface;

final class RecordingBroadcaster implements BroadcasterInterface
{
    /** @var list<array{channel: string, event: string, payload: array<string, mixed>}> */
    public array $calls = [];

    #[\Override]
    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->calls[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
    }
}
