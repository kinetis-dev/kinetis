<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Tests\Fixtures\GroupProject\GroupedCsrfMiddleware;
use Kinetis\Session\Tests\Fixtures\GroupProject\GroupedSessionController;
use Kinetis\Session\Tests\Fixtures\GroupProject\GroupedSessionMiddleware;
use Kinetis\Session\Tests\Fixtures\RecordingSessionStore;
use Kinetis\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * SessionMiddleware and CsrfMiddleware carry #[AsMiddlewareGroup]'s sole
 * supported extension the same way Kinetis\Auth\BearerAuthMiddleware and
 * Kinetis\AuthJwt\JwtAuthMiddleware do: an otherwise-empty final readonly
 * subclass. The reflection assertions below prove the base classes stay
 * extensible; the fixture-project ones prove a real discovered group
 * actually runs Session ahead of Csrf, not merely that discovery orders
 * them that way on paper.
 */
final class MiddlewareGroupExtensionTest extends TestCase
{
    private const string PROJECT_ROOT = __DIR__ . '/Fixtures/GroupProject';

    public function test_session_middleware_is_not_final(): void
    {
        self::assertFalse(new ReflectionClass(SessionMiddleware::class)->isFinal());
    }

    public function test_csrf_middleware_is_not_final(): void
    {
        self::assertFalse(new ReflectionClass(CsrfMiddleware::class)->isFinal());
    }

    /**
     * Higher priority runs more outer, so the fixture's own
     * GroupedSessionMiddleware (priority 60) has to precede
     * GroupedCsrfMiddleware (the attribute's default, 50) — the same
     * order SessionMiddleware/CsrfMiddleware always need, since Csrf
     * reads the Session the other one registers.
     */
    public function test_discovery_orders_the_group_session_first_then_csrf(): void
    {
        self::assertSame(
            [GroupedSessionMiddleware::class, GroupedCsrfMiddleware::class],
            $this->discoveredGroup(),
        );
    }

    /**
     * The point of the group: a route naming it once, through
     * #[Middleware('@session')], reaches CsrfMiddleware with a real
     * Session already registered by the member ahead of it — the
     * pipeline actually running in that order, not just a discovery-order
     * assertion.
     */
    public function test_a_grouped_route_registers_the_session_before_csrf_runs(): void
    {
        $client = $this->client();

        $seeded = $client->get('/group-token');
        $seeded->assertOk();
        $token = $seeded->json()['token'];
        self::assertIsString($token);

        $cookie = \explode(';', $seeded->getHeaderLine('Set-Cookie'))[0];

        $client->post('/group-guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => $token])
            ->assertOk()
            ->assertJsonPath('changed', true);
    }

    /**
     * Reversed (Csrf ahead of Session), CsrfMiddleware's own
     * isRegistered() guard would 500 instead — this proves the group's
     * priority ordering is load-bearing, not merely that a valid token
     * happens to work.
     */
    public function test_a_mismatched_token_is_rejected_through_the_group(): void
    {
        $client = $this->client();
        $seeded = $client->get('/group-token');
        $cookie = \explode(';', $seeded->getHeaderLine('Set-Cookie'))[0];

        $client->post('/group-guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => 'wrong'])
            ->assertStatus(403);
    }

    /**
     * @return list<class-string>
     */
    private function discoveredGroup(): array
    {
        return GlobalMiddlewareDiscovery::discoverAll(new DiscoveryContext(self::PROJECT_ROOT))['groups']['session'];
    }

    private function client(): TestClient
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['SESSION_SECURE' => 'false']));
        $app->instance(SessionStoreInterface::class, new RecordingSessionStore());
        $app->boot();

        $router = new Router();
        $router->register(GroupedSessionController::class);

        $kernel = new Kernel($app, $router, middlewareGroups: ['session' => $this->discoveredGroup()]);

        return new TestClient($kernel);
    }
}
