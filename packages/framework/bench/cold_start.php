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
 * The cached scenario reads the compiled artifact the same way a real
 * production boot does — one CacheStore::load() — and reconstructs only
 * the HTTP section from it, since that is all this route needs. The
 * OpenAPI document is never part of the artifact at all; it is generated
 * and cached separately, so it costs this path nothing.
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
        $kernel->handle(new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@example.com'])));

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
        $httpCache = (new CacheStore($cacheDir))->load()->http;
        $router = Router::fromArray($httpCache->routes);
        $kernel = new Kernel($app, $router, httpCache: $httpCache);
        $kernel->handle(new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@example.com'])));

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
$store = new CacheStore($cacheDir);
$store->write((new Compiler())->compile($router));

runCached(WARMUP, $cacheDir);
report('CACHED (cold-start simulated)', runCached(ITERATIONS, $cacheDir));

unlink($store->path());
rmdir($cacheDir);
