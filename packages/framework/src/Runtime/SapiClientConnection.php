<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * Reads the SAPI's own connection status for the request in flight.
 *
 * Stateless and request-neutral: PHP resets the status at every request
 * startup, including each FrankenPHP worker request, so one instance
 * answers for whichever request the worker is serving. The SAPI records
 * a departure only when a write or flush finds the connection closed.
 */
final class SapiClientConnection implements ClientConnectionInterface
{
    #[\Override]
    public function isGone(): bool
    {
        return \connection_aborted() === 1;
    }
}
