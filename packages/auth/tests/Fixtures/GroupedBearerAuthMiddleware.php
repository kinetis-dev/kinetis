<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Http\Attributes\AsMiddlewareGroup;

/**
 * The one supported reason BearerAuthMiddleware is not final: an
 * attribute attaches to a class only by being declared on one, so
 * joining a middleware group needs a class to carry
 * #[AsMiddlewareGroup]. Nothing else is added, openApiSecurity()
 * included.
 */
#[AsMiddlewareGroup('api')]
final readonly class GroupedBearerAuthMiddleware extends BearerAuthMiddleware {}
