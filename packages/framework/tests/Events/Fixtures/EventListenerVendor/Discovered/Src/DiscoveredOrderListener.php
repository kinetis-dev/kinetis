<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor\Discovered\Src;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\BootstrapOrderConfirmed;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * No bootstrap.php override anywhere in this fixture root — a real
 * dispatch reaching this listener is the whole proof that the discovered
 * registry is genuinely bound and reachable, not merely present under
 * some id nothing ever resolves.
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
