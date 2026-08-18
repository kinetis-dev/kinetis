<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\Get;

/**
 * Abstract, and carrying a route attribute — the scanner has to skip it
 * rather than hand it to RouteDiscovery, which could not register it.
 */
abstract class AbstractScannedController
{
    #[Get('/never-discovered')]
    public function never(): array
    {
        return [];
    }
}
