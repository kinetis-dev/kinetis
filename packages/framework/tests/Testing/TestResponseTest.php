<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestApplication;
use Kinetis\Testing\TestClient;
use Kinetis\Testing\TestResponse;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class TestResponseTest extends TestCase
{
    private function client(): TestClient
    {
        $router = new Router();
        $router->register(UserController::class);

        return TestApplication::withRouter($router)->client();
    }

    public function test_it_is_still_a_psr7_response(): void
    {
        $response = $this->client()->get('/users/42');

        // Anything already treating the client's return value as plain
        // PSR-7 keeps working — the assertions are additive.
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_status_assertions_pass_for_the_real_status(): void
    {
        $this->client()->get('/users/42')->assertOk()->assertSuccessful()->assertStatus(200);
        $this->client()->get('/users/999/maybe')->assertNotFound();
    }

    public function test_a_failed_status_assertion_reports_the_body(): void
    {
        $this->expectException(AssertionFailedError::class);
        // The body is what tells you *why* a route answered unexpectedly,
        // so a mismatch has to surface it.
        $this->expectExceptionMessage('User 999 not found.');

        $this->client()->get('/users/999/maybe')->assertStatus(200);
    }

    public function test_json_assertions_read_the_decoded_body(): void
    {
        $this->client()->get('/users/42')
            ->assertJson(['id' => 42])
            ->assertJsonPath('id', 42)
            ->assertJsonPathMissing('missing');
    }

    public function test_json_path_walks_nested_structures(): void
    {
        $response = new TestResponse(
            new \Nyholm\Psr7\Response(200, [], json_encode(['order' => ['items' => [['sku' => 'A1']]]], JSON_THROW_ON_ERROR)),
        );

        $response->assertJsonPath('order.items.0.sku', 'A1')->assertJsonPathMissing('order.items.1.sku');
    }

    public function test_validation_error_assertion_covers_status_and_field(): void
    {
        $this->client()->post('/users', ['email' => 'not-an-email', 'name' => 'x'])
            ->assertValidationError('email');
    }

    public function test_validation_error_assertion_fails_for_a_field_that_passed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected a validation error for "name"');

        $this->client()->post('/users', ['email' => 'not-an-email', 'name' => 'Ada Lovelace'])
            ->assertValidationError('name');
    }

    public function test_the_body_can_be_read_more_than_once(): void
    {
        $response = $this->client()->get('/users/42');

        // Each assertion reads the body; a stream consumed by the first
        // would leave the rest asserting against an empty string.
        self::assertSame($response->body(), $response->body());
        $response->assertJsonPath('id', 42)->assertBodyContains('42')->assertJsonPath('id', 42);
    }

    public function test_header_assertions(): void
    {
        $this->client()->get('/users/42')
            ->assertHeader('Content-Type')
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_boot_assembles_an_application_from_a_project_root(): void
    {
        // The same fixture project the discovery tests use — proving
        // boot() wires real discovery, not a hand-built router.
        $application = TestApplication::boot(__DIR__ . '/../Cache/Fixtures');

        $application->client()->get('/fixture-ping')->assertOk();
        self::assertTrue($application->app->isBooted());
    }

    public function test_boot_applies_config_overrides_over_the_environment(): void
    {
        $application = TestApplication::boot(__DIR__ . '/../Cache/Fixtures', ['APP_NAME' => 'overridden']);

        self::assertSame('overridden', $application->config->string('APP_NAME', ''));
    }

    public function test_an_app_env_override_reaches_the_environment_binding(): void
    {
        // AppScope::boot()'s own default reads getenv(), which an override
        // never touches — the container's AppEnvironment has to come from
        // the merged config, or this key silently ignores overrides.
        $application = TestApplication::boot(__DIR__ . '/../Cache/Fixtures', ['APP_ENV' => 'production']);

        self::assertTrue($application->get(\Kinetis\Runtime\AppEnvironment::class)->isProduction());

        $development = TestApplication::boot(__DIR__ . '/../Cache/Fixtures', ['APP_ENV' => 'development']);

        self::assertFalse($development->get(\Kinetis\Runtime\AppEnvironment::class)->isProduction());
    }

    public function test_kernel_constructed_clients_return_assertable_responses_too(): void
    {
        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);

        (new TestClient(new Kernel($app, $router)))->get('/users/42')->assertOk();
    }
}
