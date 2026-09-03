<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheWriteException;
use ParseError;
use Throwable;

/**
 * Publishes and reads one *generation* at a time — a complete,
 * immutable set of the four section files (http.php, commands.php,
 * events.php, plugins.php) living together in their own subdirectory,
 * never mutated once written. `writeAll()` writes every section into a
 * brand-new generation directory first, and only once all four have
 * succeeded, plus the pointer write itself, does it atomically switch a
 * single `current` pointer to name that generation — the one moment any
 * reader can ever learn it exists. Before that switch, the new
 * generation is simply invisible; after it, every reader sees the whole
 * thing at once. A reader can therefore only ever observe a fully old or
 * fully new generation, never a mix — the same file-level atomic
 * tmp-file-then-rename() mechanism, applied to which generation is
 * active just as much as to each file's own bytes.
 *
 * Each `CacheStore` *instance* pins itself to whichever generation its
 * first `load*()`/`exists()` call resolves, and every later call on that
 * same instance keeps reading from it — even if another process
 * publishes a new generation in between. This is what makes the four
 * separate `require`s a normal boot still needs (loadHttp() then, later,
 * loadEvents()/loadPlugins() — see BootSequence/public/index.php/
 * bin/kinetis) safe: each call reaches the identical generation the
 * first one did, so a production boot can never combine one generation's
 * routes with another's event listeners. A fresh instance constructed
 * later resolves independently and may see a newer generation — exactly
 * what a request that starts after a deploy's `kinetis build` finishes
 * is supposed to see.
 *
 * Sections stay lazy per generation: HTTP never has to `require`
 * commands.php, and the CLI never has to `require` http.php — `current`
 * is the one small file every entry point reads in addition, two lines
 * of plain text, not a monolithic payload.
 *
 * The pointer is deliberately *not* PHP, unlike every section file —
 * this is the one place `require()` and OPcache's shared opcode cache
 * would actively work against correctness rather than for it. Every
 * section lives at a brand-new path per generation, so OPcache caching
 * it "forever" (`opcache.validate_timestamps=0`, a common production
 * setting) is exactly right: that exact path's content never changes
 * once written. `current` is the opposite — the one path this class
 * reuses across every publish, rewritten via rename() each time — and
 * under that same setting, OPcache never re-stats a `require()`d file to
 * notice the rename happened; it would keep serving the *first* compiled
 * generation's opcodes for `current` indefinitely, silently pointing
 * every subsequent read back at the same stale generation no matter how
 * many times `kinetis build` reports success, until an explicit
 * `opcache_reset()`/`opcache_invalidate()` or a process restart. Reading
 * it with `file_get_contents()` instead — plain data, never compiled,
 * never opcode-cached — makes a fresh read see a fresh rename() every
 * time, the same guarantee the four section files get from a different
 * mechanism (a unique path) rather than needing this one.
 *
 * Retention is deliberately conservative, per this class's own
 * `writeAll()` docblock: a publish never deletes an older generation's
 * directory, only `CacheStore::destroy()` does (an explicit, whole-
 * directory operation — see `Console\BuildCommand`'s `--destroy`). There
 * is no reader-liveness signal this class could check before deleting an
 * older generation safely — a long-lived persistent-worker process can
 * hold a `CacheStore` instance, and therefore a pin, for as long as it
 * runs, possibly spanning more than one deploy's `kinetis build` — so
 * "never delete automatically" is the one policy that's safe regardless
 * of how long a reader has been pinned. The accepted cost: repeated
 * `kinetis build` runs in a long-lived environment without an
 * intervening `--destroy` accumulate generation directories on disk.
 *
 * var_export()-PHP over JSON for every *section* file, for the same
 * reason as before: OPcache's shared opcode cache is keyed by realpath
 * and shared across every FPM worker process on a host, so require()ing
 * one of these files on request 2+ (any worker) skips re-parsing
 * entirely — a benefit JSON can't get, and one the pointer file cannot
 * safely take, per its own reasoning above.
 */
final class CacheStore
{
    private const string POINTER_FILENAME = 'current';

