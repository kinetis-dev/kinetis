<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheWriteException;

/**
 * Reads/writes four independent, self-contained generated PHP files —
 * http.php, commands.php, events.php, plugins.php, each
 * returning a literal array via var_export() — rather than one
 * monolithic artifact. A normal HTTP request only ever loads http.php;
 * commands.php is used entirely by bin/kinetis's own bootstrap, to find
 * which class handles a given command name; events.php is loaded
 * wherever an EventDispatcher is resolved; plugins.php carries every
 * installed package's own CacheableDiscoveryInterface data — each
 * independent of the others. (The OpenAPI document is a separate
 * artifact entirely, loaded lazily by Kernel only the instant
 * /openapi.json is actually hit — not one of the four this class owns.)
 *
 * var_export()-PHP over JSON for the same reason as before: OPcache's
 * shared opcode cache is keyed by realpath and shared across every FPM
 * worker process on a host, so require()ing one of these files on request
 * 2+ (any worker) skips re-parsing entirely — a benefit JSON can't get.
 *
 * write() is safe under concurrent writers without any locking — see
 * writeAll()'s docblock for the exact reasoning (unchanged from the
 * single-file design: unique temp filename + atomic rename per file).
 */
final class CacheStore
{
    public function __construct(
        private readonly string $directory,
    ) {}

    public function httpPath(): string
    {
        return $this->directory . '/http.php';
    }



    public function commandsPath(): string
    {
        return $this->directory . '/commands.php';
    }

    public function eventsPath(): string
    {
        return $this->directory . '/events.php';
    }

    public function pluginsPath(): string
    {
        return $this->directory . '/plugins.php';
    }

    /**
     * True only when all four files are present — a Compiler run always
     * produces all four together, so a partially-missing set (e.g. one
     * file deleted by hand, or an old cache directory from before
     * plugins.php existed) is treated as "no cache" and triggers a full
     * recompile of all four, rather than serving an inconsistent mix.
     */
    public function exists(): bool
    {
        return is_file($this->httpPath()) && is_file($this->commandsPath()) && is_file($this->eventsPath())
            && is_file($this->pluginsPath());
    }

    public function loadHttp(): ?HttpCache
    {
        $data = $this->loadSection($this->httpPath());

        return $data === null ? null : HttpCache::fromArray($data);
    }



    public function loadCommands(): ?CommandCache
    {
        $data = $this->loadSection($this->commandsPath());

        return $data === null ? null : CommandCache::fromArray($data);
    }

    public function loadEvents(): ?EventCache
    {
        $data = $this->loadSection($this->eventsPath());

        return $data === null ? null : EventCache::fromArray($data);
    }

    public function loadPlugins(): ?PluginCache
    {
        $data = $this->loadSection($this->pluginsPath());

        return $data === null ? null : PluginCache::fromArray($data);
    }

    /**
     * Writes all four files. Each writer generates a uniquely-suffixed
     * temp filename inside the same directory, writes the full content
     * there, then rename()s it onto the real target path — rename() within
     * one directory is atomic on POSIX (a directory-entry swap, not a data
     * copy), so a reader's require() only ever addresses a fully-formed
     * file. Deliberately not mutex-exclusive: N FPM workers cold-starting
     * simultaneously against an empty cache will all redundantly compile
     * once — accepted, bounded, one-time-per-process waste, since every
     * racing writer compiles from the same discovered classes and so
     * produces byte-identical output (aside from the unused
     * compiledAt timestamp); whichever rename() lands last is
     * inconsequential.
     */
    public function writeAll(CompiledCache $cache): void
    {
        $this->writeSection($this->httpPath(), $cache->http->toArray());
        $this->writeSection($this->commandsPath(), $cache->commands->toArray());
        $this->writeSection($this->eventsPath(), $cache->events->toArray());
        $this->writeSection($this->pluginsPath(), $cache->plugins->toArray());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSection(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $data */
        $data = require $path;

        if (!is_array($data) || ($data['formatVersion'] ?? null) !== CacheFormat::VERSION) {
            // Stale/foreign shape (e.g. from an Kinetis upgrade that changed
            // this format) — the caller falls back to compiling fresh
            // rather than crashing against an incompatible cache file.
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSection(string $path, array $data): void
    {
        // The @ is deliberate, not a suppressed real error: under
        // concurrent writers (see this class's docblock), two workers can
        // both observe the directory missing and race to create it —
        // mkdir() then fails for whichever one loses that race, which is
        // expected and harmless, not a real failure. The is_dir() recheck
        // right after is what actually distinguishes that from a genuine
        // failure to create the directory.
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw CacheWriteException::couldNotCreateDirectory($this->directory);
        }

        self::assertExportable($data, basename($path));

        $contents = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        $tmpPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (file_put_contents($tmpPath, $contents) === false) {
            throw CacheWriteException::couldNotWriteTemporaryFile($tmpPath);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw CacheWriteException::couldNotPublish($path);
        }
    }

    /**
     * var_export() renders an object as a `\SomeClass::__set_state(...)`
     * call most classes can't actually replay, so an object anywhere in a
     * cache artifact — in practice, a constructor default value like
     * `new DateTimeImmutable()` captured into a binding/hydration plan —
     * would produce a file that can't be required back. Caught here, at
     * build time, as a clear error naming where the object sits, instead
     * of a corrupt artifact discovered on the first request that loads it.
     *
     * @param array<array-key, mixed> $data
     */
    private static function assertExportable(array $data, string $file, string $keyPath = ''): void
    {
        foreach ($data as $key => $value) {
            $valuePath = $keyPath === '' ? (string) $key : "{$keyPath}.{$key}";

            if (is_object($value)) {
                throw CacheWriteException::unexportableObject($file, $valuePath, $value::class);
            }

            if (is_array($value)) {
                self::assertExportable($value, $file, $valuePath);
            }
        }
    }
}
