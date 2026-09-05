<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\Tests\Fixtures\FixturePostController;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/**
 * The whole chain, not the exception in isolation: a denial thrown three
 * calls deep inside a real controller (Gate::authorize() ->
 * AuthorizationException -> the 403 that exception's own
 * HttpStatusExceptionInterface declares) reaching the client as a real
 * response through Kernel's own ExceptionHandlerMiddleware, with nothing
 * registered for it.
 */
final class KernelIntegrationTest extends TestCase
{
    public function test_an_allowed_check_lets_the_controller_finish_normally(): void
    {
        $router = new Router();
        $router->register(FixturePostController::class);

        $response = TestApplication::withRouter($router)->client()->patch('/posts/7');

        $response->assertOk()->assertJson(['id' => 7, 'updated' => true]);
    }

    public function test_a_denied_check_becomes_a_403_with_the_policys_own_message(): void
    {
        $router = new Router();
        $router->register(FixturePostController::class);

        $response = TestApplication::withRouter($router)->client()->patch('/posts/99');

        $response->assertStatus(403)->assertJson(['error' => 'This post is locked and cannot be edited.']);
    }
}
