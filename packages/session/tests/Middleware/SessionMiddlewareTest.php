<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Middleware;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Tests\Fixtures\RecordingSessionStore;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where the middleware reads a session cookie from: `getCookieParams()`,
 * and nothing else. A runtime adapter fills that from the incoming
 * `Cookie` header, so a request carrying the header alone is an
 * incomplete one, and an incomplete request resolves no session.
 */
final class SessionMiddlewareTest extends TestCase
{
    public const string KNOWN_ID = '0123456789abcdef0123456789abcdef';

    private SessionStoreInterface $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->store = new RecordingSessionStore();
        $this->store->create(self::KNOWN_ID, ['remembered' => 'kept'], 60);
    }

    public function test_a_cookie_header_alone_is_not_a_session_cookie(): void
    {
        $request = new ServerRequest('GET', '/', ['Cookie' => 'kinetis_session=' . self::KNOWN_ID]);

        self::assertNull(
            $this->rememberedDuring($request),
            'a raw Cookie header no adapter has parsed must resolve no session.',
        );
    }

    public function test_the_cookie_params_an_adapter_populates_resolve_the_session(): void
    {
        $request = new ServerRequest('GET', '/', ['Cookie' => 'kinetis_session=' . self::KNOWN_ID])
            ->withCookieParams(['kinetis_session' => self::KNOWN_ID]);

        self::assertSame('kept', $this->rememberedDuring($request));
    }

    /**
     * The terminal rule at the HTTP boundary: an overlapping request
     * retired this id while the handler was running, so the commit is
     * discarded and the response claims no session cookie for a record
     * that is not there.
     */
    public function test_a_commit_refused_because_the_record_is_gone_emits_no_cookie(): void
    {
        $request = new ServerRequest('GET', '/')->withCookieParams(['kinetis_session' => self::KNOWN_ID]);
        $scope = new RequestScope(new AppScope());
        $store = $this->store;

        $handler = new class ($scope, $store) implements RequestHandlerInterface {
            public function __construct(private RequestScope $scope, private SessionStoreInterface $store) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var Session $session */
                $session = $this->scope->get(Session::class);
                $session->set('remembered', 'replaced');
                $this->store->destroy(SessionMiddlewareTest::KNOWN_ID);

                return new Response(204);
            }
        };

        $response = new SessionMiddleware($scope, $this->store, new Config(['SESSION_SECURE' => 'false']))
            ->process($request, $handler);

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertNull($this->store->read(self::KNOWN_ID), 'the discarded commit must not restore the record.');
    }

    /** What the handler sees in the session the middleware gave it. */
    private function rememberedDuring(ServerRequestInterface $request): mixed
    {
        $scope = new RequestScope(new AppScope());
        $remembered = null;

        $handler = new class ($scope, $remembered) implements RequestHandlerInterface {
            public function __construct(private RequestScope $scope, private mixed &$remembered) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var Session $session */
                $session = $this->scope->get(Session::class);
                $this->remembered = $session->get('remembered');

                return new Response(204);
            }
        };

        new SessionMiddleware($scope, $this->store, new Config(['SESSION_SECURE' => 'false']))
            ->process($request, $handler);

        return $remembered;
    }
}
