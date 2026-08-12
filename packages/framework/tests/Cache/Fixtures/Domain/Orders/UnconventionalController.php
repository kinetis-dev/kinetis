<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Domain\Orders;

use Kinetis\Http\Attributes\Get;

final class UnconventionalController
{
    #[Get('/fixture-unconventional')]
    public function ping(): string
    {
        return 'pong';
    }
}
