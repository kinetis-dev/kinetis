<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Support\SessionExpiry;

/**
 * One JSON file per session under a single directory, needing no
 * backing service at all — suited to local development. Expiry is an
 * `expiresAt` timestamp inside the envelope, checked on read; an
 * expired file is deleted when next read, and gc() sweeps the rest —
 * schedule the `session:gc` command for that, nothing runs it
 * implicitly. A session is live only while `expiresAt` is strictly in
 * the future — read() and gc() (via isCollectable()) both check this
 * through {@see SessionExpiry::isExpired()}, the one shared predicate,
 * rather than either comparing `expiresAt` against `time()` inline —
 * the same `expires_at > now`/`expires_at <= now` boundary
 * {@see SqlSessionStore} already enforces at the database level, so
 * both stores agree on the exact second a session actually expires.
 * `expiresAt` itself, and every `$lifetimeSeconds` a caller can pass,
 * is computed and validated via {@see SessionExpiry::timestampFor()} —
 * never `time() + $lifetimeSeconds` directly — so an invalid or
 * unrepresentable lifetime fails loudly, here, rather than silently
 * corrupting the stored envelope.
 *
 * Multi-process safe only in the last-write-wins sense the store
 * contract already declares; not intended for production fleets, where
 * every worker would need the same shared filesystem anyway.
 *
 * Confidentiality is enforced, not merely intended: the session
 * directory must have no group or world permissions at all (validated
 * at construction against its real, current mode — a pre-existing,
 * externally-provisioned directory is refused rather than silently
 * corrected), and every session file's real, resulting mode is verified
 * as private (0600) before it can ever become the live session — a
 * chmod() call that reports success without genuinely narrowing the
 * file's permissions never gets to publish it.
 */
