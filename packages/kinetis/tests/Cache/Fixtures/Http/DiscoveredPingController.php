<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\Get;

final class DiscoveredPingController
{
    #[Get('/fixture-ping')]
    public function ping(): string
    {
        return 'pong';
    }
}