    /**
     * `bin2hex(random_bytes(8))` always produces exactly 16 lowercase
     * hex characters — the only shape writeAll() ever generates. A
     * pointer naming anything else (a stale/foreign write, tampering, or
     * simple corruption) is treated identically to no pointer at all,
     * rather than trusted and concatenated into a filesystem path: this
     * is what stops a `generation` value like `../../../etc` or an
     * absolute path from ever being read as one, in `readPointer()`
     * below.
     */
    private const string GENERATION_NAME_PATTERN = '/^gen_[0-9a-f]{16}$/D';

    /** @var list<string> */
    private const array SECTION_FILENAMES = ['http.php', 'commands.php', 'events.php', 'plugins.php'];

    private bool $generationResolved = false;
    private ?string $pinnedGeneration = null;

    public function __construct(
        private readonly string $directory,
    ) {}

    /**
     * True only when this instance's pinned generation is present and
     * carries all four section files, checked within whichever
     * generation directory got pinned rather than across the cache
     * directory's own top level.
     *
     * A presence check only — it does not confirm any file's own
     * `formatVersion` matches, and does not attempt to reconstruct
     * `EventListenerRegistry`/plugin data the way
     * {@see BootSequence::loadHttpFromCache()}/`loadCliFromCache()` do.
     * This is deliberately a weaker, cheaper question ("has `writeAll()`
     * ever published a complete-looking generation here") for tooling
     * and tests that only need that (`Console\BuildCommand`'s own tests,
     * `CacheStoreTest` itself) — never a stand-in for "is this
     * generation safe to boot a request or command from," which is
     * `BootSequence`'s own, stricter contract. No production boot path
     * calls this method.
     */
    public function exists(): bool
    {
        $generationDirectory = $this->pinnedGenerationDirectory();

        if ($generationDirectory === null) {
            return false;
        }

        foreach (self::SECTION_FILENAMES as $filename) {
            if (!is_file($generationDirectory . '/' . $filename)) {
                return false;
            }
        }

        return true;
    }

    public function loadHttp(): ?HttpCache
    {
        $data = $this->loadSection('http.php');

        return $data === null ? null : HttpCache::fromArray($data);
    }

    public function loadCommands(): ?CommandCache
    {
        $data = $this->loadSection('commands.php');

        return $data === null ? null : CommandCache::fromArray($data);
    }

    public function loadEvents(): ?EventCache
    {
        $data = $this->loadSection('events.php');

        return $data === null ? null : EventCache::fromArray($data);
    }

    public function loadPlugins(): ?PluginCache
    {
        $data = $this->loadSection('plugins.php');

        return $data === null ? null : PluginCache::fromArray($data);
    }

    /**
     * The currently active generation's own directory — a fresh,
     * unpinned read of the pointer on every call, deliberately
     * independent of this instance's own pin. Exists for inspection
     * (tooling, tests that need to reach into the published files
     * directly), never for `load*()`/`exists()` themselves, which always
     * go through the pinned resolution above. Null when nothing has been
     * published yet, or the pointer itself is stale/foreign/invalid.
     */
    public function activeGenerationDirectory(): ?string
    {
        $generation = $this->readPointer();

        return $generation === null ? null : $this->directory . '/' . $generation;
    }

