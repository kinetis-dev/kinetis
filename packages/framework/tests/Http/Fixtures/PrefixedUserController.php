<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\RoutePrefix;

#[RoutePrefix('/users')]
final class PrefixedUserController
{
    use SharedCrudRoutes;
}
