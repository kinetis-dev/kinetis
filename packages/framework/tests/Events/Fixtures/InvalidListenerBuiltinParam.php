<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;

final class InvalidListenerBuiltinParam
{
    #[Listener]
    public function bad(string $notAnEvent): void
    {
    }
}
