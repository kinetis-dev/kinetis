<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
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

        $authenticator = new JwtAuthenticator(
            JwtVerificationKeys::hmacSecret(self::SECRET),
            revocationStore: $revocations,
        );
        $user = $authenticator->authenticate($accessToken);

        self::assertInstanceOf(JwtUser::class, $user);
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

        self::assertNull($authenticator->authenticate($accessToken));
        self::assertNull($refreshTokens->redeem($reissuedRefreshToken));
    }
}
