<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\DualBindingFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\GroupedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\GroupedJwtAuthMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\ProtectedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\RecordingSimpleCache;
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

/**
 * The transport around the decision: which `Authorization` headers this
 * middleware will read, the one 401 it answers with, the two ids it
 * publishes the authenticated user under, and how it is resolved from a
 * real request scope. Every cryptographic and claim rule belongs to
 * JwtAuthenticator and is proven in its own suite.
 */
final class JwtAuthMiddlewareTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    private static function authenticator(): JwtAuthenticator
    {
        return new JwtAuthenticator(JwtVerificationKeys::hmacSecret(self::SECRET));
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

    /**
     * An AppScope carrying one configured JwtAuthenticator — the whole
     * application-side wiring this middleware needs, since request scope
     * autowires it from there and supplies itself.
     */
    private function appWithAuthenticator(JwtAuthenticator $authenticator): AppScope
    {
        $app = new AppScope();
        $app->instance(JwtAuthenticator::class, $authenticator);
        $app->boot();

        return $app;
    }

    public function test_a_valid_token_registers_the_resolved_user_and_passes_through(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::authenticator(), $scope);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $middleware->process($this->requestWithToken($token), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_lowercase_scheme_is_accepted(): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::authenticator(), $scope);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "bearer {$token}"]);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_missing_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_bearer_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_empty_bearer_token_is_rejected_with_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer ']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A well-formed header carrying a token the authenticator refuses is
     * the same 401 as a header that never parsed — the middleware has
     * exactly one failure shape, and this is the branch that reaches it
     * from a null the authenticator answered rather than the parser.
     */
    public function test_a_token_the_authenticator_rejects_is_answered_with_the_same_401(): void
    {
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());
        $foreignToken = new JwtIssuer(
            JwtSigningKey::hmacSecret('a-completely-different-secret-key-of-sufficient-length'),
        )->issue('user-42');

        $response = $middleware->process($this->requestWithToken($foreignToken), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getBody(), true));
    }

    public function test_the_inner_handler_never_runs_when_unauthenticated(): void
    {
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());
        $calls = 0;

        $middleware->process(new ServerRequest('GET', '/'), $this->countingHandler($calls));

        self::assertSame(0, $calls);
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
        $middleware = new JwtAuthMiddleware(self::authenticator(), $scope);

        $middleware->process(new ServerRequest('GET', '/dual'), $this->handler());

        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    /**
     * Referenced straight as #[Middleware(JwtAuthMiddleware::class)]:
     * the request scope autowires it from the one JwtAuthenticator on
     * AppScope plus itself, with no subclass and no AppScope binding for
     * the middleware.
     */
    public function test_works_as_route_middleware_through_a_real_kernel(): void
    {
        $app = $this->appWithAuthenticator(self::authenticator());

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $unauthenticated = $kernel->handle(new ServerRequest('GET', '/me'));
        $authenticated = $kernel->handle(
            new ServerRequest('GET', '/me', headers: ['Authorization' => "Bearer {$token}"]),
        );

        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame(200, $authenticated->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $authenticated->getBody(), true));
    }

    public function test_a_lowercase_scheme_works_through_a_real_kernel(): void
    {
        $app = $this->appWithAuthenticator(self::authenticator());

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $response = $kernel->handle(new ServerRequest('GET', '/me', headers: ['Authorization' => "bearer {$token}"]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $response->getBody(), true));
    }

    /**
     * An otherwise empty subclass carrying #[AsMiddlewareGroup] — the
     * one supported reason this class is not final — resolves through
     * the request scope exactly as the base class does, inheriting the
     * same AppScope-registered authenticator.
     */
    public function test_an_empty_group_subclass_resolves_through_a_real_kernel(): void
    {
        $app = $this->appWithAuthenticator(self::authenticator());

        $router = new Router();
        $router->register(GroupedFixtureController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['jwt' => [GroupedJwtAuthMiddleware::class]]);

        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $unauthenticated = $kernel->handle(new ServerRequest('GET', '/grouped'));
        $authenticated = $kernel->handle(
            new ServerRequest('GET', '/grouped', headers: ['Authorization' => "Bearer {$token}"]),
        );

        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame(200, $authenticated->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $authenticated->getBody(), true));
    }

    /**
     * The registered authenticator is what carries policy: the identical
     * controller, middleware and token answer 401 once the AppScope
     * holds an authenticator that expects a different issuer.
     */
    public function test_the_registered_authenticator_decides_policy_through_a_real_kernel(): void
    {
        $app = $this->appWithAuthenticator(new JwtAuthenticator(
            JwtVerificationKeys::hmacSecret(self::SECRET),
            expectedIssuer: 'my-app',
        ));

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $matching = new JwtIssuer(self::signingKey(), issuer: 'my-app')->issue('user-42');
        $foreign = new JwtIssuer(self::signingKey(), issuer: 'someone-else')->issue('user-42');

        $accepted = $kernel->handle(
            new ServerRequest('GET', '/me', headers: ['Authorization' => "Bearer {$matching}"]),
        );
        $rejected = $kernel->handle(
            new ServerRequest('GET', '/me', headers: ['Authorization' => "Bearer {$foreign}"]),
        );

        self::assertSame(200, $accepted->getStatusCode());
        self::assertSame(401, $rejected->getStatusCode());
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
        $app = $this->appWithAuthenticator(self::authenticator());

        $router = new Router();
        $router->register(DualBindingFixtureController::class);
        $kernel = new Kernel($app, $router);

        $token = new JwtIssuer(self::signingKey())->issue('user-42', ['role' => 'admin']);

        $authenticated = $kernel->handle(
            new ServerRequest('GET', '/dual', headers: ['Authorization' => "Bearer {$token}"]),
        );

        self::assertSame(200, $authenticated->getStatusCode());

        /** @var array{sameInstance: bool, role: string, jti: string} $body */
        $body = json_decode((string) $authenticated->getBody(), true);

        self::assertTrue($body['sameInstance']);
        self::assertSame('admin', $body['role']);
        self::assertNotSame('', $body['jti']);
    }

    /**
     * A concise grammar matrix run through the real process() entry
     * point — the same shape of matrix core's own
     * AuthorizationToken68ParserTest already proves at the parser level,
     * and the same cases kinetis/auth's own BearerAuthMiddlewareTest
     * proves for its middleware, run here to prove this middleware also
     * actually calls the parser with the `Bearer` scheme and acts on its
     * result correctly. Accept cases use a real, validly-signed JWT
     * (JwtAuthenticator itself would reject an arbitrary opaque string),
     * so unlike the Bearer package's matrix, the credential can't be a
     * fixed literal — the accept/reject expectation is what's shared.
     * Leading/trailing whitespace around the header value is omitted for
     * the identical reason kinetis/auth's own matrix omits it:
     * nyholm/psr7 already strips it when a header is set, confirmed
     * directly, so it's unreachable through a real request built through
     * it.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function grammarMatrix(): iterable
    {
        $validToken = new JwtIssuer(self::signingKey())->issue('user-42');
        // A real, validly-signed, but oversized token —
        // JwtIssuer::issue() itself, not a synthetic long string, so
        // this both proves "very long is accepted" and reuses the
        // exact code path every other accept case in this matrix does.
        $longToken = new JwtIssuer(self::signingKey())->issue('user-42', ['padding' => str_repeat('a', 8000)]);

        yield 'multiple SP separator' => ["Bearer  {$validToken}", true];
        yield 'tab separator' => ["Bearer\t{$validToken}", false];
        yield 'embedded whitespace within credential' => ['Bearer abc def', false];
        yield 'comma in credential' => ["Bearer {$validToken},x", false];
        yield 'illegal leading padding' => ["Bearer ={$validToken}", false];
        yield 'a Token scheme this middleware does not accept' => ["Token {$validToken}", false];
        yield 'very long valid credential' => ["Bearer {$longToken}", true];
    }

    #[DataProvider('grammarMatrix')]
    public function test_the_grammar_matrix_authenticates_or_rejects_correctly(string $headerValue, bool $accepted): void
    {
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(self::authenticator(), $scope);

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
     * Two separate Authorization header lines are rejected —
     * the same ambiguity core's own AuthorizationToken68ParserTest
     * proves at the parser level, proven here through the real
     * middleware, with a real, validly-signed token on both lines (so a
     * failure here can only be the duplicate-header rule itself, not an
     * unrelated signature problem).
     */
    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');
        $middleware = new JwtAuthMiddleware(self::authenticator(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]);
        $request = $request->withAddedHeader('Authorization', "Bearer {$token}");

        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A malformed header (here, a tab separator) must be rejected before
     * the authenticator is reached at all — proven observably rather
     * than by mocking it: zero revocation cache lookups, neither binding
     * registered on the scope, and the inner handler never invoked.
     */
    public function test_a_malformed_header_never_reaches_the_authenticator_scope_or_the_handler(): void
    {
        $cache = new RecordingSimpleCache();
        $scope = $this->scope();
        $middleware = new JwtAuthMiddleware(
            new JwtAuthenticator(
                JwtVerificationKeys::hmacSecret(self::SECRET),
                revocationStore: new RevocationStore($cache),
            ),
            $scope,
        );
        $calls = 0;
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer\t{$token}"]);
        $response = $middleware->process($request, $this->countingHandler($calls));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame([], $cache->getCalls);
        self::assertFalse($scope->isRegistered(CurrentUserInterface::class));
        self::assertFalse($scope->isRegistered(JwtUser::class));
    }

    private function countingHandler(int &$calls): CallableRequestHandler
    {
        return new CallableRequestHandler(static function () use (&$calls): Response {
            ++$calls;

            return new Response(200);
        });
    }
}
