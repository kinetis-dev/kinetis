<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CallableRequestHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The documented issue / access + refresh / log-out-everywhere flow, run
 * end to end against an ordinary integer application id — the one shape
 * that would break if a subject had more than one representation across
 * this package.
 */
final class SubjectIdentityTest extends TestCase
{
    private const string SECRET = 'subject-identity-test-secret-do-not-use-in-production';

    private const int APPLICATION_ID = 42;

    public function test_an_integer_application_id_revokes_both_its_access_and_refresh_credentials(): void
    {
        $cache = new InMemorySimpleCache();
        $revocations = new RevocationStore($cache);
        $refreshTokens = new RefreshTokenStore($cache);

        // A login endpoint: one access token and one refresh token, both
        // issued from the application's own integer id.
        $accessToken = new JwtIssuer(self::SECRET)->issue(self::APPLICATION_ID);
        $refreshToken = $refreshTokens->issue(self::APPLICATION_ID);

        $scope = $this->scope();
        $authenticated = $this->middleware($scope, $revocations)
            ->process($this->requestWithToken($accessToken), $this->handler());

        self::assertSame(200, $authenticated->getStatusCode());

        $subject = $scope->get(JwtUser::class)->id();

        self::assertSame('42', $subject);

        // A log-out-everywhere endpoint, holding nothing but the id the
        // request itself carried.
        $revocations->revokeAllForUser($subject, ttlSeconds: 3600);
        $refreshTokens->revokeAllForUser($subject, ttlSeconds: 3600);

        $afterRevocation = $this->middleware($this->scope(), $revocations)
            ->process($this->requestWithToken($accessToken), $this->handler());

        self::assertSame(401, $afterRevocation->getStatusCode());
        self::assertNull($refreshTokens->redeem($refreshToken));
    }

    private function middleware(RequestScope $scope, RevocationStore $revocations): JwtAuthMiddleware
    {
        return new JwtAuthMiddleware(self::SECRET, $scope, revocationStore: $revocations);
    }

    private function scope(): RequestScope
    {
        $app = new AppScope();
        $app->boot();

        return $app->createRequestScope();
    }

    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    private function requestWithToken(string $token): ServerRequest
    {
        return new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]);
    }
}
