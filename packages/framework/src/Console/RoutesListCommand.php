<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\BootSequence;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Middleware\GlobalMiddlewareOrder;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Runtime\ProjectRoot;
use Throwable;

/**
 * A read-only introspection tool: displays the route table (RouteDiscovery)
 * and the global middleware pipeline (GlobalMiddlewareDiscovery +
 * GlobalMiddlewareOrder::resolve()) via a fresh, live scan through its
 * own DiscoveryContext — never reads or writes .kinetis-cache/.
 *
 * Constructs its own AppScope and runs bootstrap.php to read
 * AppScope::middlewares(): AppScope is never registered onto the
 * RequestScope a command is dispatched through, so there's no way to
 * reach the real one from here. That scope is assembled through
 * BootSequence::run() from this scan's own discovery results, so the
 * bootstrap chain sees the same bindings as in every other entry point.
 * The command declares bootstrap: false — this internal run is the only
 * one, so listing routes never executes the application's bootstrap
 * twice. The scope is disposed once the listing is written, so a test
 * suite that runs this repeatedly does not accumulate booted scopes.
 */
final readonly class RoutesListCommand
{
    public function __construct(
        private ProjectRoot $projectRoot,
        private Output $output,
    ) {}

    #[Command('routes:list', description: 'Displays every discovered route and the full global middleware pipeline', bootstrap: false)]
    public function run(): int
    {
        $app = new AppScope();

        try {
            $this->list($app);
        } catch (Throwable $e) {
            try {
                $app->dispose();
            } catch (Throwable) {
                // Secondary: the listing failure is the outcome.
            }

            throw $e;
        }

        // Contained so a disposal failure cannot replace a listing that was
        // already written. Reported by class only, for the reason
        // bin/kinetis withholds an application-disposal message: a
        // destructor released here may put credentials in it.
        try {
            $app->dispose();
        } catch (Throwable $disposeFailure) {
            $this->output->error('Application disposal failed after listing routes: ' . $disposeFailure::class);

            return 70;
        }

        return 0;
    }

    private function list(AppScope $app): void
    {
        $projectRoot = $this->projectRoot->path;
        $context = new DiscoveryContext($projectRoot);

        $config = Config::fromEnvironment();
        $app->instance(Config::class, $config);
        BootSequence::run(
            $app,
            $projectRoot,
            $config,
            EventListenerDiscovery::discover($context),
            PluginDiscovery::reconstruct(PluginDiscovery::discover($context)),
            $context->packageBootstraps(),
        );
        $app->boot();

        // Discovered before routes, not after: RouteDiscovery needs the
        // global middleware list to resolve any #[RoutePrefix] those
        // classes declare into each route's own displayed path — see
        // Router::register()'s own doc comment.
        $discovered = GlobalMiddlewareDiscovery::discoverAll($context);
        $router = RouteDiscovery::discover($context, globalMiddleware: $discovered['global']);
        $globalMiddleware = GlobalMiddlewareOrder::resolve($app->middlewares(), $discovered['global']);

        $this->printGlobalMiddleware($globalMiddleware);
        $this->printRoutes($router->routes(), $discovered['groups']);
    }

    /**
     * @param list<class-string> $globalMiddleware
     */
    private function printGlobalMiddleware(array $globalMiddleware): void
    {
        $this->output->line('Global middleware (outermost to innermost):');

        foreach ($globalMiddleware as $index => $class) {
            $this->output->line('  ' . ($index + 1) . ". {$class}");
        }

        $this->output->line();
    }

    /**
     * @param list<Route> $routes
     * @param array<string, list<class-string>> $groups
     */
    private function printRoutes(array $routes, array $groups): void
    {
        if ($routes === []) {
            $this->output->line('No routes discovered.');

            return;
        }

        usort(
            $routes,
            static fn (Route $a, Route $b): int => [$a->pathTemplate, $a->httpMethod] <=> [$b->pathTemplate, $b->httpMethod],
        );

        $headers = ['Method', 'Path', 'Where', 'Status', 'Controller', 'Middleware'];

        // Where and Middleware list one entry per line rather than forcing
        // one very wide line: a route spans as many table rows as its
        // tallest cell, a shorter cell left blank below its own last line.
        $rows = [];

        foreach ($routes as $route) {
            $cells = [
                [$route->httpMethod],
                [$route->pathTemplate],
                self::whereLines($route->where),
                [(string) $route->status],
                ["{$route->controllerClass}::{$route->controllerMethod}"],
                self::middlewareLines($route->middleware, $groups),
            ];
            $height = max(array_map(count(...), $cells));

            for ($line = 0; $line < $height; $line++) {
                $rows[] = array_map(static fn (array $lines): string => $lines[$line] ?? '', $cells);
            }
        }

        $this->output->table($headers, $rows);
    }

    /**
     * One `name: fragment` line per constraint, in the placeholder order
     * Route already stores them in.
     *
     * @param array<string,string> $where
     * @return list<string>
     */
    private static function whereLines(array $where): array
    {
        if ($where === []) {
            return ['—'];
        }

        $lines = [];

        foreach ($where as $name => $fragment) {
            $lines[] = "{$name}: {$fragment}";
        }

        return $lines;
    }

    /**
     * A `@name` group reference is expanded into the classes that actually
     * run, each annotated with the group it came from — what a route
     * really executes is the useful thing to display here, without losing
     * where each entry originated. A reference to an undeclared group is
     * shown as-is (Kernel rejects that at startup; this command is
     * read-only and never throws on it).
     *
     * @param list<class-string|string> $middleware
     * @param array<string, list<class-string>> $groups
     * @return list<string>
     */
    private static function middlewareLines(array $middleware, array $groups): array
    {
        $entries = [];

        foreach ($middleware as $reference) {
            if (!str_starts_with($reference, Middleware::GROUP_PREFIX)) {
                $entries[] = $reference;

                continue;
            }

            $group = substr($reference, strlen(Middleware::GROUP_PREFIX));

            if (!isset($groups[$group])) {
                $entries[] = "{$reference} (undeclared)";

                continue;
            }

            foreach ($groups[$group] as $class) {
                $entries[] = "{$class} ({$reference})";
            }
        }

        if ($entries === []) {
            return ['—'];
        }

        $lastIndex = array_key_last($entries);
        $lines = [];

        foreach ($entries as $index => $entry) {
            $lines[] = $index === $lastIndex ? $entry : "{$entry} ->";
        }

        return $lines;
    }
}
