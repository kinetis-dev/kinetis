<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor\Augmenting\Src;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\BootstrapOrderConfirmed;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Discovered the same way Discovered/src's own copy is — this root's own
 * bootstrap.php only augments the registry with one more listener, so
 * this one must still run alongside it.
 */
final readonly class DiscoveredOrderListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onConfirmed(BootstrapOrderConfirmed $event): void
    {
        $this->recorder->record('discovered');
    }
}
