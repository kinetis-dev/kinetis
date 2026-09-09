<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Http\Attributes\AsMiddlewareGroup;

/**
 * The one supported reason JwtAuthMiddleware is not final: an attribute
 * attaches to a class only by being declared on one, so joining a
 * middleware group needs a class to carry #[AsMiddlewareGroup]. Nothing
 * else is added — the keys, revocation store and claim constraints all
 * belong to the JwtAuthenticator this inherits from AppScope.
 */
#[AsMiddlewareGroup('jwt')]
final class GroupedJwtAuthMiddleware extends JwtAuthMiddleware {}
