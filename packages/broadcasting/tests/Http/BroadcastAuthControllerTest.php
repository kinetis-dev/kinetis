<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Http;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Exception\BroadcastingException;
use Kinetis\Broadcasting\Http\BroadcastAuthController;
use Kinetis\Broadcasting\NullBroadcaster;
use Kinetis\Broadcasting\Tests\Fixtures\FakeCurrentUser;
use Kinetis\Broadcasting\Tests\Fixtures\MalformedPresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\NonEncodablePresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TeamPresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TrackedChannelAuthorizer;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\RevoltHttpClient\Http;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;

final class BroadcastAuthControllerTest extends TestCase
{
    private const string KEY = 'testkey';

    private const string SECRET = 'testsecret';

    public function test_a_private_channel_is_authorized_for_the_owning_user(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), OrderChannelAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orders.42',
        ]));

        self::assertIsArray($result);
        self::assertSame(
            'testkey:4fce56cd83fe76576b5c407b6cc3767efa892a518d2a972818de893f18a3fc2c',
            $result['auth'],
        );
    }

    public function test_a_private_channel_is_rejected_for_a_non_owning_user(): void
    {
        $scope = $this->scope(new FakeCurrentUser('999'), OrderChannelAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orders.42',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function test_a_presence_channel_returns_signed_auth_and_channel_data(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), TeamPresenceAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'presence-team.7',
        ]));

        self::assertIsArray($result);
        self::assertArrayHasKey('auth', $result);
        self::assertSame('{"user_id":"7"}', $result['channel_data']);

        // The signature must match one computed over exactly the
        // returned channel_data string, not a re-encoded copy of it.
        $expected = 'testkey:' . hash_hmac('sha256', '1234.1234:presence-team.7:' . $result['channel_data'], self::SECRET);
        self::assertSame($expected, $result['auth']);
    }

    /**
     * The authorizer's own return value, not the client's request, is
     * what's malformed here — never signed, treated the same as any
     * other "Not authorized" outcome.
     */
    public function test_a_presence_authorizer_returning_data_with_no_user_id_is_rejected_with_403(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), MalformedPresenceAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'presence-malformed.1',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    /**
     * A valid `user_id` alongside a value `json_encode()` itself cannot
     * encode (invalid UTF-8 here) must be rejected the same way as any
     * other malformed presence result — a `403`, never an uncaught
     * `JsonException` escaping as a `500`.
     */
    public function test_a_presence_authorizer_returning_non_encodable_data_is_rejected_with_403(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), NonEncodablePresenceAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'presence-nonencodable.1',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function test_no_current_user_registered_is_rejected_with_401(): void
    {
        $app = new AppScope();
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(BroadcasterInterface::class, $this->pusher());
        $app->boot();
        $scope = $app->createRequestScope();

        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orders.42',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(401, $result->getStatusCode());
    }

    public function test_an_unrecognized_channel_name_is_rejected_with_403(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), OrderChannelAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-unregistered.1',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function test_a_public_channel_name_is_rejected_with_422(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), OrderChannelAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'lobby',
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(422, $result->getStatusCode());
    }

    public function test_missing_fields_are_rejected_with_422(): void
    {
        $scope = $this->scope(new FakeCurrentUser('7'), OrderChannelAuthorizer::class);
        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(422, $result->getStatusCode());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function malformedSocketIdOrChannelNameProvider(): array
    {
        return [
            'socket id with a space' => ['1234 1234', 'private-tracked.1'],
            'socket id with a bad shape' => ['abc.def', 'private-tracked.1'],
            'channel name with a space' => ['1234.1234', 'private-track ed.1'],
            'channel name with a colon' => ['1234.1234', 'private-tracked:1'],
            'channel name with CRLF' => ['1234.1234', "private-tracked\r\n.1"],
        ];
    }

    /**
     * The 422 fires before the request even reaches the point of
     * looking up a registered authorizer — proven by using a channel
     * name whose bare form (`tracked.1`) genuinely has one registered,
     * so a 422 here can only come from the grammar check itself, not
     * from "no authorizer found."
     */
    #[DataProvider('malformedSocketIdOrChannelNameProvider')]
    public function test_a_malformed_socket_id_or_channel_name_is_rejected_with_422_before_any_authorizer_runs(
        string $socketId,
        string $channelName,
    ): void {
        $tracker = new TrackedChannelAuthorizer();
        $app = new AppScope();
        $registry = new BroadcastChannelRegistry();
        $registry->register(TrackedChannelAuthorizer::class);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(TrackedChannelAuthorizer::class, $tracker);
        $app->instance(BroadcasterInterface::class, $this->pusher());
        $app->boot();
        $scope = $app->createRequestScope();

        $controller = $scope->get(BroadcastAuthController::class);

        $result = $controller->auth($this->formRequest([
            'socket_id' => $socketId,
            'channel_name' => $channelName,
        ]));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(422, $result->getStatusCode());
        self::assertSame(0, $tracker->calls);
    }

    public function test_a_non_pusher_driver_cannot_sign_channel_authorization(): void
    {
        $app = new AppScope();
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(BroadcasterInterface::class, new NullBroadcaster());
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, new FakeCurrentUser('7'));

        $controller = $scope->get(BroadcastAuthController::class);

        $this->expectException(BroadcastingException::class);

        $controller->auth($this->formRequest([
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orders.42',
        ]));
    }

    private function scope(CurrentUserInterface $user, string $authorizerClass): RequestScope
    {
        $app = new AppScope();
        $registry = new BroadcastChannelRegistry();
        $registry->register($authorizerClass);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(BroadcasterInterface::class, $this->pusher());
        $app->boot();

        $scope = $app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, $user);

        return $scope;
    }

    private function pusher(): PusherBroadcaster
    {
        return new PusherBroadcaster(new Http(new MockHttpClient()), '12345', self::KEY, self::SECRET);
    }

    /**
     * @param array<string, string> $fields
     */
    private function formRequest(array $fields): ServerRequest
    {
        return new ServerRequest('POST', '/broadcasting/auth')->withParsedBody($fields);
    }
}
