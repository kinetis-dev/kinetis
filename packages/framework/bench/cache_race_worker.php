<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\Compiler;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Http\Routing\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One racing worker in cache_race.php's own proof — the same sequence
 * public/index.php and bin/kinetis run in production: a cold check,
 * then compile and publish. Then, the actual proof this script exists
 * for: read all four sections back and confirm they all carry the same
 * marker.
 *
 * The marker is a random value this worker generates itself, not
 * Compiler::compile()'s own compiledAt — DATE_ATOM has one-second
 * resolution, and with WORKERS racing well inside that window, most or
 * all of them would naturally share the identical compiledAt string
 * regardless of whether generation-pinning actually worked, making that
 * check pass even against a broken per-file publish design (a proof
 * that can't fail against the defect it exists to catch isn't a real
 * proof). $marker is embedded into every one of the four sections'
 * compiledAt field in place of the real one before writeAll() — the
 * only field all four sections already carry that's free for this — so
 * a mismatch across the four reads is unambiguous: this worker's own
 * generation, and only this worker's, was ever mixed with another's.
 *
 * The barrier sits at the actual publication boundary, not merely near
 * it: every worker performs its own cold exists() check and compiles
 * and marks its own CompiledCache first, then signals readiness and
 * waits — only once cache_race.php has confirmed every spawned worker
 * reached that same point does it release the shared go file, so every
 * worker's writeAll() call is released to run at essentially the same
 * moment, genuinely contending for the same rename() rather than merely
 * having been scheduled close together beforehand.
 *
 * Every worker that reaches release calls writeAll() unconditionally,
 * regardless of what its own earlier exists() check returned — the run
 * starts from a destroyed cache (CacheStore::destroy() in
 * cache_race.php), so that check is always false here; it's kept only
 * to mirror the real production shape (check, then compile-and-publish)
 * this proof is exercising, never to decide which workers actually
 * publish. This is what makes cache_race.php's own writerCount ===
 * count($processes) assertion a deterministic guarantee rather than a
 * "usually several of them" hope: every successfully spawned worker
 * that reaches the barrier is a writer, always.
 *
 * $checkStore (the exists() half) and $readStore (the read-back after
 * writing) are deliberately two separate CacheStore instances, not one
 * reused for both — exists() is itself a first read that pins an
 * instance the same as any load*() call would (see CacheStore's own
 * docblock), so reusing $checkStore for the read below would pin to
 * whatever was active (or absent) *before* this worker's own
 * writeAll() call. $readStore, constructed fresh after writeAll(), is
 * what actually exercises the real "a later request reads whatever is
 * active" path this proof is for.
 *
 * The go-wait deadline is deliberately derived from the ready deadline
 * cache_race.php passes in, plus SAFETY_MARGIN_SECONDS — never a
 * second, independently hardcoded number a future edit could drift out
 * of step with the parent's own timeout, which would otherwise let a
 * slow-but-still-within-its-own-deadline parent poll produce a spurious
 * worker-side failure unrelated to the actual race-condition proof.
 */
const SAFETY_MARGIN_SECONDS = 10.0;

$cacheDir = $argv[1] ?? null;
$coordDir = $argv[2] ?? null;
$workerIndex = $argv[3] ?? null;
$readyDeadlineSeconds = isset($argv[4]) ? (float) $argv[4] : 10.0;

if ($cacheDir === null || $coordDir === null || $workerIndex === null) {
    fwrite(STDERR, "usage: cache_race_worker.php <cache-dir> <coord-dir> <worker-index> [ready-deadline-seconds]\n");
    exit(1);
}

$checkStore = new CacheStore($cacheDir);

// Mirrors the real cold check a production boot performs — always
// false here, since the run starts from a destroyed cache; its result
// is deliberately not used to decide whether this worker publishes
// (see this file's own docblock).
$checkStore->exists();

$compiled = (new Compiler())->compile(new Router());
$marker = getmypid() . '-' . bin2hex(random_bytes(8));

$compiled = new CompiledCache(
    http: new HttpCache(
        formatVersion: $compiled->http->formatVersion,
        routes: $compiled->http->routes,
        httpBindingPlans: $compiled->http->httpBindingPlans,
        hydrationPlans: $compiled->http->hydrationPlans,
        globalMiddleware: $compiled->http->globalMiddleware,
        openApiMiddleware: $compiled->http->openApiMiddleware,
        compiledAt: $marker,
        middlewareGroups: $compiled->http->middlewareGroups,
        packageBootstraps: $compiled->http->packageBootstraps,
    ),
    commands: new CommandCache(
        formatVersion: $compiled->commands->formatVersion,
        commands: $compiled->commands->commands,
        compiledAt: $marker,
        packageBootstraps: $compiled->commands->packageBootstraps,
    ),
    events: new EventCache(
        formatVersion: $compiled->events->formatVersion,
        listeners: $compiled->events->listeners,
        compiledAt: $marker,
    ),
    plugins: new PluginCache(
        formatVersion: $compiled->plugins->formatVersion,
        data: $compiled->plugins->data,
        compiledAt: $marker,
    ),
);

touch("{$coordDir}/ready_{$workerIndex}");

$goDeadline = microtime(true) + $readyDeadlineSeconds + SAFETY_MARGIN_SECONDS;

while (!is_file("{$coordDir}/go")) {
    if (microtime(true) > $goDeadline) {
        fwrite(STDERR, "timed out waiting for the start barrier\n");
        exit(1);
    }

    usleep(1000);
}

$checkStore->writeAll($compiled);

$readStore = new CacheStore($cacheDir);
$http = $readStore->loadHttp();
$commands = $readStore->loadCommands();
$events = $readStore->loadEvents();
$plugins = $readStore->loadPlugins();

if ($http === null || $commands === null || $events === null || $plugins === null) {
    fwrite(STDERR, "one or more sections failed to load\n");
    exit(1);
}

$markers = [$http->compiledAt, $commands->compiledAt, $events->compiledAt, $plugins->compiledAt];

if (count(array_unique($markers)) !== 1) {
    fwrite(STDERR, 'mixed generation across sections: ' . implode(', ', $markers) . "\n");
    exit(1);
}

echo "ok writer\n";
