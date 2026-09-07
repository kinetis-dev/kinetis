<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\JwtVerificationKeys;
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
 * The documented login / authenticate / refresh / logout flow, run end
 * to end against an ordinary integer application id — the one shape
 * that would break if a subject had more than one representation across
 * this package.
 */
final class SubjectIdentityTest extends TestCase
{
    private const string SECRET = 'subject-identity-test-secret-do-not-use-in-production';

    private const int APPLICATION_ID = 42;

    public function test_an_integer_application_id_names_one_subject_across_both_credentials(): void
    {
        $cache = new InMemorySimpleCache();
        $revocations = new RevocationStore($cache);
        $refreshTokens = new RefreshTokenStore($cache);

        // A login endpoint: one access token and one refresh token, both
        // issued from the application's own integer id.
        $signingKey = JwtSigningKey::hmacSecret(self::SECRET);
        $accessToken = new JwtIssuer($signingKey)->issue(self::APPLICATION_ID);
        $refreshToken = $refreshTokens->issue(self::APPLICATION_ID);

        $scope = $this->scope();
        $authenticated = $this->middleware($scope, $revocations)
            ->process($this->requestWithToken($accessToken), $this->handler());

        self::assertSame(200, $authenticated->getStatusCode());

        $user = $scope->get(JwtUser::class);

        self::assertSame('42', $user->id());

        // A refresh endpoint: the redeemed subject is the same string
        // the authenticated request itself carried, so the replacement
        // credentials are issued under one identity.
        $redeemed = $refreshTokens->redeem($refreshToken);

        self::assertSame(['subject' => '42', 'claims' => []], $redeemed);

        $reissuedRefreshToken = $refreshTokens->issue($redeemed['subject']);

        // A logout endpoint, holding nothing but the credentials in hand.
        $revocations->revokeToken($user);
        $refreshTokens->revoke($reissuedRefreshToken);

        $afterRevocation = $this->middleware($this->scope(), $revocations)
            ->process($this->requestWithToken($accessToken), $this->handler());

        self::assertSame(401, $afterRevocation->getStatusCode());
        self::assertNull($refreshTokens->redeem($reissuedRefreshToken));
    }

    private function middleware(RequestScope $scope, RevocationStore $revocations): JwtAuthMiddleware
    {
        $keys = JwtVerificationKeys::hmacSecret(self::SECRET);

        return new JwtAuthMiddleware($keys, $scope, revocationStore: $revocations);
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
