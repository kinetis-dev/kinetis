<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Runtime\ClientConnectionInterface;

/**
 * A runtime that has observed the client leave.
 */
final class GoneClientConnection implements ClientConnectionInterface
{
    #[\Override]
    public function isGone(): bool
    {
        return true;
    }
}
