<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Not a PHPUnit test — a standalone proof that CacheStore::writeAll()'s
 * atomic tmp+rename scheme is genuinely safe when many real, independent
 * PHP processes race to be "first" against an empty cache directory
 * simultaneously (the FPM cold-start scenario CacheStore's own docblock
 * describes). Spawns real child processes via proc_open(), not threads or
 * fibers within one process, since the actual risk is separate OS
 * processes racing on the filesystem.
 */
const WORKERS = 20;
const REPEAT_RUNS = 20;

$workerScript = __DIR__ . '/cache_race_worker.php';
$cacheDir = sys_get_temp_dir() . '/kinetis_cache_race_' . bin2hex(random_bytes(4));

$failures = [];

for ($run = 1; $run <= REPEAT_RUNS; $run++) {
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($cacheDir);
    }

    $processes = [];
    $pipes = [];

    for ($i = 0; $i < WORKERS; $i++) {
        $process = proc_open(
            ['php', $workerScript, $cacheDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipeHandles,
        );

        if ($process === false) {
            $failures[] = "run {$run}: failed to spawn worker {$i}";

            continue;
        }

        $processes[] = $process;
        $pipes[] = $pipeHandles;
    }

    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($pipes[$index][1]);
        $stderr = stream_get_contents($pipes[$index][2]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $failures[] = "run {$run}, worker {$index}: exit code {$exitCode}, stderr: {$stderr}";
        } elseif (trim($stdout) !== 'ok') {
            $failures[] = "run {$run}, worker {$index}: unexpected output: " . trim($stdout);
        }
    }

    // Verify the final published state, not just that every worker exited
    // cleanly — this is the actual proof: a well-formed, require()-able
    // file for all three artifacts, and zero stray .tmp files left behind
    // regardless of which worker's rename() won the race.
    $store = new CacheStore($cacheDir);

    if (!$store->exists()) {
        $failures[] = "run {$run}: cache does not exist after all workers finished";
    }

    try {
        $http = $store->loadHttp();
        $mcp = $store->loadMcp();
        $openApi = $store->loadOpenApi();

        if ($http === null || $mcp === null || $openApi === null) {
            $failures[] = "run {$run}: one or more sections failed to load (stale format or corrupt file)";
        }
    } catch (Throwable $e) {
        $failures[] = "run {$run}: loading threw: " . $e->getMessage();
    }

    $strayTmpFiles = glob($cacheDir . '/*.tmp') ?: [];

    if ($strayTmpFiles !== []) {
        $failures[] = "run {$run}: stray tmp files left behind: " . implode(', ', $strayTmpFiles);
    }

    echo "run {$run}/" . REPEAT_RUNS . ": " . (($failures === []) ? "clean\n" : "FAILURES SO FAR: " . count($failures) . "\n");
}

foreach (glob($cacheDir . '/*') ?: [] as $file) {
    unlink($file);
}

if (is_dir($cacheDir)) {
    rmdir($cacheDir);
}

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "\nAll " . REPEAT_RUNS . " runs of " . WORKERS . " concurrent workers each: clean. No corruption, no stray tmp files.\n";
