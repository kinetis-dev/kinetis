<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Http;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Http\BroadcastAuthController;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\RevoltHttpClient\Http;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * BroadcastAuthController::formData()'s raw application/x-www-form-urlencoded
 * fallback — reachable only when getParsedBody() is empty, which
 * BroadcastAuthControllerTest's own withParsedBody()-built requests
 * never exercise — driven through a real Kernel, the same MaxBodySizeMiddleware
 * every other route runs behind. authorizeLobby() (matched via the
 * private-lobby channel, stripped to "lobby") needs no CurrentUserInterface,
 * which keeps these regressions focused on the body-size boundary rather
 * than authentication wiring.
 */
final class BroadcastAuthKernelTest extends TestCase
{
    private const string KEY = 'testkey';

    private const string SECRET = 'testsecret';

    /**
     * @param array<string, string> $config
     */
    private function kernel(array $config = []): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));

        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(
            BroadcasterInterface::class,
            new PusherBroadcaster(new Http(new MockHttpClient()), '12345', self::KEY, self::SECRET),
        );
        $app->boot();

        $router = new Router();
        $router->register(BroadcastAuthController::class);

        return new Kernel($app, $router);
    }

    /**
     * No Content-Length header at all — the declared-header fast path
     * in MaxBodySizeMiddleware can't catch this; only the actual bytes
     * read through formData()'s fallback can.
     */
    public function test_an_oversized_raw_form_body_with_no_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = http_build_query([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-lobby',
            'padding' => str_repeat('x', 200),
        ]);
        self::assertGreaterThan(50, strlen($body));

        $response = $kernel->handle(new ServerRequest('POST', '/broadcasting/auth', body: $body));

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * A Content-Length header present but understating the real body
     * size below the configured cap — the fast path passes this
     * through, so only the backstop closes it.
     */
    public function test_an_oversized_raw_form_body_with_an_understated_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = http_build_query([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-lobby',
            'padding' => str_repeat('x', 200),
        ]);
        self::assertGreaterThan(50, strlen($body));

        $response = $kernel->handle(new ServerRequest(
            'POST',
            '/broadcasting/auth',
            headers: ['Content-Length' => '10'],
            body: $body,
        ));

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * The control: a genuinely small raw form body, under the same
     * configured cap, still reaches formData()'s fallback, parses
     * correctly, and authorizes the channel normally — the fix closes a
     * real gap without breaking the fallback path itself.
     */
    public function test_a_small_raw_form_body_under_the_configured_limit_still_parses_and_authorizes(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = http_build_query([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-lobby',
        ]);
        self::assertLessThanOrEqual(50, strlen($body));

        $response = $kernel->handle(new ServerRequest('POST', '/broadcasting/auth', body: $body));

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('auth', $decoded);
    }
}