final readonly class FileSessionStore implements SessionStoreInterface, GarbageCollectableStoreInterface
{
    private const int DIRECTORY_MODE = 0700;

    private const int FILE_MODE = 0600;

    /**
     * A freshly created directory always gets DIRECTORY_MODE (mkdir()'s
     * mode argument has no group/world bits for umask to strip in the
     * first place), but a pre-existing, externally-provisioned directory
     * is never trusted just because it exists — its actual permissions
     * are validated here, every construction, so a group- or
     * world-accessible directory can never silently widen the window
     * write() relies on staying private between creating a temp file and
     * chmod()ing it. Refused, not corrected: this store does not own the
     * directory's permissions and must not silently broaden or take
     * ownership of a path it did not create.
     */
    public function __construct(private string $directory)
    {
        if (!\is_dir($directory) && !@\mkdir($directory, self::DIRECTORY_MODE, true) && !\is_dir($directory)) {
            throw new SessionException("Session directory \"{$directory}\" could not be created.");
        }

        $actualMode = @\fileperms($directory);

        if ($actualMode === false) {
            throw new SessionException("Session directory \"{$directory}\" could not be inspected.");
        }

        if (($actualMode & 0077) !== 0) {
            throw new SessionException(\sprintf(
                'Session directory "%s" is group- or world-accessible (mode 0%o); it must have no group or '
                . 'world permissions at all, since session payloads are confidential. Fix its permissions '
                . 'directly (e.g. chmod 0700) rather than relying on this store to change them for you.',
                $directory,
                $actualMode & 0777,
            ));
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $path = $this->pathFor($id);
        $raw = @\file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $envelope = \json_decode($raw, true);

        if (!\is_array($envelope) || !\is_array($envelope['data'] ?? null) || !\is_int($envelope['expiresAt'] ?? null)) {
            return null;
        }

        if (SessionExpiry::isExpired($envelope['expiresAt'], \time())) {
            @\unlink($path);

            return null;
        }

        /** @var array<string, mixed> */
        return $envelope['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $payload = \json_encode(
            ['expiresAt' => SessionExpiry::timestampFor($lifetimeSeconds), 'data' => $data],
            JSON_THROW_ON_ERROR,
        );

        $path = $this->pathFor($id);
        // Same directory as $path, not sys_get_temp_dir(): rename() is
        // only atomic within one filesystem, and writing straight to
        // $path (even under LOCK_EX, which only ever protected against
        // another *writer*) lets a concurrent, lock-free read() observe
        // a truncated/partial file mid-write — a torn read, not covered
        // by "last-write-wins". Write-then-rename is what this
        // project's own AOT CacheStore already does for the same
        // reason; a session file additionally gets 0600, matching this
        // store's own 0700 directory, since it's not meant to be
        // group/world-readable the way the AOT cache is.
        //
        // Named ".sess-tmp-*", deliberately never matching gc()'s own
        // "sess_*" glob pattern — the earlier "$path.<random>.tmp" naming
        // still started with "sess_", so a gc() sweep landing between
        // this file_put_contents() and the rename() below could collect
        // and unlink the in-progress temp file out from under this write
        // (a partial envelope is always collectable; a complete one that
        // just hasn't been renamed yet still looks collectable too, since
        // gc() has no way to tell "in progress" from "abandoned"), making
        // the rename() below fail and silently losing the write.
        $tmpPath = $this->directory . '/.sess-tmp-' . \bin2hex(\random_bytes(8)) . '.tmp';

        // Compared against the exact expected byte count, not merely
        // "not false": file_put_contents() can create a partial file
        // before failing (e.g. the disk fills up mid-write), and a short
        // write must never be published under the live session path —
        // the next read would see truncated, invalid JSON and treat a
        // session that existed a moment ago as simply missing.
        if (@\file_put_contents($tmpPath, $payload) !== \strlen($payload)) {
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be written.");
        }

        // A chmod() that reports success is not proof enough on its own:
        // its actual resulting mode is verified directly before this temp
        // file is ever allowed to become the live session, so a silent
        // mismatch here — the call "succeeding" without genuinely
        // narrowing the file to FILE_MODE — can never publish a session
        // payload wider than intended.
        if (!@\chmod($tmpPath, self::FILE_MODE)) {
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be secured with private permissions.");
        }

        $actualFileMode = @\fileperms($tmpPath);

        if ($actualFileMode === false || ($actualFileMode & 0777) !== self::FILE_MODE) {
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be secured with private permissions.");
        }

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be written.");
        }
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $path = $this->pathFor($id);

        if (@\unlink($path)) {
            return;
        }

        // unlink() fails for two genuinely different reasons: the file
        // was already gone — a benign, idempotent no-op, since a
        // concurrent destroy(), an expiry-driven read(), or a gc()
        // sweep could easily have removed it first — or a real I/O/
        // permission failure that left the file still on disk. Only
        // the second one is an actual error; checking which one
        // happened is what keeps a genuinely failed logout/regenerate()
        // from being silently treated the same as a race that already
        // resolved correctly.
        if (\file_exists($path)) {
            throw new SessionException("Session file for \"{$id}\" could not be deleted.");
        }
    }

    #[\Override]
    public function gc(): int
    {
        $removed = 0;

        foreach (\glob($this->directory . '/sess_*') ?: [] as $path) {
            if (self::isCollectable($path) && @\unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** Expired, or unreadable as a session envelope at all. */
    private static function isCollectable(string $path): bool
    {
        $raw = @\file_get_contents($path);
        $envelope = $raw === false ? null : \json_decode($raw, true);

        return !\is_array($envelope)
            || !\is_int($envelope['expiresAt'] ?? null)
            || SessionExpiry::isExpired($envelope['expiresAt'], \time());
    }

    private function pathFor(string $id): string
    {
        // Ids are validated by the middleware before ever reaching a
        // store, but a filesystem path is built here, so this store
        // guards on its own too — defense in depth against traversal.
        if (\preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new SessionException('Invalid session id.');
        }

        return $this->directory . '/sess_' . $id;
    }
}
