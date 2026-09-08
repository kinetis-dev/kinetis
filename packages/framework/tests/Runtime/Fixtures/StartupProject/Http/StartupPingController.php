<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures\StartupProject\Http;

use Kinetis\Http\Attributes\Get;

final class StartupPingController
{
    #[Get('/startup-ping')]
    public function ping(): string
    {
        return 'pong';
    }
}
