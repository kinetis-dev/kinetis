<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\DiscoveryFixtureProject;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

final class DiscoveredChannelAuthorizer
{
    #[BroadcastChannel('discovered.{id}')]
    public function authorize(string $id): bool
    {
        return true;
    }
}
