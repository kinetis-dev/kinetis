<?php

declare(strict_types=1);

/**
 * Spawns bench/many_controllers_live.php and .../_cached.php as genuinely
 * separate PHP processes, N times each, timing external wall-clock per
 * process via proc_open() — not a same-process loop (see those two
 * scripts' own comments for why that would silently hide the effect being
 * measured: PHP never unloads a declared class within one process, so only
 * a fresh process pays real autoload cost for classes it hasn't seen yet).
 *
 * Tests a specific, sharper hypothesis than the original cold-start
 * benchmark: reflecting an already-loaded class's metadata is cheap and
 * roughly constant per method regardless of method-body size — but the
 * live path must autoload (read+lex+parse+compile) *every* registered
 * controller class every single request, since it doesn't know which one
 * will be dispatched to until after routing runs; the cached path never
 * touches those files at all except for the one actually dispatched.
 */
const ITERATIONS = 60;

// The 150 controller+DTO fixture files this benchmark depends on are
// generated, not committed (see generate_many_controllers.php's own
// comment) — generate them here if they're missing, rather than letting
// every spawned process below fail silently and report() then divide by
// zero over an empty result set.
if (!is_dir(__DIR__ . '/fixtures/manyControllers')) {
    echo "Fixture controllers not found — generating them first...\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/generate_many_controllers.php'), $generateExitCode);

    if ($generateExitCode !== 0) {
        fwrite(STDERR, "generate_many_controllers.php failed (exit {$generateExitCode}); aborting.\n");
        exit(1);
    }
}

$cacheDir = sys_get_temp_dir() . '/kinetis_many_controllers_cache_' . bin2hex(random_bytes(4));

passthru('php ' . escapeshellarg(__DIR__ . '/build_many_controllers_cache.php') . ' ' . escapeshellarg($cacheDir));

/**
 * @return list<float>
 */
function runProcesses(string $script, array $args, int $n): array
{
    $durationsMs = [];

    for ($i = 0; $i < $n; $i++) {
        $cmd = array_merge(['php', $script], $args);
        $start = hrtime(true);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            continue;
        }

        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        if ($exitCode !== 0) {
            fwrite(STDERR, "process failed (exit {$exitCode}): {$stderr}\n");

            continue;
        }

        $durationsMs[] = $elapsed;
    }

    return $durationsMs;
}

/**
 * @param list<float> $d
 */
function report(string $label, array $d): void
{
    sort($d);
    $n = count($d);

    printf(
        "%-12s min=%8.3fms avg=%8.3fms median=%8.3fms p95=%8.3fms max=%8.3fms\n",
        $label,
        $d[0],
        array_sum($d) / $n,
        $d[(int) ($n * 0.50)],
        $d[(int) ($n * 0.95)],
        $d[$n - 1],
    );
}

echo "Warming up (autoloader/opcache)...\n";
runProcesses(__DIR__ . '/many_controllers_live.php', [], 5);
runProcesses(__DIR__ . '/many_controllers_cached.php', [$cacheDir], 5);

echo "Running " . ITERATIONS . " separate processes per scenario...\n\n";
$live = runProcesses(__DIR__ . '/many_controllers_live.php', [], ITERATIONS);
$cached = runProcesses(__DIR__ . '/many_controllers_cached.php', [$cacheDir], ITERATIONS);

report('LIVE', $live);
report('CACHED', $cached);

foreach (glob($cacheDir . '/*') ?: [] as $file) {
    unlink($file);
}

if (is_dir($cacheDir)) {
    rmdir($cacheDir);
}
