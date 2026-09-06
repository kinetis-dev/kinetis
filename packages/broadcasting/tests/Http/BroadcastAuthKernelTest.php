<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Http;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Http\BroadcastAuthController;
use Kinetis\Broadcasting\Http\BroadcastOriginMiddleware;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\RevoltHttpClient\Http;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The body boundary this endpoint sits behind, driven through a real
 * Kernel: RequestBodyMiddleware bounds the bytes and decides, from the
 * content type, whether they are form fields at all, and the controller
 * reads what that leaves in getParsedBody() and nothing else.
 *
 * authorizeLobby() (matched via the private-lobby channel, stripped to
 * "lobby") needs no CurrentUserInterface, which keeps these cases on the
 * body boundary rather than on authentication wiring.
 */
final class BroadcastAuthKernelTest extends TestCase
{
    private const string KEY = 'testkey';

    private const string SECRET = 'testsecret';

    private const string FORM = 'application/x-www-form-urlencoded';

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

        // The group BroadcastAuthController references, exactly as an
        // install of this package supplies it: its one permanent member
        // and nothing else, which is all an anonymous authorizer needs.
        return new Kernel($app, $router, middlewareGroups: ['broadcasting' => [BroadcastOriginMiddleware::class]]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $body,
        array $headers = ['Content-Type' => self::FORM],
    ): ServerRequest {
        return new ServerRequest('POST', '/broadcasting/auth', headers: $headers, body: $body);
    }

    private function lobbyForm(int $padding = 0): string
    {
        $fields = ['socket_id' => '1234.1234', 'channel_name' => 'private-lobby'];

        if ($padding > 0) {
            $fields['padding'] = str_repeat('x', $padding);
        }

        return http_build_query($fields);
    }

    /**
     * The ordinary client request: pusher-js posts socket_id and
     * channel_name as application/x-www-form-urlencoded,
     * RequestBodyMiddleware parses them into getParsedBody(), and the
     * channel authorizes with a signed response.
     */
    public function test_a_form_encoded_body_authorizes_through_the_request_body_middleware(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = $this->lobbyForm();
        self::assertLessThanOrEqual(50, strlen($body));

        $response = $kernel->handle($this->request($body));

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertSame(
            self::KEY . ':' . hash_hmac('sha256', '1234.1234:private-lobby', self::SECRET),
            $decoded['auth'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function nonFormContentTypes(): iterable
    {
        yield 'text/plain' => [['Content-Type' => 'text/plain']];
        yield 'application/json' => [['Content-Type' => 'application/json']];
        yield 'no content type at all' => [[]];
    }

    /**
     * The media type is what makes bytes fields. Under anything but a
     * form one, RequestBodyMiddleware leaves the identical bytes
     * unparsed, so the endpoint has nothing to read and answers with the
     * required-fields 422 instead of authorizing a channel named by a
     * body no one declared as form input.
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('nonFormContentTypes')]
    public function test_form_looking_bytes_under_a_non_form_content_type_are_not_read_as_fields(array $headers): void
    {
        $response = $this->kernel()->handle($this->request($this->lobbyForm(), $headers));

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * No Content-Length header at all — the declared-length check in
     * RequestBodyMiddleware cannot catch this; only the byte count it
     * takes while staging the body can, and it takes that before this
     * controller runs at all.
     */
    public function test_an_oversized_form_body_with_no_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = $this->lobbyForm(padding: 200);
        self::assertGreaterThan(50, strlen($body));

        $response = $kernel->handle($this->request($body));

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * A Content-Length header present but understating the real body
     * size below the configured cap — the fast path passes this
     * through, so only the backstop closes it.
     */
    public function test_an_oversized_form_body_with_an_understated_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->kernel(['MAX_BODY_SIZE' => '50']);

        $body = $this->lobbyForm(padding: 200);
        self::assertGreaterThan(50, strlen($body));

        $response = $kernel->handle($this->request($body, [
            'Content-Type' => self::FORM,
            'Content-Length' => '10',
        ]));

        self::assertSame(413, $response->getStatusCode());
    }
}
