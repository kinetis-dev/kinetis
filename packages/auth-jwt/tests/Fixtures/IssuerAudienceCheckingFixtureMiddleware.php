<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Container\RequestScope;

/**
 * The same documented subclass pattern as FixtureJwtAuthMiddleware, but
 * with expectedIssuer/acceptedAudiences configured — proving both
 * constraints activate through a real Kernel request, not just at the
 * middleware-unit level.
 */
final class IssuerAudienceCheckingFixtureMiddleware extends JwtAuthMiddleware
{
    public const string SECRET = 'issuer-audience-fixture-secret-key-do-not-use-in-production';
    public const string ISSUER = 'fixture-app';
    public const string AUDIENCE = 'fixture-api';

    public function __construct(RequestScope $scope)
    {
        parent::__construct(
            self::SECRET,
            $scope,
            expectedIssuer: self::ISSUER,
            acceptedAudiences: [self::AUDIENCE],
        );
    }
}
