<?php

declare(strict_types=1);

use Kinetis\Cache\Compiler;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

const ITERATIONS = 10_000;
const WARMUP = 500;

$app = new AppScope();
$app->boot();

$router = new Router();
$router->register(UserController::class);

$liveKernel = new Kernel($app, $router);

// Same warm, built-once-then-reused shape as $liveKernel above — the
// persistent-worker steady state, where boot only ever happens once
// regardless of caching. The comparison this half is actually testing:
// does skipping Dispatcher's/Hydrator's *per-dispatch* reflection (which
// happens on every single request even in a persistent worker, unlike
// Router::register()'s boot-time-only cost) measurably help once
// everything's already warm — not a cold-start question at all. See
// bench/cold_start.php for the separate FPM-boot-and-die comparison.
$compiled = (new Compiler())->compile($router);
$compiledKernel = new Kernel($app, $router, httpCache: $compiled->http);

/** @var array<string, callable(): ServerRequestInterface> $scenarios */
$scenarios = [
    'GET /users (query params)' => static fn (): ServerRequestInterface =>
        (new ServerRequest('GET', '/users'))->withQueryParams(['page' => '2', 'limit' => '10']),
    'GET /users/{id} (path param)' => static fn (): ServerRequestInterface =>
        new ServerRequest('GET', '/users/42'),
    'POST /users (validated body DTO)' => static fn (): ServerRequestInterface =>
        new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc'])),
];

/**
 * @param callable(): ServerRequestInterface $factory
 */
function run(Kernel $kernel, callable $factory): array
{
    for ($i = 0; $i < WARMUP; $i++) {
        $kernel->handle($factory());
    }

    $durationsMs = [];

    for ($i = 0; $i < ITERATIONS; $i++) {
        $start = hrtime(true);
        $kernel->handle($factory());
        $durationsMs[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($durationsMs);

    return $durationsMs;
}

/**
 * @param list<float> $durationsMs
 */
function report(string $label, array $durationsMs): void
{
    $count = count($durationsMs);

    printf(
        "%-45s min=%.4fms avg=%.4fms median=%.4fms p95=%.4fms p99=%.4fms max=%.4fms\n",
        $label,
        $durationsMs[0],
        array_sum($durationsMs) / $count,
        $durationsMs[(int) ($count * 0.50)],
        $durationsMs[(int) ($count * 0.95)],
        $durationsMs[(int) ($count * 0.99)],
        $durationsMs[$count - 1],
    );
}

foreach ($scenarios as $label => $factory) {
    report("{$label} [live]", run($liveKernel, $factory));
    report("{$label} [compiled]", run($compiledKernel, $factory));
}
