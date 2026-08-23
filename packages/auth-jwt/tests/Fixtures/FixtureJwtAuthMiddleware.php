<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Container\RequestScope;

/**
 * The documented subclass pattern from JwtAuthMiddleware's own docblock,
 * exercised for real: a fixed test secret, supplied through a
 * constructor that takes only RequestScope — fully autowirable through
 * the request's own scope, with no AppScope-level binding at all.
 */
final class FixtureJwtAuthMiddleware extends JwtAuthMiddleware
{
    public const string SECRET = 'fixture-secret-key-do-not-use-in-production';

    public function __construct(RequestScope $scope)
    {
        parent::__construct(self::SECRET, $scope);
    }
}
