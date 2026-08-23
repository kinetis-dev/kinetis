<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestClient;
use Kinetis\Tests\Http\Fixtures\RawRequestController;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

final class TestClientTest extends TestCase
{
    private function client(): TestClient
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(RawRequestController::class);

        return new TestClient(new Kernel($app, $router));
    }

    public function test_get_dispatches_a_request_and_returns_the_response(): void
    {
        $response = $this->client()->get('/users/42');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_get_passes_query_parameters(): void
    {
        $response = $this->client()->get('/users', query: ['page' => 2, 'limit' => 5]);

        self::assertSame(['page' => 2, 'limit' => 5], json_decode((string) $response->getBody(), true));
    }

    public function test_post_sends_a_json_encoded_body(): void
    {
        $response = $this->client()->post('/users', body: ['name' => 'Alon', 'email' => 'alon@noy.cc']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@noy.cc'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_post_sets_a_json_content_type_by_default(): void
    {
        $response = $this->client()->post('/raw-request', body: ['anything' => true]);

        self::assertSame('application/json', json_decode((string) $response->getBody(), true)['contentType']);
    }

    public function test_post_does_not_override_an_explicit_content_type(): void
    {
        $response = $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/vnd.custom+json'],
        );

        self::assertSame(
            'application/vnd.custom+json',
            json_decode((string) $response->getBody(), true)['contentType'],
        );
    }

    public function test_patch_sends_a_json_encoded_body(): void
    {
        $response = $this->client()->patch('/users/1/status', body: ['status' => 'active']);

        self::assertSame(['id' => 1, 'status' => 'active'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_failing_validation_returns_422(): void
    {
        $response = $this->client()->post('/users', body: ['name' => 'Al', 'email' => 'not-an-email']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_an_unknown_route_returns_404(): void
    {
        $response = $this->client()->get('/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
    }
}
