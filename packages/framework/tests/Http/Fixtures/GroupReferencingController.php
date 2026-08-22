<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

/**
 * References a middleware *group* rather than a real class — proves a
 * `@name` reference never contributes a prefix, since group membership
 * isn't resolved until Kernel construction, well after routing. Whether
 * "@some-group" is actually declared anywhere is irrelevant to Router
 * itself; that validation is Kernel's job.
 */
final class GroupReferencingController
{
    #[Get('/reports')]
    #[Middleware('@some-group')]
    public function index(): array
    {
        return [];
    }
}
