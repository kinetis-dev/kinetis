<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures\StartupProject\Events;

use Kinetis\Events\Listener;

final class StartupListener
{
    #[Listener]
    public function onStartupEvent(StartupEvent $event): void {}
}
