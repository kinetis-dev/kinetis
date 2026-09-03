<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

use Kinetis\Events\Listener;
use Kinetis\Tests\Events\Fixtures\Recorder;

/**
 * Never discovered — the Overriding fixture's own bootstrap.php replaces
 * EventListenerRegistry outright with one holding only this listener,
 * proving bootstrap code can genuinely override the discovered set, not
 * merely augment it.
 */
final readonly class ReplacementListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    #[Listener]
    public function onConfirmed(BootstrapOrderConfirmed $event): void
    {
        $this->recorder->record('replaced');
    }
}
