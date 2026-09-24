<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\Get;

final class DiscoveredConstrainedRouteController
{
    // Declared out of placeholder order on purpose: every consumer sees
    // the canonical year-then-slug order.
    #[Get('/fixture-archive/{year}/{slug}', where: ['slug' => '[a-z-]+', 'year' => '\d{4}'])]
    public function show(string $year, string $slug): string
    {
        return "{$year}/{$slug}";
    }
}
