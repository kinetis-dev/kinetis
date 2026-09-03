<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Container\RequestScope;

/**
 * The same documented subclass pattern as FixtureJwtAuthMiddleware, but
 * with a RevocationStore configured — proving the strict iat/jti claim
 * gate activates through a real Kernel request, not just at the
 * middleware-unit level. A fresh InMemorySimpleCache per request is
 * fine here: this fixture exists to prove a malformed token is
 * rejected before either revocation lookup runs, not to test
 * cross-request revocation state.
 */
final class RevocationCheckingFixtureMiddleware extends JwtAuthMiddleware
{
    public const string SECRET = 'revocation-fixture-secret-key-do-not-use-in-production';

    public function __construct(RequestScope $scope)
    {
        parent::__construct(
            self::SECRET,
            $scope,
            revocationStore: new RevocationStore(new InMemorySimpleCache()),
        );
    }
}
