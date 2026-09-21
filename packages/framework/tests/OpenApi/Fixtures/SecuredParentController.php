<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\OpenApiSecurity;

/** Carries a declaration a subclass must not pick up. */
#[OpenApiSecurity(AdminKeyMiddleware::class)]
abstract class SecuredParentController {}
