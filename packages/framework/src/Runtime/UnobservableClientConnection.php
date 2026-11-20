<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * The answer of a runtime that cannot observe the client leaving: never
 * gone.
 */
final class UnobservableClientConnection implements ClientConnectionInterface
{
    #[\Override]
    public function isGone(): bool
    {
        return false;
    }
}
