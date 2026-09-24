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
 * Constructs its own AppScope and runs bootstrap.php to read
 * AppScope::middlewares(): AppScope is never registered onto the
 * RequestScope a command is dispatched through, so there's no way to
 * reach the real one from here. The command declares bootstrap: false —
 * this internal run is the only one, so listing routes never executes
 * the application's bootstrap twice.
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
        private ProjectRoot $projectRoot,
        private mixed $output = STDOUT,
    ) {}

    #[Command('routes:list', description: 'Displays every discovered route and the full global middleware pipeline', bootstrap: false)]
    public function run(): int
    {
        $projectRoot = $this->projectRoot->path;

        $app = new AppScope();
        $config = Config::fromEnvironment();
        $app->instance(Config::class, $config);
        // Bound as BootSequence::run() binds it, since this chain runs
        // package bootstraps without it.
        $app->instance(ProjectRoot::class, $this->projectRoot);
        RoutesFile::loadBootstrap($projectRoot)($app, $config);
        $app->boot();

        // Discovered before routes, not after: RouteDiscovery needs the
        // global middleware list to resolve any #[RoutePrefix] those
        // classes declare into each route's own displayed path — see
        // Router::register()'s own doc comment.
        $discovered = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
        $router = RouteDiscovery::discover($projectRoot, globalMiddleware: $discovered['global']);
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

        $headers = ['Method', 'Path', 'Where', 'Status', 'Controller', 'Middleware'];

        // Where and Middleware list one entry per line rather than forcing
        // one very wide line; see printRouteRow().
        $rows = array_map(
            static fn (Route $route): array => [
                [$route->httpMethod],
                [$route->pathTemplate],
                self::whereLines($route->where),
                [(string) $route->status],
                ["{$route->controllerClass}::{$route->controllerMethod}"],
                self::middlewareLines($route->middleware, $groups),
            ],
            $routes,
        );

        $this->printTable($headers, $rows);
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

    /**
     * @param list<string> $headers
     * @param list<non-empty-list<list<string>>> $rows each cell is its own list of lines
     */
    private function printTable(array $headers, array $rows): void
    {
        $widths = array_map(self::width(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $column => $lines) {
                foreach ($lines as $line) {
                    $widths[$column] = max($widths[$column], self::width($line));
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
     * One route spans as many lines as its tallest cell; a shorter cell
     * is left blank below its own last line.
     *
     * @param non-empty-list<list<string>> $row
     * @param array<int, int> $widths
     */
    private function printRouteRow(array $row, array $widths): void
    {
        $height = max(array_map(count(...), $row));

        for ($line = 0; $line < $height; $line++) {
            $this->printRow(
                array_map(static fn (array $lines): string => $lines[$line] ?? '', $row),
                $widths,
            );
        }
    }

    /**
     * Pads by self::width(), so the multibyte `—` placeholder keeps every
     * later column aligned.
     *
     * @param list<string> $columns
     * @param array<int, int> $widths
     */
    private function printRow(array $columns, array $widths): void
    {
        $padded = [];

        foreach ($columns as $index => $value) {
            $padded[] = $value . str_repeat(' ', $widths[$index] - self::width($value));
        }

        $this->write(rtrim(implode('  ', $padded)) . "\n");
    }

    /**
     * Counts UTF-8 characters with PCRE rather than mbstring, which the
     * framework does not require. Text that is not valid UTF-8 counts
     * bytes.
     */
    private static function width(string $text): int
    {
        return preg_match_all('/./su', $text) ?: strlen($text);
    }

    private function write(string $line): void
    {
        fwrite($this->output, $line);
    }
}