    /**
     * Writes a brand-new generation directory, one uniquely-suffixed
     * random name per call so concurrent writers can never collide on
     * it, then — only once every one of the four sections has been fully
     * written and atomically renamed into place inside it — atomically
     * switches the `current` pointer to name it. That last rename is the
     * single moment a new generation exists as far as any reader is
     * concerned; everything before it is invisible, so a section write
     * (or the pointer write itself) failing never publishes a partial or
     * mixed set — the try/catch below covers the pointer write too, not
     * only the four sections, since a failure there is just as capable of
     * leaving an unpublished, orphaned generation directory behind if it
     * weren't cleaned up the same way. Either way, the previous pointer
     * (if any) is never touched until the new one's own rename() actually
     * succeeds, so a failed publish leaves whatever was already active
     * exactly as it was.
     *
     * Deliberately not mutex-exclusive, the same accepted tradeoff as
     * before: N workers cold-starting simultaneously against no
     * published generation yet will each redundantly compile once and
     * each publish their own generation — bounded, one-time-per-process
     * waste, since every racing compile produces the same discovered
     * classes. Whichever one's pointer-swap lands last is the one every
     * reader resolving after that point sees; the ones that lost the
     * race simply sit as retained, unreferenced generations, the same as
     * any other superseded one.
     */
    public function writeAll(CompiledCache $cache): void
    {
        $generation = 'gen_' . bin2hex(random_bytes(8));
        $generationDirectory = $this->directory . '/' . $generation;

        try {
            $this->writeSection($generationDirectory, 'http.php', $cache->http->toArray());
            $this->writeSection($generationDirectory, 'commands.php', $cache->commands->toArray());
            $this->writeSection($generationDirectory, 'events.php', $cache->events->toArray());
            $this->writeSection($generationDirectory, 'plugins.php', $cache->plugins->toArray());
            $this->writePointer($generation);
        } catch (Throwable $e) {
            self::removeDirectoryRecursively($generationDirectory);

            throw $e;
        }
    }

    /**
     * Removes the entire cache directory — the pointer and every
     * generation it has ever named, retained or not. The one operation
     * allowed to delete a generation a concurrent reader might still be
     * pinned to: an explicit, whole-directory action an operator chooses
     * to run (`kinetis build --destroy`), not something a normal publish
     * ever does on its own — see this class's own docblock on retention.
     *
     * Throws rather than silently leaving debris behind on a real
     * removal failure (a permission error partway through, say) — a
     * caller reporting success on the strength of this returning would
     * otherwise be wrong.
     */
    public static function destroy(string $directory): void
    {
        self::removeDirectoryRecursively($directory);
    }

    private function pinnedGenerationDirectory(): ?string
    {
        if (!$this->generationResolved) {
            $this->generationResolved = true;
            $this->pinnedGeneration = $this->readPointer();
        }

        return $this->pinnedGeneration === null ? null : $this->directory . '/' . $this->pinnedGeneration;
    }

    /**
     * Reads `current` as plain data — see this class's own docblock for
     * why it must never be `require()`d. Exactly the shape writePointer()
     * emits: the format version, a newline, the generation name, a
     * newline — nothing else, before or after. A single trailing newline
     * is required, not optional or ignored, since it is the one thing
     * that makes "exactly two lines" checkable by counting parts rather
     * than merely reading the first two and discarding whatever follows.
     * Missing, empty, one line, a third line or any other trailing data,
     * a missing trailing newline, a mismatched version, or a generation
     * value that isn't exactly the grammar writeAll() itself always
     * generates — every one of these is treated identically to no
     * pointer at all, never trusted far enough to reach a filesystem
     * path.
     */
    private function readPointer(): ?string
    {
        $pointerPath = $this->directory . '/' . self::POINTER_FILENAME;
        $contents = is_file($pointerPath) ? @file_get_contents($pointerPath) : false;

        if ($contents === false) {
            return null;
        }

        // writePointer() only ever emits exactly "{version}\n{generation}\n"
        // — nothing before it, nothing after. Splitting on "\n" with no
        // limit and requiring exactly three resulting parts, the last one
        // empty, is what actually enforces that whole shape: a real
        // trailing newline (and only one) produces exactly that; any
        // extra line, trailing data after the generation, or a missing
        // trailing newline produces a different count or a non-empty
        // last element, and is rejected the same as any other
        // stale/foreign content — never silently truncated to "the first
        // two lines, ignore the rest".
        $lines = explode("\n", $contents);

        if (count($lines) !== 3 || $lines[2] !== '') {
            return null;
        }

        [$formatVersion, $generation] = $lines;

        if ($formatVersion !== (string) CacheFormat::VERSION) {
            // Stale/foreign shape — either an older Kinetis version's own
            // pointer format, or (before generations existed at all) no
            // pointer file could ever have existed at this path in the
            // first place. Either way, the caller falls back to
            // compiling fresh rather than crashing against it.
            return null;
        }

        return self::isValidGenerationName($generation) ? $generation : null;
    }

