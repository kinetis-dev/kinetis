<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\FixtureJwtAuthMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\ProtectedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class JwtAuthMiddlewareTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

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
        $middleware = new JwtAuthMiddleware(self::SECRET, $scope);
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

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
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time() - 10], self::SECRET, 'HS256');
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
}
