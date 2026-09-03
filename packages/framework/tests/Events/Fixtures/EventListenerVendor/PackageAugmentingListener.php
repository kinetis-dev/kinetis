<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Never discovered — registered only by PackageBootstrap below, standing
 * in for a real installed package's own `extra.kinetis.bootstrap` class
 * augmenting the discovered registry, distinct from an application's own
 * bootstrap.php doing the same thing.
 */
final readonly class PackageAugmentingListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onConfirmed(BootstrapOrderConfirmed $event): void
    {
        $this->recorder->record('package');
    }
}
