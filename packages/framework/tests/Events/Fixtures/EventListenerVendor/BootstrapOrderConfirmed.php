<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

/**
 * Shared by every fixture project root under this directory — the event
 * class itself never needs to be discoverable, only the listener methods
 * that type-hint it, so one copy is reused rather than duplicated per
 * root the way each root's own listener class is.
 */
final readonly class BootstrapOrderConfirmed
{
}
