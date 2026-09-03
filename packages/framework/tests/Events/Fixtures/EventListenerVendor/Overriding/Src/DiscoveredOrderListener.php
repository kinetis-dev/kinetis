<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor\Overriding\Src;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\BootstrapOrderConfirmed;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Discovered the same way as every other root's own copy — this root's
 * own bootstrap.php replaces the registry outright, so this one must
 * NOT run once that replacement has happened.
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
