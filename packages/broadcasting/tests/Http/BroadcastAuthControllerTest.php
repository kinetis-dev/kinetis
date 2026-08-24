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
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TeamPresenceAuthorizer;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\RevoltHttpClient\Http;
use Nyholm\Psr7\ServerRequest;
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
