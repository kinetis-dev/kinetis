<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Never discovered — the Augmenting fixture's own bootstrap.php resolves
 * the already-bound EventListenerRegistry and registers this explicitly,
 * proving bootstrap code can augment the discovered set rather than only
 * replace it wholesale.
 */
final readonly class AugmentingListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onConfirmed(BootstrapOrderConfirmed $event): void
    {
        $this->recorder->record('augmented');
    }
}
