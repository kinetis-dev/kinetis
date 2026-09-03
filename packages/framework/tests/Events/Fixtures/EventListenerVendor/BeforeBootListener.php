<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Never discovered — installed only via TestApplication::boot()'s
 * $beforeBoot callback, which runs after the application's own
 * bootstrap.php. Proves $beforeBoot wins over both discovery and a
 * bootstrap.php override, not only over discovery alone.
 */
final readonly class BeforeBootListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onConfirmed(BootstrapOrderConfirmed $event): void
    {
        $this->recorder->record('before-boot');
    }
}
