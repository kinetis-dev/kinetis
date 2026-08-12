<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final class InvalidListenerNoParams
{
    #[Listener]
    public function bad(): void
    {
    }
}
