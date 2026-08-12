<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\McpRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One "racing first request" — exactly the sequence public/index.php runs
 * in production when no cache exists yet: check, compile if absent, write.
 * Spawned as an independent OS process by bench/cache_race.php, not a
 * thread/fiber within one process, since the real risk is separate
 * processes racing on the filesystem.
 */
$cacheDir = $argv[1] ?? null;

if ($cacheDir === null) {
    fwrite(STDERR, "usage: cache_race_worker.php <cache-dir>\n");
    exit(1);
}

$store = new CacheStore($cacheDir);

if (!$store->exists()) {
    $compiled = (new Compiler())->compile(new Router(), new McpRegistry());
    $store->writeAll($compiled);
}

echo "ok\n";
