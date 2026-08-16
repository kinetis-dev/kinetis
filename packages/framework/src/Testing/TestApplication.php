<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Kinetis\Cache\RoutesFile;
use Kinetis\Config\Config;
use Kinetis\Config\EnvFile;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;

/**
 * Boots a real application for a test: the same container, discovery, and
 * middleware pipeline a request goes through in development, assembled
 * once and reused.
 *
 * Deliberately mirrors public/index.php's development branch rather than
 * approximating it — a test that passes against a hand-assembled Kernel
 * proves less than one that passes against the wiring production
 * actually uses. Discovery runs live (never the compiled cache), so a
 * route or listener added mid-test-run is picked up without a build step.
 *
 * Framework-agnostic on purpose: nothing here depends on PHPUnit, so it
 * works from any test runner, a script, or a REPL.
 * {@see ApplicationTestCase} is the PHPUnit-shaped wrapper around it.
 *
 *     $app = TestApplication::boot(__DIR__ . '/..', ['DB_NAME' => 'app_test']);
 *     $app->client()->get('/orders')->assertOk();
 *
 * $configOverrides win over both the real environment and .env, so a test
 * can point the application at a test database without touching either.
 */
final class TestApplication
{
    private ?TestClient $client = null;

    private function __construct(
        public readonly AppScope $app,
        public readonly Kernel $kernel,
        public readonly Config $config,
    ) {}

    /**
     * @param array<string, string> $configOverrides
     */
    public static function boot(string $projectRoot, array $configOverrides = []): self
    {
        EnvFile::safeLoad($projectRoot);

        // getenv() is the same source Config::fromEnvironment() snapshots;
        // read it directly so the overrides can be merged over it.
        /** @var array<string, string> $environment */
        $environment = getenv();
        $config = new Config([...$environment, ...$configOverrides]);

        $app = new AppScope();
        $app->instance(Config::class, $config);
        // AppScope::boot()'s own default detects the environment from
        // getenv(), which an APP_ENV in $configOverrides never reaches —
        // registering it from the merged config is what makes that
        // override actually win, like every other key.
        $app->instance(AppEnvironment::class, AppEnvironment::detect($config->get('APP_ENV')));

        $router = RouteDiscovery::discover($projectRoot);
        $middleware = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
        $listeners = EventListenerDiscovery::discover($projectRoot);

        // Package bootstraps first, then the application's own
        // bootstrap.php — the same order, and the same last-write-wins
        // override, every other entry point uses.
        RoutesFile::loadBootstrap($projectRoot)($app, $config);

        $app->instance(EventListenerRegistry::class, $listeners);
        $app->boot();

        $kernel = new Kernel(
            $app,
            $router,
            discoveredGlobalMiddleware: $middleware['global'],
            discoveredMcpMiddleware: $middleware['mcp'],
            discoveredOpenApiMiddleware: $middleware['openApi'],
            middlewareGroups: $middleware['groups'],
        );

        return new self($app, $kernel, $config);
    }

    /**
     * Builds routing and the container without discovery, for a test that
     * wants an explicit route table rather than whatever the project
     * happens to contain — the shape core's own Kernel tests use.
     */
    public static function withRouter(Router $router, ?AppScope $app = null): self
    {
        $app = $app ?? new AppScope();

        if (!$app->isBooted()) {
            $app->boot();
        }

        /** @var Config $config */
        $config = $app->get(Config::class);

        return new self($app, new Kernel($app, $router), $config);
    }

    public function client(): TestClient
    {
        return $this->client ??= new TestClient($this->kernel);
    }

    /**
     * Resolves a service out of the booted container, so a test can
     * assert against the same instance the application used.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        return $this->app->get($id);
    }
}
