<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Unlike bench/dispatch.php (which builds AppScope/Router once outside the
 * timed loop — the FrankenPHP persistent-worker steady-state shape), this
 * moves that construction *inside* the timed loop for both scenarios, to
 * simulate what PHP-FPM's boot-and-die model actually pays: the entire
 * public/index.php boot sequence reruns from scratch on every single
 * request, not just Kernel::handle().
 *
 * The cached scenario only ever loads http.php — never mcp.php or
 * openapi.php — matching exactly what a real request to this route would
 * do via CacheStore::loadHttp() and Kernel's lazy $cacheStore. This is the
 * whole point of splitting the artifact: a monolithic file (routes + MCP
 * + the full OpenAPI document) would mean reconstructing data this route
 * never needs, which at this small scale can make the cached path lose
 * to live reflection. Splitting avoids that by construction, not just by
 * tuning.
 */
const ITERATIONS = 2_000;
const WARMUP = 100;

/**
 * @return list<float>
 */
function runLive(int $iterations): array
{
    $durationsMs = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);

        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);
        $kernel = new Kernel($app, $router);
        $kernel->handle(new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc'])));

        $durationsMs[] = (hrtime(true) - $start) / 1_000_000;
    }

    return $durationsMs;
}

/**
 * @return list<float>
 */
function runCached(int $iterations, string $cacheDir): array
{
    $durationsMs = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);

        $app = new AppScope();
        $app->boot();
        $httpCache = (new CacheStore($cacheDir))->loadHttp();
        $router = Router::fromArray($httpCache->routes);
        $kernel = new Kernel($app, $router, httpCache: $httpCache);
        $kernel->handle(new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc'])));

        $durationsMs[] = (hrtime(true) - $start) / 1_000_000;
    }

    return $durationsMs;
}

/**
 * @param list<float> $durationsMs
 */
function report(string $label, array $durationsMs): void
{
    sort($durationsMs);
    $count = count($durationsMs);

    printf(
        "%-35s min=%.4fms avg=%.4fms median=%.4fms p95=%.4fms p99=%.4fms max=%.4fms\n",
        $label,
        $durationsMs[0],
        array_sum($durationsMs) / $count,
        $durationsMs[(int) ($count * 0.50)],
        $durationsMs[(int) ($count * 0.95)],
        $durationsMs[(int) ($count * 0.99)],
        $durationsMs[$count - 1],
    );
}

runLive(WARMUP);
report('LIVE (cold-start simulated)', runLive(ITERATIONS));

$cacheDir = sys_get_temp_dir() . '/kinetis_bench_cache_' . bin2hex(random_bytes(4));
$router = new Router();
$router->register(UserController::class);
$compiled = (new Compiler())->compile($router);
(new CacheStore($cacheDir))->writeAll($compiled);

runCached(WARMUP, $cacheDir);
report('CACHED (cold-start simulated)', runCached(ITERATIONS, $cacheDir));

foreach (glob($cacheDir . '/*') ?: [] as $file) {
    unlink($file);
}

rmdir($cacheDir);
