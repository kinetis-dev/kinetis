<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests;

use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Auth\Tests\Fixtures\FixtureUser;
use Kinetis\Auth\Tests\Fixtures\InMemoryUserProvider;
use Kinetis\Auth\Tests\Fixtures\ProtectedFixtureController;
use Kinetis\Auth\Tests\Fixtures\RecordingUserProvider;
use Kinetis\Auth\UserProviderInterface;
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

final class BearerAuthMiddlewareTest extends TestCase
{
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

    public function test_a_valid_token_registers_the_resolved_user_and_passes_through(): void
    {
        $scope = $this->scope();
        $users = new InMemoryUserProvider(['secret-token' => new FixtureUser('user-42')]);
        $middleware = new BearerAuthMiddleware($users, $scope);

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer secret-token']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_lowercase_scheme_is_accepted(): void
    {
        $scope = $this->scope();
        $users = new InMemoryUserProvider(['secret-token' => new FixtureUser('user-42')]);
        $middleware = new BearerAuthMiddleware($users, $scope);

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'bearer secret-token']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_missing_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new BearerAuthMiddleware(new InMemoryUserProvider(), $this->scope());

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_bearer_authorization_header_is_rejected_with_401(): void
    {
        $middleware = new BearerAuthMiddleware(new InMemoryUserProvider(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_empty_bearer_token_is_rejected_with_401(): void
    {
        $middleware = new BearerAuthMiddleware(new InMemoryUserProvider(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer ']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_token_unknown_to_the_user_provider_is_rejected_with_401(): void
    {
        $middleware = new BearerAuthMiddleware(new InMemoryUserProvider(), $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer nonexistent-token']);
        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_the_inner_handler_never_runs_when_unauthenticated(): void
    {
        $middleware = new BearerAuthMiddleware(new InMemoryUserProvider(), $this->scope());
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
        $app->instance(
            UserProviderInterface::class,
            new InMemoryUserProvider(['secret-token' => new FixtureUser('user-42')]),
        );
        $app->boot();

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $unauthenticated = $kernel->handle(new ServerRequest('GET', '/me'));
        $authenticated = $kernel->handle(new ServerRequest('GET', '/me', headers: ['Authorization' => 'Bearer secret-token']));

        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame(200, $authenticated->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $authenticated->getBody(), true));
    }

    public function test_a_lowercase_scheme_works_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->instance(
            UserProviderInterface::class,
            new InMemoryUserProvider(['secret-token' => new FixtureUser('user-42')]),
        );
        $app->boot();

        $router = new Router();
        $router->register(ProtectedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $response = $kernel->handle(new ServerRequest('GET', '/me', headers: ['Authorization' => 'bearer secret-token']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $response->getBody(), true));
    }

    /**
     * A concise grammar matrix run through the real process() entry
     * point — the same shape of matrix core's own
     * BearerCredentialParserTest already proves at the parser level, run
     * here to prove the middleware actually calls the parser and acts
     * on its result correctly, not just that the parser itself is
     * correct in isolation. $expectedCredential is the exact bytes
     * expected to reach the provider on success, or null when the
     * header must never reach the provider at all.
     *
     * Leading/trailing whitespace around the header value is
     * deliberately not one of these cases: nyholm/psr7 (used throughout
     * Kinetis) already strips it when a header is set, per RFC 9110's
     * own OWS-stripping guidance — confirmed directly, not assumed — so
     * a real request built through it can never carry that whitespace
     * as far as this parser. Core's own BearerCredentialParserTest
     * proves that case against the raw string, where it's actually
     * reachable.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function grammarMatrix(): iterable
    {
        yield 'multiple SP separator' => ['Bearer  secret-token', 'secret-token'];
        yield 'tab separator' => ["Bearer\tsecret-token", null];
        yield 'embedded whitespace within credential' => ['Bearer sec ret-token', null];
        yield 'comma in credential' => ['Bearer abc,def', null];
        yield 'illegal leading padding' => ['Bearer =secret-token', null];
        yield 'very long valid credential' => ['Bearer ' . str_repeat('a', 8192), str_repeat('a', 8192)];
    }

    #[DataProvider('grammarMatrix')]
    public function test_the_grammar_matrix_reaches_the_provider_with_exact_bytes_or_not_at_all(
        string $headerValue,
        ?string $expectedCredential,
    ): void {
        $users = new RecordingUserProvider([
            'secret-token' => new FixtureUser('user-42'),
            str_repeat('a', 8192) => new FixtureUser('user-42'),
        ]);
        $middleware = new BearerAuthMiddleware($users, $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => $headerValue]);
        $response = $middleware->process($request, $this->handler());

        if ($expectedCredential !== null) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame([$expectedCredential], $users->calls);
        } else {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame([], $users->calls);
        }
    }

    /**
     * Two genuinely separate Authorization header lines never reach the
     * provider at all — the same ambiguity core's own
     * BearerCredentialParserTest proves at the parser level, proven here
     * through the real middleware.
     */
    public function test_duplicate_authorization_headers_never_reach_the_provider(): void
    {
        $users = new RecordingUserProvider(['secret-token' => new FixtureUser('user-42')]);
        $middleware = new BearerAuthMiddleware($users, $this->scope());

        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer secret-token']);
        $request = $request->withAddedHeader('Authorization', 'Bearer secret-token');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $users->calls);
    }
}