    private static function isValidGenerationName(string $generation): bool
    {
        return preg_match(self::GENERATION_NAME_PATTERN, $generation) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSection(string $filename): ?array
    {
        $generationDirectory = $this->pinnedGenerationDirectory();

        if ($generationDirectory === null) {
            return null;
        }

        $path = $generationDirectory . '/' . $filename;

        if (!is_file($path)) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = require $path;
        } catch (ParseError) {
            // A syntax-corrupt section file (a truncated write, disk
            // corruption, manual tampering) is exactly as unusable as a
            // missing one — never trusted far enough to let require()'s
            // own ParseError propagate out of this class. Caught
            // narrowly by type: only the failure mode intrinsic to
            // requiring a persisted artifact (its PHP is malformed) is
            // classified this way, never any other Throwable a file's
            // own top-level code might deliberately execute — such a
            // file would already have failed assertExportable() at
            // write time, since every section is generated by
            // var_export(), never hand-authored.
            return null;
        }

        if (!is_array($data) || ($data['formatVersion'] ?? null) !== CacheFormat::VERSION) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSection(string $directory, string $filename, array $data): void
    {
        self::assertExportable($data, $filename);

        $this->writeAtomically($directory, $filename, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    }

    private function writePointer(string $generation): void
    {
        $this->writeAtomically($this->directory, self::POINTER_FILENAME, CacheFormat::VERSION . "\n" . $generation . "\n");
    }

    /**
     * The one atomic-write primitive both writeSection() and
     * writePointer() share: a uniquely-suffixed temp file written inside
     * the same target directory, then rename()d onto the real path —
     * rename() within one directory is atomic on POSIX (a directory-entry
     * swap, not a data copy), so a reader ever addressing this path only
     * ever sees a fully-formed file, never a partially-written one.
     */
    private function writeAtomically(string $directory, string $filename, string $contents): void
    {
        // The @ is deliberate, not a suppressed real error: under
        // concurrent writers, two can both observe a directory missing
        // and race to create it — mkdir() then fails for whichever one
        // loses that race, which is expected and harmless, not a real
        // failure. The is_dir() recheck right after is what actually
        // distinguishes that from a genuine failure to create it.
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw CacheWriteException::couldNotCreateDirectory($directory);
        }

        $path = $directory . '/' . $filename;
        $tmpPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (file_put_contents($tmpPath, $contents) === false) {
            throw CacheWriteException::couldNotWriteTemporaryFile($tmpPath);
        }

        // The @ here is the same discipline as mkdir()'s own above:
        // CacheWriteException::couldNotPublish() is the real, loud
        // report of this failure (its own message names the exact
        // path), so a second, redundant raw PHP warning for the
        // identical failure adds nothing.
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw CacheWriteException::couldNotPublish($path);
        }
    }

    /**
     * Never follows a symlink, at any depth, including $directory itself
     * being one: is_dir() alone can't tell a real directory from a
     * symlink pointing at one, and recursing into a symlinked directory
     * would let anything with write access inside the cache tree (a
     * malicious or simply accidental link) make this delete files far
     * outside it. is_link() is checked first, at every level, and only
     * ever removes the link itself.
     */
    private static function removeDirectoryRecursively(string $directory): void
    {
        if (is_link($directory)) {
            if (!unlink($directory)) {
                throw CacheWriteException::couldNotRemove($directory);
            }

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;

            if (is_link($path)) {
                if (!unlink($path)) {
                    throw CacheWriteException::couldNotRemove($path);
                }
            } elseif (is_dir($path)) {
                self::removeDirectoryRecursively($path);
            } elseif (!unlink($path)) {
                throw CacheWriteException::couldNotRemove($path);
            }
        }

        if (!rmdir($directory)) {
            throw CacheWriteException::couldNotRemove($directory);
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
