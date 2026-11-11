<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Kinetis\Cache\BootSequence;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Config\Config;
use Kinetis\Config\EnvFile;
use Kinetis\Console\CommandDiscovery;
use Kinetis\Console\CommandRegistry;
use Kinetis\Console\CommandRunner;
use Kinetis\Console\Output;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\Runtime\AppEnvironment;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Boots a real application for a test: the same container, discovery, and
 * middleware pipeline a request goes through in development, assembled
 * once and reused.
 *
 * Mirrors {@see \Kinetis\Runtime\HttpStartup}'s development branch rather
 * than approximating it — a test that passes against a hand-assembled
 * Kernel proves less than one that passes against the wiring production
 * actually uses. Discovery runs live (never the compiled cache), so a
 * route, listener or command added mid-test-run is picked up without a
 * build step.
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
        private readonly CommandRegistry $commands,
    ) {}

    /**
     * @param array<string, string> $configOverrides
     * @param (callable(AppScope, Config): void)|null $beforeBoot registrations applied
     *        after the application's own bootstrap.php and before boot() locks the
     *        container, which is the only window a test double can win in. An
     *        application that talks to something it should not talk to from a test —
     *        a payment gateway, a WebSocket server — is replaced here.
     */
    public static function boot(string $projectRoot, array $configOverrides = [], ?callable $beforeBoot = null): self
    {
        EnvFile::safeLoad($projectRoot);

        // getenv() is the same source Config::fromEnvironment() snapshots;
        // read it directly so the overrides can be merged over it.
        /** @var array<string, string> $environment */
        $environment = getenv();
        $config = new Config([...$environment, ...$configOverrides]);

        $app = new AppScope();

        // Everything past the scope's own construction can fail —
        // discovery, a package's bootstrap, a test double's factory,
        // boot() itself — and a callback an earlier step already
        // registered on the scope owns something real. Disposal is
        // attempted so that resource closes, and its own failure is
        // swallowed rather than allowed to replace the boot failure the
        // caller has to see.
        try {
            $app->instance(Config::class, $config);
            // AppScope::boot()'s own default detects the environment from
            // getenv(), which an APP_ENV in $configOverrides never reaches —
            // registering it from the merged config is what makes that
            // override actually win, like every other key.
            $app->instance(AppEnvironment::class, AppEnvironment::detect($config->get('APP_ENV')));

            // One context for this boot's whole discovery, never the
            // compiled cache: a second boot gets its own and sees the
            // source as it is then.
            $context = new DiscoveryContext($projectRoot);
            // Discovered before routes, not after: RouteDiscovery needs the
            // global middleware list to resolve any #[RoutePrefix] those
            // classes declare into each route's own path — see
            // Router::register()'s own doc comment.
            $middleware = GlobalMiddlewareDiscovery::discoverAll($context);
            $router = RouteDiscovery::discover($context, globalMiddleware: $middleware['global']);
            $listeners = EventListenerDiscovery::discover($context);
            $pluginInstances = PluginDiscovery::reconstruct(PluginDiscovery::discover($context));
            $commands = CommandDiscovery::discover($context);

            // The plugin instances and the discovered EventListenerRegistry
            // both have to be bound before the bootstrap chain runs — see
            // BootSequence's own docblock, the one place this ordering
            // lives, shared with HttpStartup and bin/kinetis. Package
            // bootstraps run first inside it, then this application's own
            // bootstrap.php — the same last-write-wins order every other
            // entry point uses.
            BootSequence::run($app, $projectRoot, $config, $listeners, $pluginInstances, $context->packageBootstraps());

            // Last, so a double registered here replaces whatever
            // bootstrap.php bound under the same id.
            if ($beforeBoot !== null) {
                $beforeBoot($app, $config);
            }

            $app->boot();
        } catch (Throwable $e) {
            try {
                $app->dispose();
            } catch (Throwable) {
                // Secondary: the boot failure is the outcome.
            }

            throw $e;
        }

        // Read back from the booted scope, as HttpStartup does: a
        // bootstrap or $beforeBoot may have replaced the Config.
        /** @var Config $bootedConfig */
        $bootedConfig = $app->get(Config::class);

        $kernel = new Kernel(
            $app,
            OpenApiAccess::fromConfig($bootedConfig)->routes($router),
            discoveredGlobalMiddleware: $middleware['global'],
            discoveredOpenApiMiddleware: $middleware['openApi'],
            middlewareGroups: $middleware['groups'],
        );

        return new self($app, $kernel, $config, $commands);
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

        return new self($app, new Kernel($app, OpenApiAccess::fromConfig($config)->routes($router)), $config, new CommandRegistry());
    }

    public function client(): TestClient
    {
        return $this->client ??= new TestClient($this->kernel);
    }

    /**
     * Runs a discovered command by name through the same runner
     * bin/kinetis uses: a fresh request scope with the Output bound, the
     * command resolved from that scope, a throwable logged and reported
     * as CommandFailed with exit code 1, and the scope disposed (70 when
     * only that disposal fails). Stdout and stderr are captured
     * separately, without colour. The throwable is returned on the
     * result, not rethrown.
     *
     * The application stays booted across calls; dispose() still ends
     * it. A #[Command(bootstrap: false)] command runs against this
     * already-booted application rather than an unbootstrapped one. A
     * command that loops until signalled, such as queue:work, never
     * returns here.
     *
     * @param list<string> $arguments what follows the command name on the command line
     * @throws InvalidArgumentException when no discovered command has this name
     */
    public function command(string $name, array $arguments = []): CommandResult
    {
        $definition = $this->commands->findCommand($name)
            ?? throw new InvalidArgumentException("No discovered command is named \"{$name}\".");

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not open in-memory streams to capture command output.');
        }

        try {
            $exitCode = new CommandRunner($this->app)->run(
                $definition,
                $arguments,
                new Output($stdout, $stderr, false, false),
                $failure,
            );

            rewind($stdout);
            rewind($stderr);

            return new CommandResult(
                $exitCode,
                (string) stream_get_contents($stdout),
                (string) stream_get_contents($stderr),
                $failure,
            );
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
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

    /**
     * Disposes the application this booted, running whatever a package
     * or a test double registered through {@see AppScope::onDispose()}.
     * A test that boots a fresh application per test calls this when it
     * finishes, or every app-scoped resource that boot opened is
     * abandoned once per test — {@see ApplicationTestCase} does it from
     * its own after-hook.
     *
     * Idempotent, because AppScope::dispose() is: a suite that disposes
     * explicitly and then hits the after-hook disposes once.
     */
    public function dispose(): void
    {
        $this->app->dispose();
    }
}
