<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\PackageBootstrap;
use Kinetis\Authorization\Tests\Fixtures\FixturePostController;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/**
 * The whole chain, not the middleware in isolation: PackageBootstrap's
 * $app->middleware() registration actually reaching Kernel's global
 * pipeline, and a denial thrown three calls deep inside a real controller
 * (Gate::authorize() -> AuthorizationException -> the registered
 * middleware) turning into a real 403 response.
 */
final class KernelIntegrationTest extends TestCase
{
    public function test_an_allowed_check_lets_the_controller_finish_normally(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        $router = new Router();
        $router->register(FixturePostController::class);

        $response = TestApplication::withRouter($router, $app)->client()->patch('/posts/7');

        $response->assertOk()->assertJson(['id' => 7, 'updated' => true]);
    }

    public function test_a_denied_check_becomes_a_403_with_the_policys_own_message(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        $router = new Router();
        $router->register(FixturePostController::class);

        $response = TestApplication::withRouter($router, $app)->client()->patch('/posts/99');

        $response->assertStatus(403)->assertJson(['error' => 'This post is locked and cannot be edited.']);
    }
}
