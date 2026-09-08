<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\JoseHeader;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\DualBindingFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\FixtureJwtAuthMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\IssuerAudienceCheckedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\IssuerAudienceCheckingFixtureMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\ProtectedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\RecordingSimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\RevocationCheckedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\RevocationCheckingFixtureMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\SecondRsaKeyPair;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtAuthMiddlewareTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    // Long enough (85 bytes) to satisfy HS256/HS384/HS512's minimum
    // alike — self::SECRET (40 bytes) only clears HS256's.
    private const string LONG_SECRET = 'this-is-a-generously-long-test-secret-key-well-over-64-bytes-do-not-use-in-production';

    /**
     * The HS256 configuration these tests default to: two views of
     * self::SECRET, one for verification and one for signing.
     */
    private static function keys(): JwtVerificationKeys
    {
        return JwtVerificationKeys::hmacSecret(self::SECRET);
    }

    private static function signingKey(): JwtSigningKey
    {
        return JwtSigningKey::hmacSecret(self::SECRET);
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

    public function test_a_valid_token_registers_the_resolved_user_and_passes_through(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_lowercase_scheme_is_accepted(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "bearer {$token}"]);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_missing_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_bearer_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_empty_bearer_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer ']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_malformed_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());

        $response = $middleware->process($this->requestWithToken('not-a-jwt-at-all'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_signed_with_a_different_key_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $otherKey = JwtSigningKey::hmacSecret('a-completely-different-secret-key-of-sufficient-length');
        $token = new JwtIssuer($otherKey)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_expired_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $token = JWT::encode(
            ['sub' => 'user-42', 'iat' => time() - 3600, 'exp' => time() - 1800],
            self::SECRET,
            'HS256',
        );

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_no_subject_claim_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $token = JWT::encode(['iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A subject is one canonical non-empty string across this package —
     * the form JwtIssuer writes and RefreshTokenStore stores. A token
     * whose `sub` is a JSON number or an empty string names no user this
     * package can act on, so it never authenticates at all.
     */
    #[DataProvider('nonCanonicalSubjectClaims')]
    public function test_a_token_whose_subject_is_not_a_non_empty_string_is_rejected_with_401(mixed $sub): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $token = JWT::encode(['sub' => $sub, 'iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public static function nonCanonicalSubjectClaims(): iterable
    {
        yield 'an integer' => [42];
        yield 'an empty string' => [''];
        yield 'a float' => [42.5];
        yield 'a boolean' => [true];
        yield 'a list' => [['user-42']];
    }

    public function test_the_inner_handler_never_runs_when_unauthenticated(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(0, $calls);
    }

    public function test_works_as_route_middleware_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $token = new JwtIssuer(JwtSigningKey::hmacSecret(FixtureJwtAuthMiddleware::SECRET))->issue('user-42');

        $unauthenticated = $kernel->handle(new ServerRequest('GET', '/me'));
        $authenticated = $kernel->handle(new ServerRequest('GET', '/me', headers: ['Authorization' => "Bearer {$token}"]));

        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame(200, $authenticated->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $authenticated->getBody(), true));
    }

    public function test_a_lowercase_scheme_works_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $token = new JwtIssuer(JwtSigningKey::hmacSecret(FixtureJwtAuthMiddleware::SECRET))->issue('user-42');

        $response = $kernel->handle(new ServerRequest('GET', '/me', headers: ['Authorization' => "bearer {$token}"]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $response->getBody(), true));
    }

    /**
     * CurrentUserInterface and JwtUser are documented as the two things
     * a controller can legitimately constructor-inject — proven here
     * through a real Kernel request that both resolve to the identical
     * object, and that a custom claim (role) plus the standard jti claim
     * are genuinely reachable only through the concrete JwtUser.
     */
    public function test_current_user_interface_and_jwt_user_resolve_to_the_identical_object_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(DualBindingFixtureController::class);
        $kernel = new Kernel($app, $router);

        $signingKey = JwtSigningKey::hmacSecret(FixtureJwtAuthMiddleware::SECRET);
        $token = new JwtIssuer($signingKey)->issue('user-42', ['role' => 'admin']);

        $authenticated = $kernel->handle(new ServerRequest('GET', '/dual', headers: ['Authorization' => "Bearer {$token}"]));

        self::assertSame(200, $authenticated->getStatusCode());

        /** @var array{sameInstance: bool, role: string, jti: string} $body */
        $body = json_decode((string) $authenticated->getBody(), true);

        self::assertTrue($body['sameInstance']);
        self::assertSame('admin', $body['role']);
        self::assertNotSame('', $body['jti']);
    }

    /**
     * Neither binding is ever registered on the request scope when
     * authentication fails — checked directly against the scope via
     * isRegistered() (not has(), which also reports true for any
     * autowirable class — JwtUser included — regardless of whether
     * anything actually registered one), not inferred from a response
     * body, since a failed request never reaches the controller that
     * response body would come from at all.
     */
    public function test_neither_binding_is_registered_on_authentication_failure(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);

        $middleware->process(new ServerRequest('GET', '/dual'), $this->handler());

        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    public function test_a_revoked_token_is_rejected_with_401(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_non_revoked_token_still_passes_through_when_a_revocation_store_is_configured(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_revoking_one_token_does_not_reject_a_different_one(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), revocationStore: $revocationStore);

        $revoked = new JwtIssuer(self::signingKey())->issue('user-42');
        $claims = JWT::decode($revoked, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        $stillValid = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($stillValid), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedJtiClaims(): iterable
    {
        yield 'missing jti' => [null]; // null here means "omit the claim entirely" — see the test body.
        yield 'empty jti' => [''];
        yield 'non-string jti' => [12345];
    }

    /**
     * A revocation store is configured but the token carries no jti the
     * denylist could ever name — rejected outright rather than
     * authenticated with the one check it makes silently skipped. Every
     * case here is a real, validly-signed token: the rejection is
     * JwtAuthMiddleware's own, not a signature or decode failure.
     */
    #[DataProvider('malformedJtiClaims')]
    public function test_a_token_with_a_malformed_jti_is_rejected_when_a_revocation_store_is_configured(
        mixed $jti,
    ): void {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), revocationStore: $revocationStore);

        $claims = ['sub' => 'user-42'];

        if ($jti !== null) {
            $claims['jti'] = $jti;
        }

        $token = JWT::encode($claims, self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The jti gate must reject a malformed token before the revocation
     * lookup ever runs — proven against a cache that records every get()
     * call, not just inferred from the 401 status.
     */
    public function test_a_malformed_jti_never_reaches_the_revocation_lookup(): void
    {
        $cache = new RecordingSimpleCache();
        $revocationStore = new RevocationStore($cache);
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), revocationStore: $revocationStore);

        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $cache->getCalls);
    }

    /**
     * The jti gate through a real Kernel request, not just the
     * middleware-unit level — the same "prove it end to end, not only in
     * isolation" discipline test_works_as_route_middleware_through_a_real_kernel()
     * already established.
     */
    public function test_a_malformed_claim_is_rejected_through_a_real_kernel_when_a_revocation_store_is_configured(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(RevocationCheckedFixtureController::class);
        $kernel = new Kernel($app, $router);

        // A validly signed token overall, but missing jti — hand-built,
        // since JwtIssuer always includes one.
        $token = JWT::encode(
            ['sub' => 'user-42', 'iat' => time()],
            RevocationCheckingFixtureMiddleware::SECRET,
            'HS256',
        );

        $response = $kernel->handle(new ServerRequest(
            'GET',
            '/revocation-checked',
            headers: ['Authorization' => "Bearer {$token}"],
        ));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_valid_token_passes_through_when_no_revocation_store_is_configured(): void
    {
        // The default — revocationStore is optional and null by default,
        // matching every existing test above that never mentions it.
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_rs256_verifies_a_token_signed_with_the_matching_private_key(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY), $scope);
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY))->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_rs256_rejects_a_token_that_was_never_signed_with_the_private_key(): void
    {
        $middleware = new JwtAuthMiddleware(JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY), $this->scope());
        // Signed with an HS256 secret, not the RSA private key — the
        // middleware only ever tries to verify as RS256 against the
        // configured public key, so this must fail, not silently
        // "succeed" under a different algorithm.
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }



    public function test_a_token_with_the_matching_issuer_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_no_iss_is_rejected_when_an_issuer_is_expected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), expectedIssuer: 'my-app');
        // No issuer configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_the_wrong_issuer_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-elses-app')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIssuerValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'integer' => [42];
        yield 'array' => [['my-app']];
        yield 'boolean' => [true];
    }

    #[DataProvider('malformedIssuerValues')]
    public function test_a_token_with_a_malformed_iss_is_rejected(mixed $iss): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), expectedIssuer: 'my-app');
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'iss' => $iss], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_a_matching_string_audience_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        $token = new JwtIssuer(self::signingKey(), audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_a_matching_list_audience_passes_through(): void
    {
        // Any-match semantics: only one of the token's own audiences
        // needs to be present in the accepted list.
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-b']);
        $token = new JwtIssuer(self::signingKey(), audience: ['svc-a', 'svc-b'])->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_no_matching_audience_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        $token = new JwtIssuer(self::signingKey(), audience: 'svc-c')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_no_aud_is_rejected_when_audiences_are_expected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        // No audience configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_an_empty_string_audience_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => ''], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_an_empty_audience_list_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => []], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_a_mixed_type_audience_list_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => ['svc-a', 123]], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_both_issuer_and_audience_matching_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::keys(),
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_matching_issuer_alone_is_not_enough_when_audience_is_also_required(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::keys(),
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-wrong')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_matching_audience_alone_is_not_enough_when_issuer_is_also_required(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::keys(),
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-else', audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_no_issuer_or_audience_constraint_by_default_accepts_a_token_carrying_either_claim(): void
    {
        // Backward compatible: a token carrying real iss/aud claims still
        // passes through when neither constraint is configured on this
        // middleware.
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A rejected issuer/audience must be exactly as inert as any other
     * failure: no revocation cache lookup, no user id registered on
     * either binding, and the inner handler never invoked.
     */
    public function test_an_issuer_mismatch_never_reaches_revocation_registers_no_user_or_invokes_the_handler(): void
    {
        $cache = new RecordingSimpleCache();
        $revocationStore = new RevocationStore($cache);
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::keys(),
            $scope,
            revocationStore: $revocationStore,
            expectedIssuer: 'my-app',
        );
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-else')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame([], $cache->getCalls);
        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    /**
     * Key rotation succeeding (a token validly signed under a recognized
     * kid) must not bypass the issuer check — the two are independent
     * gates.
     */
    public function test_a_key_set_still_enforces_issuer_after_verifying_under_the_matching_kid(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $this->scope(),
            expectedIssuer: 'my-app',
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey, issuer: 'someone-else')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_key_set_passes_through_when_kid_and_issuer_both_match(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $scope,
            expectedIssuer: 'my-app',
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey, issuer: 'my-app')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_construction_throws_when_expected_issuer_is_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), expectedIssuer: '');
    }

    public function test_construction_throws_when_accepted_audiences_is_an_empty_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: []);
    }

    public function test_construction_throws_when_accepted_audiences_contains_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a', '']);
    }

    public function test_construction_throws_when_accepted_audiences_contains_a_non_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a', 123]);
    }

    /**
     * $acceptedAudiences is documented as list<string> — an associative
     * array doesn't match that shape, even though in_array()/
     * array_intersect() would happen to still work on it at runtime.
     */
    public function test_construction_throws_when_accepted_audiences_is_an_associative_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['primary' => 'svc-a']);
    }

    public function test_construction_throws_when_accepted_audiences_is_a_sparse_numeric_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: [0 => 'svc-a', 2 => 'svc-b']);
    }

    /**
     * @return iterable<string, array{string|array<int, string>, list<string>}>
     */
    public static function acceptedAudienceRoundTrips(): iterable
    {
        yield 'single string audience' => ['svc-a', ['svc-a']];
        yield 'single-element list audience' => [['svc-a'], ['svc-a']];
        yield 'multi-element list audience, first entry accepted' => [['svc-a', 'svc-b'], ['svc-a']];
        yield 'multi-element list audience, second entry accepted' => [['svc-a', 'svc-b'], ['svc-b']];
    }

    /**
     * Every audience shape JwtIssuer will actually construct and emit
     * must authenticate successfully against a verifier configured with
     * a matching accepted audience — a round-trip invariant across the
     * whole accepted shape space, not just one example of each.
     *
     * @param string|array<int, string> $issuedAudience
     * @param list<string> $acceptedAudiences
     */
    #[DataProvider('acceptedAudienceRoundTrips')]
    public function test_every_accepted_audience_shape_round_trips_successfully(
        string|array $issuedAudience,
        array $acceptedAudiences,
    ): void {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: $acceptedAudiences);
        $token = new JwtIssuer(self::signingKey(), audience: $issuedAudience)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A token whose `aud` claim is a JSON object (rather than a string
     * or a JSON array) — reachable only from a hand-crafted or
     * third-party token, since JwtIssuer itself now refuses to construct
     * an associative $audience — must still be rejected as a generic
     * 401, not treated as a match or cause an error.
     */
    public function test_a_token_whose_aud_claim_is_a_json_object_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope(), acceptedAudiences: ['svc-a']);
        // A PHP associative array here serializes via JWT::encode()'s own
        // json_encode() call as a JSON object, e.g. {"primary":"svc-a"}.
        $token = JWT::encode(
            ['sub' => 'user-42', 'iat' => time(), 'aud' => ['primary' => 'svc-a']],
            self::SECRET,
            'HS256',
        );

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The strict issuer/audience gate through a real Kernel request, not
     * just the middleware-unit level.
     */
    public function test_issuer_and_audience_are_enforced_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(IssuerAudienceCheckedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $signingKey = JwtSigningKey::hmacSecret(IssuerAudienceCheckingFixtureMiddleware::SECRET);

        $validToken = new JwtIssuer(
            $signingKey,
            issuer: IssuerAudienceCheckingFixtureMiddleware::ISSUER,
            audience: IssuerAudienceCheckingFixtureMiddleware::AUDIENCE,
        )->issue('user-42');

        $wrongIssuerToken = new JwtIssuer(
            $signingKey,
            issuer: 'someone-else',
            audience: IssuerAudienceCheckingFixtureMiddleware::AUDIENCE,
        )->issue('user-42');

        $noClaimsToken = new JwtIssuer($signingKey)->issue('user-42');

        $valid = $kernel->handle(new ServerRequest(
            'GET',
            '/issuer-audience-checked',
            headers: ['Authorization' => "Bearer {$validToken}"],
        ));
        $wrongIssuer = $kernel->handle(new ServerRequest(
            'GET',
            '/issuer-audience-checked',
            headers: ['Authorization' => "Bearer {$wrongIssuerToken}"],
        ));
        $noClaims = $kernel->handle(new ServerRequest(
            'GET',
            '/issuer-audience-checked',
            headers: ['Authorization' => "Bearer {$noClaimsToken}"],
        ));

        self::assertSame(200, $valid->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $valid->getBody(), true));
        self::assertSame(401, $wrongIssuer->getStatusCode());
        self::assertSame(401, $noClaims->getStatusCode());
    }

    /**
     * A concise grammar matrix run through the real process() entry
     * point — the same shape of matrix core's own
     * BearerCredentialParserTest already proves at the parser level, and
     * the same cases kinetis/auth's own BearerAuthMiddlewareTest proves
     * for its middleware, run here to prove this middleware also
     * actually calls the parser and acts on its result correctly.
     * Accept cases use a real, validly-signed JWT (JWT::decode() itself
     * would reject an arbitrary opaque string), so unlike the Bearer
     * package's matrix, the credential can't be a fixed literal —
     * the accept/reject expectation is what's shared. Leading/trailing
     * whitespace around the header value is omitted for the identical
     * reason kinetis/auth's own matrix omits it: nyholm/psr7 already
     * strips it when a header is set, confirmed directly, so it's
     * unreachable through a real request built through it.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function grammarMatrix(): iterable
    {
        $validToken = new JwtIssuer(self::signingKey())->issue('user-42');
        // A real, validly-signed, but deliberately oversized token —
        // JwtIssuer::issue() itself, not a synthetic long string, so
        // this both proves "very long is accepted" and reuses the
        // exact code path every other accept case in this matrix does.
        $longToken = new JwtIssuer(self::signingKey())->issue('user-42', ['padding' => str_repeat('a', 8000)]);

        yield 'multiple SP separator' => ["Bearer  {$validToken}", true];
        yield 'tab separator' => ["Bearer\t{$validToken}", false];
        yield 'embedded whitespace within credential' => ['Bearer abc def', false];
        yield 'comma in credential' => ["Bearer {$validToken},x", false];
        yield 'illegal leading padding' => ["Bearer ={$validToken}", false];
        yield 'very long valid credential' => ["Bearer {$longToken}", true];
    }

    #[DataProvider('grammarMatrix')]
    public function test_the_grammar_matrix_authenticates_or_rejects_correctly(string $headerValue, bool $accepted): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => $headerValue]);
        $response = $middleware->process($request, $this->handler());

        if ($accepted) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
        } else {
            self::assertSame(401, $response->getStatusCode());
        }
    }

    /**
     * Two genuinely separate Authorization header lines are rejected —
     * the same ambiguity core's own BearerCredentialParserTest proves at
     * the parser level, proven here through the real middleware, with a
     * real, validly-signed token on both lines (so a failure here can
     * only be the duplicate-header rule itself, not an unrelated
     * signature problem).
     */
    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');
        $middleware = new JwtAuthMiddleware(self::keys(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]);
        $request = $request->withAddedHeader('Authorization', "Bearer {$token}");

        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A malformed header (here, a tab separator) must be rejected before
     * JWT::decode() is even attempted — proven observably rather than by
     * mocking the static decoder: zero revocation cache lookups, neither
     * binding registered on the scope, and the inner handler never
     * invoked, the same three-part inertness proof
     * test_an_issuer_mismatch_never_reaches_revocation_registers_no_user_or_invokes_the_handler()
     * already established for a different (post-decode) rejection
     * reason.
     */
    public function test_a_malformed_header_never_reaches_revocation_scope_or_the_handler(): void
    {
        $cache = new RecordingSimpleCache();
        $revocationStore = new RevocationStore($cache);
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope, revocationStore: $revocationStore);
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer\t{$token}"]);
        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame([], $cache->getCalls);
        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

















    /**
     * @return iterable<string, array{string}>
     */
    public static function hmacAlgorithms(): iterable
    {
        yield 'HS256' => ['HS256'];
        yield 'HS384' => ['HS384'];
        yield 'HS512' => ['HS512'];
    }

    #[DataProvider('hmacAlgorithms')]
    public function test_a_real_token_authenticates_under_every_hmac_algorithm(string $algorithm): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(JwtVerificationKeys::hmacSecret(self::LONG_SECRET, $algorithm), $scope);
        $token = new JwtIssuer(JwtSigningKey::hmacSecret(self::LONG_SECRET, $algorithm))->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rsaAlgorithms(): iterable
    {
        yield 'RS256' => ['RS256'];
        yield 'RS384' => ['RS384'];
        yield 'RS512' => ['RS512'];
    }

    #[DataProvider('rsaAlgorithms')]
    public function test_a_real_token_authenticates_under_every_rsa_algorithm(string $algorithm): void
    {
        $scope = $this->scope();
        $keys = JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY, $algorithm);
        $middleware = new JwtAuthMiddleware($keys, $scope);
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, $algorithm))->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }


    /**
     * A JWK Set published by JwkSet, serialized the way a
     * `.well-known/jwks.json` route serializes it, and parsed back.
     *
     * @param list<PublishedRsaKey> $keys
     */
    private static function publishedKeySet(array $keys): JwtVerificationKeys
    {
        return JwtVerificationKeys::jwks((string) json_encode(JwkSet::fromRsaPublicKeys($keys), JSON_THROW_ON_ERROR));
    }

    /**
     * Two kids under two different key pairs, so which one a token
     * reaches is observable.
     */
    private static function keySetWithKids(string $firstKid, string $secondKid): JwtVerificationKeys
    {
        return self::publishedKeySet([
            new PublishedRsaKey($firstKid, RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey($secondKid, SecondRsaKeyPair::publicKey()),
        ]);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function tokenWithHeader(array $header): string
    {
        $segments = [
            JWT::urlsafeB64Encode((string) JWT::jsonEncode($header)),
            JWT::urlsafeB64Encode((string) JWT::jsonEncode(['sub' => 'user-42'])),
        ];
        $segments[] = JWT::urlsafeB64Encode(hash_hmac('sha256', implode('.', $segments), self::SECRET, true));

        return implode('.', $segments);
    }

    private function countingHandler(int &$calls): CallableRequestHandler
    {
        return new CallableRequestHandler(static function () use (&$calls): Response {
            ++$calls;

            return new Response(200);
        });
    }

    private function assertRejectedWithoutTouchingTheRequest(
        JwtAuthMiddleware $middleware,
        RequestScope $scope,
        string $token,
    ): void {
        $calls = 0;
        $response = $middleware->process($this->requestWithToken($token), $this->countingHandler($calls));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $calls);
        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    public function test_a_published_key_set_verifies_a_token_signed_under_a_matching_kid(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keySetWithKids('2025-key', '2026-key'), $scope);
        $signingKey = JwtSigningKey::rsaPrivateKey(SecondRsaKeyPair::privateKey(), kid: '2026-key');
        $token = new JwtIssuer($signingKey)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    /**
     * Each kid selects its own published key and no other. "0" and
     * "00" are the pair a PHP array key cannot hold apart (see
     * JwkSetParser); "ordinary" shares its key pair with "0", so a
     * token verifying under one of them is a fact about the kid the
     * document published, not about which key happens to be in the set.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function publishedKidSelections(): array
    {
        return [
            'kid 0 under its own key' => ['0', RsaKeyPair::PRIVATE_KEY, true],
            'kid 00 under its own key' => ['00', SecondRsaKeyPair::privateKey(), true],
            'an ordinary kid under its own key' => ['ordinary', RsaKeyPair::PRIVATE_KEY, true],
            'kid 0 under the key published as 00' => ['0', SecondRsaKeyPair::privateKey(), false],
            'kid 00 under the key published as 0' => ['00', RsaKeyPair::PRIVATE_KEY, false],
            'an ordinary kid under the key published as 00' => ['ordinary', SecondRsaKeyPair::privateKey(), false],
        ];
    }

    #[DataProvider('publishedKidSelections')]
    public function test_a_published_kid_selects_its_own_key_through_the_whole_path(
        string $kid,
        string $privateKey,
        bool $verifies,
    ): void {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::publishedKeySet([
                new PublishedRsaKey('0', RsaKeyPair::PUBLIC_KEY),
                new PublishedRsaKey('00', SecondRsaKeyPair::publicKey()),
                new PublishedRsaKey('ordinary', RsaKeyPair::PUBLIC_KEY),
            ]), $scope);
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey($privateKey, kid: $kid))->issue('user-42');

        if (!$verifies) {
            $this->assertRejectedWithoutTouchingTheRequest($middleware, $scope, $token);

            return;
        }

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    /**
     * Publisher, parser, signing key and header boundary hold a kid to
     * the one rule, so the longest kid JwkSet will emit is one the rest
     * of the path still accepts.
     */
    public function test_the_longest_publishable_kid_survives_the_whole_path(): void
    {
        $kid = str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH);
        $scope = $this->scope();
        $keySet = self::publishedKeySet([new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY)]);
        $middleware = new JwtAuthMiddleware($keySet, $scope);
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: $kid))->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_published_key_set_rejects_an_unrecognized_kid(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $scope,
        );
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'retired'))->issue('user-42');

        $this->assertRejectedWithoutTouchingTheRequest($middleware, $scope, $token);
    }

    public function test_a_published_key_set_rejects_a_token_carrying_no_kid_at_all(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $scope,
        );
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY))->issue('user-42');

        $this->assertRejectedWithoutTouchingTheRequest($middleware, $scope, $token);
    }


    /**
     * Header shapes JWT::decode() reaches by a path that raises a raw
     * TypeError rather than a decode failure — see JoseHeader.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function tokenControlledHeaderShapes(): array
    {
        return [
            'alg as an array' => [['alg' => ['RS256'], 'kid' => 'current']],
            'alg as an object' => [['alg' => ['name' => 'RS256'], 'kid' => 'current']],
            'alg as an integer' => [['alg' => 256, 'kid' => 'current']],
            'alg as a boolean' => [['alg' => true, 'kid' => 'current']],
            'alg as null' => [['alg' => null, 'kid' => 'current']],
            'no alg at all' => [['typ' => 'JWT', 'kid' => 'current']],
            'an algorithm this package does not support' => [['alg' => 'ES256', 'kid' => 'current']],
            'the none algorithm' => [['alg' => 'none', 'kid' => 'current']],
            'kid as an array' => [['alg' => 'RS256', 'kid' => ['current']]],
            'kid as an object' => [['alg' => 'RS256', 'kid' => ['name' => 'current']]],
            'kid as an integer' => [['alg' => 'RS256', 'kid' => 0]],
            'kid as a boolean' => [['alg' => 'RS256', 'kid' => true]],
            'kid as null' => [['alg' => 'RS256', 'kid' => null]],
            'a blank kid' => [['alg' => 'RS256', 'kid' => "  \t"]],
            'a kid past the length limit' => [
                ['alg' => 'RS256', 'kid' => str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1)],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('tokenControlledHeaderShapes')]
    public function test_a_token_controlled_header_shape_is_rejected_without_reaching_the_handler(array $header): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $scope,
        );

        $this->assertRejectedWithoutTouchingTheRequest($middleware, $scope, $this->tokenWithHeader($header));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCompactTokens(): array
    {
        $header = JWT::urlsafeB64Encode('{"alg":"HS256"}');
        $payload = JWT::urlsafeB64Encode('{"sub":"user-42"}');
        $signature = JWT::urlsafeB64Encode('signature');

        // Both of these encode a length whose final character carries
        // unused bits, so setting one leaves a different spelling of
        // the identical bytes.
        $spacedHeader = JWT::urlsafeB64Encode('{"alg": "HS256"}');
        $longSignature = JWT::urlsafeB64Encode('signatures');

        return [
            'two segments' => ["{$header}.{$payload}"],
            'four segments' => ["{$header}.{$payload}.{$signature}.{$signature}"],
            'an empty header segment' => [".{$payload}.{$signature}"],
            'an empty signature segment' => ["{$header}.{$payload}."],
            'a padded signature segment' => ["{$header}.{$payload}.{$signature}=="],
            'a header segment of an impossible length' => ["{$header}A.{$payload}.{$signature}"],
            'a header segment that is not base64url' => ["a+b.{$payload}.{$signature}"],
            'a header that is not JSON' => [JWT::urlsafeB64Encode('nonsense') . ".{$payload}.{$signature}"],
            'a header that is a JSON array' => [JWT::urlsafeB64Encode('[1,2,3]') . ".{$payload}.{$signature}"],
            'a header that is a JSON string' => [JWT::urlsafeB64Encode('"HS256"') . ".{$payload}.{$signature}"],
            'a header naming alg twice' => [
                JWT::urlsafeB64Encode('{"alg":"HS256","alg":"none"}') . ".{$payload}.{$signature}",
            ],
            'a header naming kid twice' => [
                JWT::urlsafeB64Encode('{"alg":"HS256","kid":"a","kid":"b"}') . ".{$payload}.{$signature}",
            ],
            'a token past the length limit' => [
                str_repeat('a', JoseHeader::MAXIMUM_TOKEN_LENGTH) . ".{$payload}.{$signature}",
            ],
            'a header segment past its own length limit' => [
                str_repeat('a', JoseHeader::MAXIMUM_HEADER_SEGMENT_LENGTH + 1) . ".{$payload}.{$signature}",
            ],
            'a non-canonical header spelling' => [
                self::withUnusedBitsSet($spacedHeader) . ".{$payload}.{$signature}",
            ],
            'a non-canonical signature spelling' => [
                "{$spacedHeader}.{$payload}." . self::withUnusedBitsSet($longSignature),
            ],
        ];
    }

    /**
     * Rewrites a base64url string into a second spelling of the same
     * bytes — see Base64Url.
     */
    private static function withUnusedBitsSet(string $base64Url): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $index = strpos($alphabet, $base64Url[strlen($base64Url) - 1]);
        self::assertIsInt($index);
        $tampered = substr($base64Url, 0, -1) . $alphabet[$index + 1];

        self::assertSame(
            base64_decode(strtr($base64Url, '-_', '+/')),
            base64_decode(strtr($tampered, '-_', '+/')),
        );

        return $tampered;
    }

    #[DataProvider('malformedCompactTokens')]
    public function test_a_malformed_compact_token_is_rejected_without_reaching_the_handler(string $token): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);

        $this->assertRejectedWithoutTouchingTheRequest($middleware, $scope, $token);
    }

    /**
     * A single-key middleware never reads `kid`: JWT::decode() returns
     * the one key it holds before the lookup that would use it. A
     * malformed `kid` is refused anyway.
     */
    public function test_a_single_key_middleware_refuses_a_malformed_kid_it_would_never_read(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::keys(), $scope);

        $this->assertRejectedWithoutTouchingTheRequest(
            $middleware,
            $scope,
            $this->tokenWithHeader(['alg' => 'HS256', 'kid' => ['current']]),
        );
    }

    /**
     * firebase/php-jwt reads neither `crit` nor `b64`, so each of these
     * tokens decodes there ignoring its declaration; the test asserts
     * that before requiring this package's boundary to refuse it.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unimplementedProtectedHeaders(): array
    {
        return [
            'crit naming an extension' => [['crit' => ['http://example.test/exp']]],
            'crit naming b64' => [['crit' => ['b64'], 'b64' => false]],
            'an empty crit' => [['crit' => []]],
            'crit that is not a list' => [['crit' => 'b64']],
            'b64 false without crit' => [['b64' => false]],
            'b64 true without crit' => [['b64' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('unimplementedProtectedHeaders')]
    public function test_a_header_declaring_semantics_this_package_does_not_implement_is_refused(
        array $header,
    ): void {
        $token = $this->tokenWithHeader($header + ['alg' => 'HS256']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        self::assertSame('user-42', $claims->sub);

        $scope = $this->scope();

        $this->assertRejectedWithoutTouchingTheRequest(new JwtAuthMiddleware(self::keys(), $scope), $scope, $token);
    }

    /**
     * The ordinary case: a token whose header names a supported
     * algorithm and an ordinary kid verifies against the key that kid
     * selects.
     */
    public function test_an_ordinary_kid_still_verifies_through_the_header_boundary(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            $scope,
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }
}
