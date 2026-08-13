<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\RoutesFile;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Container\AppScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Middleware\GlobalMiddlewareOrder;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Runtime\ProjectRoot;

/**
 * A read-only introspection tool: displays the route table (RouteDiscovery)
 * and the global middleware pipeline (GlobalMiddlewareDiscovery +
 * GlobalMiddlewareOrder::resolve()) via a fresh, live scan — never reads
 * or writes .kinetis-cache/.
 *
 * Constructs its own AppScope and re-runs bootstrap.php to read
 * AppScope::middlewares(): AppScope is never registered onto the
 * RequestScope a command is dispatched through, so there's no way to
 * reach the real one from here.
 *
 * $output is injectable (defaulting to STDOUT) for testability against
 * php://memory — a #[Command] method must take zero parameters or
 * exactly one CommandArguments, so it can't be a run() parameter.
 */
final readonly class RoutesListCommand
{
    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *     "resource" type and a readonly property requires one
     */
    public function __construct(
        private ?string $projectRootOverride = null,
        private mixed $output = STDOUT,
    ) {}

    #[Command('routes:list', description: 'Displays every discovered route and the full global middleware pipeline')]
    public function run(): int
    {
        // dirname(__DIR__) — see BuildCommand's own doc comment for why:
        // this file lives one level deeper than bin/kinetis does.
        $projectRoot = $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__));

        $app = new AppScope();
        $config = Config::fromEnvironment();
        $app->instance(Config::class, $config);
        RoutesFile::loadBootstrap($projectRoot)($app, $config);
        $app->boot();

        $router = RouteDiscovery::discover($projectRoot);
        $discovered = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
        $globalMiddleware = GlobalMiddlewareOrder::resolve($app->middlewares(), $discovered['global']);

        $this->printGlobalMiddleware($globalMiddleware);
        $this->printRoutes($router->routes(), $discovered['groups']);

        return 0;
    }

    /**
     * @param list<class-string> $globalMiddleware
     */
    private function printGlobalMiddleware(array $globalMiddleware): void
    {
        $this->write("Global middleware (outermost to innermost):\n");

        foreach ($globalMiddleware as $index => $class) {
            $this->write('  ' . ($index + 1) . ". {$class}\n");
        }

        $this->write("\n");
    }

    /**
     * @param list<Route> $routes
     * @param array<string, list<class-string>> $groups
     */
    private function printRoutes(array $routes, array $groups): void
    {
        if ($routes === []) {
            $this->write("No routes discovered.\n");

            return;
        }

        usort(
            $routes,
            static fn (Route $a, Route $b): int => [$a->pathTemplate, $a->httpMethod] <=> [$b->pathTemplate, $b->httpMethod],
        );

        $headers = ['Method', 'Path', 'Status', 'Controller', 'Middleware'];

        // The Middleware column is the one that can genuinely run long —
        // a route stacking several classes would otherwise force a single
        // very wide line. self::middlewareLines() instead gives each
        // route's *own* middleware list one line each, "->" trailing every
        // line but the last, with the other four columns left blank on
        // every line after the first.
        $rows = array_map(
            static fn (Route $route): array => [
                $route->httpMethod,
                $route->pathTemplate,
                (string) $route->status,
                "{$route->controllerClass}::{$route->controllerMethod}",
                self::middlewareLines($route->middleware, $groups),
            ],
            $routes,
        );

        $this->printTable($headers, $rows);
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

    /**
     * @param list<string> $headers
     * @param list<array{0:string,1:string,2:string,3:string,4:list<string>}> $rows
     */
    private function printTable(array $headers, array $rows): void
    {
        $widths = array_map(strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                foreach ((is_array($value) ? $value : [$value]) as $line) {
                    $widths[$column] = max($widths[$column], strlen($line));
                }
            }
        }

        $this->printRow($headers, $widths);
        $this->write(implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths)) . "\n");

        foreach ($rows as $row) {
            $this->printRouteRow($row, $widths);
        }
    }

    /**
     * @param array{0:string,1:string,2:string,3:string,4:list<string>} $row
     * @param array<int, int> $widths
     */
    private function printRouteRow(array $row, array $widths): void
    {
        [$method, $path, $status, $controller, $middlewareLines] = $row;

        foreach ($middlewareLines as $index => $middlewareLine) {
            $this->printRow(
                $index === 0 ? [$method, $path, $status, $controller, $middlewareLine] : ['', '', '', '', $middlewareLine],
                $widths,
            );
        }
    }

    /**
     * @param list<string> $columns
     * @param array<int, int> $widths
     */
    private function printRow(array $columns, array $widths): void
    {
        $padded = [];

        foreach ($columns as $index => $value) {
            $padded[] = str_pad($value, $widths[$index]);
        }

        $this->write(rtrim(implode('  ', $padded)) . "\n");
    }

    private function write(string $line): void
    {
        fwrite($this->output, $line);
    }
}
