<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Not a PHPUnit test — a standalone proof that CacheStore's generation
 * publishing (a new generation directory fully written, then a single
 * current pointer switched onto it — see CacheStore's own docblock) is
 * genuinely safe when many real, independent PHP processes race to
 * publish against an empty cache directory at essentially the same
 * moment (the FPM cold-start scenario). Spawns real child processes via
 * proc_open(), not threads or fibers within one process, since the
 * actual risk is separate OS processes racing on the filesystem. The
 * deterministic proof that one CacheStore instance can never observe a
 * mixed generation lives in CacheStoreTest — this script is the
 * complementary, real-multi-process timing proof that repeated,
 * genuinely simultaneous racing never corrupts a file or publishes
 * something unsafe, run REPEAT_RUNS times rather than trusted on a
 * single pass.
 *
 * The barrier sits at the actual publication boundary, not merely
 * somewhere before it: every worker signals ready only once it has
 * already performed its own cold check and compiled and marked its own
 * CompiledCache — the point immediately before it would call
 * writeAll() — and this script releases the shared go file only once
 * every successfully spawned worker has reached that same point.
 * Reaching READY_DEADLINE_SECONDS with any worker still unready is
 * treated as a failed run outright, not a partial cohort silently
 * reported as the intended proof — see cache_race_worker.php's own
 * docblock for the deadline this passes it and why the worker's own
 * go-wait deadline is derived from it rather than a second,
 * independently chosen number.
 *
 * Once released, every worker calls writeAll() unconditionally, so
 * $writerCount === count($processes) is a deterministic guarantee for
 * every run that clears the readiness barrier, not a threshold several
 * of WORKERS processes merely tend to reach.
 */
const WORKERS = 20;
const REPEAT_RUNS = 20;
const READY_DEADLINE_SECONDS = 10.0;

/**
 * Every .tmp file left anywhere under $directory, at any depth — a
 * generation's own temp-then-rename writes land inside its own
 * subdirectory, not directly under the cache directory's top level, so
 * a plain glob('*.tmp') would miss them.
 *
 * @return list<string>
 */
function strayTmpFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $found = [];

    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . '/' . $entry;

        if (is_dir($path)) {
            $found = [...$found, ...strayTmpFiles($path)];
        } elseif (str_ends_with($entry, '.tmp')) {
            $found[] = $path;
        }
    }

    return $found;
}

$workerScript = __DIR__ . '/cache_race_worker.php';
$cacheDir = sys_get_temp_dir() . '/kinetis_cache_race_' . bin2hex(random_bytes(4));

$failures = [];

for ($run = 1; $run <= REPEAT_RUNS; $run++) {
    CacheStore::destroy($cacheDir);

    $coordDir = sys_get_temp_dir() . '/kinetis_cache_race_coord_' . bin2hex(random_bytes(4));
    mkdir($coordDir, 0775, true);

    $processes = [];
    $pipes = [];

    for ($i = 0; $i < WORKERS; $i++) {
        $process = proc_open(
            ['php', $workerScript, $cacheDir, $coordDir, (string) $i, (string) READY_DEADLINE_SECONDS],
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

    // Release the shared go file only once every spawned worker has
    // reached the publication boundary — see this script's own
    // docblock.
    $readyDeadline = microtime(true) + READY_DEADLINE_SECONDS;

    while (count(glob("{$coordDir}/ready_*") ?: []) < count($processes)) {
        if (microtime(true) > $readyDeadline) {
            break;
        }

        usleep(1000);
    }

    $readyCount = count(glob("{$coordDir}/ready_*") ?: []);
    $barrierFailed = $readyCount < count($processes);

    // Released either way — a worker already past its own ready signal
    // is simply waiting on this file next, and letting it proceed here
    // (rather than leaving it to run out its own, separately consistent
    // go-wait deadline) keeps this run's wall time bounded even once
    // the run is already known to have failed.
    touch("{$coordDir}/go");

    if ($barrierFailed) {
        $failures[] = "run {$run}: only {$readyCount} of " . count($processes)
            . ' workers reached the publication boundary before the readiness deadline'
            . ' — a partial cohort, not a valid concurrent-publish proof';
    }

    $writerCount = 0;

    foreach ($processes as $index => $process) {
        $stdout = trim(stream_get_contents($pipes[$index][1]));
        $stderr = stream_get_contents($pipes[$index][2]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $failures[] = "run {$run}, worker {$index}: exit code {$exitCode}, stderr: {$stderr}";
        } elseif ($stdout === 'ok writer') {
            $writerCount++;
        } else {
            $failures[] = "run {$run}, worker {$index}: unexpected output: {$stdout}";
        }
    }

    CacheStore::destroy($coordDir);

    if ($barrierFailed) {
        // Already recorded above, and nothing past this point can be
        // trusted to mean anything for a run some workers never even
        // reached the barrier for.
        echo "run {$run}/" . REPEAT_RUNS . ": barrier failed ({$readyCount}/" . count($processes) . " ready) — FAILURES SO FAR: " . count($failures) . "\n";

        continue;
    }

    // The actual proof this run exercised genuinely simultaneous
    // publication — see this script's own docblock. Every worker that
    // reached the barrier must call writeAll(); anything less is a
    // real failure, not "usually enough."
    if ($writerCount !== count($processes)) {
        $failures[] = "run {$run}: only {$writerCount} of " . count($processes) . " worker(s) actually published";
    }

    // Verify the final published state with a brand-new CacheStore
    // instance — not one any worker used — so this is a genuinely fresh
    // pin, exactly what the next real request after this race would
    // see: a well-formed, require()-able file for all four sections,
    // all four from the identical generation (the same
    // compiledAt-consistency check each worker already did for its own
    // read, repeated here against whichever generation is active once
    // every worker has finished), and zero stray .tmp files left behind
    // regardless of which worker's pointer-swap won the race.
    $store = new CacheStore($cacheDir);

    if (!$store->exists()) {
        $failures[] = "run {$run}: cache does not exist after all workers finished";
    }

    try {
        $http = $store->loadHttp();
        $commands = $store->loadCommands();
        $events = $store->loadEvents();
        $plugins = $store->loadPlugins();

        if ($http === null || $commands === null || $events === null || $plugins === null) {
            $failures[] = "run {$run}: one or more sections failed to load (stale format or corrupt file)";
        } else {
            $markers = [$http->compiledAt, $commands->compiledAt, $events->compiledAt, $plugins->compiledAt];

            if (count(array_unique($markers)) !== 1) {
                $failures[] = "run {$run}: final published state is a mixed generation: " . implode(', ', $markers);
            }
        }
    } catch (Throwable $e) {
        $failures[] = "run {$run}: loading threw: " . $e->getMessage();
    }

    $strayTmpFiles = strayTmpFiles($cacheDir);

    if ($strayTmpFiles !== []) {
        $failures[] = "run {$run}: stray tmp files left behind: " . implode(', ', $strayTmpFiles);
    }

    echo "run {$run}/" . REPEAT_RUNS . ": {$writerCount}/" . count($processes) . ' writers — '
        . (($failures === []) ? "clean\n" : "FAILURES SO FAR: " . count($failures) . "\n");
}

CacheStore::destroy($cacheDir);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "\nAll " . REPEAT_RUNS . " runs of " . WORKERS . " concurrent workers each: clean, every run with every"
    . " worker reaching the publication boundary together and publishing. No corruption, no mixed generation,"
    . " no stray tmp files.\n";
