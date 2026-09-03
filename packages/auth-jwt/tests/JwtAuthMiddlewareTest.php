<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use ErrorException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtAuthMiddlewareException;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtUser;
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
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
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

    /**
     * Hand-crafts a validly-signed token straight from a raw payload,
     * bypassing JWT::encode()'s own numeric-only validation on iat/exp/
     * nbf — needed to exercise a claim shape (a boolean iat, for one)
     * the library itself would otherwise refuse to encode at all. Some
     * of these cases are still independently caught by JWT::decode()'s
     * own identical numeric check one layer earlier than
     * JwtAuthMiddleware's own strict gate; that's fine — the point is
     * proving the token is rejected end to end, regardless of which
     * layer is responsible.
     *
     * @param array<string, mixed> $payload
     */
    private function rawToken(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            JWT::urlsafeB64Encode((string) JWT::jsonEncode($header)),
            JWT::urlsafeB64Encode((string) JWT::jsonEncode($payload)),
        ];
        $segments[] = JWT::urlsafeB64Encode(hash_hmac('sha256', implode('.', $segments), self::SECRET, true));

        return implode('.', $segments);
    }

    public function test_a_valid_token_registers_the_resolved_user_and_passes_through(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_lowercase_scheme_is_accepted(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "bearer {$token}"]);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_missing_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_bearer_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_empty_bearer_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer ']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_malformed_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());

        $response = $middleware->process($this->requestWithToken('not-a-jwt-at-all'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_signed_with_a_different_key_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
        $token = new JwtIssuer('a-completely-different-secret-key-of-sufficient-length')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_expired_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
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
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
        $token = JWT::encode(['iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_the_inner_handler_never_runs_when_unauthenticated(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
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

        $token = new JwtIssuer(FixtureJwtAuthMiddleware::SECRET)->issue('user-42');

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

        $token = new JwtIssuer(FixtureJwtAuthMiddleware::SECRET)->issue('user-42');

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

        $token = new JwtIssuer(FixtureJwtAuthMiddleware::SECRET)->issue('user-42', ['role' => 'admin']);

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope);

        $middleware->process(new ServerRequest('GET', '/dual'), $this->handler());

        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    public function test_a_revoked_token_is_rejected_with_401(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_non_revoked_token_still_passes_through_when_a_revocation_store_is_configured(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_revoking_one_token_does_not_reject_a_different_one(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        $revoked = new JwtIssuer(self::SECRET)->issue('user-42');
        $claims = JWT::decode($revoked, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        $stillValid = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($stillValid), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_revoke_all_for_user_rejects_a_token_issued_before_the_call(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        // Built with an explicit past `iat`, not JwtIssuer's own time() —
        // issuing then immediately revoking-all could otherwise land both
        // calls in the same wall-clock second, making iat < cutoff false.
        // Carries a real jti too — without one, the strict claim gate
        // below would reject this token before ever reaching the
        // per-user cutoff check this test exists to exercise.
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time() - 10, 'jti' => 'some-jti'], self::SECRET, 'HS256');
        $revocationStore->revokeAllForUser('user-42', 60);

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_revoke_all_for_user_does_not_reject_a_token_issued_after_the_call(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        $revocationStore->revokeAllForUser('user-42', 60);
        // A fresh login well after "log out everywhere" must still work.
        // Can't pin this with an explicit future `iat` the way the
        // "before" test above pins a past one — firebase/php-jwt itself
        // rejects a token whose iat is ahead of the current time
        // (BeforeValidException), confirmed directly rather than
        // assumed. And two back-to-back real time() calls with no gap
        // could legitimately land in the same wall-clock second, which
        // would now make this token *revoked* instead (isRevokedForUser()
        // uses <=, closing the same-second bypass a strict < left open) —
        // flipping this test's own expected outcome depending on timing.
        // A real 1-second sleep between the two calls deterministically
        // guarantees the token's own real iat lands in a later second
        // than the cutoff, not just usually.
        sleep(1);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_revoke_all_for_user_does_not_reject_a_different_users_token(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        $revocationStore->revokeAllForUser('user-42', 60);
        $token = new JwtIssuer(self::SECRET)->issue('user-99');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function malformedRevocationClaims(): iterable
    {
        yield 'missing iat' => [null, 'the-jti'];
        yield 'missing jti' => [null, null]; // null iat here means "omit the claim entirely" too — see the test body.
        yield 'numeric string iat' => ['numeric-string', 'the-jti'];
        yield 'fractional iat' => ['fractional', 'the-jti'];
        yield 'exponent string iat' => ['exponent-string', 'the-jti'];
        yield 'boolean iat' => ['boolean', 'the-jti'];
        yield 'empty jti' => ['valid', ''];
        yield 'non-string jti' => ['valid', 12345];
    }

    /**
     * A revocation store is configured but the token itself is malformed
     * on iat and/or jti — every case here must be rejected outright, not
     * silently reduced to just the one check the well-formed claim would
     * still support. $iatMode is a marker for a value that can't be
     * expressed as a plain data-provider literal, resolved in the test
     * body. Built via rawToken() rather than JwtIssuer/JWT::encode(), so
     * every case reaches a real, validly-signed token regardless of
     * whether the underlying library would have refused to encode it.
     */
    #[DataProvider('malformedRevocationClaims')]
    public function test_a_token_with_malformed_iat_or_jti_is_rejected_when_a_revocation_store_is_configured(
        ?string $iatMode,
        mixed $jti,
    ): void {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        $claims = ['sub' => 'user-42'];

        $iat = match ($iatMode) {
            null => null,
            'numeric-string' => (string) time(),
            // A genuine fraction, not merely a float type — an
            // integer-valued float (e.g. (float) time()) loses its
            // float-ness on the JSON round trip PHP performs here
            // (json_encode(1787669098.0) emits the bare integer
            // 1787669098, indistinguishable from int on decode), so it
            // would not actually exercise this case at all.
            'fractional' => time() + 0.5,
            'exponent-string' => '1e10',
            'boolean' => true,
            'valid' => time(),
        };

        if ($iat !== null) {
            $claims['iat'] = $iat;
        }

        if ($jti !== null) {
            $claims['jti'] = $jti;
        }

        $token = $this->rawToken($claims);

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The strict claim gate must reject a malformed token before either
     * revocation lookup ever runs — proven against a cache that records
     * every get() call, not just inferred from the 401 status.
     */
    public function test_a_malformed_claim_never_reaches_either_revocation_lookup(): void
    {
        $cache = new RecordingSimpleCache();
        $revocationStore = new RevocationStore($cache);
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), revocationStore: $revocationStore);

        // A valid iat but no jti at all — under independent per-claim
        // checks this would still have run the per-user cutoff lookup;
        // the combined gate must reject before either lookup runs.
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $cache->getCalls);
    }

    /**
     * The strict claim gate through a real Kernel request, not just the
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
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_rs256_verifies_a_token_signed_with_the_matching_private_key(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(RsaKeyPair::PUBLIC_KEY, $scope, algorithm: 'RS256');
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_rs256_rejects_a_token_that_was_never_signed_with_the_private_key(): void
    {
        $middleware = new JwtAuthMiddleware(RsaKeyPair::PUBLIC_KEY, $this->scope(), algorithm: 'RS256');
        // Signed with an HS256 secret, not the RSA private key — the
        // middleware only ever tries to verify as RS256 against the
        // configured public key, so this must fail, not silently
        // "succeed" under a different algorithm.
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_multi_key_map_verifies_a_token_signed_under_a_matching_kid(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(['current' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')], $scope);
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'current')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_multi_key_map_rejects_a_token_with_an_unrecognized_kid(): void
    {
        $middleware = new JwtAuthMiddleware(['current' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')], $this->scope());
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'retired')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_the_matching_issuer_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::SECRET, issuer: 'my-app')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_no_iss_is_rejected_when_an_issuer_is_expected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), expectedIssuer: 'my-app');
        // No issuer configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_the_wrong_issuer_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::SECRET, issuer: 'someone-elses-app')->issue('user-42');

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), expectedIssuer: 'my-app');
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'iss' => $iss], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_a_matching_string_audience_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        $token = new JwtIssuer(self::SECRET, audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_a_matching_list_audience_passes_through(): void
    {
        // Any-match semantics: only one of the token's own audiences
        // needs to be present in the accepted list.
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-b']);
        $token = new JwtIssuer(self::SECRET, audience: ['svc-a', 'svc-b'])->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_token_with_no_matching_audience_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        $token = new JwtIssuer(self::SECRET, audience: 'svc-c')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_no_aud_is_rejected_when_audiences_are_expected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        // No audience configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_an_empty_string_audience_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => ''], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_an_empty_audience_list_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => []], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_with_a_mixed_type_audience_list_is_rejected(): void
    {
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => ['svc-a', 123]], self::SECRET, 'HS256');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_both_issuer_and_audience_matching_passes_through(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::SECRET,
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::SECRET, issuer: 'my-app', audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_matching_issuer_alone_is_not_enough_when_audience_is_also_required(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::SECRET,
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::SECRET, issuer: 'my-app', audience: 'svc-wrong')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_matching_audience_alone_is_not_enough_when_issuer_is_also_required(): void
    {
        $middleware = new JwtAuthMiddleware(
            self::SECRET,
            $this->scope(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::SECRET, issuer: 'someone-else', audience: 'svc-a')->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_no_issuer_or_audience_constraint_by_default_accepts_a_token_carrying_either_claim(): void
    {
        // Backward compatible: a token carrying real iss/aud claims still
        // passes through when neither constraint is configured on this
        // middleware.
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());
        $token = new JwtIssuer(self::SECRET, issuer: 'my-app', audience: 'svc-a')->issue('user-42');

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
            self::SECRET,
            $scope,
            revocationStore: $revocationStore,
            expectedIssuer: 'my-app',
        );
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });
        $token = new JwtIssuer(self::SECRET, issuer: 'someone-else')->issue('user-42');

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
    public function test_a_multi_key_map_still_enforces_issuer_after_verifying_under_the_matching_kid(): void
    {
        $middleware = new JwtAuthMiddleware(
            ['current' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')],
            $this->scope(),
            expectedIssuer: 'my-app',
        );
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'current', issuer: 'someone-else')
            ->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_multi_key_map_passes_through_when_kid_and_issuer_both_match(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            ['current' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')],
            $scope,
            expectedIssuer: 'my-app',
        );
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'current', issuer: 'my-app')
            ->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_construction_throws_when_expected_issuer_is_an_empty_string(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), expectedIssuer: '');
    }

    public function test_construction_throws_when_accepted_audiences_is_an_empty_array(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: []);
    }

    public function test_construction_throws_when_accepted_audiences_contains_an_empty_string(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a', '']);
    }

    public function test_construction_throws_when_accepted_audiences_contains_a_non_string(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a', 123]);
    }

    /**
     * $acceptedAudiences is documented as list<string> — an associative
     * array doesn't match that shape, even though in_array()/
     * array_intersect() would happen to still work on it at runtime.
     */
    public function test_construction_throws_when_accepted_audiences_is_an_associative_array(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['primary' => 'svc-a']);
    }

    public function test_construction_throws_when_accepted_audiences_is_a_sparse_numeric_array(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: [0 => 'svc-a', 2 => 'svc-b']);
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
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: $acceptedAudiences);
        $token = new JwtIssuer(self::SECRET, audience: $issuedAudience)->issue('user-42');

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope(), acceptedAudiences: ['svc-a']);
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

        $validToken = new JwtIssuer(
            IssuerAudienceCheckingFixtureMiddleware::SECRET,
            issuer: IssuerAudienceCheckingFixtureMiddleware::ISSUER,
            audience: IssuerAudienceCheckingFixtureMiddleware::AUDIENCE,
        )->issue('user-42');

        $wrongIssuerToken = new JwtIssuer(
            IssuerAudienceCheckingFixtureMiddleware::SECRET,
            issuer: 'someone-else',
            audience: IssuerAudienceCheckingFixtureMiddleware::AUDIENCE,
        )->issue('user-42');

        $noClaimsToken = new JwtIssuer(IssuerAudienceCheckingFixtureMiddleware::SECRET)->issue('user-42');

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
        $validToken = new JwtIssuer(self::SECRET)->issue('user-42');
        // A real, validly-signed, but deliberately oversized token —
        // JwtIssuer::issue() itself, not a synthetic long string, so
        // this both proves "very long is accepted" and reuses the
        // exact code path every other accept case in this matrix does.
        $longToken = new JwtIssuer(self::SECRET)->issue('user-42', ['padding' => str_repeat('a', 8000)]);

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope);

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
        $token = new JwtIssuer(self::SECRET)->issue('user-42');
        $middleware = new JwtAuthMiddleware(self::SECRET, $this->scope());

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope, revocationStore: $revocationStore);
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer\t{$token}"]);
        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame([], $cache->getCalls);
        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    // --- Cryptographic configuration, validated at construction ---
    //
    // Every test in this section proves the failure happens at
    // construction — new JwtAuthMiddleware(...) itself throws — not that
    // a later process() call happens to 401. A misconfigured middleware
    // can never be built at all, so it can never become a live 401 loop
    // masking the real, server-side mistake.

    public function test_construction_throws_for_an_unsupported_algorithm(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(self::LONG_SECRET, $this->scope(), algorithm: 'ES256');
    }

    public function test_construction_throws_for_a_too_short_hmac_secret(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(str_repeat('a', 16), $this->scope());
    }

    public function test_construction_throws_for_an_empty_hmac_secret(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware('', $this->scope());
    }

    public function test_construction_throws_for_a_malformed_rsa_public_key(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware('not a real pem', $this->scope(), algorithm: 'RS256');
    }

    public function test_construction_throws_for_an_undersized_rsa_public_key(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(UndersizedRsaKeyPair::PUBLIC_KEY, $this->scope(), algorithm: 'RS256');
    }

    public function test_construction_throws_when_an_rsa_private_key_is_given_as_the_public_key(): void
    {
        // A real, valid, correctly-sized RSA key — just the wrong half.
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(RsaKeyPair::PRIVATE_KEY, $this->scope(), algorithm: 'RS256');
    }

    public function test_construction_throws_for_an_empty_key_map(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware([], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_with_a_non_string_kid(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware([0 => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_with_an_empty_string_kid(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(['' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_entry_that_is_not_a_key_instance(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(['current' => 'not-a-key-object'], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_entry_with_an_unsupported_algorithm(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(['current' => new Key(self::LONG_SECRET, 'ES256')], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_entry_with_a_too_short_hmac_secret(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(['current' => new Key(str_repeat('a', 16), 'HS256')], $this->scope());
    }

    public function test_construction_throws_for_a_key_map_entry_with_an_undersized_rsa_key(): void
    {
        $this->expectException(JwtAuthMiddlewareException::class);

        new JwtAuthMiddleware(
            ['current' => new Key(UndersizedRsaKeyPair::PUBLIC_KEY, 'RS256')],
            $this->scope(),
        );
    }

    /**
     * The exact scenario a PHP warning-to-exception error handler
     * (a real, legitimate application pattern) could otherwise let leak
     * through as an unrelated exception: a Key wrapping an already-
     * parsed *private* OpenSSLAsymmetricKey object, handed in where the
     * key map only ever needs a public one. Confirmed this construction
     * ends in JwtAuthMiddlewareException specifically, not some other
     * exception type a leaked PHP warning would produce, even under
     * that adversarial handler.
     */
    public function test_construction_throws_cleanly_for_a_key_map_entry_wrapping_a_private_key_object(): void
    {
        $privateKeyObject = openssl_pkey_get_private(RsaKeyPair::PRIVATE_KEY);
        self::assertNotFalse($privateKeyObject);

        set_error_handler(static function (int $errno, string $errstr): never {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $this->expectException(JwtAuthMiddlewareException::class);

            new JwtAuthMiddleware(
                ['current' => new Key($privateKeyObject, 'RS256')],
                $this->scope(),
            );
        } finally {
            restore_error_handler();
        }
    }

    /**
     * $algorithm has no effect at all once $key is an array — a
     * nonsensical top-level $algorithm must not make construction fail,
     * since it's never even read in that case (see the class docblock).
     */
    public function test_an_unsupported_top_level_algorithm_is_ignored_when_a_key_map_is_given(): void
    {
        $middleware = new JwtAuthMiddleware(
            ['current' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256')],
            $this->scope(),
            algorithm: 'this-is-not-a-real-algorithm',
        );

        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'current')->issue('user-42');
        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
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
        $middleware = new JwtAuthMiddleware(self::LONG_SECRET, $scope, algorithm: $algorithm);
        $token = new JwtIssuer(self::LONG_SECRET, algorithm: $algorithm)->issue('user-42');

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
        $middleware = new JwtAuthMiddleware(RsaKeyPair::PUBLIC_KEY, $scope, algorithm: $algorithm);
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: $algorithm)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    /**
     * A valid multi-key map, mixing an HMAC and an RSA entry under
     * different kids — each entry's own algorithm and key material
     * validated independently at construction, and each still correctly
     * selectable and verifiable at request time.
     */
    public function test_a_valid_multi_key_map_with_mixed_algorithms_verifies_either_kid(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            [
                'hmac-key' => new Key(self::LONG_SECRET, 'HS256'),
                'rsa-key' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256'),
            ],
            $scope,
        );

        $hmacToken = new JwtIssuer(self::LONG_SECRET, algorithm: 'HS256', kid: 'hmac-key')->issue('user-42');
        $rsaToken = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: 'rsa-key')->issue('user-99');

        $hmacResponse = $middleware->process($this->requestWithToken($hmacToken), $this->handler());
        self::assertSame(200, $hmacResponse->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());

        $rsaScope = $this->scope();
        $rsaMiddleware = new JwtAuthMiddleware(
            [
                'hmac-key' => new Key(self::LONG_SECRET, 'HS256'),
                'rsa-key' => new Key(RsaKeyPair::PUBLIC_KEY, 'RS256'),
            ],
            $rsaScope,
        );
        $rsaResponse = $rsaMiddleware->process($this->requestWithToken($rsaToken), $this->handler());
        self::assertSame(200, $rsaResponse->getStatusCode());
        self::assertSame('user-99', $rsaScope->get(CurrentUserInterface::class)->id());
    }
}
